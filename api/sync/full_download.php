<?php
/**
 * 全量同步下載（新裝置首次）
 * POST /api/sync/full_download.php
 * 參數: device_udid
 *
 * 回傳: 該使用者所有未刪除的資料
 */
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';

requirePost();
$userId = requireAuth();
$data = getPostData();
requireFields($data, ['device_udid']);

$db = getDB();

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

// === 分類 ===
$stmt = $db->prepare('SELECT server_id, local_udid, type, name, sort_order, is_deleted, created_at, updated_at FROM categories WHERE user_id = ?');
$stmt->execute([$userId]);
$categories = $stmt->fetchAll();

$stmt = $db->prepare('SELECT sc.server_id, sc.local_udid, sc.category_id, sc.name, sc.sort_order, sc.is_deleted, sc.created_at, sc.updated_at, c.local_udid AS category_local_udid FROM sub_categories sc INNER JOIN categories c ON c.id = sc.category_id WHERE sc.user_id = ?');
$stmt->execute([$userId]);
$subCategories = $stmt->fetchAll();

// === Tags ===
$stmt = $db->prepare('SELECT id, name, created_at FROM tags WHERE user_id = ?');
$stmt->execute([$userId]);
$tags = $stmt->fetchAll();

// === Cells ===
$stmt = $db->prepare('SELECT server_id, local_udid, cell_type, title, description, importance, content_json, is_deleted, deleted_at, scheduled_delete, scheduled_delete_at, ai_edited, ai_edited_at, created_at, updated_at FROM cells WHERE user_id = ?');
$stmt->execute([$userId]);
$cells = $stmt->fetchAll();

foreach ($cells as &$cell) {
    if ($cell['content_json']) {
        $cell['content_json'] = json_decode($cell['content_json'], true);
    }
    // 取 tags
    $tagStmt = $db->prepare('SELECT t.name FROM tags t INNER JOIN cell_tags ct ON ct.tag_id = t.id INNER JOIN cells c ON c.id = ct.cell_id WHERE c.user_id = ? AND c.local_udid = ?');
    $tagStmt->execute([$userId, $cell['local_udid']]);
    $cell['tags'] = $tagStmt->fetchAll(PDO::FETCH_COLUMN);
}

// === 資料單 ===
$stmt = $db->prepare('SELECT server_id, local_udid, title, description, importance, category_id, sub_category_id, is_smart, is_deleted, deleted_at, scheduled_delete, scheduled_delete_at, ai_edited, ai_edited_at, created_at, updated_at FROM data_sheets WHERE user_id = ?');
$stmt->execute([$userId]);
$dataSheets = $stmt->fetchAll();

foreach ($dataSheets as &$sheet) {
    // Cell 引用
    $stmt2 = $db->prepare('SELECT cell_local_udid, sort_order FROM data_sheet_cells WHERE data_sheet_id = (SELECT id FROM data_sheets WHERE user_id = ? AND local_udid = ?) ORDER BY sort_order');
    $stmt2->execute([$userId, $sheet['local_udid']]);
    $sheet['cells'] = $stmt2->fetchAll();

    // Tags
    $tagStmt = $db->prepare('SELECT t.name FROM tags t INNER JOIN data_sheet_tags dst ON dst.tag_id = t.id WHERE dst.data_sheet_id = (SELECT id FROM data_sheets WHERE user_id = ? AND local_udid = ?)');
    $tagStmt->execute([$userId, $sheet['local_udid']]);
    $sheet['tags'] = $tagStmt->fetchAll(PDO::FETCH_COLUMN);

    // 智慧條件
    if ($sheet['is_smart']) {
        $condStmt = $db->prepare('SELECT condition_type, condition_value FROM smart_sheet_conditions WHERE data_sheet_id = (SELECT id FROM data_sheets WHERE user_id = ? AND local_udid = ?)');
        $condStmt->execute([$userId, $sheet['local_udid']]);
        $sheet['smart_conditions'] = $condStmt->fetchAll();
    }
}

// === 桌面 ===
$stmt = $db->prepare('SELECT server_id, local_udid, title, description, importance, category_id, sub_category_id, ui_type, is_favorite, is_deleted, deleted_at, scheduled_delete, scheduled_delete_at, ai_edited, ai_edited_at, created_at, updated_at FROM desktops WHERE user_id = ?');
$stmt->execute([$userId]);
$desktops = $stmt->fetchAll();

foreach ($desktops as &$desktop) {
    // 組件
    $compStmt = $db->prepare('SELECT server_id, local_udid, component_type, ref_type, ref_local_udid, config_json, sort_order, created_at, updated_at FROM desktop_components WHERE desktop_id = (SELECT id FROM desktops WHERE user_id = ? AND local_udid = ?) ORDER BY sort_order');
    $compStmt->execute([$userId, $desktop['local_udid']]);
    $desktop['components'] = $compStmt->fetchAll();

    foreach ($desktop['components'] as &$comp) {
        if ($comp['config_json']) {
            $comp['config_json'] = json_decode($comp['config_json'], true);
        }
    }

    // 臨時 Cell
    $tempStmt = $db->prepare('SELECT server_id, local_udid, component_id, cell_type, title, description, content_json, promoted_to_cell_udid, created_at, updated_at FROM desktop_temp_cells WHERE desktop_id = (SELECT id FROM desktops WHERE user_id = ? AND local_udid = ?)');
    $tempStmt->execute([$userId, $desktop['local_udid']]);
    $desktop['temp_cells'] = $tempStmt->fetchAll();

    foreach ($desktop['temp_cells'] as &$tc) {
        if ($tc['content_json']) {
            $tc['content_json'] = json_decode($tc['content_json'], true);
        }
    }

    // Tags
    $tagStmt = $db->prepare('SELECT t.name FROM tags t INNER JOIN desktop_tags dt ON dt.tag_id = t.id WHERE dt.desktop_id = (SELECT id FROM desktops WHERE user_id = ? AND local_udid = ?)');
    $tagStmt->execute([$userId, $desktop['local_udid']]);
    $desktop['tags'] = $tagStmt->fetchAll(PDO::FETCH_COLUMN);
}

// === AI 對話 ===
$stmt = $db->prepare('SELECT server_id, local_udid, context_type, context_local_udid, created_at, updated_at FROM ai_conversations WHERE user_id = ?');
$stmt->execute([$userId]);
$aiConversations = $stmt->fetchAll();

foreach ($aiConversations as &$conv) {
    $msgStmt = $db->prepare('SELECT server_id, local_udid, role, content, referenced_udids, sort_order, created_at FROM ai_messages WHERE conversation_id = (SELECT id FROM ai_conversations WHERE user_id = ? AND local_udid = ?) ORDER BY sort_order');
    $msgStmt->execute([$userId, $conv['local_udid']]);
    $conv['messages'] = $msgStmt->fetchAll();

    foreach ($conv['messages'] as &$msg) {
        if ($msg['referenced_udids']) {
            $msg['referenced_udids'] = json_decode($msg['referenced_udids'], true);
        }
    }
}

// 取得伺服器當前時間（查詢前已取會更準，但 full_download 資料量大，用查詢後的時間也可接受）
$serverNow = $db->query("SELECT NOW()")->fetchColumn();

// 更新裝置同步資訊
$db->prepare('UPDATE devices SET last_sync_at = ? WHERE id = ?')->execute([$serverNow, $deviceId]);

jsonSuccess([
    'categories' => $categories,
    'sub_categories' => $subCategories,
    'tags' => $tags,
    'cells' => $cells,
    'data_sheets' => $dataSheets,
    'desktops' => $desktops,
    'ai_conversations' => $aiConversations,
    'server_now' => $serverNow,
]);
