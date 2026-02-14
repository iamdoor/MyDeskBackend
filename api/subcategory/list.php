<?php
/**
 * 列表子分類
 * GET /api/subcategory/list.php
 * 參數: category_id
 */
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';

requireGet();
$userId = requireAuth();

$categoryId = $_GET['category_id'] ?? '';
if ($categoryId === '') {
    jsonError('缺少 category_id');
}

$db = getDB();

$stmt = $db->prepare('
    SELECT id, server_id, local_udid, name, sort_order, created_at, updated_at
    FROM sub_categories
    WHERE category_id = ? AND user_id = ? AND is_deleted = 0
    ORDER BY sort_order ASC, name ASC
');
$stmt->execute([(int) $categoryId, $userId]);

jsonSuccess(['sub_categories' => $stmt->fetchAll()]);
