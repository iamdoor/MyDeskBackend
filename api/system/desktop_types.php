<?php
/**
 * 取得桌面類型列表
 * GET /api/system/desktop_types.php
 */
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';

requireGet();
requireAuth();

$db = getDB();
$stmt = $db->query('SELECT id, code, name, max_columns, config_schema, is_active, sort_order FROM desktop_types WHERE is_active = 1 ORDER BY sort_order ASC');
$types = $stmt->fetchAll();

foreach ($types as &$type) {
    if (is_string($type['config_schema'])) {
        $decoded = json_decode($type['config_schema'], true);
        if ($decoded !== null) $type['config_schema'] = $decoded;
    }
}
unset($type);

jsonSuccess(['desktop_types' => $types]);
