<?php
/**
 * 新增 AI 訊息
 * POST /api/ai/message/create.php
 * 參數: local_udid, conversation_local_udid, role (user/assistant), content
 * 選填: referenced_udids (JSON array), sort_order
 */
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/db.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/sync_helper.php';

requirePost();
$userId = requireAuth();
$data = getPostData();
requireFields($data, ['local_udid', 'conversation_local_udid', 'role', 'content']);

if (!in_array($data['role'], ['user', 'assistant'])) {
    jsonError('role 必須為 user 或 assistant');
}

$db = getDB();

// 取得 conversation
$stmt = $db->prepare('SELECT id, server_id FROM ai_conversations WHERE user_id = ? AND local_udid = ?');
$stmt->execute([$userId, $data['conversation_local_udid']]);
$conv = $stmt->fetch();

if (!$conv) {
    jsonError('對話不存在', 404);
}

$serverId = generateUUID();

// sort_order
$sortOrder = $data['sort_order'] ?? null;
if ($sortOrder === null) {
    $stmt = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM ai_messages WHERE conversation_id = ?');
    $stmt->execute([$conv['id']]);
    $sortOrder = (int) $stmt->fetchColumn();
}

$referencedUdids = $data['referenced_udids'] ?? null;
if (is_array($referencedUdids)) {
    $referencedUdids = json_encode($referencedUdids, JSON_UNESCAPED_UNICODE);
}

$stmt = $db->prepare('
    INSERT INTO ai_messages (server_id, local_udid, conversation_id, role, content, referenced_udids, sort_order)
    VALUES (?, ?, ?, ?, ?, ?, ?)
');
$stmt->execute([$serverId, $data['local_udid'], $conv['id'], $data['role'], $data['content'], $referencedUdids, (int) $sortOrder]);

// 更新對話的 updated_at
$db->prepare('UPDATE ai_conversations SET updated_at = NOW() WHERE id = ?')->execute([$conv['id']]);

writeSyncLog($userId, null, 'ai_message', $serverId, $data['local_udid'], 'create', [
    'conversation_local_udid' => $data['conversation_local_udid'],
    'role' => $data['role'],
]);

jsonSuccess([
    'message_id' => (int) $db->lastInsertId(),
    'server_id' => $serverId,
], '建立成功', 201);
