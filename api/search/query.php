<?php
/**
 * 搜尋
 * GET /api/search/query.php
 * 選填: keyword, date_from, date_to, scope (cell/datasheet/desktop, 預設 datasheet), page, per_page
 */
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';

requireGet();
$userId = requireAuth();

$db = getDB();

$keyword = $_GET['keyword'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$scope = $_GET['scope'] ?? 'datasheet';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 50)));
$offset = ($page - 1) * $perPage;

$results = [];
$total = 0;

// 搜尋函數
$buildSearch = function(string $table, string $entityType, array $selectFields) use ($db, $userId, $keyword, $dateFrom, $dateTo, $perPage, $offset) {
    $where = ["t.user_id = ?", "t.is_deleted = 0"];
    $params = [$userId];

    if ($keyword !== '') {
        $kw = '%' . $keyword . '%';
        $where[] = '(t.title LIKE ? OR t.description LIKE ?)';
        $params[] = $kw;
        $params[] = $kw;
    }

    if ($dateFrom !== '') {
        $where[] = 't.updated_at >= ?';
        $params[] = $dateFrom;
    }

    if ($dateTo !== '') {
        $where[] = 't.updated_at <= ?';
        $params[] = $dateTo;
    }

    $whereClause = implode(' AND ', $where);

    // 計數
    $countStmt = $db->prepare("SELECT COUNT(*) FROM `$table` t WHERE $whereClause");
    $countStmt->execute($params);
    $count = (int) $countStmt->fetchColumn();

    // 資料
    $fields = implode(', ', array_map(fn($f) => "t.$f", $selectFields));
    $sql = "SELECT $fields, '$entityType' AS entity_type FROM `$table` t WHERE $whereClause ORDER BY t.updated_at DESC LIMIT $perPage OFFSET $offset";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return ['items' => $stmt->fetchAll(), 'total' => $count];
};

switch ($scope) {
    case 'cell':
        $r = $buildSearch('cells', 'cell', ['server_id', 'local_udid', 'cell_type', 'title', 'description', 'importance', 'created_at', 'updated_at']);
        $results = $r['items'];
        $total = $r['total'];
        break;

    case 'datasheet':
        $r = $buildSearch('data_sheets', 'datasheet', ['server_id', 'local_udid', 'title', 'description', 'importance', 'is_smart', 'created_at', 'updated_at']);
        $results = $r['items'];
        $total = $r['total'];
        break;

    case 'desktop':
        $r = $buildSearch('desktops', 'desktop', ['server_id', 'local_udid', 'title', 'description', 'importance', 'ui_type', 'created_at', 'updated_at']);
        $results = $r['items'];
        $total = $r['total'];
        break;

    case 'all':
        // 搜尋全部（合併顯示）
        $allResults = [];

        $r1 = $buildSearch('cells', 'cell', ['server_id', 'local_udid', 'title', 'description', 'importance', 'created_at', 'updated_at']);
        $r2 = $buildSearch('data_sheets', 'datasheet', ['server_id', 'local_udid', 'title', 'description', 'importance', 'created_at', 'updated_at']);
        $r3 = $buildSearch('desktops', 'desktop', ['server_id', 'local_udid', 'title', 'description', 'importance', 'created_at', 'updated_at']);

        $allResults = array_merge($r1['items'], $r2['items'], $r3['items']);
        $total = $r1['total'] + $r2['total'] + $r3['total'];

        // 按 updated_at 排序
        usort($allResults, fn($a, $b) => strcmp($b['updated_at'], $a['updated_at']));
        $results = array_slice($allResults, 0, $perPage);
        break;

    default:
        jsonError('scope 必須為 cell, datasheet, desktop 或 all');
}

jsonSuccess([
    'results' => $results,
    'total' => $total,
    'page' => $page,
    'per_page' => $perPage,
    'scope' => $scope,
]);
