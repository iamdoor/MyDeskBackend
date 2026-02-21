<?php
/**
 * 取得配色表列表
 * GET /api/system/color_schemes.php
 */
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';

requireGet();
requireAuth();

$db = getDB();
$stmt = $db->query('SELECT id, name, bg_color, primary_color, secondary_color, accent_color, text_color, sort_order FROM color_schemes WHERE is_active = 1 ORDER BY sort_order ASC');
$schemes = $stmt->fetchAll();

jsonSuccess(['color_schemes' => $schemes]);
