<?php
/**
 * 列表 AI 對話
 * GET /api/ai/conversation/list.php
 * 選填: context_type, context_local_udid
 */
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/db.php';
require_once __DIR__ . '/../../../lib/auth.php';

requireGet();
$userId = requireAuth();

$db = getDB();

$where = ['user_id = ?'];
$params = [$userId];

if (!empty($_GET['context_type'])) {
    $where[] = 'context_type = ?';
    $params[] = $_GET['context_type'];
}

if (!empty($_GET['context_local_udid'])) {
    $where[] = 'context_local_udid = ?';
    $params[] = $_GET['context_local_udid'];
}

$stmt = $db->prepare('
    SELECT server_id, local_udid, context_type, context_local_udid, created_at, updated_at
    FROM ai_conversations
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY updated_at DESC
');
$stmt->execute($params);

jsonSuccess(['conversations' => $stmt->fetchAll()]);
