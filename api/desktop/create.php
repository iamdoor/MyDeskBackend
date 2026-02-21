<?php
/**
 * 建立桌面
 * POST /api/desktop/create.php
 * 必填: local_udid, title
 * 選填: description, importance, desktop_type_code, mixed_vertical_columns,
 *       color_scheme_id, custom_bg_color, custom_primary_color, custom_secondary_color,
 *       custom_accent_color, custom_text_color, is_favorite,
 *       category_id, sub_category_id, tags (JSON array),
 *       scheduled_delete, scheduled_delete_at
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
requireFields($data, ['local_udid', 'title']);

$db = getDB();
$serverId = generateUUID();
$localUdid = $data['local_udid'];

$stmt = $db->prepare('SELECT id FROM desktops WHERE user_id = ? AND local_udid = ?');
$stmt->execute([$userId, $localUdid]);
if ($stmt->fetch()) {
    jsonError('local_udid 已存在');
}

// 驗證分類
$categoryLocalUdid = null;
$subCategoryLocalUdid = null;

if (!empty($data['category_id'])) {
    $category = findCategory($db, $userId, $data['category_id'], 'desktop');
    if (!$category) jsonError('分類不存在', 404);
    $categoryLocalUdid = $category['local_udid'];
}

if (!empty($data['sub_category_id'])) {
    $sub = findSubCategory($db, $userId, $data['sub_category_id']);
    if (!$sub) jsonError('子分類不存在', 404);
    $parentCategory = findCategory($db, $userId, $sub['category_id'], 'desktop');
    if (!$parentCategory) jsonError('分類不存在', 404);
    if ($categoryLocalUdid && $parentCategory['local_udid'] !== $categoryLocalUdid) {
        jsonError('子分類不屬於指定的大分類');
    }
    $categoryLocalUdid = $categoryLocalUdid ?: $parentCategory['local_udid'];
    $subCategoryLocalUdid = $sub['local_udid'];
}

$desktopTypeCode = $data['desktop_type_code'] ?? 'single_column';

$stmt = $db->prepare('
    INSERT INTO desktops (
        server_id, local_udid, user_id, title, description, importance,
        category_id, sub_category_id, desktop_type_code, mixed_vertical_columns,
        color_scheme_id, custom_bg_color, custom_primary_color, custom_secondary_color,
        custom_accent_color, custom_text_color, is_favorite,
        scheduled_delete, scheduled_delete_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
');
$stmt->execute([
    $serverId,
    $localUdid,
    $userId,
    $data['title'],
    $data['description'] ?? null,
    (int) ($data['importance'] ?? 0),
    $categoryLocalUdid,
    $subCategoryLocalUdid,
    $desktopTypeCode,
    isset($data['mixed_vertical_columns']) ? (int) $data['mixed_vertical_columns'] : null,
    isset($data['color_scheme_id']) ? (int) $data['color_scheme_id'] : null,
    $data['custom_bg_color'] ?? null,
    $data['custom_primary_color'] ?? null,
    $data['custom_secondary_color'] ?? null,
    $data['custom_accent_color'] ?? null,
    $data['custom_text_color'] ?? null,
    (int) ($data['is_favorite'] ?? 0),
    (int) ($data['scheduled_delete'] ?? 0),
    $data['scheduled_delete_at'] ?? null,
]);

$desktopId = (int) $db->lastInsertId();

// 處理 tags
if (!empty($data['tags'])) {
    $tags = is_string($data['tags']) ? json_decode($data['tags'], true) : $data['tags'];
    if (is_array($tags)) {
        foreach ($tags as $tagName) {
            $tagName = trim($tagName);
            if ($tagName === '') continue;
            $tagLocalUdid = ensureTagLocalUdid($db, $userId, $tagName);
            $db->prepare('INSERT IGNORE INTO desktop_tags (desktop_local_udid, tag_local_udid) VALUES (?, ?)')->execute([$localUdid, $tagLocalUdid]);
        }
    }
}

writeSyncLog($userId, null, 'desktop', $serverId, $localUdid, 'create', [
    'server_id' => $serverId,
    'local_udid' => $localUdid,
    'title' => $data['title'],
    'desktop_type_code' => $desktopTypeCode,
]);

jsonSuccess([
    'desktop_id' => $desktopId,
    'server_id' => $serverId,
    'local_udid' => $localUdid,
], '建立成功', 201);
