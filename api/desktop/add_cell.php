<?php
/**
 * 將 Cell / 資料單加入桌面 Cell 池
 * POST /api/desktop/add_cell.php
 * 必填: desktop_local_udid, ref_local_udid
 * 選填: ref_type (cell|datasheet，預設 cell)
 */
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/sync_helper.php';

requirePost();
$userId = requireAuth();
$data = getPostData();
requireFields($data, ['desktop_local_udid', 'ref_local_udid']);

$db = getDB();
$desktopLocalUdid = $data['desktop_local_udid'];
$refLocalUdid = $data['ref_local_udid'];
$refType = in_array($data['ref_type'] ?? '', ['cell', 'datasheet']) ? $data['ref_type'] : 'cell';

// 驗證桌面歸屬
$stmt = $db->prepare('SELECT id FROM desktops WHERE user_id = ? AND local_udid = ? AND is_deleted = 0');
$stmt->execute([$userId, $desktopLocalUdid]);
if (!$stmt->fetch()) jsonError('桌面不存在', 404);

// 驗證 Cell / 資料單存在
if ($refType === 'cell') {
    $stmt = $db->prepare('SELECT id FROM cells WHERE user_id = ? AND local_udid = ? AND is_deleted = 0');
    $stmt->execute([$userId, $refLocalUdid]);
    if (!$stmt->fetch()) jsonError('Cell 不存在', 404);
} else {
    $stmt = $db->prepare('SELECT id FROM data_sheets WHERE user_id = ? AND local_udid = ? AND is_deleted = 0');
    $stmt->execute([$userId, $refLocalUdid]);
    if (!$stmt->fetch()) jsonError('資料單不存在', 404);
}

// INSERT IGNORE 防止重複
$stmt = $db->prepare('INSERT IGNORE INTO desktop_cells (desktop_local_udid, ref_type, ref_local_udid) VALUES (?, ?, ?)');
$stmt->execute([$desktopLocalUdid, $refType, $refLocalUdid]);

writeSyncLog($userId, null, 'desktop_cells', '', $desktopLocalUdid, 'update', [
    'desktop_local_udid' => $desktopLocalUdid,
    'ref_type' => $refType,
    'ref_local_udid' => $refLocalUdid,
    'action' => 'add',
]);

jsonSuccess(['desktop_local_udid' => $desktopLocalUdid, 'ref_local_udid' => $refLocalUdid], '已加入 Cell 池');
