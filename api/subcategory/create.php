<?php
/**
 * 建立子分類
 * POST /api/subcategory/create.php
 * 參數: local_udid, category_id, name
 * 選填: sort_order
 */
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/sync_helper.php';
require_once __DIR__ . '/../../lib/category_helper.php';

requirePost();
$userId = requireAuth();
$data = getPostData();
requireFields($data, ['local_udid', 'category_id', 'name']);

$db = getDB();

// 驗證分類，可接受 ID 或 local_udid
$category = findCategory($db, $userId, $data['category_id']);
if (!$category) {
    jsonError('大分類不存在', 404);
}
$categoryLocalUdid = $category['local_udid'];

$stmt = $db->prepare('SELECT id FROM sub_categories WHERE user_id = ? AND local_udid = ?');
$stmt->execute([$userId, $data['local_udid']]);
if ($stmt->fetch()) {
    jsonError('local_udid 已存在');
}

$serverId = generateUUID();

$stmt = $db->prepare('
    INSERT INTO sub_categories (server_id, local_udid, category_id, user_id, name, sort_order)
    VALUES (?, ?, ?, ?, ?, ?)
');
$stmt->execute([
    $serverId,
    $data['local_udid'],
    $categoryLocalUdid,
    $userId,
    $data['name'],
    (int) ($data['sort_order'] ?? 0),
]);

$id = (int) $db->lastInsertId();

writeSyncLog($userId, null, 'sub_category', $serverId, $data['local_udid'], 'create', [
    'server_id' => $serverId,
    'local_udid' => $data['local_udid'],
    'category_id' => $categoryLocalUdid,
    'name' => $data['name'],
]);

jsonSuccess([
    'sub_category_id' => $id,
    'server_id' => $serverId,
], '建立成功', 201);
