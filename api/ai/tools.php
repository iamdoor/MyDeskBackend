<?php
/**
 * 取得 AI Function Calling 工具定義
 * GET /api/ai/tools.php
 * 參數: context_type (cell/datasheet)
 */
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/ai_tools.php';

requireGet();
requireAuth();

$contextType = $_GET['context_type'] ?? '';
if (!in_array($contextType, ['cell', 'datasheet'])) {
    jsonError('context_type 必須為 cell 或 datasheet');
}

$tools = getAITools($contextType);
jsonSuccess(['tools' => $tools]);
