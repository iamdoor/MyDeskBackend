<?php
/**
 * 移除組件
 * POST /api/desktop/remove_component.php
 * 必填: component_local_udid
 */
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/sync_helper.php';

requirePost();
$userId = requireAuth();
$data = getPostData();
requireFields($data, ['component_local_udid']);

$db = getDB();
$componentLocalUdid = $data['component_local_udid'];

$stmt = $db->prepare('
    SELECT dc.id, dc.server_id FROM desktop_components dc
    INNER JOIN desktops d ON d.local_udid = dc.desktop_local_udid
    WHERE d.user_id = ? AND dc.local_udid = ?
');
$stmt->execute([$userId, $componentLocalUdid]);
$component = $stmt->fetch();
if (!$component) jsonError('組件不存在', 404);

// 先移除所有 Cell ↔ 組件連結
$db->prepare('DELETE FROM desktop_component_links WHERE component_local_udid = ?')->execute([$componentLocalUdid]);

// 再移除組件
$db->prepare('DELETE FROM desktop_components WHERE id = ?')->execute([$component['id']]);

writeSyncLog($userId, null, 'desktop_component', $component['server_id'], $componentLocalUdid, 'delete', ['local_udid' => $componentLocalUdid]);

jsonSuccess(['local_udid' => $componentLocalUdid], '組件已移除');
