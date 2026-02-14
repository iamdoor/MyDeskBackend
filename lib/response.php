<?php
/**
 * API 回應輔助函數
 */

function jsonSuccess(array $data = [], string $message = 'success', int $httpCode = 200): void {
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['status' => 'success', 'message' => $message], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonError(string $message, int $httpCode = 400, array $extra = []): void {
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['status' => 'error', 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function requireMethod(string $method): void {
    if ($_SERVER['REQUEST_METHOD'] !== strtoupper($method)) {
        jsonError(strtoupper($method) . ' only', 405);
    }
}

function requirePost(): void {
    requireMethod('POST');
}

function requireGet(): void {
    requireMethod('GET');
}

/**
 * 取得 POST 參數（支援 form-data 和 JSON body）
 */
function getPostData(): array {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
    return $_POST;
}

/**
 * 驗證必填欄位
 */
function requireFields(array $data, array $fields): void {
    $missing = [];
    foreach ($fields as $field) {
        if (!isset($data[$field]) || (is_string($data[$field]) && trim($data[$field]) === '')) {
            $missing[] = $field;
        }
    }
    if (!empty($missing)) {
        jsonError('缺少必填欄位: ' . implode(', ', $missing));
    }
}
