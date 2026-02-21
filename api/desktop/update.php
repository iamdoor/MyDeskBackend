<?php
/**
 * 更新桌面
 * POST /api/desktop/update.php
 * 必填: local_udid
 * 選填: title, description, importance, desktop_type_code, mixed_vertical_columns,
 *       color_scheme_id, custom_bg_color, custom_primary_color, custom_secondary_color,
 *       custom_accent_color, custom_text_color, is_favorite,
 *       category_id, sub_category_id, tags,
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
requireFields($data, ['local_udid']);

$db = getDB();
$localUdid = $data['local_udid'];

$stmt = $db->prepare('SELECT id, server_id FROM desktops WHERE user_id = ? AND local_udid = ? AND is_deleted = 0');
$stmt->execute([$userId, $localUdid]);
$desktop = $stmt->fetch();
if (!$desktop) jsonError('桌面不存在', 404);

$allowed = [
    'title', 'description', 'importance', 'desktop_type_code',
    'mixed_vertical_columns', 'color_scheme_id',
    'custom_bg_color', 'custom_primary_color', 'custom_secondary_color',
    'custom_accent_color', 'custom_text_color',
    'is_favorite', 'scheduled_delete', 'scheduled_delete_at',
];
$updates = [];
$params = [];
$logPayload = [];

// 分類處理
$categoryLocalUdid = null;
if (array_key_exists('category_id', $data)) {
    $value = $data['category_id'];
    if ($value === null || $value === '') {
        $updates[] = '`category_id` = NULL';
        $updates[] = '`sub_category_id` = NULL';
    } else {
        $category = findCategory($db, $userId, $value, 'desktop');
        if (!$category) jsonError('分類不存在', 404);
        $categoryLocalUdid = $category['local_udid'];
        $updates[] = '`category_id` = ?';
        $params[] = $categoryLocalUdid;
        $logPayload['category_id'] = $categoryLocalUdid;
    }
}

if (array_key_exists('sub_category_id', $data)) {
    $value = $data['sub_category_id'];
    if ($value === null || $value === '') {
        $updates[] = '`sub_category_id` = NULL';
    } else {
        $sub = findSubCategory($db, $userId, $value);
        if (!$sub) jsonError('子分類不存在', 404);
        $parent = findCategory($db, $userId, $sub['category_id'], 'desktop');
        if (!$parent) jsonError('分類不存在', 404);
        if ($categoryLocalUdid !== null && $parent['local_udid'] !== $categoryLocalUdid) {
            jsonError('子分類不屬於指定的大分類');
        }
        if ($categoryLocalUdid === null) {
            $categoryLocalUdid = $parent['local_udid'];
            $updates[] = '`category_id` = ?';
            $params[] = $categoryLocalUdid;
        }
        $updates[] = '`sub_category_id` = ?';
        $params[] = $sub['local_udid'];
        $logPayload['sub_category_id'] = $sub['local_udid'];
    }
}

foreach ($allowed as $field) {
    if (array_key_exists($field, $data)) {
        $updates[] = "`$field` = ?";
        $params[] = $data[$field];
        $logPayload[$field] = $data[$field];
    }
}

if (!empty($updates)) {
    $params[] = $desktop['id'];
    $db->prepare('UPDATE desktops SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($params);
}

// 更新 tags（先清除再重新寫入）
if (array_key_exists('tags', $data)) {
    $db->prepare('DELETE dt FROM desktop_tags dt WHERE dt.desktop_local_udid = ?')->execute([$localUdid]);
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

writeSyncLog($userId, null, 'desktop', $desktop['server_id'], $localUdid, 'update', $logPayload);

jsonSuccess(['local_udid' => $localUdid], '更新成功');
