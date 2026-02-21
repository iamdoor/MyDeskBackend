<?php
/**
 * 刪除桌面（軟刪除）
 * POST /api/desktop/delete.php
 * 必填: local_udid
 */
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/sync_helper.php';

requirePost();
$userId = requireAuth();
$data = getPostData();
requireFields($data, ['local_udid']);

$db = getDB();
$localUdid = $data['local_udid'];

$stmt = $db->prepare('SELECT id, server_id FROM desktops WHERE user_id = ? AND local_udid = ? AND is_deleted = 0');
$stmt->execute([$userId, $localUdid]);
$desktop = $stmt->fetch();
if (!$desktop) jsonError('桌面不存在', 404);

$db->prepare('UPDATE desktops SET is_deleted = 1, deleted_at = NOW() WHERE id = ?')->execute([$desktop['id']]);

writeSyncLog($userId, null, 'desktop', $desktop['server_id'], $localUdid, 'delete', ['local_udid' => $localUdid]);

jsonSuccess(['local_udid' => $localUdid], '刪除成功');
