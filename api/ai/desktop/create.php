<?php
/**
 * AI 建立桌面
 * POST /api/ai/desktop/create.php
 * 參數: local_udid, title
 * 選填: ui_type, description, importance, tags, category_id, sub_category_id
 */
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/ai_helper.php';

requirePost();
$userId = requireAuth();
$data = getPostData();
requireFields($data, ['local_udid', 'title']);

$helper = new DesktopAIHelper($userId);

$tags = $data['tags'] ?? [];
if (is_string($tags)) $tags = json_decode($tags, true) ?: [];

$result = $helper->create(
    $data['local_udid'],
    $data['title'],
    $data['ui_type'] ?? 'list',
    $data['description'] ?? null,
    (int) ($data['importance'] ?? 0),
    $tags,
    $data['category_id'] ?? null,
    $data['sub_category_id'] ?? null
);

jsonSuccess($result, 'AI 建立成功', 201);
