<?php
/**
 * 執行智慧資料單查詢
 * GET /api/datasheet/smart_query.php
 * 參數: local_udid (智慧資料單)
 * 選填: page, per_page
 *
 * 回傳：動態條件匹配的 Cell + 手動釘選的 Reference Cell
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

// 取得資料單
$stmt = $db->prepare('SELECT id, is_smart FROM data_sheets WHERE user_id = ? AND local_udid = ? AND is_deleted = 0');
$stmt->execute([$userId, $localUdid]);
$sheet = $stmt->fetch();

if (!$sheet) {
    jsonError('資料單不存在', 404);
}

if (!$sheet['is_smart']) {
    jsonError('此資料單不是智慧資料單');
}

// 取得條件
$stmt = $db->prepare('SELECT condition_type, condition_value FROM smart_sheet_conditions WHERE data_sheet_id = ?');
$stmt->execute([$sheet['id']]);
$conditions = $stmt->fetchAll();

$cellUdids = [];

foreach ($conditions as $cond) {
    switch ($cond['condition_type']) {
        case 'tag':
            $stmt = $db->prepare('
                SELECT c.local_udid FROM cells c
                INNER JOIN cell_tags ct ON ct.cell_id = c.id
                INNER JOIN tags t ON t.id = ct.tag_id
                WHERE c.user_id = ? AND c.is_deleted = 0 AND t.name = ?
            ');
            $stmt->execute([$userId, $cond['condition_value']]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $udid) {
                $cellUdids[$udid] = true;
            }
            break;

        case 'keyword':
            $keyword = '%' . $cond['condition_value'] . '%';
            $stmt = $db->prepare('
                SELECT local_udid FROM cells
                WHERE user_id = ? AND is_deleted = 0
                AND (title LIKE ? OR description LIKE ?)
            ');
            $stmt->execute([$userId, $keyword, $keyword]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $udid) {
                $cellUdids[$udid] = true;
            }
            break;

        case 'reference':
            $cellUdids[$cond['condition_value']] = true;
            break;
    }
}

$udidList = array_keys($cellUdids);

if (empty($udidList)) {
    jsonSuccess(['cells' => [], 'total' => 0]);
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 50)));
$offset = ($page - 1) * $perPage;

$placeholders = implode(',', array_fill(0, count($udidList), '?'));

$params = array_merge([$userId], $udidList);
$sql = "
    SELECT server_id, local_udid, cell_type, title, description, importance,
           content_json, created_at, updated_at
    FROM cells
    WHERE user_id = ? AND local_udid IN ($placeholders) AND is_deleted = 0
    ORDER BY updated_at DESC
    LIMIT $perPage OFFSET $offset
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$cells = $stmt->fetchAll();

foreach ($cells as &$cell) {
    if ($cell['content_json']) {
        $cell['content_json'] = json_decode($cell['content_json'], true);
    }
}

jsonSuccess([
    'cells' => $cells,
    'total' => count($udidList),
    'page' => $page,
    'per_page' => $perPage,
]);
