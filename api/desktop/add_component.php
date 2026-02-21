<?php
/**
 * 新增組件到桌面
 * POST /api/desktop/add_component.php
 * 必填: desktop_local_udid, component_local_udid, component_type_code, config_json
 * 選填: bg_color, border_color, border_width, corner_radius
 */
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/sync_helper.php';

requirePost();
$userId = requireAuth();
$data = getPostData();
requireFields($data, ['desktop_local_udid', 'component_local_udid', 'component_type_code', 'config_json']);

$db = getDB();
$desktopLocalUdid = $data['desktop_local_udid'];
$componentLocalUdid = $data['component_local_udid'];

// 驗證桌面歸屬
$stmt = $db->prepare('SELECT id FROM desktops WHERE user_id = ? AND local_udid = ? AND is_deleted = 0');
$stmt->execute([$userId, $desktopLocalUdid]);
if (!$stmt->fetch()) jsonError('桌面不存在', 404);

// 檢查組件 local_udid 是否重複
$stmt = $db->prepare('SELECT id FROM desktop_components WHERE local_udid = ?');
$stmt->execute([$componentLocalUdid]);
if ($stmt->fetch()) jsonError('component_local_udid 已存在');

$configJson = $data['config_json'];
if (is_array($configJson)) $configJson = json_encode($configJson, JSON_UNESCAPED_UNICODE);

$serverId = generateUUID();

$stmt = $db->prepare('
    INSERT INTO desktop_components (server_id, local_udid, desktop_local_udid, component_type_code,
        bg_color, border_color, border_width, corner_radius, config_json)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
');
$stmt->execute([
    $serverId,
    $componentLocalUdid,
    $desktopLocalUdid,
    $data['component_type_code'],
    $data['bg_color'] ?? null,
    $data['border_color'] ?? null,
    (int) ($data['border_width'] ?? 0),
    (int) ($data['corner_radius'] ?? 0),
    $configJson,
]);

$componentId = (int) $db->lastInsertId();

writeSyncLog($userId, null, 'desktop_component', $serverId, $componentLocalUdid, 'create', [
    'server_id' => $serverId,
    'local_udid' => $componentLocalUdid,
    'desktop_local_udid' => $desktopLocalUdid,
    'component_type_code' => $data['component_type_code'],
]);

jsonSuccess([
    'component_id' => $componentId,
    'server_id' => $serverId,
    'local_udid' => $componentLocalUdid,
], '組件建立成功', 201);
