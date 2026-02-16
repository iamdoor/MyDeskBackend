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
    LEFT JOIN categories c ON c.local_udid = ds.category_id AND c.user_id = ds.user_id
    LEFT JOIN sub_categories sc ON sc.local_udid = ds.sub_category_id AND sc.user_id = ds.user_id
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
    INNER JOIN data_sheet_tags dst ON dst.tag_local_udid = t.local_udid
    WHERE dst.data_sheet_id = (SELECT id FROM data_sheets WHERE user_id = ? AND local_udid = ?)
');
$stmt->execute([$userId, $localUdid]);
$sheet['tags'] = $stmt->fetchAll(PDO::FETCH_COLUMN);

// 取得 Cell 引用列表（含完整 Cell 資料，供跨裝置同步使用）
$stmt = $db->prepare('
    SELECT dsc.cell_local_udid, dsc.sort_order,
           cl.server_id, cl.cell_type, cl.title, cl.description, cl.importance,
           cl.content_json, cl.is_deleted, cl.deleted_at,
           cl.scheduled_delete, cl.scheduled_delete_at,
           cl.ai_edited, cl.ai_edited_at,
           cl.created_at, cl.updated_at
    FROM data_sheet_cells dsc
    LEFT JOIN cells cl ON cl.user_id = ? AND cl.local_udid = dsc.cell_local_udid
    WHERE dsc.data_sheet_local_udid = ?
    ORDER BY dsc.sort_order ASC
');
$stmt->execute([$userId, $localUdid]);
$cellRows = $stmt->fetchAll();

// 為每個 Cell 附加 tags
$dsId = $db->prepare('SELECT id FROM data_sheets WHERE user_id = ? AND local_udid = ?');
$dsId->execute([$userId, $localUdid]);

foreach ($cellRows as &$cellRow) {
    $cellUdid = $cellRow['cell_local_udid'];
        $tagStmt = $db->prepare('
            SELECT t.name FROM tags t
            INNER JOIN cell_tags ct ON ct.tag_local_udid = t.local_udid
            WHERE ct.cell_id = (SELECT id FROM cells WHERE user_id = ? AND local_udid = ?)
        ');
    $tagStmt->execute([$userId, $cellUdid]);
    $cellRow['tags'] = $tagStmt->fetchAll(PDO::FETCH_COLUMN);

    // content_json 若為字串則解碼為物件
    if (is_string($cellRow['content_json'])) {
        $decoded = json_decode($cellRow['content_json'], true);
        if ($decoded !== null) {
            $cellRow['content_json'] = $decoded;
        }
    }
}
unset($cellRow);
$sheet['cells'] = $cellRows;

// 如果是智慧資料單，取得條件
if ($sheet['is_smart']) {
    $stmt = $db->prepare('
        SELECT id, condition_type, condition_value
        FROM smart_sheet_conditions
        WHERE data_sheet_local_udid = ?
    ');
    $stmt->execute([$localUdid]);
    $sheet['smart_conditions'] = $stmt->fetchAll();
}

jsonSuccess(['data_sheet' => $sheet]);
