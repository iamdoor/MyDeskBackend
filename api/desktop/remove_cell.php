<?php
/**
 * 從桌面 Cell 池移除 Cell / 資料單
 * POST /api/desktop/remove_cell.php
 * 必填: desktop_local_udid, ref_local_udid
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

// 驗證桌面歸屬
$stmt = $db->prepare('SELECT id FROM desktops WHERE user_id = ? AND local_udid = ? AND is_deleted = 0');
$stmt->execute([$userId, $desktopLocalUdid]);
if (!$stmt->fetch()) jsonError('桌面不存在', 404);

$stmt = $db->prepare('DELETE FROM desktop_cells WHERE desktop_local_udid = ? AND ref_local_udid = ?');
$stmt->execute([$desktopLocalUdid, $refLocalUdid]);

if ($stmt->rowCount() === 0) jsonError('Cell 不在 Cell 池中', 404);

// 同時移除此 Cell 與所有組件的連結
$db->prepare('DELETE FROM desktop_component_links WHERE ref_local_udid = ? AND component_local_udid IN (SELECT local_udid FROM desktop_components WHERE desktop_local_udid = ?)')->execute([$refLocalUdid, $desktopLocalUdid]);

writeSyncLog($userId, null, 'desktop_cells', '', $desktopLocalUdid, 'update', [
    'desktop_local_udid' => $desktopLocalUdid,
    'ref_local_udid' => $refLocalUdid,
    'action' => 'remove',
]);

jsonSuccess(['desktop_local_udid' => $desktopLocalUdid, 'ref_local_udid' => $refLocalUdid], '已從 Cell 池移除');
