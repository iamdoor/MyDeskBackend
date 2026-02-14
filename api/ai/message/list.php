<?php
/**
 * 列表對話訊息
 * GET /api/ai/message/list.php
 * 參數: conversation_local_udid
 */
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/db.php';
require_once __DIR__ . '/../../../lib/auth.php';

requireGet();
$userId = requireAuth();

$convUdid = $_GET['conversation_local_udid'] ?? '';
if ($convUdid === '') {
    jsonError('缺少 conversation_local_udid');
}

$db = getDB();

$stmt = $db->prepare('SELECT id FROM ai_conversations WHERE user_id = ? AND local_udid = ?');
$stmt->execute([$userId, $convUdid]);
$conv = $stmt->fetch();

if (!$conv) {
    jsonError('對話不存在', 404);
}

$stmt = $db->prepare('
    SELECT server_id, local_udid, role, content, referenced_udids, sort_order, created_at
    FROM ai_messages
    WHERE conversation_id = ?
    ORDER BY sort_order ASC
');
$stmt->execute([$conv['id']]);
$messages = $stmt->fetchAll();

foreach ($messages as &$msg) {
    if ($msg['referenced_udids']) {
        $msg['referenced_udids'] = json_decode($msg['referenced_udids'], true);
    }
}

jsonSuccess(['messages' => $messages]);
