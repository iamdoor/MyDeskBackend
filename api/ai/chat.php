<?php
/**
 * AI 對話端點（後端代理模式）
 * POST /api/ai/chat.php
 * 參數: user_message, context_type (cell/datasheet), context_local_udid
 * 選填: conversation_local_udid（空則自動建立）
 */
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/sync_helper.php';
require_once __DIR__ . '/../../lib/ai_tools.php';

requirePost();
$userId = requireAuth();
$data = getPostData();
requireFields($data, ['user_message', 'context_type', 'context_local_udid']);

if (empty(OPENAI_API_KEY)) {
    jsonError('伺服器未設定 OpenAI API Key', 500);
}

$contextType = $data['context_type'];
if (!in_array($contextType, ['cell', 'datasheet'])) {
    jsonError('context_type 必須為 cell 或 datasheet');
}

$db = getDB();
$contextLocalUdid = $data['context_local_udid'];
$userMessage = $data['user_message'];
$referencedUdids = [];
if (isset($data['referenced_udids'])) {
    if (is_string($data['referenced_udids'])) {
        $decoded = json_decode($data['referenced_udids'], true);
        if (is_array($decoded)) {
            $referencedUdids = array_values(array_filter(array_map('strval', $decoded)));
        }
    } elseif (is_array($data['referenced_udids'])) {
        $referencedUdids = array_values(array_filter(array_map('strval', $data['referenced_udids'])));
    }
}

// 1. 取得或建立 conversation
$convLocalUdid = $data['conversation_local_udid'] ?? '';
$convId = null;

if (!empty($convLocalUdid)) {
    $stmt = $db->prepare('SELECT id FROM ai_conversations WHERE user_id = ? AND local_udid = ?');
    $stmt->execute([$userId, $convLocalUdid]);
    $conv = $stmt->fetch();
    if ($conv) $convId = (int) $conv['id'];
}

if ($convId === null) {
    $convLocalUdid = generateUUID();
    $convServerId = generateUUID();
    $stmt = $db->prepare('
        INSERT INTO ai_conversations (server_id, local_udid, user_id, context_type, context_local_udid)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([$convServerId, $convLocalUdid, $userId, $contextType, $contextLocalUdid]);
    $convId = (int) $db->lastInsertId();

    writeSyncLog($userId, null, 'ai_conversation', $convServerId, $convLocalUdid, 'create', [
        'context_type' => $contextType,
        'context_local_udid' => $contextLocalUdid,
    ]);
}

// 2. 儲存 user message
$userMsgServerId = generateUUID();
$userMsgLocalUdid = generateUUID();
$stmt = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM ai_messages WHERE conversation_id = ?');
$stmt->execute([$convId]);
$sortOrder = (int) $stmt->fetchColumn();
$referencedJson = !empty($referencedUdids) ? json_encode($referencedUdids, JSON_UNESCAPED_UNICODE) : null;

$db->prepare('
    INSERT INTO ai_messages (server_id, local_udid, conversation_id, role, content, referenced_udids, sort_order)
    VALUES (?, ?, ?, ?, ?, ?, ?)
')->execute([$userMsgServerId, $userMsgLocalUdid, $convId, 'user', $userMessage, $referencedJson, $sortOrder]);

// 3. 載入對話歷史
$stmt = $db->prepare('SELECT role, content FROM ai_messages WHERE conversation_id = ? ORDER BY sort_order ASC');
$stmt->execute([$convId]);
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 4. 取得目標物件資訊
$cellHelper = new CellAIHelper($userId);
$sheetHelper = new DataSheetAIHelper($userId);

$contextInfo = '';
if ($contextType === 'cell') {
    $obj = $cellHelper->get($contextLocalUdid);
    if ($obj) {
        $contextInfo = "目前正在操作的 Cell 資訊：\n" . json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
} else {
    $obj = $sheetHelper->get($contextLocalUdid);
    if ($obj) {
        $contextInfo = "目前正在操作的資料單資訊：\n" . json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}

// 5. 組裝 system prompt
$systemPrompt = "你是 MyDesk 的 AI 助手，幫助使用者管理 Cell（資料單元）和資料單。\n";
$systemPrompt .= "你可以使用提供的工具來建立、更新、刪除、查詢 Cell 和資料單，以及管理標籤。\n";
$systemPrompt .= "每次建立/更新任一包含文字、程式碼或 JSON 內容的 Cell 時，都要在 content_json 中寫入實際內容：\n";
$systemPrompt .= "- 文字 Cell → {plain_text: \"完整內容\"}\n";
$systemPrompt .= "- 程式碼 Cell → {code: \"程式碼\", language: \"語言\"}\n";
$systemPrompt .= "- 便利貼/HTML/JSON/表格等也要填對應欄位，絕對不要留空。\n";
$systemPrompt .= "請用繁體中文回覆。操作完成後，簡要告知使用者結果。\n";
if ($contextInfo) {
    $systemPrompt .= "\n" . $contextInfo;
}
$referenceContext = buildReferenceContext($referencedUdids, $cellHelper, $sheetHelper);
if ($referenceContext) {
    $systemPrompt .= "\n使用者另外提供以下資料供參考：\n" . $referenceContext;
}

// 6. 組裝 messages
$messages = [['role' => 'system', 'content' => $systemPrompt]];
foreach ($history as $msg) {
    $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
}

// 7. 取得 tools
$tools = getAITools($contextType);

// 8. Function calling 迴圈
$toolResults = [];
$maxIterations = 10;

for ($i = 0; $i < $maxIterations; $i++) {
    $response = callOpenAI($messages, $tools);

    if (isset($response['error'])) {
        jsonError('OpenAI 錯誤: ' . ($response['error']['message'] ?? '未知'), 502);
    }

    $choice = $response['choices'][0] ?? null;
    if (!$choice) {
        jsonError('OpenAI 無回覆', 502);
    }

    $assistantMsg = $choice['message'];
    $messages[] = $assistantMsg;

    // 檢查是否有 tool_calls
    if (!empty($assistantMsg['tool_calls'])) {
        foreach ($assistantMsg['tool_calls'] as $toolCall) {
            $funcName = $toolCall['function']['name'];
            $funcArgs = json_decode($toolCall['function']['arguments'], true) ?? [];

            $result = executeAITool($funcName, $funcArgs, $userId);
            $toolResults[] = ['tool' => $funcName, 'args' => $funcArgs, 'result' => $result];

            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => $toolCall['id'],
                'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
            ];
        }
        continue; // 繼續迴圈讓 LLM 處理 tool 結果
    }

    // 沒有 tool_calls，取得最終回覆
    $assistantContent = $assistantMsg['content'] ?? '';
    break;
}

if (!isset($assistantContent)) {
    $assistantContent = '抱歉，處理過程超過最大迭代次數。';
}

// 9. 儲存 assistant message
$assistantServerId = generateUUID();
$assistantLocalUdid = generateUUID();
$sortOrder++;

$db->prepare('
    INSERT INTO ai_messages (server_id, local_udid, conversation_id, role, content, referenced_udids, sort_order)
    VALUES (?, ?, ?, ?, ?, NULL, ?)
')->execute([$assistantServerId, $assistantLocalUdid, $convId, 'assistant', $assistantContent, $sortOrder]);

// 更新 conversation updated_at
$db->prepare('UPDATE ai_conversations SET updated_at = NOW() WHERE id = ?')->execute([$convId]);

// 10. 回傳結果
jsonSuccess([
    'conversation_local_udid' => $convLocalUdid,
    'assistant_message' => $assistantContent,
    'assistant_message_local_udid' => $assistantLocalUdid,
    'user_message_local_udid' => $userMsgLocalUdid,
    'tool_results' => $toolResults,
]);

// === Helper ===

function callOpenAI(array $messages, array $tools): array {
    $payload = [
        'model' => OPENAI_MODEL,
        'messages' => $messages,
    ];
    if (!empty($tools)) {
        $payload['tools'] = $tools;
    }

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENAI_API_KEY,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['error' => ['message' => "cURL 錯誤: $error"]];
    }

    $result = json_decode($response, true);
    if (!$result) {
        return ['error' => ['message' => "OpenAI 回應解析失敗 (HTTP $httpCode)"]];
    }

    return $result;
}

function buildReferenceContext(array $keys, CellAIHelper $cellHelper, DataSheetAIHelper $sheetHelper): string {
    if (empty($keys)) return '';
    $sections = [];
    foreach ($keys as $key) {
        $parsed = parseReferenceKey($key);
        if (!$parsed) continue;
        [$kind, $udid] = $parsed;
        if ($kind === 'cell') {
            $cell = $cellHelper->get($udid);
            if ($cell) {
                $sections[] = formatCellContext($cell);
            }
        } elseif ($kind === 'datasheet') {
            $sheet = $sheetHelper->get($udid);
            if ($sheet) {
                $sections[] = formatSheetContext($sheet);
            }
        }
    }
    return implode("\n---\n", $sections);
}

function parseReferenceKey(string $key): ?array {
    $parts = explode('|', $key, 2);
    if (count($parts) !== 2) {
        return null;
    }
    $kind = $parts[0];
    if (!in_array($kind, ['cell', 'datasheet'], true)) {
        return null;
    }
    return [$kind, $parts[1]];
}

function formatCellContext(array $cell): string {
    $title = trim($cell['title'] ?? '') ?: ($cell['local_udid'] ?? '');
    $lines = ["Cell：$title"];
    if (!empty($cell['description'])) {
        $lines[] = '描述：' . $cell['description'];
    }
    if (!empty($cell['content_json'])) {
        $content = $cell['content_json'];
        if (is_string($content)) {
            $decoded = json_decode($content, true);
            $content = $decoded ?: $content;
        }
        if (is_array($content)) {
            $lines[] = '內容：' . json_encode($content, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } else {
            $lines[] = '內容：' . $content;
        }
    }
    return implode("\n", $lines);
}

function formatSheetContext(array $sheet): string {
    $title = trim($sheet['title'] ?? '') ?: ($sheet['local_udid'] ?? '');
    $lines = ["資料單：$title"];
    if (!empty($sheet['description'])) {
        $lines[] = '描述：' . $sheet['description'];
    }
    return implode("\n", $lines);
}
