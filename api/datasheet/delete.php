<?php
/**
 * 刪除資料單（軟刪除）
 * POST /api/datasheet/delete.php
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

$stmt = $db->prepare('SELECT id, server_id, is_deleted FROM data_sheets WHERE user_id = ? AND local_udid = ?');
$stmt->execute([$userId, $data['local_udid']]);
$sheet = $stmt->fetch();

if (!$sheet) {
    jsonError('資料單不存在', 404);
}

if ($sheet['is_deleted']) {
    jsonError('資料單已被刪除');
}

$stmt = $db->prepare('UPDATE data_sheets SET is_deleted = 1, deleted_at = NOW() WHERE id = ?');
$stmt->execute([$sheet['id']]);

writeSyncLog($userId, null, 'datasheet', $sheet['server_id'], $data['local_udid'], 'delete', [
    'local_udid' => $data['local_udid'],
]);

jsonSuccess([], '已標記刪除');
