<?php
/**
 * 將 Cell 加入資料單
 * POST /api/datasheet/add_cell.php
 * 參數: local_udid (資料單), cell_local_udid
 * 選填: sort_order
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

// 驗證資料單
$stmt = $db->prepare('SELECT id, server_id, local_udid FROM data_sheets WHERE user_id = ? AND local_udid = ? AND is_deleted = 0');
$stmt->execute([$userId, $data['local_udid']]);
$sheet = $stmt->fetch();

if (!$sheet) {
    jsonError('資料單不存在', 404);
}

$sheetLocalUdid = $sheet['local_udid'];

// 驗證 Cell 存在
$stmt = $db->prepare('SELECT id FROM cells WHERE user_id = ? AND local_udid = ? AND is_deleted = 0');
$stmt->execute([$userId, $data['cell_local_udid']]);
if (!$stmt->fetch()) {
    jsonError('Cell 不存在', 404);
}

// 檢查是否已加入
$stmt = $db->prepare('SELECT id FROM data_sheet_cells WHERE data_sheet_local_udid = ? AND cell_local_udid = ?');
$stmt->execute([$sheetLocalUdid, $data['cell_local_udid']]);
if ($stmt->fetch()) {
    jsonError('Cell 已在此資料單中');
}

// 決定排序
$sortOrder = $data['sort_order'] ?? null;
if ($sortOrder === null) {
$stmt = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM data_sheet_cells WHERE data_sheet_local_udid = ?');
$stmt->execute([$sheetLocalUdid]);
    $sortOrder = (int) $stmt->fetchColumn();
}

$stmt = $db->prepare('INSERT INTO data_sheet_cells (data_sheet_local_udid, cell_local_udid, sort_order) VALUES (?, ?, ?)');
$stmt->execute([$sheetLocalUdid, $data['cell_local_udid'], (int) $sortOrder]);

// 更新資料單的 updated_at
$db->prepare('UPDATE data_sheets SET updated_at = NOW() WHERE id = ?')->execute([$sheet['id']]);

writeSyncLog($userId, null, 'data_sheet_cells', $sheet['server_id'], $data['local_udid'], 'update', [
    'action' => 'add_cell',
    'cell_local_udid' => $data['cell_local_udid'],
    'sort_order' => (int) $sortOrder,
]);

jsonSuccess([], '已加入');
