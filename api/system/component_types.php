<?php
/**
 * 取得組件類型列表
 * GET /api/system/component_types.php
 */
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';

requireGet();
requireAuth();

$db = getDB();
$stmt = $db->query('SELECT id, code, name, category, allowed_cell_types, is_active, sort_order FROM component_types WHERE is_active = 1 ORDER BY sort_order ASC');
$types = $stmt->fetchAll();

foreach ($types as &$type) {
    if (is_string($type['allowed_cell_types'])) {
        $decoded = json_decode($type['allowed_cell_types'], true);
        if ($decoded !== null) $type['allowed_cell_types'] = $decoded;
    }
}
unset($type);

jsonSuccess(['component_types' => $types]);
