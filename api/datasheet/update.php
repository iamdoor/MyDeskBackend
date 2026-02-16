<?php
/**
 * 更新資料單
 * POST /api/datasheet/update.php
 * 參數: local_udid
 * 選填: title, description, importance, category_id, sub_category_id, is_smart,
 *       tags (JSON array, 完整替換), scheduled_delete, scheduled_delete_at
 */
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/sync_helper.php';
require_once __DIR__ . '/../../lib/category_helper.php';
require_once __DIR__ . '/../../lib/tag_helper.php';

requirePost();
$userId = requireAuth();
$data = getPostData();
requireFields($data, ['local_udid']);

$db = getDB();

$stmt = $db->prepare('SELECT id, server_id FROM data_sheets WHERE user_id = ? AND local_udid = ? AND is_deleted = 0');
$stmt->execute([$userId, $data['local_udid']]);
$sheet = $stmt->fetch();

if (!$sheet) {
    jsonError('資料單不存在', 404);
}

$updates = [];
$params = [];

$allowedFields = [
    'title' => 'string',
    'description' => 'string',
    'importance' => 'int',
    'is_smart' => 'int',
    'scheduled_delete' => 'int',
    'scheduled_delete_at' => 'string',
];

foreach ($allowedFields as $field => $type) {
    if (array_key_exists($field, $data)) {
        $updates[] = "`$field` = ?";
        $params[] = $type === 'int' ? (int) $data[$field] : $data[$field];
    }
}

// 分類更新
$resolvedCategoryUdid = null;
if (array_key_exists('category_id', $data)) {
    if ($data['category_id'] === null || $data['category_id'] === '') {
        $updates[] = '`category_id` = NULL';
        $updates[] = '`sub_category_id` = NULL';
    } else {
        $category = findCategory($db, $userId, $data['category_id'], 'datasheet');
        if (!$category) {
            jsonError('分類不存在', 404);
        }
        $resolvedCategoryUdid = $category['local_udid'];
        $updates[] = '`category_id` = ?';
        $params[] = $resolvedCategoryUdid;
    }
}

if (array_key_exists('sub_category_id', $data)) {
    if ($data['sub_category_id'] === null || $data['sub_category_id'] === '') {
        $updates[] = '`sub_category_id` = NULL';
    } else {
        $sub = findSubCategory($db, $userId, $data['sub_category_id']);
        if (!$sub) {
            jsonError('子分類不存在', 404);
        }
        $parentCategory = findCategory($db, $userId, $sub['category_id'], 'datasheet');
        if (!$parentCategory) {
            jsonError('分類不存在', 404);
        }
        if ($resolvedCategoryUdid !== null && $parentCategory['local_udid'] !== $resolvedCategoryUdid) {
            jsonError('子分類不屬於指定的大分類');
        }
        if ($resolvedCategoryUdid === null) {
            $resolvedCategoryUdid = $parentCategory['local_udid'];
            $updates[] = '`category_id` = ?';
            $params[] = $resolvedCategoryUdid;
        }
        $updates[] = '`sub_category_id` = ?';
        $params[] = $sub['local_udid'];
    }
}

if (!empty($updates)) {
    $params[] = $sheet['id'];
    $sql = 'UPDATE data_sheets SET ' . implode(', ', $updates) . ' WHERE id = ?';
    $db->prepare($sql)->execute($params);
}

// 處理 tags（完整替換）
if (array_key_exists('tags', $data)) {
    $db->prepare('DELETE FROM data_sheet_tags WHERE data_sheet_id = ?')->execute([$sheet['id']]);

    $tags = is_string($data['tags']) ? json_decode($data['tags'], true) : $data['tags'];
    if (is_array($tags)) {
        foreach ($tags as $tagName) {
            $tagName = trim($tagName);
            if ($tagName === '') continue;

            $tagLocalUdid = ensureTagLocalUdid($db, $userId, $tagName);
            $db->prepare('INSERT IGNORE INTO data_sheet_tags (data_sheet_id, tag_local_udid) VALUES (?, ?)')->execute([$sheet['id'], $tagLocalUdid]);
        }
    }
}

writeSyncLog($userId, null, 'datasheet', $sheet['server_id'], $data['local_udid'], 'update', $data);

jsonSuccess([], '更新成功');
