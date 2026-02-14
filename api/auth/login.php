<?php
/**
 * 登入
 * POST /api/auth/login.php
 * 參數: username (或 email), password
 * 選填: device_udid, device_name, platform
 */
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';

requirePost();
$data = getPostData();
requireFields($data, ['password']);

if (empty($data['username']) && empty($data['email'])) {
    jsonError('請提供 username 或 email');
}

$db = getDB();
$login = trim($data['username'] ?? $data['email'] ?? '');
$password = $data['password'];

$stmt = $db->prepare('SELECT id, username, email, password_hash, status FROM users WHERE username = ? OR email = ?');
$stmt->execute([$login, $login]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
    jsonError('帳號或密碼錯誤', 401);
}

if ($user['status'] !== 'active') {
    jsonError('帳號已被停用', 403);
}

$token = generateToken((int) $user['id']);

// 註冊裝置（如果有提供）
$deviceId = null;
if (!empty($data['device_udid'])) {
    $deviceUdid = $data['device_udid'];
    $deviceName = $data['device_name'] ?? '';
    $platform = $data['platform'] ?? 'ios';

    $stmt = $db->prepare('SELECT id FROM devices WHERE user_id = ? AND device_udid = ?');
    $stmt->execute([$user['id'], $deviceUdid]);
    $existing = $stmt->fetch();

    if ($existing) {
        $deviceId = (int) $existing['id'];
        $stmt = $db->prepare('UPDATE devices SET device_name = ?, platform = ?, push_token = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$deviceName, $platform, $data['push_token'] ?? null, $deviceId]);
    } else {
        $stmt = $db->prepare('INSERT INTO devices (user_id, device_udid, device_name, platform, push_token) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$user['id'], $deviceUdid, $deviceName, $platform, $data['push_token'] ?? null]);
        $deviceId = (int) $db->lastInsertId();
    }
}

jsonSuccess([
    'user_id' => (int) $user['id'],
    'username' => $user['username'],
    'email' => $user['email'],
    'token' => $token,
    'device_id' => $deviceId,
]);
