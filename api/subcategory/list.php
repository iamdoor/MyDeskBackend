<?php
/**
 * 列表子分類
 * GET /api/subcategory/list.php
 * 參數: category_id
 */
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/category_helper.php';

requireGet();
$userId = requireAuth();

$categoryIdParam = $_GET['category_id'] ?? '';
if ($categoryIdParam === '') {
    jsonError('缺少 category_id');
}

$db = getDB();
$category = findCategory($db, $userId, $categoryIdParam);
if (!$category) {
    jsonError('分類不存在', 404);
}

$stmt = $db->prepare('
    SELECT id, server_id, local_udid, name, sort_order, created_at, updated_at
    FROM sub_categories
    WHERE category_id = ? AND user_id = ? AND is_deleted = 0
    ORDER BY sort_order ASC, name ASC
');
$stmt->execute([$category['local_udid'], $userId]);

jsonSuccess(['sub_categories' => $stmt->fetchAll()]);
