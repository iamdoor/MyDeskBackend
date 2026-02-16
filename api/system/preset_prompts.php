<?php
/**
 * 取得 AI 預設問題
 * GET /api/system/preset_prompts.php
 * 參數: context_type (datasheet/desktop)
 */
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';

requireGet();
requireAuth();

$contextType = $_GET['context_type'] ?? '';
if (!in_array($contextType, ['cell', 'datasheet', 'desktop'])) {
    jsonError('context_type 必須為 cell、datasheet 或 desktop');
}

$db = getDB();

$stmt = $db->prepare('
    SELECT id, prompt_text, sort_order
    FROM ai_preset_prompts
    WHERE context_type = ? AND is_active = 1
    ORDER BY sort_order ASC
');
$stmt->execute([$contextType]);

jsonSuccess(['prompts' => $stmt->fetchAll()]);
