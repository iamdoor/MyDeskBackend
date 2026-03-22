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

$clientUserId = isset($data['user_id']) ? (int) $data['user_id'] : $userId;
if ($clientUserId !== $userId) {
    jsonError('user_id 不匹配', 400);
}

$db = getDB();

// 取得裝置 ID（不存在則自動註冊）
$deviceUdid = $data['device_udid'];
$stmt = $db->prepare('SELECT id, device_name, platform FROM devices WHERE user_id = ? AND device_udid = ?');
$stmt->execute([$userId, $deviceUdid]);
$device = $stmt->fetch();

if (!$device) {
    $platform = $data['platform'] ?? 'ios';
    $stmt = $db->prepare('INSERT INTO devices (user_id, device_udid, device_name, platform) VALUES (?, ?, ?, ?)');
    $deviceNameInput = $data['device_name'] ?? '';
    $stmt->execute([$userId, $deviceUdid, $deviceNameInput, $platform]);
    $deviceId = (int) $db->lastInsertId();
    $deviceName = $deviceNameInput;
} else {
    $deviceId = (int) $device['id'];
    $deviceName = $device['device_name'] ?? ($data['device_name'] ?? '');
    $platform = $data['platform'] ?? ($device['platform'] ?? 'ios');
}

$changes = is_string($data['changes']) ? json_decode($data['changes'], true) : $data['changes'];
if (!is_array($changes)) {
    jsonError('changes 格式錯誤');
}

$activityLogsPayload = [];
if (isset($data['activity_logs'])) {
    $activityLogsPayload = is_string($data['activity_logs']) ? json_decode($data['activity_logs'], true) : $data['activity_logs'];
    if (!is_array($activityLogsPayload)) {
        jsonError('activity_logs 格式錯誤');
    }
}

$deviceLogSettingsPayload = null;
if (isset($data['device_log_settings'])) {
    $deviceLogSettingsPayload = is_string($data['device_log_settings']) ? json_decode($data['device_log_settings'], true) : $data['device_log_settings'];
    if (!is_array($deviceLogSettingsPayload)) {
        jsonError('device_log_settings 格式錯誤');
    }
}

$results = [];
$conflicts = [];
$activityLogResults = [
    'accepted' => [],
    'failed' => [],
];
$deviceLogSettingsResult = [
    'status' => $deviceLogSettingsPayload ? 'pending' : 'skipped',
];

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

    // 桌面 Tab（無衝突機制，upsert）
    if ($entityType === 'desktop_tab') {
        try {
            $desktopUdid = $changeData['desktop_local_udid'] ?? '';
            if (!$desktopUdid) throw new Exception('缺少 desktop_local_udid');

            switch ($action) {
                case 'create':
                    $stmt = $db->prepare('SELECT server_id FROM desktop_tabs WHERE local_udid = ?');
                    $stmt->execute([$localUdid]);
                    $existing = $stmt->fetch();
                    if ($existing) {
                        $serverId = $existing['server_id'];
                        $db->prepare('UPDATE desktop_tabs SET title=?, icon=?, sort_order=?, desktop_type_code=?, mixed_vertical_columns=?, color_scheme_id=?, custom_bg_color=?, custom_primary_color=?, custom_secondary_color=?, custom_accent_color=?, custom_text_color=?, updated_at=? WHERE local_udid=?')
                           ->execute([
                               $changeData['title'] ?? 'Tab',
                               $changeData['icon'] ?? null,
                               (int)($changeData['sort_order'] ?? 0),
                               $changeData['desktop_type_code'] ?? 'single_column',
                               isset($changeData['mixed_vertical_columns']) ? (int)$changeData['mixed_vertical_columns'] : null,
                               isset($changeData['color_scheme_id']) ? (int)$changeData['color_scheme_id'] : null,
                               $changeData['custom_bg_color'] ?? null,
                               $changeData['custom_primary_color'] ?? null,
                               $changeData['custom_secondary_color'] ?? null,
                               $changeData['custom_accent_color'] ?? null,
                               $changeData['custom_text_color'] ?? null,
                               $changeData['updated_at'] ?? date('Y-m-d H:i:s'),
                               $localUdid,
                           ]);
                    } else {
                        $serverId = generateUUID();
                        $now = $changeData['created_at'] ?? date('Y-m-d H:i:s');
                        $db->prepare('INSERT INTO desktop_tabs (server_id, local_udid, desktop_local_udid, title, icon, sort_order, desktop_type_code, mixed_vertical_columns, color_scheme_id, custom_bg_color, custom_primary_color, custom_secondary_color, custom_accent_color, custom_text_color, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                           ->execute([
                               $serverId, $localUdid, $desktopUdid,
                               $changeData['title'] ?? 'Tab',
                               $changeData['icon'] ?? null,
                               (int)($changeData['sort_order'] ?? 0),
                               $changeData['desktop_type_code'] ?? 'single_column',
                               isset($changeData['mixed_vertical_columns']) ? (int)$changeData['mixed_vertical_columns'] : null,
                               isset($changeData['color_scheme_id']) ? (int)$changeData['color_scheme_id'] : null,
                               $changeData['custom_bg_color'] ?? null,
                               $changeData['custom_primary_color'] ?? null,
                               $changeData['custom_secondary_color'] ?? null,
                               $changeData['custom_accent_color'] ?? null,
                               $changeData['custom_text_color'] ?? null,
                               $now, $changeData['updated_at'] ?? $now,
                           ]);
                    }
                    $results[] = ['local_udid' => $localUdid, 'status' => 'success', 'server_id' => $serverId];
                    break;

                case 'delete':
                    $now = date('Y-m-d H:i:s');
                    $db->prepare('UPDATE desktop_tabs SET is_deleted = 1, deleted_at = ?, updated_at = ? WHERE local_udid = ?')
                       ->execute([$now, $now, $localUdid]);
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
                        $tabUdid = $changeData['tab_local_udid'] ?? null;
                        $db->prepare('INSERT INTO desktop_components (server_id, local_udid, desktop_local_udid, tab_local_udid, component_type_code, bg_color, border_color, border_width, corner_radius, config_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                           ->execute([
                               $serverId, $localUdid, $desktopUdid, $tabUdid,
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
                    $tabUdid      = $changeData['tab_local_udid'] ?? null;
                    if (!$desktopUdid || !$refLocalUdid) throw new Exception('缺少 desktop_local_udid 或 ref_local_udid');
                    if ($action === 'remove') {
                        if ($tabUdid) {
                            $db->prepare('DELETE FROM desktop_cells WHERE desktop_local_udid = ? AND tab_local_udid = ? AND ref_local_udid = ?')
                               ->execute([$desktopUdid, $tabUdid, $refLocalUdid]);
                        } else {
                            $db->prepare('DELETE FROM desktop_cells WHERE desktop_local_udid = ? AND tab_local_udid IS NULL AND ref_local_udid = ?')
                               ->execute([$desktopUdid, $refLocalUdid]);
                        }
                    } else {
                        $cellLocalUdid = $localUdid ?: generateUUID();
                        if ($tabUdid) {
                            $db->prepare('INSERT IGNORE INTO desktop_cells (local_udid, desktop_local_udid, tab_local_udid, ref_type, ref_local_udid, created_at) VALUES (?, ?, ?, ?, ?, NOW())')
                               ->execute([$cellLocalUdid, $desktopUdid, $tabUdid, $refType, $refLocalUdid]);
                        } else {
                            $db->prepare('INSERT IGNORE INTO desktop_cells (local_udid, desktop_local_udid, tab_local_udid, ref_type, ref_local_udid, created_at) VALUES (?, ?, NULL, ?, ?, NOW())')
                               ->execute([$cellLocalUdid, $desktopUdid, $refType, $refLocalUdid]);
                        }
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

$allowedActivityEvents = [
    'app_launch',
    'settings_change',
    'desktop_tab_created',
    'desktop_tab_updated',
    'desktop_tab_switched',
    'custom_note',
];
$defaultEventTitles = [
    'app_launch' => '開啟 App',
    'settings_change' => '修改設定',
    'desktop_tab_created' => '建立桌面 Tab',
    'desktop_tab_updated' => '編輯桌面 Tab',
    'desktop_tab_switched' => '切換桌面 Tab',
    'custom_note' => '自訂備註',
];
$allowedConsentStatuses = ['accepted', 'rejected', 'auto_applied'];

if (!empty($activityLogsPayload)) {
    if (count($activityLogsPayload) > 2000) {
        jsonError('activity_logs 單次不可超過 2000 筆', 429);
    }
    $now = new DateTime('now');
    $oldestAllowed = (clone $now)->modify('-35 days');

    foreach ($activityLogsPayload as $log) {
        $clientTempId = $log['client_temp_id'] ?? null;
        if (!$clientTempId) {
            $activityLogResults['failed'][] = ['client_temp_id' => null, 'error' => '缺少 client_temp_id'];
            continue;
        }

        $eventCode = $log['event_code'] ?? '';
        if (!in_array($eventCode, $allowedActivityEvents, true)) {
            $activityLogResults['failed'][] = ['client_temp_id' => $clientTempId, 'error' => 'event_code 不支援'];
            continue;
        }

        $occurredAtRaw = $log['occurred_at'] ?? null;
        try {
            $occurredAt = $occurredAtRaw ? new DateTime($occurredAtRaw) : null;
        } catch (Exception $e) {
            $occurredAt = null;
        }
        if (!$occurredAt) {
            $activityLogResults['failed'][] = ['client_temp_id' => $clientTempId, 'error' => 'occurred_at 格式錯誤'];
            continue;
        }
        if ($occurredAt < $oldestAllowed) {
            $activityLogResults['failed'][] = ['client_temp_id' => $clientTempId, 'error' => 'occurred_at 超出 30 天限制'];
            continue;
        }

        $expiresAtRaw = $log['expires_at'] ?? null;
        try {
            $expiresAt = $expiresAtRaw ? new DateTime($expiresAtRaw) : null;
        } catch (Exception $e) {
            $expiresAt = null;
        }
        if (!$expiresAt) {
            $expiresAt = (clone $occurredAt)->modify('+30 days');
        }

        $consentRequired = !empty($log['consent_required']);
        $consentStatus = $log['consent_status'] ?? 'accepted';
        if (!in_array($consentStatus, $allowedConsentStatuses, true)) {
            $activityLogResults['failed'][] = ['client_temp_id' => $clientTempId, 'error' => 'consent_status 不支援'];
            continue;
        }
        $consentDecidedAtRaw = $log['consent_decided_at'] ?? null;
        $consentDecidedAt = null;
        if ($consentDecidedAtRaw) {
            try {
                $consentDecidedAt = (new DateTime($consentDecidedAtRaw))->format('Y-m-d H:i:s');
            } catch (Exception $e) {
                $consentDecidedAt = null;
            }
        }

        $detailsJson = null;
        if (isset($log['details_json'])) {
            if (is_array($log['details_json'])) {
                $detailsJson = json_encode($log['details_json'], JSON_UNESCAPED_UNICODE);
            } elseif (is_string($log['details_json'])) {
                $decoded = json_decode($log['details_json'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $detailsJson = json_encode($decoded, JSON_UNESCAPED_UNICODE);
                }
            }
        }

        $actionTitle = $log['action_title'] ?? ($defaultEventTitles[$eventCode] ?? '操作紀錄');
        $changeSummary = $log['change_summary'] ?? $actionTitle;
        $desktopName = $log['desktop_name_snapshot'] ?? null;
        $tabName = $log['tab_name_snapshot'] ?? null;
        $customNote = $log['custom_note'] ?? null;
        $platformForLog = $log['platform'] ?? $platform;
        $deviceNameSnapshot = $log['device_name_snapshot'] ?? $deviceName;

        try {
            $stmt = $db->prepare('
                INSERT INTO activity_logs (
                    user_id, device_udid, platform, device_name_snapshot,
                    event_code, action_title,
                    desktop_local_udid, desktop_name_snapshot,
                    tab_local_udid, tab_name_snapshot,
                    details_json, change_summary, custom_note,
                    consent_required, consent_status, consent_decided_at,
                    occurred_at, expires_at, client_temp_id, created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
                )
                ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)
            ');
            $stmt->execute([
                $userId,
                $deviceUdid,
                $platformForLog,
                $deviceNameSnapshot,
                $eventCode,
                $actionTitle,
                $log['desktop_local_udid'] ?? null,
                $desktopName,
                $log['tab_local_udid'] ?? null,
                $tabName,
                $detailsJson,
                $changeSummary,
                $customNote,
                $consentRequired ? 1 : 0,
                $consentStatus,
                $consentDecidedAt,
                $occurredAt->format('Y-m-d H:i:s'),
                $expiresAt->format('Y-m-d H:i:s'),
                $clientTempId,
            ]);

            $activityLogResults['accepted'][] = [
                'client_temp_id' => $clientTempId,
                'occurred_at' => $occurredAt->format('Y-m-d H:i:s'),
            ];
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                $activityLogResults['accepted'][] = [
                    'client_temp_id' => $clientTempId,
                    'occurred_at' => $occurredAt->format('Y-m-d H:i:s'),
                ];
            } else {
                $activityLogResults['failed'][] = [
                    'client_temp_id' => $clientTempId,
                    'error' => $e->getMessage(),
                ];
            }
        }
    }
}

if ($deviceLogSettingsPayload) {
    $enabledEventsRaw = $deviceLogSettingsPayload['enabled_events'] ?? [];
    if (is_string($enabledEventsRaw)) {
        $decoded = json_decode($enabledEventsRaw, true);
        $enabledEventsRaw = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($enabledEventsRaw)) {
        $enabledEventsRaw = [];
    }
    $normalizedEvents = [];
    foreach ($allowedActivityEvents as $code) {
        $normalizedEvents[$code] = !empty($enabledEventsRaw[$code]);
    }

    $requireConsent = !empty($deviceLogSettingsPayload['require_consent']);
    $defaultConsent = $deviceLogSettingsPayload['default_consent'] === 'reject' ? 'reject' : 'accept';
    $deviceNamePayload = $deviceLogSettingsPayload['device_name'] ?? $deviceName ?? '';
    $platformPayload = $deviceLogSettingsPayload['platform'] ?? $platform;

    // log_view_filter：null 代表顯示全部，非 null 時為事件 code 陣列
    $logViewFilterRaw = $deviceLogSettingsPayload['log_view_filter'] ?? null;
    $logViewFilterJson = null;
    if (is_array($logViewFilterRaw)) {
        $logViewFilterJson = json_encode(array_values($logViewFilterRaw), JSON_UNESCAPED_UNICODE);
    }
    $lastUpdatedRaw = $deviceLogSettingsPayload['last_updated_at'] ?? null;
    try {
        $lastUpdatedAt = $lastUpdatedRaw ? new DateTime($lastUpdatedRaw) : new DateTime('now');
    } catch (Exception $e) {
        $lastUpdatedAt = new DateTime('now');
    }
    $lastUpdatedString = $lastUpdatedAt->format('Y-m-d H:i:s');

    $stmt = $db->prepare('SELECT last_updated_at FROM device_log_settings WHERE user_id = ? AND device_udid = ?');
    $stmt->execute([$userId, $deviceUdid]);
    $existingSettings = $stmt->fetch();

    if ($existingSettings && $existingSettings['last_updated_at'] >= $lastUpdatedString) {
        $deviceLogSettingsResult = [
            'status' => 'stale',
            'server_last_updated_at' => $existingSettings['last_updated_at'],
        ];
    } else {
        $stmt = $db->prepare('
            INSERT INTO device_log_settings (
                user_id, device_udid, platform, device_name,
                require_consent, default_consent, enabled_events, log_view_filter, last_updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                platform = VALUES(platform),
                device_name = VALUES(device_name),
                require_consent = VALUES(require_consent),
                default_consent = VALUES(default_consent),
                enabled_events = VALUES(enabled_events),
                log_view_filter = VALUES(log_view_filter),
                last_updated_at = VALUES(last_updated_at),
                updated_at = CURRENT_TIMESTAMP
        ');
        $stmt->execute([
            $userId,
            $deviceUdid,
            $platformPayload,
            $deviceNamePayload,
            $requireConsent ? 1 : 0,
            $defaultConsent,
            json_encode($normalizedEvents, JSON_UNESCAPED_UNICODE),
            $logViewFilterJson,
            $lastUpdatedString,
        ]);

        $deviceLogSettingsResult = [
            'status' => 'accepted',
            'server_last_updated_at' => $lastUpdatedString,
        ];

        $db->prepare('UPDATE devices SET device_name = ?, platform = ? WHERE id = ?')
           ->execute([$deviceNamePayload, $platformPayload, $deviceId]);
        $deviceName = $deviceNamePayload;
    }
} elseif (!$deviceLogSettingsPayload) {
    $deviceLogSettingsResult = [
        'status' => 'skipped',
    ];
}

// 更新裝置同步時間
$serverNow = $db->query("SELECT NOW()")->fetchColumn();
$db->prepare('UPDATE devices SET last_sync_at = ? WHERE id = ?')->execute([$serverNow, $deviceId]);

jsonSuccess([
    'results' => $results,
    'conflicts' => $conflicts,
    'has_conflicts' => !empty($conflicts),
    'server_now' => $serverNow,
    'activity_logs' => $activityLogResults,
    'device_log_settings' => $deviceLogSettingsResult,
]);
