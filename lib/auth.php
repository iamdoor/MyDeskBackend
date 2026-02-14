<?php
/**
 * 身份驗證
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/config.php';

/**
 * 產生 token
 */
function generateToken(int $userId): string {
    $payload = [
        'user_id' => $userId,
        'expires' => time() + (TOKEN_EXPIRE_HOURS * 3600),
        'rand' => bin2hex(random_bytes(16)),
    ];
    $json = json_encode($payload);
    $signature = hash_hmac('sha256', $json, TOKEN_SECRET);
    return base64_encode($json) . '.' . $signature;
}

/**
 * 驗證 token，回傳 user_id
 */
function verifyToken(string $token): int {
    $parts = explode('.', $token);
    if (count($parts) !== 2) {
        jsonError('無效的 token', 401);
    }

    [$encodedPayload, $signature] = $parts;
    $json = base64_decode($encodedPayload);
    if ($json === false) {
        jsonError('無效的 token', 401);
    }

    $expectedSig = hash_hmac('sha256', $json, TOKEN_SECRET);
    if (!hash_equals($expectedSig, $signature)) {
        jsonError('無效的 token', 401);
    }

    $payload = json_decode($json, true);
    if (!$payload || !isset($payload['user_id'], $payload['expires'])) {
        jsonError('無效的 token', 401);
    }

    if (time() > $payload['expires']) {
        jsonError('Token 已過期', 403);
    }

    return (int) $payload['user_id'];
}

/**
 * 從請求中取得已驗證的 user_id
 * 支援 Header: Authorization: Bearer <token>
 * 或 POST/GET 參數 token
 */
function requireAuth(): int {
    $token = null;

    // 優先從 Header 取
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
        $token = $matches[1];
    }

    // 其次從參數取
    if (!$token) {
        $token = $_GET['token'] ?? $_POST['token'] ?? null;
    }

    // 也支援 JSON body
    if (!$token) {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') !== false) {
            $raw = file_get_contents('php://input');
            $data = json_decode($raw, true);
            $token = $data['token'] ?? null;
        }
    }

    if (!$token) {
        jsonError('未提供 token', 401);
    }

    return verifyToken($token);
}

/**
 * 產生 UUID v4
 */
function generateUUID(): string {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}
