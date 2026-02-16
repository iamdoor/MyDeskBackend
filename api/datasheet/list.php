<?php
/**
 * 列表資料單
 * GET /api/datasheet/list.php
 * 選填: category_id, sub_category_id, is_smart, include_deleted, page, per_page, tag, keyword
 */
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/category_helper.php';

requireGet();
$userId = requireAuth();

$db = getDB();

$where = ['ds.user_id = ?'];
$params = [$userId];

if (isset($_GET['category_id'])) {
    if ($_GET['category_id'] === '' || $_GET['category_id'] === 'null') {
        $where[] = 'ds.category_id IS NULL';
    } else {
        $category = findCategory($db, $userId, $_GET['category_id'], 'datasheet');
        if (!$category) {
            jsonError('分類不存在', 404);
        }
        $where[] = 'ds.category_id = ?';
        $params[] = $category['local_udid'];
    }
}

if (isset($_GET['sub_category_id']) && $_GET['sub_category_id'] !== '') {
    $sub = findSubCategory($db, $userId, $_GET['sub_category_id']);
    if (!$sub) {
        jsonError('子分類不存在', 404);
    }
    $where[] = 'ds.sub_category_id = ?';
    $params[] = $sub['local_udid'];
}

if (isset($_GET['is_smart']) && $_GET['is_smart'] !== '') {
    $where[] = 'ds.is_smart = ?';
    $params[] = (int) $_GET['is_smart'];
}

if (empty($_GET['include_deleted'])) {
    $where[] = 'ds.is_deleted = 0';
}

if (!empty($_GET['tag'])) {
    $where[] = 'EXISTS (SELECT 1 FROM data_sheet_tags dst INNER JOIN tags t ON t.local_udid = dst.tag_local_udid WHERE dst.data_sheet_id = ds.id AND t.name = ?)';
    $params[] = $_GET['tag'];
}

if (!empty($_GET['keyword'])) {
    $keyword = '%' . $_GET['keyword'] . '%';
    $where[] = '(ds.title LIKE ? OR ds.description LIKE ?)';
    $params[] = $keyword;
    $params[] = $keyword;
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 50)));
$offset = ($page - 1) * $perPage;

$whereClause = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) FROM data_sheets ds WHERE $whereClause");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$sql = "
    SELECT ds.server_id, ds.local_udid, ds.title, ds.description, ds.importance,
           ds.category_id, ds.sub_category_id, ds.is_smart,
           ds.is_deleted, ds.deleted_at, ds.scheduled_delete, ds.scheduled_delete_at,
           ds.ai_edited, ds.ai_edited_at, ds.created_at, ds.updated_at,
           c.name AS category_name, sc.name AS sub_category_name
    FROM data_sheets ds
    LEFT JOIN categories c ON c.local_udid = ds.category_id AND c.user_id = ds.user_id
    LEFT JOIN sub_categories sc ON sc.local_udid = ds.sub_category_id AND sc.user_id = ds.user_id
    WHERE $whereClause
    ORDER BY ds.updated_at DESC
    LIMIT $perPage OFFSET $offset
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$sheets = $stmt->fetchAll();

// 批次取 tags
foreach ($sheets as &$sheet) {
    $tagStmt = $db->prepare('
        SELECT t.name FROM tags t
        INNER JOIN data_sheet_tags dst ON dst.tag_local_udid = t.local_udid
        WHERE dst.data_sheet_id = (SELECT id FROM data_sheets WHERE user_id = ? AND local_udid = ?)
    ');
    $tagStmt->execute([$userId, $sheet['local_udid']]);
    $sheet['tags'] = $tagStmt->fetchAll(PDO::FETCH_COLUMN);

    // Cell 數量
    $cellCountStmt = $db->prepare('
        SELECT COUNT(*) FROM data_sheet_cells
        WHERE data_sheet_local_udid = ?
    ');
    $cellCountStmt->execute([$sheet['local_udid']]);
    $sheet['cell_count'] = (int) $cellCountStmt->fetchColumn();
}

jsonSuccess([
    'data_sheets' => $sheets,
    'total' => $total,
    'page' => $page,
    'per_page' => $perPage,
]);
