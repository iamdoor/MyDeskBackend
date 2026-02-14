<?php
/**
 * 建立資料單
 * POST /api/datasheet/create.php
 * 參數: local_udid, title
 * 選填: description, importance, category_id, sub_category_id, is_smart, tags (JSON array),
 *       scheduled_delete, scheduled_delete_at
 */
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/sync_helper.php';

requirePost();
$userId = requireAuth();
$data = getPostData();
requireFields($data, ['local_udid', 'title']);

$db = getDB();
$serverId = generateUUID();
$localUdid = $data['local_udid'];

// 檢查 local_udid 是否重複
$stmt = $db->prepare('SELECT id FROM data_sheets WHERE user_id = ? AND local_udid = ?');
$stmt->execute([$userId, $localUdid]);
if ($stmt->fetch()) {
    jsonError('local_udid 已存在');
}

// 驗證分類歸屬
$categoryId = null;
$subCategoryId = null;

if (!empty($data['category_id'])) {
    $stmt = $db->prepare('SELECT id FROM categories WHERE id = ? AND user_id = ? AND type = "datasheet" AND is_deleted = 0');
    $stmt->execute([(int) $data['category_id'], $userId]);
    if (!$stmt->fetch()) {
        jsonError('分類不存在');
    }
    $categoryId = (int) $data['category_id'];
}

if (!empty($data['sub_category_id'])) {
    $stmt = $db->prepare('SELECT id, category_id FROM sub_categories WHERE id = ? AND user_id = ? AND is_deleted = 0');
    $stmt->execute([(int) $data['sub_category_id'], $userId]);
    $sub = $stmt->fetch();
    if (!$sub) {
        jsonError('子分類不存在');
    }
    if ($categoryId && (int) $sub['category_id'] !== $categoryId) {
        jsonError('子分類不屬於指定的大分類');
    }
    $subCategoryId = (int) $data['sub_category_id'];
    if (!$categoryId) {
        $categoryId = (int) $sub['category_id'];
    }
}

$stmt = $db->prepare('
    INSERT INTO data_sheets (server_id, local_udid, user_id, title, description, importance,
                             category_id, sub_category_id, is_smart, scheduled_delete, scheduled_delete_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
');
$stmt->execute([
    $serverId,
    $localUdid,
    $userId,
    $data['title'],
    $data['description'] ?? null,
    (int) ($data['importance'] ?? 0),
    $categoryId,
    $subCategoryId,
    (int) ($data['is_smart'] ?? 0),
    (int) ($data['scheduled_delete'] ?? 0),
    $data['scheduled_delete_at'] ?? null,
]);

$sheetId = (int) $db->lastInsertId();

// 處理 tags
if (!empty($data['tags'])) {
    $tags = is_string($data['tags']) ? json_decode($data['tags'], true) : $data['tags'];
    if (is_array($tags)) {
        foreach ($tags as $tagName) {
            $tagName = trim($tagName);
            if ($tagName === '') continue;

            $stmt = $db->prepare('SELECT id FROM tags WHERE user_id = ? AND name = ?');
            $stmt->execute([$userId, $tagName]);
            $tag = $stmt->fetch();

            if ($tag) {
                $tagId = (int) $tag['id'];
            } else {
                $stmt = $db->prepare('INSERT INTO tags (user_id, name) VALUES (?, ?)');
                $stmt->execute([$userId, $tagName]);
                $tagId = (int) $db->lastInsertId();
            }

            $db->prepare('INSERT IGNORE INTO data_sheet_tags (data_sheet_id, tag_id) VALUES (?, ?)')->execute([$sheetId, $tagId]);
        }
    }
}

writeSyncLog($userId, null, 'datasheet', $serverId, $localUdid, 'create', [
    'server_id' => $serverId,
    'local_udid' => $localUdid,
    'title' => $data['title'],
]);

jsonSuccess([
    'data_sheet_id' => $sheetId,
    'server_id' => $serverId,
    'local_udid' => $localUdid,
], '建立成功', 201);
