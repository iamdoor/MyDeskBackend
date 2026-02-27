<?php
/**
 * 更新 Cell
 * POST /api/cell/update.php
 * 參數: local_udid
 * 選填: cell_type, title, description, importance, content_json,
 *       tags (JSON array, 完整替換), scheduled_delete, scheduled_delete_at
 */
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/sync_helper.php';
require_once __DIR__ . '/../../lib/tag_helper.php';

requirePost();
$userId = requireAuth();
$data = getPostData();
requireFields($data, ['local_udid']);

$db = getDB();

$stmt = $db->prepare('SELECT id, server_id FROM cells WHERE user_id = ? AND local_udid = ? AND is_deleted = 0');
$stmt->execute([$userId, $data['local_udid']]);
$cell = $stmt->fetch();

if (!$cell) {
    jsonError('Cell 不存在', 404);
}

$updates = [];
$params = [];

$allowedFields = [
    'cell_type' => 'int',
    'title' => 'string',
    'description' => 'string',
    'importance' => 'int',
    'custom_id' => 'string',
    'desktop_origin' => 'int',
    'scheduled_delete' => 'int',
    'scheduled_delete_at' => 'string',
];

foreach ($allowedFields as $field => $type) {
    if (array_key_exists($field, $data)) {
        $updates[] = "`$field` = ?";
        if ($type === 'int') {
            $params[] = (int) $data[$field];
        } else {
            $params[] = $data[$field];
        }
    }
}

if (array_key_exists('content_json', $data)) {
    $contentJson = $data['content_json'];
    if (is_array($contentJson)) {
        $contentJson = json_encode($contentJson, JSON_UNESCAPED_UNICODE);
    }
    if (is_string($contentJson) && $contentJson !== '') {
        json_decode($contentJson);
        if (json_last_error() !== JSON_ERROR_NONE) {
            jsonError('content_json 格式錯誤');
        }
    }
    $updates[] = '`content_json` = ?';
    $params[] = $contentJson;
}

if (empty($updates)) {
    jsonError('未提供更新欄位');
}

$params[] = $cell['id'];
$sql = 'UPDATE cells SET ' . implode(', ', $updates) . ' WHERE id = ?';
$db->prepare($sql)->execute($params);

// 處理 tags（完整替換）
if (array_key_exists('tags', $data)) {
    // 先清除舊的
    $db->prepare('DELETE FROM cell_tags WHERE cell_id = ?')->execute([$cell['id']]);

    $tags = is_string($data['tags']) ? json_decode($data['tags'], true) : $data['tags'];
    if (is_array($tags)) {
        foreach ($tags as $tagName) {
            $tagName = trim($tagName);
            if ($tagName === '') continue;

            $tagLocalUdid = ensureTagLocalUdid($db, $userId, $tagName);
            $db->prepare('INSERT IGNORE INTO cell_tags (cell_id, tag_local_udid) VALUES (?, ?)')->execute([$cell['id'], $tagLocalUdid]);
        }
    }
}

// 寫入同步日誌
writeSyncLog($userId, null, 'cell', $cell['server_id'], $data['local_udid'], 'update', $data);

jsonSuccess([], '更新成功');
