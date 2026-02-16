<?php
/**
 * 刪除分類（軟刪除，子分類一併刪除）
 * POST /api/category/delete.php
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

$stmt = $db->prepare('SELECT id, server_id FROM categories WHERE user_id = ? AND local_udid = ? AND is_deleted = 0');
$stmt->execute([$userId, $data['local_udid']]);
$cat = $stmt->fetch();

if (!$cat) {
    jsonError('分類不存在', 404);
}

// 軟刪除分類
$db->prepare('UPDATE categories SET is_deleted = 1, deleted_at = NOW() WHERE id = ?')->execute([$cat['id']]);

// 軟刪除子分類
$db->prepare('UPDATE sub_categories SET is_deleted = 1, deleted_at = NOW() WHERE category_id = ? AND is_deleted = 0')->execute([$data['local_udid']]);

// 清除引用此分類的資料單/桌面的分類欄位
$db->prepare('UPDATE data_sheets SET category_id = NULL, sub_category_id = NULL WHERE category_id = ?')->execute([$data['local_udid']]);
$db->prepare('UPDATE desktops SET category_id = NULL, sub_category_id = NULL WHERE category_id = ?')->execute([$data['local_udid']]);

writeSyncLog($userId, null, 'category', $cat['server_id'], $data['local_udid'], 'delete', [
    'local_udid' => $data['local_udid'],
]);

jsonSuccess([], '已刪除');
