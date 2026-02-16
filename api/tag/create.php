<?php
/**
 * 建立 Tag
 * POST /api/tag/create.php
 * 參數: name
 * 選填: local_udid
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

$localUdid = !empty($data['local_udid']) ? trim($data['local_udid']) : generateUUID();
if ($localUdid === '') {
    $localUdid = generateUUID();
}

// 檢查名稱是否重複
$stmt = $db->prepare('SELECT id FROM tags WHERE user_id = ? AND name = ?');
$stmt->execute([$userId, $name]);
if ($stmt->fetch()) {
    jsonError('Tag 已存在');
}

// 檢查 local_udid 是否重複
$stmt = $db->prepare('SELECT id FROM tags WHERE user_id = ? AND local_udid = ?');
$stmt->execute([$userId, $localUdid]);
if ($stmt->fetch()) {
    jsonError('local_udid 已存在');
}

$stmt = $db->prepare('INSERT INTO tags (user_id, local_udid, name) VALUES (?, ?, ?)');
$stmt->execute([$userId, $localUdid, $name]);

jsonSuccess([
    'tag_id' => (int) $db->lastInsertId(),
    'local_udid' => $localUdid,
    'name' => $name
], '建立成功', 201);
