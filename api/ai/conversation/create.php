<?php
/**
 * 建立 AI 對話
 * POST /api/ai/conversation/create.php
 * 參數: local_udid, context_type (cell/datasheet/desktop), context_local_udid
 */
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/db.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/sync_helper.php';

requirePost();
$userId = requireAuth();
$data = getPostData();
requireFields($data, ['local_udid', 'context_type', 'context_local_udid']);

if (!in_array($data['context_type'], ['cell', 'datasheet', 'desktop'])) {
    jsonError('context_type 必須為 cell, datasheet 或 desktop');
}

$db = getDB();
$serverId = generateUUID();

$stmt = $db->prepare('
    INSERT INTO ai_conversations (server_id, local_udid, user_id, context_type, context_local_udid)
    VALUES (?, ?, ?, ?, ?)
');
$stmt->execute([$serverId, $data['local_udid'], $userId, $data['context_type'], $data['context_local_udid']]);

$id = (int) $db->lastInsertId();

writeSyncLog($userId, null, 'ai_conversation', $serverId, $data['local_udid'], 'create', [
    'context_type' => $data['context_type'],
    'context_local_udid' => $data['context_local_udid'],
]);

jsonSuccess([
    'conversation_id' => $id,
    'server_id' => $serverId,
], '建立成功', 201);
