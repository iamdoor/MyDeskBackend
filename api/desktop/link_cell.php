<?php
/**
 * 連結 Cell 到組件（Cell ↔ 組件連結）
 * POST /api/desktop/link_cell.php
 * 必填: link_local_udid, component_local_udid, ref_local_udid
 * 選填: ref_type (cell|datasheet|temp，預設 cell), sort_order
 */
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/sync_helper.php';

requirePost();
$userId = requireAuth();
$data = getPostData();
requireFields($data, ['link_local_udid', 'component_local_udid', 'ref_local_udid']);

$db = getDB();
$linkLocalUdid = $data['link_local_udid'];
$componentLocalUdid = $data['component_local_udid'];
$refLocalUdid = $data['ref_local_udid'];
$refType = in_array($data['ref_type'] ?? '', ['cell', 'datasheet', 'temp']) ? $data['ref_type'] : 'cell';

// 驗證組件歸屬
$stmt = $db->prepare('
    SELECT dc.id, dc.desktop_local_udid FROM desktop_components dc
    INNER JOIN desktops d ON d.local_udid = dc.desktop_local_udid
    WHERE d.user_id = ? AND dc.local_udid = ?
');
$stmt->execute([$userId, $componentLocalUdid]);
$component = $stmt->fetch();
if (!$component) jsonError('組件不存在', 404);

// 驗證 ref 存在於 Cell 池
if ($refType !== 'temp') {
    $stmt = $db->prepare('SELECT id FROM desktop_cells WHERE desktop_local_udid = ? AND ref_local_udid = ?');
    $stmt->execute([$component['desktop_local_udid'], $refLocalUdid]);
    if (!$stmt->fetch()) jsonError('Cell 不在此桌面的 Cell 池中，請先加入 Cell 池');
} else {
    $stmt = $db->prepare('SELECT id FROM desktop_temp_cells WHERE desktop_local_udid = ? AND local_udid = ?');
    $stmt->execute([$component['desktop_local_udid'], $refLocalUdid]);
    if (!$stmt->fetch()) jsonError('暫時 Cell 不存在', 404);
}

// 計算 sort_order
$sortOrder = isset($data['sort_order']) ? (int) $data['sort_order'] : null;
if ($sortOrder === null) {
    $stmt = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM desktop_component_links WHERE component_local_udid = ?');
    $stmt->execute([$componentLocalUdid]);
    $sortOrder = (int) $stmt->fetchColumn();
}

$stmt = $db->prepare('
    INSERT INTO desktop_component_links (local_udid, component_local_udid, ref_type, ref_local_udid, sort_order)
    VALUES (?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order)
');
$stmt->execute([$linkLocalUdid, $componentLocalUdid, $refType, $refLocalUdid, $sortOrder]);

writeSyncLog($userId, null, 'desktop_component_links', '', $linkLocalUdid, 'create', [
    'link_local_udid' => $linkLocalUdid,
    'component_local_udid' => $componentLocalUdid,
    'ref_type' => $refType,
    'ref_local_udid' => $refLocalUdid,
    'sort_order' => $sortOrder,
]);

jsonSuccess([
    'link_local_udid' => $linkLocalUdid,
    'component_local_udid' => $componentLocalUdid,
    'ref_local_udid' => $refLocalUdid,
], '連結成功', 201);
