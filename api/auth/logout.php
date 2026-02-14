<?php
/**
 * 登出
 * POST /api/auth/logout.php
 * 參數: token, device_udid (選填，清除裝置 push_token)
 */
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';

requirePost();
$userId = requireAuth();
$data = getPostData();

// 清除裝置 push_token
if (!empty($data['device_udid'])) {
    $db = getDB();
    $stmt = $db->prepare('UPDATE devices SET push_token = NULL, updated_at = NOW() WHERE user_id = ? AND device_udid = ?');
    $stmt->execute([$userId, $data['device_udid']]);
}

jsonSuccess([], '已登出');
