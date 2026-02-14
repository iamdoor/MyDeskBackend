<?php
/**
 * 刪除 Tag（會移除所有關聯）
 * POST /api/tag/delete.php
 * 參數: name
 */
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';

requirePost();
$userId = requireAuth();
$data = getPostData();
requireFields($data, ['name']);

$db = getDB();

$stmt = $db->prepare('SELECT id FROM tags WHERE user_id = ? AND name = ?');
$stmt->execute([$userId, trim($data['name'])]);
$tag = $stmt->fetch();

if (!$tag) {
    jsonError('Tag 不存在', 404);
}

// CASCADE 會自動清除 cell_tags, data_sheet_tags, desktop_tags
$db->prepare('DELETE FROM tags WHERE id = ?')->execute([$tag['id']]);

jsonSuccess([], '已刪除');
