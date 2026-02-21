<?php
/**
 * 取得單一桌面（含 Cell 池、組件列表、組件連結）
 * GET /api/desktop/get.php
 * 必填: local_udid
 */
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';

requireGet();
$userId = requireAuth();

$localUdid = $_GET['local_udid'] ?? '';
if ($localUdid === '') jsonError('缺少 local_udid');

$db = getDB();

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
    WHERE d.user_id = ? AND d.local_udid = ?
');
$stmt->execute([$userId, $localUdid]);
$desktop = $stmt->fetch();
if (!$desktop) jsonError('桌面不存在', 404);

// Tags
$stmt = $db->prepare('
    SELECT t.name FROM tags t
    INNER JOIN desktop_tags dt ON dt.tag_local_udid = t.local_udid
    WHERE dt.desktop_local_udid = ? AND t.user_id = ?
');
$stmt->execute([$localUdid, $userId]);
$desktop['tags'] = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Cell 池
$stmt = $db->prepare('
    SELECT id, ref_type, ref_local_udid, created_at
    FROM desktop_cells
    WHERE desktop_local_udid = ?
    ORDER BY created_at ASC
');
$stmt->execute([$localUdid]);
$desktop['cells'] = $stmt->fetchAll();

// 組件列表
$stmt = $db->prepare('
    SELECT server_id, local_udid, component_type_code, bg_color, border_color,
           border_width, corner_radius, config_json, created_at, updated_at
    FROM desktop_components
    WHERE desktop_local_udid = ?
    ORDER BY created_at ASC
');
$stmt->execute([$localUdid]);
$components = $stmt->fetchAll();

foreach ($components as &$comp) {
    if (is_string($comp['config_json'])) {
        $decoded = json_decode($comp['config_json'], true);
        if ($decoded !== null) $comp['config_json'] = $decoded;
    }
    // 每個組件的 Cell 連結
    $linkStmt = $db->prepare('
        SELECT local_udid, ref_type, ref_local_udid, sort_order, created_at, updated_at
        FROM desktop_component_links
        WHERE component_local_udid = ?
        ORDER BY sort_order ASC
    ');
    $linkStmt->execute([$comp['local_udid']]);
    $comp['links'] = $linkStmt->fetchAll();
}
unset($comp);
$desktop['components'] = $components;

// 暫時 Cell
$stmt = $db->prepare('
    SELECT server_id, local_udid, cell_type, title, description, content_json,
           promoted_to_cell_udid, created_at, updated_at
    FROM desktop_temp_cells
    WHERE desktop_local_udid = ?
    ORDER BY created_at ASC
');
$stmt->execute([$localUdid]);
$tempCells = $stmt->fetchAll();
foreach ($tempCells as &$tc) {
    if (is_string($tc['content_json'])) {
        $decoded = json_decode($tc['content_json'], true);
        if ($decoded !== null) $tc['content_json'] = $decoded;
    }
}
unset($tc);
$desktop['temp_cells'] = $tempCells;

jsonSuccess(['desktop' => $desktop]);
