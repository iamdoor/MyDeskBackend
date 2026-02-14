<?php
/**
 * 刪除 Cell（軟刪除）
 * POST /api/cell/delete.php
 * 參數: local_udid
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

$stmt = $db->prepare('SELECT id, server_id, is_deleted FROM cells WHERE user_id = ? AND local_udid = ?');
$stmt->execute([$userId, $data['local_udid']]);
$cell = $stmt->fetch();

if (!$cell) {
    jsonError('Cell 不存在', 404);
}

if ($cell['is_deleted']) {
    jsonError('Cell 已被刪除');
}

$stmt = $db->prepare('UPDATE cells SET is_deleted = 1, deleted_at = NOW() WHERE id = ?');
$stmt->execute([$cell['id']]);

// 寫入同步日誌
writeSyncLog($userId, null, 'cell', $cell['server_id'], $data['local_udid'], 'delete', [
    'local_udid' => $data['local_udid'],
]);

jsonSuccess([], '已標記刪除');
