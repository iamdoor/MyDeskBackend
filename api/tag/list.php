<?php
/**
 * 列表所有 Tag
 * GET /api/tag/list.php
 */
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';

requireGet();
$userId = requireAuth();

$db = getDB();

$stmt = $db->prepare('SELECT id, local_udid, name, created_at FROM tags WHERE user_id = ? ORDER BY name ASC');
$stmt->execute([$userId]);

jsonSuccess(['tags' => $stmt->fetchAll()]);
