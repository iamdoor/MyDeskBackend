<?php
/**
 * 建立 Tag
 * POST /api/tag/create.php
 * 參數: name
 */
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';

requirePost();
$userId = requireAuth();
$data = getPostData();
requireFields($data, ['name']);

$name = trim($data['name']);
if ($name === '') {
    jsonError('Tag 名稱不能為空');
}

$db = getDB();

$stmt = $db->prepare('SELECT id FROM tags WHERE user_id = ? AND name = ?');
$stmt->execute([$userId, $name]);
if ($stmt->fetch()) {
    jsonError('Tag 已存在');
}

$stmt = $db->prepare('INSERT INTO tags (user_id, name) VALUES (?, ?)');
$stmt->execute([$userId, $name]);

jsonSuccess(['tag_id' => (int) $db->lastInsertId(), 'name' => $name], '建立成功', 201);
