<?php
/**
 * 查詢 Activity Log
 * POST /api/activity_log/query.php
 *
 * 參數：
 * - token：system_config.activity_log_api_token 內設定的服務 token
 * - start_at / end_at：YYYY-MM-DD HH:MM:SS，區間不可超過 31 天
 * - event_codes (array, optional)：限定事件
 * - feature_filters.desktop_local_udid (optional)
 * - limit (<= 500, default 200)
 * - cursor（base64，伺服器回傳，用於下一頁）
 */
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/db.php';

requirePost();
$data = getPostData();
requireFields($data, ['token', 'start_at', 'end_at']);

$db = getDB();

$stmt = $db->prepare('SELECT config_value FROM system_config WHERE config_key = ? LIMIT 1');
$stmt->execute(['activity_log_api_token']);
$configRow = $stmt->fetchColumn();

if (!$configRow) {
    jsonError('未設定 activity_log_api_token', 403);
}

$decodedConfig = json_decode($configRow, true);
if (json_last_error() === JSON_ERROR_NONE && is_array($decodedConfig)) {
    $expectedToken = $decodedConfig['token'] ?? $decodedConfig['api_token'] ?? null;
} else {
    $expectedToken = $configRow;
}

if (!$expectedToken || !hash_equals($expectedToken, $data['token'])) {
    jsonError('token 無效', 403);
}

try {
    $startAt = new DateTime($data['start_at']);
    $endAt = new DateTime($data['end_at']);
} catch (Exception $e) {
    jsonError('時間格式錯誤', 400);
}

if ($startAt > $endAt) {
    jsonError('start_at 需早於 end_at', 400);
}

$maxRangeStart = (clone $endAt)->modify('-31 days');
if ($startAt < $maxRangeStart) {
    jsonError('查詢區間不得超過 31 天', 400);
}

$allowedEvents = [
    'app_launch',
    'settings_change',
    'desktop_tab_created',
    'desktop_tab_updated',
    'desktop_tab_switched',
    'custom_note',
];

$eventCodes = $data['event_codes'] ?? [];
if (is_string($eventCodes)) {
    $decodedEvents = json_decode($eventCodes, true);
    $eventCodes = is_array($decodedEvents) ? $decodedEvents : [];
}
if (!is_array($eventCodes)) {
    $eventCodes = [];
}
$eventCodes = array_values(array_intersect($eventCodes, $allowedEvents));

$featureFilters = $data['feature_filters'] ?? [];
if (is_string($featureFilters)) {
    $decodedFilters = json_decode($featureFilters, true);
    $featureFilters = is_array($decodedFilters) ? $decodedFilters : [];
}
$desktopFilter = $featureFilters['desktop_local_udid'] ?? null;

$limit = isset($data['limit']) ? (int) $data['limit'] : 200;
if ($limit <= 0) { $limit = 200; }
$limit = min($limit, 500);
$fetchLimit = $limit + 1;

$cursor = $data['cursor'] ?? null;
$cursorCondition = '';
$cursorParams = [];
if ($cursor) {
    $decodedCursor = json_decode(base64_decode($cursor), true);
    if (is_array($decodedCursor) && isset($decodedCursor['occurred_at'], $decodedCursor['id'])) {
        $cursorCondition = ' AND (al.occurred_at > ? OR (al.occurred_at = ? AND al.id > ?))';
        $cursorParams = [
            $decodedCursor['occurred_at'],
            $decodedCursor['occurred_at'],
            (int) $decodedCursor['id'],
        ];
    }
}

$sql = '
    SELECT
        al.id, al.user_id, al.device_udid, al.platform,
        al.device_name_snapshot, al.event_code, al.action_title,
        al.desktop_local_udid, al.desktop_name_snapshot,
        al.tab_local_udid, al.tab_name_snapshot,
        al.details_json, al.change_summary, al.custom_note,
        al.consent_required, al.consent_status, al.consent_decided_at,
        al.occurred_at, al.expires_at, al.client_temp_id,
        al.created_at, al.updated_at,
        u.username
    FROM activity_logs al
    INNER JOIN users u ON u.id = al.user_id
    WHERE al.occurred_at BETWEEN ? AND ?
';
$params = [
    $startAt->format('Y-m-d H:i:s'),
    $endAt->format('Y-m-d H:i:s'),
];

if (!empty($eventCodes)) {
    $placeholders = implode(',', array_fill(0, count($eventCodes), '?'));
    $sql .= " AND al.event_code IN ($placeholders)";
    $params = array_merge($params, $eventCodes);
}

if ($desktopFilter) {
    $sql .= ' AND al.desktop_local_udid = ?';
    $params[] = $desktopFilter;
}

if ($cursorCondition) {
    $sql .= $cursorCondition;
    $params = array_merge($params, $cursorParams);
}

$sql .= ' ORDER BY al.occurred_at ASC, al.id ASC LIMIT ' . (int) $fetchLimit;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$nextCursor = null;
if (count($rows) === $fetchLimit) {
    $lastRow = array_pop($rows);
    $nextCursor = base64_encode(json_encode([
        'occurred_at' => $lastRow['occurred_at'],
        'id' => (int) $lastRow['id'],
    ]));
}

foreach ($rows as &$row) {
    if (!empty($row['details_json'])) {
        $decoded = json_decode($row['details_json'], true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $row['details_json'] = $decoded;
        }
    }
    $row['consent_required'] = (int) $row['consent_required'];
}
unset($row);

jsonSuccess([
    'data' => $rows,
    'next_cursor' => $nextCursor,
]);
