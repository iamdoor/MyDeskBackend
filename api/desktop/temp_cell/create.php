<?php
/**
 * 建立桌面暫時 Cell
 * POST /api/desktop/temp_cell/create.php
 * 必填: local_udid, desktop_local_udid, cell_type, title
 * 選填: description, content_json
 */
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/db.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/sync_helper.php';

requirePost();
$userId = requireAuth();
$data = getPostData();
requireFields($data, ['local_udid', 'desktop_local_udid', 'cell_type', 'title']);

$db = getDB();
$localUdid = $data['local_udid'];
$desktopLocalUdid = $data['desktop_local_udid'];

// 驗證桌面歸屬
$stmt = $db->prepare('SELECT id FROM desktops WHERE user_id = ? AND local_udid = ? AND is_deleted = 0');
$stmt->execute([$userId, $desktopLocalUdid]);
if (!$stmt->fetch()) jsonError('桌面不存在', 404);

$contentJson = $data['content_json'] ?? null;
if (is_array($contentJson)) $contentJson = json_encode($contentJson, JSON_UNESCAPED_UNICODE);

$serverId = generateUUID();

$stmt = $db->prepare('
    INSERT INTO desktop_temp_cells (server_id, local_udid, desktop_local_udid, cell_type, title, description, content_json)
    VALUES (?, ?, ?, ?, ?, ?, ?)
');
$stmt->execute([
    $serverId,
    $localUdid,
    $desktopLocalUdid,
    (int) $data['cell_type'],
    $data['title'],
    $data['description'] ?? null,
    $contentJson,
]);

$tempCellId = (int) $db->lastInsertId();

writeSyncLog($userId, null, 'desktop_temp_cell', $serverId, $localUdid, 'create', [
    'server_id' => $serverId,
    'local_udid' => $localUdid,
    'desktop_local_udid' => $desktopLocalUdid,
    'cell_type' => (int) $data['cell_type'],
    'title' => $data['title'],
]);

jsonSuccess([
    'temp_cell_id' => $tempCellId,
    'server_id' => $serverId,
    'local_udid' => $localUdid,
], '暫時 Cell 建立成功', 201);
