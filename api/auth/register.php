<?php
/**
 * 註冊帳號
 * POST /api/auth/register.php
 * 參數: username, email, password
 */
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';

requirePost();
$data = getPostData();
requireFields($data, ['username', 'email', 'password']);

$username = trim($data['username']);
$email = trim($data['email']);
$password = $data['password'];

if (strlen($password) < 6) {
    jsonError('密碼至少 6 個字元');
}

$db = getDB();

// 檢查重複
$stmt = $db->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
$stmt->execute([$username, $email]);
if ($stmt->fetch()) {
    jsonError('帳號或信箱已被使用');
}

$hash = password_hash($password, PASSWORD_BCRYPT);

$stmt = $db->prepare('INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)');
$stmt->execute([$username, $email, $hash]);

$userId = (int) $db->lastInsertId();
$token = generateToken($userId);

jsonSuccess([
    'user_id' => $userId,
    'username' => $username,
    'token' => $token,
], '註冊成功', 201);
