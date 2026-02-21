<?php
/**
 * 刪除桌面暫時 Cell
 * POST /api/desktop/temp_cell/delete.php
 * 必填: local_udid
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

$stmt = $db->prepare('
    SELECT dtc.id, dtc.server_id FROM desktop_temp_cells dtc
    INNER JOIN desktops d ON d.local_udid = dtc.desktop_local_udid
    WHERE d.user_id = ? AND dtc.local_udid = ?
');
$stmt->execute([$userId, $localUdid]);
$tempCell = $stmt->fetch();
if (!$tempCell) jsonError('暫時 Cell 不存在', 404);

// 先移除所有連結
$db->prepare('DELETE FROM desktop_component_links WHERE ref_type = ? AND ref_local_udid = ?')->execute(['temp', $localUdid]);

// 刪除暫時 Cell
$db->prepare('DELETE FROM desktop_temp_cells WHERE id = ?')->execute([$tempCell['id']]);

writeSyncLog($userId, null, 'desktop_temp_cell', $tempCell['server_id'], $localUdid, 'delete', ['local_udid' => $localUdid]);

jsonSuccess(['local_udid' => $localUdid], '暫時 Cell 已刪除');
