<?php
/**
 * 更新桌面暫時 Cell
 * POST /api/desktop/temp_cell/update.php
 * 必填: local_udid
 * 選填: cell_type, title, description, content_json
 */
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/db.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/sync_helper.php';

requirePost();
$userId = requireAuth();
$data = getPostData();
requireFields($data, ['local_udid']);

$db = getDB();
$localUdid = $data['local_udid'];

// 驗證歸屬
$stmt = $db->prepare('
    SELECT dtc.id, dtc.server_id FROM desktop_temp_cells dtc
    INNER JOIN desktops d ON d.local_udid = dtc.desktop_local_udid
    WHERE d.user_id = ? AND dtc.local_udid = ?
');
$stmt->execute([$userId, $localUdid]);
$tempCell = $stmt->fetch();
if (!$tempCell) jsonError('暫時 Cell 不存在', 404);

$allowed = ['cell_type', 'title', 'description', 'content_json'];
$updates = [];
$params = [];

foreach ($allowed as $field) {
    if (array_key_exists($field, $data)) {
        $value = $data[$field];
        if ($field === 'content_json' && is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        $updates[] = "`$field` = ?";
        $params[] = $value;
    }
}

if (!empty($updates)) {
    $params[] = $tempCell['id'];
    $db->prepare('UPDATE desktop_temp_cells SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($params);
}

writeSyncLog($userId, null, 'desktop_temp_cell', $tempCell['server_id'], $localUdid, 'update', $data);

jsonSuccess(['local_udid' => $localUdid], '暫時 Cell 更新成功');
