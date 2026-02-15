<?php
/**
 * 同步下載（增量）
 * POST /api/sync/download.php
 * 參數: device_udid, last_sync_version
 *
 * 回傳: 所有 sync_version > last_sync_version 的變更
 */
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';

requirePost();
$userId = requireAuth();
$data = getPostData();
requireFields($data, ['device_udid', 'last_sync_version']);

$db = getDB();

// 驗證裝置（不存在則自動註冊）
$stmt = $db->prepare('SELECT id FROM devices WHERE user_id = ? AND device_udid = ?');
$stmt->execute([$userId, $data['device_udid']]);
$device = $stmt->fetch();

if (!$device) {
    $platform = $data['platform'] ?? 'ios';
    $stmt = $db->prepare('INSERT INTO devices (user_id, device_udid, device_name, platform) VALUES (?, ?, ?, ?)');
    $stmt->execute([$userId, $data['device_udid'], $data['device_name'] ?? '', $platform]);
    $deviceId = (int) $db->lastInsertId();
} else {
    $deviceId = (int) $device['id'];
}
$lastVersion = (int) $data['last_sync_version'];

// 取得該裝置之後的所有同步紀錄（排除自己產生的）
$stmt = $db->prepare('
    SELECT entity_type, entity_server_id, entity_local_udid, action, sync_version, payload_json, created_at
    FROM sync_log
    WHERE user_id = ? AND sync_version > ? AND (device_id IS NULL OR device_id != ?)
    ORDER BY sync_version ASC
    LIMIT 1000
');
$stmt->execute([$userId, $lastVersion, $deviceId]);
$changes = $stmt->fetchAll();

foreach ($changes as &$change) {
    if ($change['payload_json']) {
        $change['payload_json'] = json_decode($change['payload_json'], true);
    }
}

// 取得最新的 sync_version
$stmt = $db->prepare('SELECT COALESCE(MAX(sync_version), 0) FROM sync_log WHERE user_id = ?');
$stmt->execute([$userId]);
$latestVersion = (int) $stmt->fetchColumn();

// 更新裝置同步資訊
$db->prepare('UPDATE devices SET last_sync_at = NOW(), last_sync_version = ? WHERE id = ?')->execute([$latestVersion, $deviceId]);

jsonSuccess([
    'changes' => $changes,
    'latest_sync_version' => $latestVersion,
    'count' => count($changes),
]);
