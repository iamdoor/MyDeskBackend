<?php
/**
 * 取得單一 Cell
 * GET /api/cell/get.php
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
    SELECT server_id, local_udid, cell_type, title, description, importance,
           content_json, is_deleted, deleted_at, scheduled_delete, scheduled_delete_at,
           ai_edited, ai_edited_at, created_at, updated_at
    FROM cells
    WHERE user_id = ? AND local_udid = ?
');
$stmt->execute([$userId, $localUdid]);
$cell = $stmt->fetch();

if (!$cell) {
    jsonError('Cell 不存在', 404);
}

// 解析 content_json
if ($cell['content_json']) {
    $cell['content_json'] = json_decode($cell['content_json'], true);
}

// 取得 tags
$stmt = $db->prepare('
    SELECT t.name FROM tags t
    INNER JOIN cell_tags ct ON ct.tag_id = t.id
    INNER JOIN cells c ON c.id = ct.cell_id
    WHERE c.user_id = ? AND c.local_udid = ?
');
$stmt->execute([$userId, $localUdid]);
$cell['tags'] = $stmt->fetchAll(PDO::FETCH_COLUMN);

jsonSuccess(['cell' => $cell]);
