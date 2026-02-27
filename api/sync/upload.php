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
$deviceUdid = $data['device_udid'];
$stmt = $db->prepare('SELECT id FROM devices WHERE user_id = ? AND device_udid = ?');
$stmt->execute([$userId, $deviceUdid]);
$device = $stmt->fetch();

if (!$device) {
    $platform = $data['platform'] ?? 'ios';
    $stmt = $db->prepare('INSERT INTO devices (user_id, device_udid, device_name, platform) VALUES (?, ?, ?, ?)');
    $stmt->execute([$userId, $deviceUdid, $data['device_name'] ?? '', $platform]);
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

    // API 模板（無衝突機制，直接覆寫）
    if ($entityType === 'api_template') {
        try {
            switch ($action) {
                case 'create':
                    $stmt = $db->prepare('SELECT server_id FROM api_templates WHERE user_id = ? AND local_udid = ?');
                    $stmt->execute([$userId, $localUdid]);
                    $existing = $stmt->fetch();
                    if ($existing) {
                        $serverId = $existing['server_id'];
                        $db->prepare('UPDATE api_templates SET name = ?, template_json = ?, updated_at = ? WHERE user_id = ? AND local_udid = ?')
                           ->execute([$changeData['name'] ?? '', $changeData['template_json'] ?? '{}', $changeData['updated_at'] ?? date('Y-m-d H:i:s'), $userId, $localUdid]);
                    } else {
                        $serverId = generateUUID();
                        $now = $changeData['created_at'] ?? date('Y-m-d H:i:s');
                        $db->prepare('INSERT INTO api_templates (server_id, user_id, local_udid, name, template_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
                           ->execute([$serverId, $userId, $localUdid, $changeData['name'] ?? '', $changeData['template_json'] ?? '{}', $now, $changeData['updated_at'] ?? $now]);
                    }
                    $results[] = ['local_udid' => $localUdid, 'status' => 'success', 'server_id' => $serverId];
                    break;

                case 'update':
                    $stmt = $db->prepare('SELECT server_id FROM api_templates WHERE user_id = ? AND local_udid = ?');
                    $stmt->execute([$userId, $localUdid]);
                    $existing = $stmt->fetch();
                    if (!$existing) {
                        $results[] = ['local_udid' => $localUdid, 'status' => 'error', 'message' => '模板不存在'];
                        break;
                    }
                    $db->prepare('UPDATE api_templates SET name = ?, template_json = ?, updated_at = ? WHERE user_id = ? AND local_udid = ?')
                       ->execute([$changeData['name'] ?? '', $changeData['template_json'] ?? '{}', $changeData['updated_at'] ?? date('Y-m-d H:i:s'), $userId, $localUdid]);
                    $results[] = ['local_udid' => $localUdid, 'status' => 'success'];
                    break;

                case 'delete':
                    $now = date('Y-m-d H:i:s');
                    $db->prepare('UPDATE api_templates SET is_deleted = 1, deleted_at = ?, updated_at = ? WHERE user_id = ? AND local_udid = ?')
                       ->execute([$now, $now, $userId, $localUdid]);
                    $results[] = ['local_udid' => $localUdid, 'status' => 'success'];
                    break;

                default:
                    $results[] = ['local_udid' => $localUdid, 'status' => 'error', 'message' => '未知 action'];
            }
        } catch (Exception $e) {
            $results[] = ['local_udid' => $localUdid, 'status' => 'error', 'message' => $e->getMessage()];
        }
        continue;
    }

    // App 主題（無衝突機制，直接覆寫）
    if ($entityType === 'app_theme') {
        try {
            switch ($action) {
                case 'create':
                    $stmt = $db->prepare('SELECT server_id FROM app_themes WHERE user_id = ? AND local_udid = ?');
                    $stmt->execute([$userId, $localUdid]);
                    $existing = $stmt->fetch();
                    if ($existing) {
                        $serverId = $existing['server_id'];
                        $db->prepare('UPDATE app_themes SET name = ?, accent_hex = ?, bg_hex = ?, surface_hex = ?, text_hex = ?, warning_hex = ?, updated_at = ? WHERE user_id = ? AND local_udid = ?')
                           ->execute([
                               $changeData['name'] ?? '',
                               $changeData['accent_hex'] ?? '#0D9488',
                               $changeData['bg_hex'] ?? '#FFFFFF',
                               $changeData['surface_hex'] ?? '#E8F7F6',
                               $changeData['text_hex'] ?? '#1A2B4A',
                               $changeData['warning_hex'] ?? '#FFB347',
                               $changeData['updated_at'] ?? date('Y-m-d H:i:s'),
                               $userId, $localUdid
                           ]);
                    } else {
                        $serverId = generateUUID();
                        $now = $changeData['created_at'] ?? date('Y-m-d H:i:s');
                        $db->prepare('INSERT INTO app_themes (server_id, user_id, local_udid, name, accent_hex, bg_hex, surface_hex, text_hex, warning_hex, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                           ->execute([
                               $serverId, $userId, $localUdid,
                               $changeData['name'] ?? '',
                               $changeData['accent_hex'] ?? '#0D9488',
                               $changeData['bg_hex'] ?? '#FFFFFF',
                               $changeData['surface_hex'] ?? '#E8F7F6',
                               $changeData['text_hex'] ?? '#1A2B4A',
                               $changeData['warning_hex'] ?? '#FFB347',
                               $now, $changeData['updated_at'] ?? $now
                           ]);
                    }
                    $results[] = ['local_udid' => $localUdid, 'status' => 'success', 'server_id' => $serverId];
                    break;

                case 'delete':
                    $now = date('Y-m-d H:i:s');
                    $db->prepare('UPDATE app_themes SET is_deleted = 1, deleted_at = ?, updated_at = ? WHERE user_id = ? AND local_udid = ?')
                       ->execute([$now, $now, $userId, $localUdid]);
                    $results[] = ['local_udid' => $localUdid, 'status' => 'success'];
                    break;

                default:
                    $results[] = ['local_udid' => $localUdid, 'status' => 'error', 'message' => '未知 action'];
            }
        } catch (Exception $e) {
            $results[] = ['local_udid' => $localUdid, 'status' => 'error', 'message' => $e->getMessage()];
        }
        continue;
    }

    // 桌面組件（無 user_id，需自定義處理，create 需回傳 server_id）
    if ($entityType === 'desktop_component') {
        try {
            $desktopUdid = $changeData['desktop_local_udid'] ?? '';
            if (!$desktopUdid) throw new Exception('缺少 desktop_local_udid');

            switch ($action) {
                case 'create':
                    $stmt = $db->prepare('SELECT server_id FROM desktop_components WHERE local_udid = ?');
                    $stmt->execute([$localUdid]);
                    $existing = $stmt->fetch();
                    $configJson = $changeData['config_json'] ?? '{}';
                    if (is_array($configJson)) $configJson = json_encode($configJson);

                    if ($existing) {
                        $serverId = $existing['server_id'];
                        $db->prepare('UPDATE desktop_components SET component_type_code=?, bg_color=?, border_color=?, border_width=?, corner_radius=?, config_json=?, updated_at=? WHERE local_udid=?')
                           ->execute([
                               $changeData['component_type_code'] ?? 'free_block',
                               $changeData['bg_color'] ?? null,
                               $changeData['border_color'] ?? null,
                               (int)($changeData['border_width'] ?? 0),
                               (int)($changeData['corner_radius'] ?? 12),
                               $configJson,
                               $changeData['updated_at'] ?? date('Y-m-d H:i:s'),
                               $localUdid,
                           ]);
                    } else {
                        $serverId = generateUUID();
                        $now = $changeData['created_at'] ?? date('Y-m-d H:i:s');
                        $db->prepare('INSERT INTO desktop_components (server_id, local_udid, desktop_local_udid, component_type_code, bg_color, border_color, border_width, corner_radius, config_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                           ->execute([
                               $serverId, $localUdid, $desktopUdid,
                               $changeData['component_type_code'] ?? 'free_block',
                               $changeData['bg_color'] ?? null,
                               $changeData['border_color'] ?? null,
                               (int)($changeData['border_width'] ?? 0),
                               (int)($changeData['corner_radius'] ?? 12),
                               $configJson,
                               $now,
                               $changeData['updated_at'] ?? $now,
                           ]);
                    }
                    $results[] = ['local_udid' => $localUdid, 'status' => 'success', 'server_id' => $serverId];
                    break;

                case 'update':
                    $configJson = $changeData['config_json'] ?? '{}';
                    if (is_array($configJson)) $configJson = json_encode($configJson);
                    $db->prepare('UPDATE desktop_components SET component_type_code=?, bg_color=?, border_color=?, border_width=?, corner_radius=?, config_json=?, updated_at=? WHERE local_udid=?')
                       ->execute([
                           $changeData['component_type_code'] ?? 'free_block',
                           $changeData['bg_color'] ?? null,
                           $changeData['border_color'] ?? null,
                           (int)($changeData['border_width'] ?? 0),
                           (int)($changeData['corner_radius'] ?? 12),
                           $configJson,
                           $changeData['updated_at'] ?? date('Y-m-d H:i:s'),
                           $localUdid,
                       ]);
                    $results[] = ['local_udid' => $localUdid, 'status' => 'success'];
                    break;

                case 'delete':
                    $db->prepare('DELETE FROM desktop_component_links WHERE component_local_udid = ?')->execute([$localUdid]);
                    $db->prepare('DELETE FROM desktop_components WHERE local_udid = ?')->execute([$localUdid]);
                    $results[] = ['local_udid' => $localUdid, 'status' => 'success'];
                    break;

                default:
                    $results[] = ['local_udid' => $localUdid, 'status' => 'error', 'message' => '未知 action'];
            }
        } catch (Exception $e) {
            $results[] = ['local_udid' => $localUdid, 'status' => 'error', 'message' => $e->getMessage()];
        }
        continue;
    }

    // 關聯型實體（無 user_id / server_id / conflict 機制）
    if (in_array($entityType, ['datasheet_cell', 'desktop_cell', 'desktop_component_link'])) {
        try {
            switch ($entityType) {
                case 'datasheet_cell':
                    $dsUdid   = $changeData['data_sheet_local_udid'] ?? '';
                    $cellUdid = $changeData['cell_local_udid'] ?? '';
                    if (!$dsUdid || !$cellUdid) throw new Exception('缺少 data_sheet_local_udid 或 cell_local_udid');
                    if ($action === 'remove') {
                        $db->prepare('DELETE FROM data_sheet_cells WHERE data_sheet_local_udid = ? AND cell_local_udid = ?')
                           ->execute([$dsUdid, $cellUdid]);
                    } else {
                        $sortOrder = $changeData['sort_order'] ?? 0;
                        $db->prepare('INSERT INTO data_sheet_cells (data_sheet_local_udid, cell_local_udid, sort_order) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order)')
                           ->execute([$dsUdid, $cellUdid, $sortOrder]);
                    }
                    break;

                case 'desktop_cell':
                    $desktopUdid  = $changeData['desktop_local_udid'] ?? '';
                    $refLocalUdid = $changeData['ref_local_udid'] ?? '';
                    $refType      = $changeData['ref_type'] ?? 'cell';
                    if (!$desktopUdid || !$refLocalUdid) throw new Exception('缺少 desktop_local_udid 或 ref_local_udid');
                    if ($action === 'remove') {
                        $db->prepare('DELETE FROM desktop_cells WHERE desktop_local_udid = ? AND ref_local_udid = ?')
                           ->execute([$desktopUdid, $refLocalUdid]);
                    } else {
                        $db->prepare('INSERT IGNORE INTO desktop_cells (desktop_local_udid, ref_type, ref_local_udid, created_at) VALUES (?, ?, ?, NOW())')
                           ->execute([$desktopUdid, $refType, $refLocalUdid]);
                    }
                    break;

                case 'desktop_component_link':
                    $compUdid     = $changeData['component_local_udid'] ?? '';
                    $refLocalUdid = $changeData['ref_local_udid'] ?? '';
                    $refType      = $changeData['ref_type'] ?? 'cell';
                    $sortOrder    = $changeData['sort_order'] ?? 0;
                    if (!$compUdid || !$refLocalUdid) throw new Exception('缺少 component_local_udid 或 ref_local_udid');
                    if ($action === 'remove') {
                        $db->prepare('DELETE FROM desktop_component_links WHERE local_udid = ?')
                           ->execute([$localUdid]);
                    } elseif ($action === 'update') {
                        $db->prepare('UPDATE desktop_component_links SET sort_order = ?, updated_at = NOW() WHERE local_udid = ?')
                           ->execute([$sortOrder, $localUdid]);
                    } else {
                        $now = date('Y-m-d H:i:s');
                        $db->prepare('INSERT IGNORE INTO desktop_component_links (local_udid, component_local_udid, ref_type, ref_local_udid, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
                           ->execute([$localUdid, $compUdid, $refType, $refLocalUdid, $sortOrder, $now, $now]);
                    }
                    break;
            }
            $results[] = ['local_udid' => $localUdid, 'status' => 'success'];
        } catch (Exception $e) {
            $results[] = ['local_udid' => $localUdid, 'status' => 'error', 'message' => $e->getMessage()];
        }
        continue;
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

                    writeSyncLog($userId, $deviceUdid, $entityType, $serverId, $localUdid, 'create', $changeData);

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

                    writeSyncLog($userId, $deviceUdid, $entityType, $serverRecord['server_id'], $localUdid, 'update', $changeData);

                    $results[] = [
                        'local_udid' => $localUdid,
                        'status' => 'success',
                    ];
                }
                break;

            case 'delete':
                if ($table) {
                    $db->prepare("UPDATE `$table` SET is_deleted = 1, deleted_at = NOW() WHERE user_id = ? AND local_udid = ?")->execute([$userId, $localUdid]);

                    writeSyncLog($userId, $deviceUdid, $entityType, $serverRecord['server_id'] ?? '', $localUdid, 'delete', ['local_udid' => $localUdid]);

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
