<?php
/**
 * 刪除 Tag（會移除所有關聯）
 * POST /api/tag/delete.php
 * 參數: local_udid 或 name
 */
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';

requirePost();
$userId = requireAuth();
$data = getPostData();
if (empty($data['local_udid']) && empty($data['name'])) {
    jsonError('請提供 local_udid 或 name');
}

$db = getDB();

if (!empty($data['local_udid'])) {
    $stmt = $db->prepare('SELECT id, local_udid FROM tags WHERE user_id = ? AND local_udid = ?');
    $stmt->execute([$userId, trim($data['local_udid'])]);
} else {
    $stmt = $db->prepare('SELECT id, local_udid FROM tags WHERE user_id = ? AND name = ?');
    $stmt->execute([$userId, trim($data['name'])]);
}
$tag = $stmt->fetch();

if (!$tag) {
    jsonError('Tag 不存在', 404);
}

// 手動移除關聯
$db->prepare('DELETE FROM cell_tags WHERE tag_local_udid = ?')->execute([$tag['local_udid']]);
$db->prepare('DELETE FROM data_sheet_tags WHERE tag_local_udid = ?')->execute([$tag['local_udid']]);
$db->prepare('DELETE FROM desktop_tags WHERE tag_local_udid = ?')->execute([$tag['local_udid']]);

$db->prepare('DELETE FROM tags WHERE id = ?')->execute([$tag['id']]);

jsonSuccess([], '已刪除');
