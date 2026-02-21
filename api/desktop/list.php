<?php
/**
 * 取得桌面列表
 * GET /api/desktop/list.php
 * 選填: category_id, is_favorite (1/0), include_deleted (1/0)
 */
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';

requireGet();
$userId = requireAuth();
$db = getDB();

$where = ['d.user_id = ?'];
$params = [$userId];

if (!isset($_GET['include_deleted']) || !$_GET['include_deleted']) {
    $where[] = 'd.is_deleted = 0';
}
if (isset($_GET['category_id']) && $_GET['category_id'] !== '') {
    $where[] = 'd.category_id = ?';
    $params[] = $_GET['category_id'];
}
if (isset($_GET['is_favorite'])) {
    $where[] = 'd.is_favorite = ?';
    $params[] = (int) $_GET['is_favorite'];
}

$stmt = $db->prepare('
    SELECT d.server_id, d.local_udid, d.title, d.description, d.importance,
           d.category_id, d.sub_category_id, d.desktop_type_code, d.mixed_vertical_columns,
           d.color_scheme_id, d.custom_bg_color, d.custom_primary_color, d.custom_secondary_color,
           d.custom_accent_color, d.custom_text_color, d.is_favorite,
           d.is_deleted, d.deleted_at, d.scheduled_delete, d.scheduled_delete_at,
           d.ai_edited, d.ai_edited_at, d.created_at, d.updated_at,
           c.name AS category_name, sc.name AS sub_category_name
    FROM desktops d
    LEFT JOIN categories c ON c.local_udid = d.category_id AND c.user_id = d.user_id
    LEFT JOIN sub_categories sc ON sc.local_udid = d.sub_category_id AND sc.user_id = d.user_id
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY d.updated_at DESC
');
$stmt->execute($params);
$desktops = $stmt->fetchAll();

foreach ($desktops as &$desktop) {
    $tagStmt = $db->prepare('
        SELECT t.name FROM tags t
        INNER JOIN desktop_tags dt ON dt.tag_local_udid = t.local_udid
        WHERE dt.desktop_local_udid = ? AND t.user_id = ?
    ');
    $tagStmt->execute([$desktop['local_udid'], $userId]);
    $desktop['tags'] = $tagStmt->fetchAll(PDO::FETCH_COLUMN);
}
unset($desktop);

jsonSuccess(['desktops' => $desktops, 'count' => count($desktops)]);
