<?php
/**
 * 取得單一資料單（含 Cell 列表）
 * GET /api/datasheet/get.php
 * 參數: local_udid
 */
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';

requireGet();
$userId = requireAuth();

$localUdid = $_GET['local_udid'] ?? '';
if ($localUdid === '') {
    jsonError('缺少 local_udid');
}

$db = getDB();

$stmt = $db->prepare('
    SELECT ds.server_id, ds.local_udid, ds.title, ds.description, ds.importance,
           ds.category_id, ds.sub_category_id, ds.is_smart,
           ds.is_deleted, ds.deleted_at, ds.scheduled_delete, ds.scheduled_delete_at,
           ds.ai_edited, ds.ai_edited_at, ds.created_at, ds.updated_at,
           c.name AS category_name, sc.name AS sub_category_name
    FROM data_sheets ds
    LEFT JOIN categories c ON c.id = ds.category_id
    LEFT JOIN sub_categories sc ON sc.id = ds.sub_category_id
    WHERE ds.user_id = ? AND ds.local_udid = ?
');
$stmt->execute([$userId, $localUdid]);
$sheet = $stmt->fetch();

if (!$sheet) {
    jsonError('資料單不存在', 404);
}

// 取得 tags
$stmt = $db->prepare('
    SELECT t.name FROM tags t
    INNER JOIN data_sheet_tags dst ON dst.tag_id = t.id
    WHERE dst.data_sheet_id = (SELECT id FROM data_sheets WHERE user_id = ? AND local_udid = ?)
');
$stmt->execute([$userId, $localUdid]);
$sheet['tags'] = $stmt->fetchAll(PDO::FETCH_COLUMN);

// 取得 Cell 引用列表
$stmt = $db->prepare('
    SELECT dsc.cell_local_udid, dsc.sort_order,
           cl.cell_type, cl.title, cl.description, cl.importance
    FROM data_sheet_cells dsc
    LEFT JOIN cells cl ON cl.user_id = ? AND cl.local_udid = dsc.cell_local_udid
    WHERE dsc.data_sheet_id = (SELECT id FROM data_sheets WHERE user_id = ? AND local_udid = ?)
    ORDER BY dsc.sort_order ASC
');
$stmt->execute([$userId, $userId, $localUdid]);
$sheet['cells'] = $stmt->fetchAll();

// 如果是智慧資料單，取得條件
if ($sheet['is_smart']) {
    $stmt = $db->prepare('
        SELECT id, condition_type, condition_value
        FROM smart_sheet_conditions
        WHERE data_sheet_id = (SELECT id FROM data_sheets WHERE user_id = ? AND local_udid = ?)
    ');
    $stmt->execute([$userId, $localUdid]);
    $sheet['smart_conditions'] = $stmt->fetchAll();
}

jsonSuccess(['data_sheet' => $sheet]);
