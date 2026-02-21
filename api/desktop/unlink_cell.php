<?php
/**
 * 取消 Cell 與組件的連結
 * POST /api/desktop/unlink_cell.php
 * 必填: link_local_udid 或 (component_local_udid + ref_local_udid)
 */
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/sync_helper.php';

requirePost();
$userId = requireAuth();
$data = getPostData();

$db = getDB();

if (!empty($data['link_local_udid'])) {
    // 透過 link_local_udid 刪除
    $linkLocalUdid = $data['link_local_udid'];

    // 驗證歸屬
    $stmt = $db->prepare('
        SELECT dcl.id FROM desktop_component_links dcl
        INNER JOIN desktop_components dc ON dc.local_udid = dcl.component_local_udid
        INNER JOIN desktops d ON d.local_udid = dc.desktop_local_udid
        WHERE d.user_id = ? AND dcl.local_udid = ?
    ');
    $stmt->execute([$userId, $linkLocalUdid]);
    if (!$stmt->fetch()) jsonError('連結不存在', 404);

    $db->prepare('DELETE FROM desktop_component_links WHERE local_udid = ?')->execute([$linkLocalUdid]);

    writeSyncLog($userId, null, 'desktop_component_links', '', $linkLocalUdid, 'delete', ['link_local_udid' => $linkLocalUdid]);

    jsonSuccess(['link_local_udid' => $linkLocalUdid], '連結已移除');
}

// 透過 component + ref 刪除
requireFields($data, ['component_local_udid', 'ref_local_udid']);
$componentLocalUdid = $data['component_local_udid'];
$refLocalUdid = $data['ref_local_udid'];

$stmt = $db->prepare('
    SELECT dcl.local_udid FROM desktop_component_links dcl
    INNER JOIN desktop_components dc ON dc.local_udid = dcl.component_local_udid
    INNER JOIN desktops d ON d.local_udid = dc.desktop_local_udid
    WHERE d.user_id = ? AND dcl.component_local_udid = ? AND dcl.ref_local_udid = ?
');
$stmt->execute([$userId, $componentLocalUdid, $refLocalUdid]);
$link = $stmt->fetch();
if (!$link) jsonError('連結不存在', 404);

$db->prepare('DELETE FROM desktop_component_links WHERE component_local_udid = ? AND ref_local_udid = ?')->execute([$componentLocalUdid, $refLocalUdid]);

writeSyncLog($userId, null, 'desktop_component_links', '', $link['local_udid'], 'delete', [
    'component_local_udid' => $componentLocalUdid,
    'ref_local_udid' => $refLocalUdid,
]);

jsonSuccess(['component_local_udid' => $componentLocalUdid, 'ref_local_udid' => $refLocalUdid], '連結已移除');
