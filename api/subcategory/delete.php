<?php
/**
 * 刪除子分類
 * POST /api/subcategory/delete.php
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

$stmt = $db->prepare('SELECT id, server_id FROM sub_categories WHERE user_id = ? AND local_udid = ? AND is_deleted = 0');
$stmt->execute([$userId, $data['local_udid']]);
$sub = $stmt->fetch();

if (!$sub) {
    jsonError('子分類不存在', 404);
}

$db->prepare('UPDATE sub_categories SET is_deleted = 1, deleted_at = NOW() WHERE id = ?')->execute([$sub['id']]);

// 清除引用此子分類的資料單/桌面
$db->prepare('UPDATE data_sheets SET sub_category_id = NULL WHERE sub_category_id = ?')->execute([$sub['id']]);
$db->prepare('UPDATE desktops SET sub_category_id = NULL WHERE sub_category_id = ?')->execute([$sub['id']]);

writeSyncLog($userId, null, 'sub_category', $sub['server_id'], $data['local_udid'], 'delete', [
    'local_udid' => $data['local_udid'],
]);

jsonSuccess([], '已刪除');
