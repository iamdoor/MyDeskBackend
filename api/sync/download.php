<?php
/**
 * 同步下載（增量）
 * POST /api/sync/download.php
 * 參數: device_udid, last_sync_at (server timestamp string, e.g. "2024-01-01 12:00:00")
 *
 * 回傳: 所有 updated_at > last_sync_at 的資料（直接查各資料表）
 *       + server_now（伺服器當前時間，供下次同步用）
 *
 * 相容：若 client 傳 last_sync_version 但沒傳 last_sync_at，
 *       視為首次改版，回傳全量（等同 full_download）。
 */
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';

requirePost();
$userId = requireAuth();
$data = getPostData();
requireFields($data, ['device_udid']);

$db = getDB();

// 先取 server 時間（查詢前取，確保不會漏掉查詢期間的變更）
$serverNow = $db->query("SELECT NOW()")->fetchColumn();

// 驗證裝置（不存在則自動註冊）
$stmt = $db->prepare('SELECT id FROM devices WHERE user_id = ? AND device_udid = ?');
$stmt->execute([$userId, $data['device_udid']]);
$device = $stmt->fetch();

if (!$device) {
    $platform = $data['platform'] ?? 'ios';
    $stmt = $db->prepare('INSERT INTO devices (user_id, device_udid, device_name, platform) VALUES (?, ?, ?, ?)');
    $stmt->execute([$userId, $data['device_udid'], $data['device_name'] ?? '', $platform]);
    $deviceId = (int) $db->lastInsertId();
} else {
    $deviceId = (int) $device['id'];
}

$lastSyncAt = $data['last_sync_at'] ?? null;

if (!$lastSyncAt) {
    // 舊版 client 或首次改版 → 回傳全量
    jsonError('請使用 full_download.php 進行首次同步', 400);
}

// === 增量查詢：updated_at > last_sync_at ===

// Categories
$stmt = $db->prepare('SELECT server_id, local_udid, type, name, sort_order, is_deleted, created_at, updated_at FROM categories WHERE user_id = ? AND updated_at > ?');
$stmt->execute([$userId, $lastSyncAt]);
$categories = $stmt->fetchAll();

// Sub-categories
$stmt = $db->prepare('SELECT sc.server_id, sc.local_udid, sc.category_id, sc.name, sc.sort_order, sc.is_deleted, sc.created_at, sc.updated_at, c.local_udid AS category_local_udid FROM sub_categories sc INNER JOIN categories c ON c.local_udid = sc.category_id WHERE sc.user_id = ? AND sc.updated_at > ?');
$stmt->execute([$userId, $lastSyncAt]);
$subCategories = $stmt->fetchAll();

// Tags
$stmt = $db->prepare('SELECT id, local_udid, name, created_at FROM tags WHERE user_id = ? AND created_at > ?');
$stmt->execute([$userId, $lastSyncAt]);
$tags = $stmt->fetchAll();

// Cells
$stmt = $db->prepare('SELECT server_id, local_udid, cell_type, title, description, importance, content_json, desktop_origin, is_deleted, deleted_at, scheduled_delete, scheduled_delete_at, ai_edited, ai_edited_at, created_at, updated_at FROM cells WHERE user_id = ? AND updated_at > ?');
$stmt->execute([$userId, $lastSyncAt]);
$cells = $stmt->fetchAll();

foreach ($cells as &$cell) {
    if ($cell['content_json']) {
        $cell['content_json'] = json_decode($cell['content_json'], true);
    }
    $tagStmt = $db->prepare('SELECT t.name FROM tags t INNER JOIN cell_tags ct ON ct.tag_local_udid = t.local_udid INNER JOIN cells c ON c.id = ct.cell_id WHERE c.user_id = ? AND c.local_udid = ?');
    $tagStmt->execute([$userId, $cell['local_udid']]);
    $cell['tags'] = $tagStmt->fetchAll(PDO::FETCH_COLUMN);
}

// DataSheets
$stmt = $db->prepare('SELECT server_id, local_udid, title, description, importance, category_id, sub_category_id, is_smart, is_deleted, deleted_at, scheduled_delete, scheduled_delete_at, ai_edited, ai_edited_at, created_at, updated_at FROM data_sheets WHERE user_id = ? AND updated_at > ?');
$stmt->execute([$userId, $lastSyncAt]);
$dataSheets = $stmt->fetchAll();

foreach ($dataSheets as &$sheet) {
    // Cell 引用
    $stmt2 = $db->prepare('SELECT cell_local_udid, sort_order FROM data_sheet_cells WHERE data_sheet_local_udid = ? ORDER BY sort_order');
    $stmt2->execute([$sheet['local_udid']]);
    $sheet['cells'] = $stmt2->fetchAll();

    // Tags
    $tagStmt = $db->prepare('SELECT t.name FROM tags t INNER JOIN data_sheet_tags dst ON dst.tag_local_udid = t.local_udid WHERE dst.data_sheet_id = (SELECT id FROM data_sheets WHERE user_id = ? AND local_udid = ?)');
    $tagStmt->execute([$userId, $sheet['local_udid']]);
    $sheet['tags'] = $tagStmt->fetchAll(PDO::FETCH_COLUMN);

    // 智慧條件
    if ($sheet['is_smart']) {
        $condStmt = $db->prepare('SELECT condition_type, condition_value FROM smart_sheet_conditions WHERE data_sheet_local_udid = ?');
        $condStmt->execute([$sheet['local_udid']]);
        $sheet['smart_conditions'] = $condStmt->fetchAll();
    }
}

// Desktops
$stmt = $db->prepare('
    SELECT server_id, local_udid, title, description, importance,
           category_id, sub_category_id, desktop_type_code, mixed_vertical_columns,
           color_scheme_id, custom_bg_color, custom_primary_color, custom_secondary_color,
           custom_accent_color, custom_text_color, is_favorite,
           is_deleted, deleted_at, scheduled_delete, scheduled_delete_at,
           ai_edited, ai_edited_at, created_at, updated_at
    FROM desktops WHERE user_id = ? AND updated_at > ?
');
$stmt->execute([$userId, $lastSyncAt]);
$desktops = $stmt->fetchAll();

$desktopCells = [];
$desktopComponents = [];
$desktopComponentLinks = [];

foreach ($desktops as &$desktop) {
    $tagStmt = $db->prepare('SELECT t.name FROM tags t INNER JOIN desktop_tags dt ON dt.tag_local_udid = t.local_udid WHERE dt.desktop_local_udid = ? AND t.user_id = ?');
    $tagStmt->execute([$desktop['local_udid'], $userId]);
    $desktop['tags'] = $tagStmt->fetchAll(PDO::FETCH_COLUMN);

    // Cell 池
    $stmt2 = $db->prepare('SELECT desktop_local_udid, ref_type, ref_local_udid, created_at FROM desktop_cells WHERE desktop_local_udid = ?');
    $stmt2->execute([$desktop['local_udid']]);
    $desktop['cells'] = $stmt2->fetchAll();
    foreach ($desktop['cells'] as $cellRef) {
        $desktopCells[] = $cellRef;
    }

    // 組件
    $stmt2 = $db->prepare('SELECT desktop_local_udid, server_id, local_udid, component_type_code, bg_color, border_color, border_width, corner_radius, config_json, created_at, updated_at FROM desktop_components WHERE desktop_local_udid = ?');
    $stmt2->execute([$desktop['local_udid']]);
    $components = $stmt2->fetchAll();
    foreach ($components as &$comp) {
        $desktopComponents[] = $comp;
        if (is_string($comp['config_json'])) {
            $decoded = json_decode($comp['config_json'], true);
            if ($decoded !== null) $comp['config_json'] = $decoded;
        }
        $linkStmt = $db->prepare('SELECT local_udid, component_local_udid, ref_type, ref_local_udid, sort_order, created_at, updated_at FROM desktop_component_links WHERE component_local_udid = ? ORDER BY sort_order');
        $linkStmt->execute([$comp['local_udid']]);
        $comp['links'] = $linkStmt->fetchAll();
        foreach ($comp['links'] as $link) {
            $desktopComponentLinks[] = $link;
        }
    }
    unset($comp);
    $desktop['components'] = $components;
}
unset($desktop);

// 更新裝置同步時間
$db->prepare('UPDATE devices SET last_sync_at = ? WHERE id = ?')->execute([$serverNow, $deviceId]);

$totalCount = count($categories)
    + count($subCategories)
    + count($tags)
    + count($cells)
    + count($dataSheets)
    + count($desktops)
    + count($desktopComponents)
    + count($desktopCells)
    + count($desktopComponentLinks);

jsonSuccess([
    'categories' => $categories,
    'sub_categories' => $subCategories,
    'tags' => $tags,
    'cells' => $cells,
    'data_sheets' => $dataSheets,
    'desktops' => $desktops,
    'desktop_cells' => $desktopCells,
    'desktop_components' => $desktopComponents,
    'desktop_component_links' => $desktopComponentLinks,
    'server_now' => $serverNow,
    'count' => $totalCount,
]);
