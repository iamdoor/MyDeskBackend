<?php
/**
 * AI 建立資料單
 * POST /api/ai/datasheet/create.php
 * 參數: local_udid, title
 * 選填: description, importance, category_id, sub_category_id, tags
 */
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/ai_helper.php';

requirePost();
$userId = requireAuth();
$data = getPostData();
requireFields($data, ['local_udid', 'title']);

$helper = new DataSheetAIHelper($userId);

$tags = $data['tags'] ?? [];
if (is_string($tags)) $tags = json_decode($tags, true) ?: [];

$result = $helper->create(
    $data['local_udid'],
    $data['title'],
    $data['description'] ?? null,
    (int) ($data['importance'] ?? 0),
    $data['category_id'] ?? null,
    $data['sub_category_id'] ?? null,
    $tags
);

jsonSuccess($result, 'AI 建立成功', 201);
