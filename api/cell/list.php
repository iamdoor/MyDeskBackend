<?php
/**
 * 列表 Cell
 * GET /api/cell/list.php
 * 選填參數: cell_type, include_deleted (0/1), page, per_page, tag, keyword
 */
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';

requireGet();
$userId = requireAuth();

$db = getDB();

$where = ['c.user_id = ?'];
$params = [$userId];

// 按類型篩選
if (isset($_GET['cell_type']) && $_GET['cell_type'] !== '') {
    $where[] = 'c.cell_type = ?';
    $params[] = (int) $_GET['cell_type'];
}

// 是否包含已刪除
if (empty($_GET['include_deleted'])) {
    $where[] = 'c.is_deleted = 0';
}

// 按 tag 篩選
if (!empty($_GET['tag'])) {
    $where[] = 'EXISTS (SELECT 1 FROM cell_tags ct INNER JOIN tags t ON t.local_udid = ct.tag_local_udid WHERE ct.cell_id = c.id AND t.name = ?)';
    $params[] = $_GET['tag'];
}

// 按關鍵字搜尋
if (!empty($_GET['keyword'])) {
    $keyword = '%' . $_GET['keyword'] . '%';
    $where[] = '(c.title LIKE ? OR c.description LIKE ?)';
    $params[] = $keyword;
    $params[] = $keyword;
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 50)));
$offset = ($page - 1) * $perPage;

$whereClause = implode(' AND ', $where);

// 總數
$countStmt = $db->prepare("SELECT COUNT(*) FROM cells c WHERE $whereClause");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

// 資料
$sql = "
    SELECT c.server_id, c.local_udid, c.cell_type, c.title, c.description, c.importance,
           c.content_json, c.desktop_origin, c.is_deleted, c.deleted_at, c.scheduled_delete, c.scheduled_delete_at,
           c.ai_edited, c.ai_edited_at, c.created_at, c.updated_at
    FROM cells c
    WHERE $whereClause
    ORDER BY c.updated_at DESC
    LIMIT $perPage OFFSET $offset
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$cells = $stmt->fetchAll();

// 批次取 tags
foreach ($cells as &$cell) {
    if ($cell['content_json']) {
        $cell['content_json'] = json_decode($cell['content_json'], true);
    }

    $tagStmt = $db->prepare('
        SELECT t.name FROM tags t
        INNER JOIN cell_tags ct ON ct.tag_local_udid = t.local_udid
        INNER JOIN cells c2 ON c2.id = ct.cell_id
        WHERE c2.user_id = ? AND c2.local_udid = ?
    ');
    $tagStmt->execute([$userId, $cell['local_udid']]);
    $cell['tags'] = $tagStmt->fetchAll(PDO::FETCH_COLUMN);
}

jsonSuccess([
    'cells' => $cells,
    'total' => $total,
    'page' => $page,
    'per_page' => $perPage,
]);
