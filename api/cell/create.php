<?php
/**
 * 建立 Cell
 * POST /api/cell/create.php
 * 參數: local_udid, cell_type, title, content_json
 * 選填: description, importance, tags (JSON array of tag names),
 *       scheduled_delete, scheduled_delete_at
 */
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/sync_helper.php';
require_once __DIR__ . '/../../lib/tag_helper.php';

requirePost();
$userId = requireAuth();
$data = getPostData();
requireFields($data, ['local_udid', 'cell_type', 'title']);

$db = getDB();
$serverId = generateUUID();
$localUdid = $data['local_udid'];

// 檢查 local_udid 是否重複
$stmt = $db->prepare('SELECT id FROM cells WHERE user_id = ? AND local_udid = ?');
$stmt->execute([$userId, $localUdid]);
if ($stmt->fetch()) {
    jsonError('local_udid 已存在');
}

$contentJson = $data['content_json'] ?? null;
if (is_string($contentJson)) {
    // 驗證 JSON 格式
    json_decode($contentJson);
    if (json_last_error() !== JSON_ERROR_NONE) {
        jsonError('content_json 格式錯誤');
    }
} elseif (is_array($contentJson)) {
    $contentJson = json_encode($contentJson, JSON_UNESCAPED_UNICODE);
}

$stmt = $db->prepare('
    INSERT INTO cells (server_id, local_udid, user_id, cell_type, title, description, importance, content_json, desktop_origin, scheduled_delete, scheduled_delete_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
');
$stmt->execute([
    $serverId,
    $localUdid,
    $userId,
    (int) $data['cell_type'],
    $data['title'],
    $data['description'] ?? null,
    (int) ($data['importance'] ?? 0),
    $contentJson,
    (int) ($data['desktop_origin'] ?? 0),
    (int) ($data['scheduled_delete'] ?? 0),
    $data['scheduled_delete_at'] ?? null,
]);

$cellId = (int) $db->lastInsertId();

// 處理 tags
if (!empty($data['tags'])) {
    $tags = is_string($data['tags']) ? json_decode($data['tags'], true) : $data['tags'];
    if (is_array($tags)) {
        foreach ($tags as $tagName) {
            $tagName = trim($tagName);
            if ($tagName === '') continue;

            // 找或建 tag
            $tagLocalUdid = ensureTagLocalUdid($db, $userId, $tagName);
            $db->prepare('INSERT IGNORE INTO cell_tags (cell_id, tag_local_udid) VALUES (?, ?)')->execute([$cellId, $tagLocalUdid]);
        }
    }
}

// 寫入同步日誌
writeSyncLog($userId, null, 'cell', $serverId, $localUdid, 'create', [
    'server_id' => $serverId,
    'local_udid' => $localUdid,
    'cell_type' => (int) $data['cell_type'],
    'title' => $data['title'],
    'description' => $data['description'] ?? null,
    'importance' => (int) ($data['importance'] ?? 0),
    'content_json' => $contentJson,
]);

jsonSuccess([
    'cell_id' => $cellId,
    'server_id' => $serverId,
    'local_udid' => $localUdid,
], '建立成功', 201);
