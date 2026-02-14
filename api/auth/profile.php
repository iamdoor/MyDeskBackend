<?php
/**
 * 取得 / 更新個人資料
 * GET  /api/auth/profile.php — 取得
 * POST /api/auth/profile.php — 更新 (email, password)
 */
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';

$userId = requireAuth();
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->prepare('SELECT id, username, email, status, created_at, updated_at FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        jsonError('使用者不存在', 404);
    }

    jsonSuccess(['user' => $user]);

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = getPostData();
    $updates = [];
    $params = [];

    if (!empty($data['email'])) {
        // 檢查信箱是否已被使用
        $stmt = $db->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
        $stmt->execute([trim($data['email']), $userId]);
        if ($stmt->fetch()) {
            jsonError('此信箱已被使用');
        }
        $updates[] = 'email = ?';
        $params[] = trim($data['email']);
    }

    if (!empty($data['password'])) {
        if (strlen($data['password']) < 6) {
            jsonError('密碼至少 6 個字元');
        }
        $updates[] = 'password_hash = ?';
        $params[] = password_hash($data['password'], PASSWORD_BCRYPT);
    }

    if (empty($updates)) {
        jsonError('未提供更新欄位');
    }

    $params[] = $userId;
    $sql = 'UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?';
    $db->prepare($sql)->execute($params);

    jsonSuccess([], '更新成功');

} else {
    jsonError('GET 或 POST only', 405);
}
