<?php
/**
 * AI 建立 Cell
 * POST /api/ai/cell/create.php
 * 參數: local_udid, cell_type, title
 * 選填: description, importance, content_json, tags
 */
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/ai_helper.php';

requirePost();
$userId = requireAuth();
$data = getPostData();
requireFields($data, ['local_udid', 'cell_type', 'title']);

$helper = new CellAIHelper($userId);

$contentJson = $data['content_json'] ?? null;
$tags = $data['tags'] ?? [];
if (is_string($tags)) $tags = json_decode($tags, true) ?: [];

$result = $helper->create(
    $data['local_udid'],
    (int) $data['cell_type'],
    $data['title'],
    $data['description'] ?? null,
    (int) ($data['importance'] ?? 0),
    $contentJson,
    $tags
);

jsonSuccess($result, 'AI 建立成功', 201);
