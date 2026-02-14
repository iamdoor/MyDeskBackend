<?php
/**
 * 更新分類
 * POST /api/category/update.php
 * 參數: local_udid
 * 選填: name, sort_order
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

$stmt = $db->prepare('SELECT id, server_id FROM categories WHERE user_id = ? AND local_udid = ? AND is_deleted = 0');
$stmt->execute([$userId, $data['local_udid']]);
$cat = $stmt->fetch();

if (!$cat) {
    jsonError('分類不存在', 404);
}

$updates = [];
$params = [];

if (array_key_exists('name', $data)) {
    $updates[] = 'name = ?';
    $params[] = $data['name'];
}
if (array_key_exists('sort_order', $data)) {
    $updates[] = 'sort_order = ?';
    $params[] = (int) $data['sort_order'];
}

if (empty($updates)) {
    jsonError('未提供更新欄位');
}

$params[] = $cat['id'];
$db->prepare('UPDATE categories SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($params);

writeSyncLog($userId, null, 'category', $cat['server_id'], $data['local_udid'], 'update', $data);

jsonSuccess([], '更新成功');
