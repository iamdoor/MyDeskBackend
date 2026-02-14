<?php
/**
 * AI 更新資料單
 * POST /api/ai/datasheet/update.php
 * 參數: local_udid
 * 選填: title, description, importance, category_id, sub_category_id
 */
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/ai_helper.php';

requirePost();
$userId = requireAuth();
$data = getPostData();
requireFields($data, ['local_udid']);

$helper = new DataSheetAIHelper($userId);

$fields = [];
foreach (['title', 'description', 'importance', 'category_id', 'sub_category_id'] as $f) {
    if (array_key_exists($f, $data)) {
        $fields[$f] = $data[$f];
    }
}

if (empty($fields)) {
    jsonError('未提供更新欄位');
}

$ok = $helper->update($data['local_udid'], $fields);
if (!$ok) {
    jsonError('資料單不存在', 404);
}

jsonSuccess([], 'AI 更新成功');
