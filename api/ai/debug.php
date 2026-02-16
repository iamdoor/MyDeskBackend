<?php
/**
 * AI 模組診斷腳本（測試完請刪除）
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json; charset=utf-8');

$checks = [];

// 1. 檢查必要檔案
$files = [
    'lib/response.php',
    'lib/db.php',
    'lib/auth.php',
    'lib/config.php',
    'lib/sync_helper.php',
    'lib/ai_tools.php',
    'lib/ai_helper.php',
    'lib/category_helper.php',
    'lib/tag_helper.php',
];

$base = realpath(__DIR__ . '/../../');
foreach ($files as $f) {
    $full = $base . '/' . $f;
    $checks['files'][$f] = file_exists($full) ? 'OK' : 'MISSING';
}

// 2. 嘗試 require
try {
    require_once $base . '/lib/response.php';
    require_once $base . '/lib/db.php';
    require_once $base . '/lib/auth.php';
    require_once $base . '/lib/config.php';
    require_once $base . '/lib/sync_helper.php';
    $checks['require_base'] = 'OK';
} catch (Throwable $e) {
    $checks['require_base'] = $e->getMessage();
}

try {
    require_once $base . '/lib/ai_tools.php';
    $checks['require_ai_tools'] = 'OK';
} catch (Throwable $e) {
    $checks['require_ai_tools'] = $e->getMessage();
}

// 3. 檢查 DB 連線 + AI 表
try {
    $db = getDB();
    $checks['db_connection'] = 'OK';

    $tables = ['ai_conversations', 'ai_messages', 'ai_preset_prompts'];
    foreach ($tables as $t) {
        try {
            $db->query("SELECT 1 FROM `$t` LIMIT 1");
            $checks['tables'][$t] = 'OK';
        } catch (Throwable $e) {
            $checks['tables'][$t] = $e->getMessage();
        }
    }
} catch (Throwable $e) {
    $checks['db_connection'] = $e->getMessage();
}

// 4. 檢查 OpenAI Key
$checks['openai_key'] = defined('OPENAI_API_KEY') && OPENAI_API_KEY !== ''
    ? 'SET (' . strlen(OPENAI_API_KEY) . ' chars)'
    : 'EMPTY';

// 5. 檢查 PHP 版本
$checks['php_version'] = PHP_VERSION;

echo json_encode($checks, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
