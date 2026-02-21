<?php
/**
 * AI 更新桌面
 * POST /api/ai/desktop/update.php
 * 必填: local_udid
 * 選填: title, description, importance, desktop_type_code, mixed_vertical_columns,
 *       is_favorite, color_scheme_id, custom_*color, category_id, sub_category_id
 */
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/ai_helper.php';

requirePost();
$userId = requireAuth();
$data = getPostData();
requireFields($data, ['local_udid']);

$helper = new DesktopAIHelper($userId);

$allowedFields = [
    'title', 'description', 'importance', 'desktop_type_code',
    'mixed_vertical_columns', 'is_favorite',
    'color_scheme_id', 'custom_bg_color', 'custom_primary_color',
    'custom_secondary_color', 'custom_accent_color', 'custom_text_color',
    'category_id', 'sub_category_id',
];

$fields = [];
foreach ($allowedFields as $f) {
    if (array_key_exists($f, $data)) {
        $fields[$f] = $data[$f];
    }
}

if (empty($fields)) {
    jsonError('未提供更新欄位');
}

$ok = $helper->update($data['local_udid'], $fields);
if (!$ok) {
    jsonError('桌面不存在', 404);
}

jsonSuccess([], 'AI 更新成功');
