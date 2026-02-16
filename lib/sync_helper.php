<?php
/**
 * 同步輔助函數
 */
require_once __DIR__ . '/db.php';

/**
 * 寫入同步日誌，回傳新的 sync_version
 */
function writeSyncLog(
    int $userId,
    ?string $deviceUdid,
    string $entityType,
    string $entityServerId,
    string $entityLocalUdid,
    string $action,
    array $payload
): int {
    $db = getDB();

    // 取得該使用者的下一個 sync_version
    $stmt = $db->prepare('SELECT COALESCE(MAX(sync_version), 0) + 1 FROM sync_log WHERE user_id = ?');
    $stmt->execute([$userId]);
    $nextVersion = (int) $stmt->fetchColumn();

    $stmt = $db->prepare('
        INSERT INTO sync_log (user_id, device_udid, entity_type, entity_server_id, entity_local_udid, action, sync_version, payload_json, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ');
    $stmt->execute([
        $userId,
        $deviceUdid,
        $entityType,
        $entityServerId,
        $entityLocalUdid,
        $action,
        $nextVersion,
        json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);

    return $nextVersion;
}
