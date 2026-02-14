<?php
/**
 * 建立分類
 * POST /api/category/create.php
 * 參數: local_udid, name, type (datasheet/desktop)
 * 選填: sort_order
 */
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/sync_helper.php';

requirePost();
$userId = requireAuth();
$data = getPostData();
requireFields($data, ['local_udid', 'name', 'type']);

if (!in_array($data['type'], ['datasheet', 'desktop'])) {
    jsonError('type 必須為 datasheet 或 desktop');
}

$db = getDB();
$serverId = generateUUID();

$stmt = $db->prepare('SELECT id FROM categories WHERE user_id = ? AND local_udid = ?');
$stmt->execute([$userId, $data['local_udid']]);
if ($stmt->fetch()) {
    jsonError('local_udid 已存在');
}

$stmt = $db->prepare('
    INSERT INTO categories (server_id, local_udid, user_id, type, name, sort_order)
    VALUES (?, ?, ?, ?, ?, ?)
');
$stmt->execute([
    $serverId,
    $data['local_udid'],
    $userId,
    $data['type'],
    $data['name'],
    (int) ($data['sort_order'] ?? 0),
]);

$id = (int) $db->lastInsertId();

writeSyncLog($userId, null, 'category', $serverId, $data['local_udid'], 'create', [
    'server_id' => $serverId,
    'local_udid' => $data['local_udid'],
    'type' => $data['type'],
    'name' => $data['name'],
]);

jsonSuccess([
    'category_id' => $id,
    'server_id' => $serverId,
], '建立成功', 201);
