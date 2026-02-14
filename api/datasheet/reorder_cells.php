<?php
/**
 * 重排資料單內的 Cell 順序
 * POST /api/datasheet/reorder_cells.php
 * 參數: local_udid (資料單), cell_order (JSON array of cell_local_udid，按新順序排列)
 */
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/sync_helper.php';

requirePost();
$userId = requireAuth();
$data = getPostData();
requireFields($data, ['local_udid', 'cell_order']);

$db = getDB();

$stmt = $db->prepare('SELECT id, server_id FROM data_sheets WHERE user_id = ? AND local_udid = ? AND is_deleted = 0');
$stmt->execute([$userId, $data['local_udid']]);
$sheet = $stmt->fetch();

if (!$sheet) {
    jsonError('資料單不存在', 404);
}

$cellOrder = is_string($data['cell_order']) ? json_decode($data['cell_order'], true) : $data['cell_order'];
if (!is_array($cellOrder)) {
    jsonError('cell_order 必須是陣列');
}

$updateStmt = $db->prepare('UPDATE data_sheet_cells SET sort_order = ? WHERE data_sheet_id = ? AND cell_local_udid = ?');

foreach ($cellOrder as $index => $cellUdid) {
    $updateStmt->execute([$index, $sheet['id'], $cellUdid]);
}

$db->prepare('UPDATE data_sheets SET updated_at = NOW() WHERE id = ?')->execute([$sheet['id']]);

writeSyncLog($userId, null, 'data_sheet_cells', $sheet['server_id'], $data['local_udid'], 'update', [
    'action' => 'reorder',
    'cell_order' => $cellOrder,
]);

jsonSuccess([], '排序已更新');
