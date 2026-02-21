<?php
/**
 * 更新組件設定
 * POST /api/desktop/update_component.php
 * 必填: component_local_udid
 * 選填: component_type_code, bg_color, border_color, border_width, corner_radius, config_json
 */
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/sync_helper.php';

requirePost();
$userId = requireAuth();
$data = getPostData();
requireFields($data, ['component_local_udid']);

$db = getDB();
$componentLocalUdid = $data['component_local_udid'];

// 驗證組件歸屬（透過 desktop 確認 user）
$stmt = $db->prepare('
    SELECT dc.id, dc.server_id FROM desktop_components dc
    INNER JOIN desktops d ON d.local_udid = dc.desktop_local_udid
    WHERE d.user_id = ? AND dc.local_udid = ?
');
$stmt->execute([$userId, $componentLocalUdid]);
$component = $stmt->fetch();
if (!$component) jsonError('組件不存在', 404);

$allowed = ['component_type_code', 'bg_color', 'border_color', 'border_width', 'corner_radius', 'config_json'];
$updates = [];
$params = [];
$logPayload = [];

foreach ($allowed as $field) {
    if (array_key_exists($field, $data)) {
        $value = $data[$field];
        if ($field === 'config_json' && is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        $updates[] = "`$field` = ?";
        $params[] = $value;
        $logPayload[$field] = $data[$field];
    }
}

if (!empty($updates)) {
    $params[] = $component['id'];
    $db->prepare('UPDATE desktop_components SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($params);
}

writeSyncLog($userId, null, 'desktop_component', $component['server_id'], $componentLocalUdid, 'update', $logPayload);

jsonSuccess(['local_udid' => $componentLocalUdid], '組件更新成功');
