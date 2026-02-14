<?php
/**
 * 列表分類（含子分類）
 * GET /api/category/list.php
 * 參數: type (datasheet/desktop)
 */
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';

requireGet();
$userId = requireAuth();

$type = $_GET['type'] ?? '';
if (!in_array($type, ['datasheet', 'desktop'])) {
    jsonError('type 必須為 datasheet 或 desktop');
}

$db = getDB();

$stmt = $db->prepare('
    SELECT id, server_id, local_udid, name, sort_order, created_at, updated_at
    FROM categories
    WHERE user_id = ? AND type = ? AND is_deleted = 0
    ORDER BY sort_order ASC, name ASC
');
$stmt->execute([$userId, $type]);
$categories = $stmt->fetchAll();

// 取得子分類
foreach ($categories as &$cat) {
    $stmt = $db->prepare('
        SELECT id, server_id, local_udid, name, sort_order, created_at, updated_at
        FROM sub_categories
        WHERE category_id = ? AND is_deleted = 0
        ORDER BY sort_order ASC, name ASC
    ');
    $stmt->execute([$cat['id']]);
    $cat['sub_categories'] = $stmt->fetchAll();
}

jsonSuccess(['categories' => $categories]);
