<?php
/**
 * 暫時 Cell 轉正為正式 Cell
 * POST /api/desktop/temp_cell/promote.php
 * 必填: temp_cell_local_udid, new_cell_local_udid
 *
 * 流程：
 * 1. 讀取 temp_cell 資料
 * 2. 建立正式 Cell（含 server_id）
 * 3. 將正式 Cell 加入桌面 Cell 池
 * 4. 把所有 desktop_component_links ref 從 temp → cell
 * 5. 更新 temp_cell.promoted_to_cell_udid
 */
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/db.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/sync_helper.php';

requirePost();
$userId = requireAuth();
$data = getPostData();
requireFields($data, ['temp_cell_local_udid', 'new_cell_local_udid']);

$db = getDB();
$tempCellLocalUdid = $data['temp_cell_local_udid'];
$newCellLocalUdid = $data['new_cell_local_udid'];

// 取得暫時 Cell
$stmt = $db->prepare('
    SELECT dtc.* FROM desktop_temp_cells dtc
    INNER JOIN desktops d ON d.local_udid = dtc.desktop_local_udid
    WHERE d.user_id = ? AND dtc.local_udid = ? AND dtc.promoted_to_cell_udid IS NULL
');
$stmt->execute([$userId, $tempCellLocalUdid]);
$tempCell = $stmt->fetch();
if (!$tempCell) jsonError('暫時 Cell 不存在或已轉正', 404);

// 建立正式 Cell
$cellServerId = generateUUID();
$stmt = $db->prepare('
    INSERT INTO cells (server_id, local_udid, user_id, cell_type, title, description, content_json)
    VALUES (?, ?, ?, ?, ?, ?, ?)
');
$stmt->execute([
    $cellServerId,
    $newCellLocalUdid,
    $userId,
    $tempCell['cell_type'],
    $tempCell['title'],
    $tempCell['description'],
    $tempCell['content_json'],
]);

writeSyncLog($userId, null, 'cell', $cellServerId, $newCellLocalUdid, 'create', [
    'server_id' => $cellServerId,
    'cell_type' => $tempCell['cell_type'],
    'title' => $tempCell['title'],
    'promoted_from_temp' => true,
]);

// 加入桌面 Cell 池
$db->prepare('INSERT IGNORE INTO desktop_cells (desktop_local_udid, ref_type, ref_local_udid) VALUES (?, ?, ?)')->execute([
    $tempCell['desktop_local_udid'], 'cell', $newCellLocalUdid,
]);

// 把組件連結從 temp → cell
$db->prepare('
    UPDATE desktop_component_links
    SET ref_type = ?, ref_local_udid = ?
    WHERE ref_type = ? AND ref_local_udid = ?
')->execute(['cell', $newCellLocalUdid, 'temp', $tempCellLocalUdid]);

// 標記 temp_cell 已轉正
$db->prepare('UPDATE desktop_temp_cells SET promoted_to_cell_udid = ? WHERE id = ?')->execute([$newCellLocalUdid, $tempCell['id']]);

writeSyncLog($userId, null, 'desktop_temp_cell', $tempCell['server_id'], $tempCellLocalUdid, 'update', [
    'promoted_to_cell_udid' => $newCellLocalUdid,
]);

jsonSuccess([
    'new_cell_server_id' => $cellServerId,
    'new_cell_local_udid' => $newCellLocalUdid,
    'temp_cell_local_udid' => $tempCellLocalUdid,
    'desktop_local_udid' => $tempCell['desktop_local_udid'],
], '暫時 Cell 轉正成功');
