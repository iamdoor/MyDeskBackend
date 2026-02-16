<?php
/**
 * 從資料單移除 Cell
 * POST /api/datasheet/remove_cell.php
 * 參數: local_udid (資料單), cell_local_udid
 */
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/sync_helper.php';

requirePost();
$userId = requireAuth();
$data = getPostData();
requireFields($data, ['local_udid', 'cell_local_udid']);

$db = getDB();

$stmt = $db->prepare('SELECT id, server_id, local_udid FROM data_sheets WHERE user_id = ? AND local_udid = ? AND is_deleted = 0');
$stmt->execute([$userId, $data['local_udid']]);
$sheet = $stmt->fetch();

if (!$sheet) {
    jsonError('資料單不存在', 404);
}

$stmt = $db->prepare('DELETE FROM data_sheet_cells WHERE data_sheet_local_udid = ? AND cell_local_udid = ?');
$stmt->execute([$sheet['local_udid'], $data['cell_local_udid']]);

if ($stmt->rowCount() === 0) {
    jsonError('Cell 不在此資料單中', 404);
}

$db->prepare('UPDATE data_sheets SET updated_at = NOW() WHERE id = ?')->execute([$sheet['id']]);

writeSyncLog($userId, null, 'data_sheet_cells', $sheet['server_id'], $data['local_udid'], 'update', [
    'action' => 'remove_cell',
    'cell_local_udid' => $data['cell_local_udid'],
]);

jsonSuccess([], '已移除');
