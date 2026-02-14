<?php
/**
 * AI 更新 Cell
 * POST /api/ai/cell/update.php
 * 參數: local_udid
 * 選填: cell_type, title, description, importance, content_json
 */
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/ai_helper.php';

requirePost();
$userId = requireAuth();
$data = getPostData();
requireFields($data, ['local_udid']);

$helper = new CellAIHelper($userId);

$fields = [];
foreach (['cell_type', 'title', 'description', 'importance', 'content_json'] as $f) {
    if (array_key_exists($f, $data)) {
        $fields[$f] = $data[$f];
    }
}

if (empty($fields)) {
    jsonError('未提供更新欄位');
}

$ok = $helper->update($data['local_udid'], $fields);
if (!$ok) {
    jsonError('Cell 不存在', 404);
}

jsonSuccess([], 'AI 更新成功');
