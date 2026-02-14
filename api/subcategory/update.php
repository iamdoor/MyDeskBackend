<?php
/**
 * 更新子分類
 * POST /api/subcategory/update.php
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

$stmt = $db->prepare('SELECT id, server_id FROM sub_categories WHERE user_id = ? AND local_udid = ? AND is_deleted = 0');
$stmt->execute([$userId, $data['local_udid']]);
$sub = $stmt->fetch();

if (!$sub) {
    jsonError('子分類不存在', 404);
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

$params[] = $sub['id'];
$db->prepare('UPDATE sub_categories SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($params);

writeSyncLog($userId, null, 'sub_category', $sub['server_id'], $data['local_udid'], 'update', $data);

jsonSuccess([], '更新成功');
