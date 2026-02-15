<?php
/**
 * 同步上傳
 * POST /api/sync/upload.php
 *
 * 參數: device_udid, changes (JSON array)
 * changes 中每一筆: { entity_type, local_udid, action, data, base_updated_at }
 *
 * 回傳:
 * - results: 每一筆的處理結果 (success / conflict)
 * - conflicts: 有衝突的資料，包含伺服器版本與本地版本
 */
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/sync_helper.php';

requirePost();
$userId = requireAuth();
$data = getPostData();
requireFields($data, ['device_udid', 'changes']);

$db = getDB();

// 取得裝置 ID（不存在則自動註冊）
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

$changes = is_string($data['changes']) ? json_decode($data['changes'], true) : $data['changes'];
if (!is_array($changes)) {
    jsonError('changes 格式錯誤');
}

$results = [];
$conflicts = [];

// 實體類型對應的資料表
$entityTableMap = [
    'cell' => 'cells',
    'datasheet' => 'data_sheets',
    'desktop' => 'desktops',
    'category' => 'categories',
    'sub_category' => 'sub_categories',
];

foreach ($changes as $change) {
    $entityType = $change['entity_type'] ?? '';
    $localUdid = $change['local_udid'] ?? '';
    $action = $change['action'] ?? '';
    $changeData = $change['data'] ?? [];
    $baseUpdatedAt = $change['base_updated_at'] ?? null;

    if (!$entityType || !$localUdid || !$action) {
        $results[] = [
            'local_udid' => $localUdid,
            'status' => 'error',
            'message' => '缺少必填欄位',
        ];
        continue;
    }

    $table = $entityTableMap[$entityType] ?? null;

    // 對於有對應資料表的實體，檢查衝突
    if ($table && $action !== 'create') {
        $stmt = $db->prepare("SELECT server_id, updated_at FROM `$table` WHERE user_id = ? AND local_udid = ?");
        $stmt->execute([$userId, $localUdid]);
        $serverRecord = $stmt->fetch();

        if (!$serverRecord) {
            $results[] = [
                'local_udid' => $localUdid,
                'status' => 'error',
                'message' => '資料不存在',
            ];
            continue;
        }

        // 衝突偵測
        if ($baseUpdatedAt && $serverRecord['updated_at'] > $baseUpdatedAt) {
            // 取得伺服器端完整資料
            $stmt = $db->prepare("SELECT * FROM `$table` WHERE user_id = ? AND local_udid = ?");
            $stmt->execute([$userId, $localUdid]);
            $serverData = $stmt->fetch();

            $conflicts[] = [
                'entity_type' => $entityType,
                'local_udid' => $localUdid,
                'server_version' => $serverData,
                'local_version' => $changeData,
                'server_updated_at' => $serverRecord['updated_at'],
                'base_updated_at' => $baseUpdatedAt,
            ];

            $results[] = [
                'local_udid' => $localUdid,
                'status' => 'conflict',
            ];
            continue;
        }
    }

    // 處理變更
    try {
        switch ($action) {
            case 'create':
                if ($table) {
                    $serverId = generateUUID();

                    // 組裝 INSERT
                    $changeData['server_id'] = $serverId;
                    $changeData['local_udid'] = $localUdid;
                    $changeData['user_id'] = $userId;

                    $columns = array_keys($changeData);
                    $placeholders = array_fill(0, count($columns), '?');

                    $sql = "INSERT INTO `$table` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $placeholders) . ")";
                    $db->prepare($sql)->execute(array_values($changeData));

                    writeSyncLog($userId, $deviceId, $entityType, $serverId, $localUdid, 'create', $changeData);

                    $results[] = [
                        'local_udid' => $localUdid,
                        'status' => 'success',
                        'server_id' => $serverId,
                    ];
                }
                break;

            case 'update':
                if ($table) {
                    $setClauses = [];
                    $params = [];
                    foreach ($changeData as $key => $value) {
                        if (in_array($key, ['id', 'server_id', 'local_udid', 'user_id'])) continue;
                        $setClauses[] = "`$key` = ?";
                        $params[] = $value;
                    }

                    if (!empty($setClauses)) {
                        $params[] = $userId;
                        $params[] = $localUdid;
                        $sql = "UPDATE `$table` SET " . implode(', ', $setClauses) . " WHERE user_id = ? AND local_udid = ?";
                        $db->prepare($sql)->execute($params);
                    }

                    writeSyncLog($userId, $deviceId, $entityType, $serverRecord['server_id'], $localUdid, 'update', $changeData);

                    $results[] = [
                        'local_udid' => $localUdid,
                        'status' => 'success',
                    ];
                }
                break;

            case 'delete':
                if ($table) {
                    $db->prepare("UPDATE `$table` SET is_deleted = 1, deleted_at = NOW() WHERE user_id = ? AND local_udid = ?")->execute([$userId, $localUdid]);

                    writeSyncLog($userId, $deviceId, $entityType, $serverRecord['server_id'] ?? '', $localUdid, 'delete', ['local_udid' => $localUdid]);

                    $results[] = [
                        'local_udid' => $localUdid,
                        'status' => 'success',
                    ];
                }
                break;

            default:
                $results[] = [
                    'local_udid' => $localUdid,
                    'status' => 'error',
                    'message' => '未知的 action: ' . $action,
                ];
        }
    } catch (Exception $e) {
        $results[] = [
            'local_udid' => $localUdid,
            'status' => 'error',
            'message' => $e->getMessage(),
        ];
    }
}

// 更新裝置同步時間
$serverNow = $db->query("SELECT NOW()")->fetchColumn();
$db->prepare('UPDATE devices SET last_sync_at = ? WHERE id = ?')->execute([$serverNow, $deviceId]);

jsonSuccess([
    'results' => $results,
    'conflicts' => $conflicts,
    'has_conflicts' => !empty($conflicts),
    'server_now' => $serverNow,
]);
