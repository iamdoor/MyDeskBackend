<?php
/**
 * AI Function Calling 工具定義 + 分派器
 */
require_once __DIR__ . '/ai_helper.php';
require_once __DIR__ . '/db.php';

/**
 * 取得 OpenAI tools JSON Schema 陣列
 * @param string $contextType 'cell' 或 'datasheet'
 */
function getAITools(string $contextType): array {
    $webTools = [
        [
            'type' => 'function',
            'function' => [
                'name' => 'searchWeb',
                'description' => '搜尋網路資料，回傳標題、摘要、連結（及可能的圖片 URL）。建立 Cell 前先用此工具查詢資料。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => '搜尋關鍵字'],
                        'num_results' => ['type' => 'integer', 'description' => '回傳筆數（預設 5，最多 10）'],
                    ],
                    'required' => ['query'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'uploadMediaFromUrl',
                'description' => '將網路圖片或影片 URL 下載並上傳至 DriveServer，回傳 drive_udid 供建立 Cell 使用。適用於搜尋到含圖片/影片的結果後，把媒體存入 Cell（type=2 圖片/type=3 影片/type=4 音訊）。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'url' => ['type' => 'string', 'description' => '圖片或影片的完整 https URL'],
                        'filename' => ['type' => 'string', 'description' => '儲存檔名（選填，會自動從 URL 或 Content-Disposition 推斷）'],
                    ],
                    'required' => ['url'],
                ],
            ],
        ],
    ];

    $cellTools = [
        [
            'type' => 'function',
            'function' => [
                'name' => 'createCell',
                'description' => '建立一個新的 Cell（資料單元）。cell_type 對應：1=文字, 2=圖片連結, 3=影片連結, 4=音訊連結, 5=相簿, 6=清單(checklist), 7=JSON文字, 8=位置, 9=提醒, 10=便利貼, 11=網址, 12=Scheme連結, 13=資料夾連結, 14=程式碼, 15=HTML, 16=表格, 17=參照, 18=檔案連結。content_json 格式依類型而異，例如：文字={text:"內容"}, 清單={items:[{text:"項目",is_checked:false}]}, 網址={url:"https://..."}, 位置={latitude:25.0,longitude:121.5,address:"地址"}, 提醒={remind_at:"2025-01-01 09:00:00",note:"備註"}, 程式碼={language:"python",code:"print(1)"}, 表格={headers:["欄1"],rows:[["值"]]}。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'cell_type' => ['type' => 'integer', 'description' => 'Cell 類型代碼 (1~18)'],
                        'title' => ['type' => 'string', 'description' => 'Cell 標題'],
                        'description' => ['type' => 'string', 'description' => 'Cell 描述（選填）'],
                        'importance' => ['type' => 'integer', 'description' => '重要度 0~5（選填，預設 0）'],
                        'content_json' => ['type' => 'object', 'description' => 'Cell 內容 JSON（依 cell_type 而異）'],
                        'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '標籤陣列（選填）'],
                    ],
                    'required' => ['cell_type', 'title'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'updateCell',
                'description' => '更新指定的 Cell。可更新 cell_type、title、description、importance、content_json 等欄位。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'local_udid' => ['type' => 'string', 'description' => 'Cell 的 local_udid'],
                        'cell_type' => ['type' => 'integer', 'description' => '新的 Cell 類型代碼'],
                        'title' => ['type' => 'string', 'description' => '新標題'],
                        'description' => ['type' => 'string', 'description' => '新描述'],
                        'importance' => ['type' => 'integer', 'description' => '新重要度 0~5'],
                        'content_json' => ['type' => 'object', 'description' => '新的內容 JSON'],
                    ],
                    'required' => ['local_udid'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'deleteCell',
                'description' => '軟刪除指定的 Cell。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'local_udid' => ['type' => 'string', 'description' => 'Cell 的 local_udid'],
                    ],
                    'required' => ['local_udid'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'getCell',
                'description' => '取得指定 Cell 的完整資訊。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'local_udid' => ['type' => 'string', 'description' => 'Cell 的 local_udid'],
                    ],
                    'required' => ['local_udid'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'listCells',
                'description' => '列出使用者的 Cell 清單。可依類型篩選。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'cell_type' => ['type' => 'integer', 'description' => '篩選特定 Cell 類型（選填）'],
                    ],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'addTagToCell',
                'description' => '為指定 Cell 加上標籤。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'local_udid' => ['type' => 'string', 'description' => 'Cell 的 local_udid'],
                        'tag_name' => ['type' => 'string', 'description' => '標籤名稱'],
                    ],
                    'required' => ['local_udid', 'tag_name'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'removeTagFromCell',
                'description' => '移除指定 Cell 的標籤。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'local_udid' => ['type' => 'string', 'description' => 'Cell 的 local_udid'],
                        'tag_name' => ['type' => 'string', 'description' => '標籤名稱'],
                    ],
                    'required' => ['local_udid', 'tag_name'],
                ],
            ],
        ],
    ];

    $dataSheetTools = [
        [
            'type' => 'function',
            'function' => [
                'name' => 'createDataSheet',
                'description' => '建立一個新的資料單。資料單是 Cell 的集合，類似播放清單。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string', 'description' => '資料單標題'],
                        'description' => ['type' => 'string', 'description' => '資料單描述（選填）'],
                        'importance' => ['type' => 'integer', 'description' => '重要度 0~5（選填，預設 0）'],
                        'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '標籤陣列（選填）'],
                    ],
                    'required' => ['title'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'updateDataSheet',
                'description' => '更新指定的資料單。可更新 title、description、importance 等欄位。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'local_udid' => ['type' => 'string', 'description' => '資料單的 local_udid'],
                        'title' => ['type' => 'string', 'description' => '新標題'],
                        'description' => ['type' => 'string', 'description' => '新描述'],
                        'importance' => ['type' => 'integer', 'description' => '新重要度 0~5'],
                    ],
                    'required' => ['local_udid'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'deleteDataSheet',
                'description' => '軟刪除指定的資料單。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'local_udid' => ['type' => 'string', 'description' => '資料單的 local_udid'],
                    ],
                    'required' => ['local_udid'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'getDataSheet',
                'description' => '取得指定資料單的完整資訊。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'local_udid' => ['type' => 'string', 'description' => '資料單的 local_udid'],
                    ],
                    'required' => ['local_udid'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'listDataSheets',
                'description' => '列出使用者的資料單清單。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => new stdClass(),
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'addTagToDataSheet',
                'description' => '為指定資料單加上標籤。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'local_udid' => ['type' => 'string', 'description' => '資料單的 local_udid'],
                        'tag_name' => ['type' => 'string', 'description' => '標籤名稱'],
                    ],
                    'required' => ['local_udid', 'tag_name'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'removeTagFromDataSheet',
                'description' => '移除指定資料單的標籤。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'local_udid' => ['type' => 'string', 'description' => '資料單的 local_udid'],
                        'tag_name' => ['type' => 'string', 'description' => '標籤名稱'],
                    ],
                    'required' => ['local_udid', 'tag_name'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'addCellToSheet',
                'description' => '將一個 Cell 加入到資料單中。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'sheet_local_udid' => ['type' => 'string', 'description' => '資料單的 local_udid'],
                        'cell_local_udid' => ['type' => 'string', 'description' => 'Cell 的 local_udid'],
                    ],
                    'required' => ['sheet_local_udid', 'cell_local_udid'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'removeCellFromSheet',
                'description' => '從資料單中移除一個 Cell。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'sheet_local_udid' => ['type' => 'string', 'description' => '資料單的 local_udid'],
                        'cell_local_udid' => ['type' => 'string', 'description' => 'Cell 的 local_udid'],
                    ],
                    'required' => ['sheet_local_udid', 'cell_local_udid'],
                ],
            ],
        ],
    ];

    if ($contextType === 'cell') {
        return array_merge($webTools, $cellTools);
    }
    // datasheet: 包含全部工具
    return array_merge($webTools, $cellTools, $dataSheetTools);
}

/**
 * 執行 AI 工具呼叫
 * @return array 執行結果
 */
function executeAITool(string $name, array $args, int $userId,
                       string $userToken = '', string $username = ''): array {
    logAIToolDebug($userId, $name, $args);

    $cellHelper = new CellAIHelper($userId);
    $dsHelper = new DataSheetAIHelper($userId);

    switch ($name) {
        // === 網路工具 ===
        case 'searchWeb':
            return aiToolSearchWeb($args);

        case 'uploadMediaFromUrl':
            return aiToolUploadMediaFromUrl($args, $userToken, $username);
        // === Cell 工具 ===
        case 'createCell':
            $cellType = (int) ($args['cell_type'] ?? 1);
            $content = buildCellContent($cellType, $args);
            if (requiresContent($cellType) && $content === null) {
                return ['success' => false, 'error' => 'content_json 必須包含實際內容'];
            }

            $localUdid = $args['local_udid'] ?? generateUUID();
            $result = $cellHelper->create(
                $localUdid,
                $cellType,
                $args['title'] ?? '',
                $args['description'] ?? null,
                (int) ($args['importance'] ?? 0),
                $content,
                $args['tags'] ?? []
            );
            return ['success' => true, 'action' => '已建立 Cell', 'data' => $result];

        case 'updateCell':
            $fields = [];
            foreach (['cell_type', 'title', 'description', 'importance', 'content_json'] as $f) {
                if (array_key_exists($f, $args)) $fields[$f] = $args[$f];
            }
            if (!empty($fields['content_json']) || isset($args['content'])) {
                $cellType = isset($fields['cell_type']) ? (int) $fields['cell_type'] : null;
                $built = buildCellContent($cellType, $args);
                if ($built !== null) {
                    $fields['content_json'] = $built;
                }
            }
            $ok = $cellHelper->update($args['local_udid'], $fields);
            return $ok
                ? ['success' => true, 'action' => '已更新 Cell']
                : ['success' => false, 'error' => 'Cell 不存在'];

        case 'deleteCell':
            $ok = $cellHelper->delete($args['local_udid']);
            return $ok
                ? ['success' => true, 'action' => '已刪除 Cell']
                : ['success' => false, 'error' => 'Cell 不存在'];

        case 'getCell':
            $cell = $cellHelper->get($args['local_udid']);
            return $cell
                ? ['success' => true, 'data' => $cell]
                : ['success' => false, 'error' => 'Cell 不存在'];

        case 'listCells':
            $filters = [];
            if (isset($args['cell_type'])) $filters['cell_type'] = $args['cell_type'];
            $cells = $cellHelper->list($filters);
            return ['success' => true, 'data' => ['cells' => $cells, 'count' => count($cells)]];

        case 'addTagToCell':
            $ok = $cellHelper->addTag($args['local_udid'], $args['tag_name']);
            return $ok
                ? ['success' => true, 'action' => '已加上標籤']
                : ['success' => false, 'error' => 'Cell 不存在'];

        case 'removeTagFromCell':
            $ok = $cellHelper->removeTag($args['local_udid'], $args['tag_name']);
            return $ok
                ? ['success' => true, 'action' => '已移除標籤']
                : ['success' => false, 'error' => '找不到標籤或 Cell'];

        // === DataSheet 工具 ===
        case 'createDataSheet':
            $localUdid = generateUUID();
            $result = $dsHelper->create(
                $localUdid,
                $args['title'] ?? '',
                $args['description'] ?? null,
                (int) ($args['importance'] ?? 0),
                null, null,
                $args['tags'] ?? []
            );
            return ['success' => true, 'action' => '已建立資料單', 'data' => $result];

        case 'updateDataSheet':
            $fields = [];
            foreach (['title', 'description', 'importance'] as $f) {
                if (array_key_exists($f, $args)) $fields[$f] = $args[$f];
            }
            $ok = $dsHelper->update($args['local_udid'], $fields);
            return $ok
                ? ['success' => true, 'action' => '已更新資料單']
                : ['success' => false, 'error' => '資料單不存在'];

        case 'deleteDataSheet':
            $ok = $dsHelper->delete($args['local_udid']);
            return $ok
                ? ['success' => true, 'action' => '已刪除資料單']
                : ['success' => false, 'error' => '資料單不存在'];

        case 'getDataSheet':
            $ds = $dsHelper->get($args['local_udid']);
            return $ds
                ? ['success' => true, 'data' => $ds]
                : ['success' => false, 'error' => '資料單不存在'];

        case 'listDataSheets':
            $sheets = $dsHelper->list();
            return ['success' => true, 'data' => ['data_sheets' => $sheets, 'count' => count($sheets)]];

        case 'addTagToDataSheet':
            $ok = $dsHelper->addTag($args['local_udid'], $args['tag_name']);
            return $ok
                ? ['success' => true, 'action' => '已加上標籤']
                : ['success' => false, 'error' => '資料單不存在'];

        case 'removeTagFromDataSheet':
            $ok = $dsHelper->removeTag($args['local_udid'], $args['tag_name']);
            return $ok
                ? ['success' => true, 'action' => '已移除標籤']
                : ['success' => false, 'error' => '找不到標籤或資料單'];

        case 'addCellToSheet':
            $ok = $dsHelper->addCellToSheet($args['sheet_local_udid'], $args['cell_local_udid']);
            return $ok
                ? ['success' => true, 'action' => '已將 Cell 加入資料單']
                : ['success' => false, 'error' => '資料單不存在'];

        case 'removeCellFromSheet':
            $ok = $dsHelper->removeCellFromSheet($args['sheet_local_udid'], $args['cell_local_udid']);
            return $ok
                ? ['success' => true, 'action' => '已從資料單移除 Cell']
                : ['success' => false, 'error' => '資料單或 Cell 不存在'];

        default:
            return ['success' => false, 'error' => "未知的工具: $name"];
    }
}

function logAIToolDebug(int $userId, string $toolName, array $args): void {
    static $tableEnsured = false;

    try {
        $db = getDB();

        if (!$tableEnsured) {
            $db->exec('
                CREATE TABLE IF NOT EXISTS ai_tool_debug (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id INT UNSIGNED NOT NULL,
                    tool_name VARCHAR(100) NOT NULL,
                    payload_json LONGTEXT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_user (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ');
            $tableEnsured = true;
        }

        $payload = json_encode($args, JSON_UNESCAPED_UNICODE);
        $stmt = $db->prepare('INSERT INTO ai_tool_debug (user_id, tool_name, payload_json) VALUES (?, ?, ?)');
        $stmt->execute([$userId, $toolName, $payload]);
    } catch (Throwable $e) {
        // Debug table is optional; swallow errors to avoid影響主流程
    }
}

function requiresContent(int $cellType): bool {
    $textTypes = [
        1,  // text
        7,  // json text
        10, // sticky note
        14, // code
        15, // html
        16, // table
    ];
    return in_array($cellType, $textTypes, true);
}

/**
 * searchWeb：用 Serper.dev 搜尋 Google 結果
 */
function aiToolSearchWeb(array $args): array {
    $apiKey = defined('SERPER_API_KEY') ? SERPER_API_KEY : '';
    if (empty($apiKey)) {
        return ['success' => false, 'error' => '伺服器未設定 SERPER_API_KEY'];
    }

    $query = trim($args['query'] ?? '');
    if ($query === '') {
        return ['success' => false, 'error' => '搜尋關鍵字不可為空'];
    }

    $numResults = min(10, max(1, (int) ($args['num_results'] ?? 5)));

    $payload = json_encode(['q' => $query, 'num' => $numResults], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://google.serper.dev/search');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'X-API-KEY: ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['success' => false, 'error' => "網路錯誤: $curlError"];
    }
    if ($httpCode !== 200) {
        return ['success' => false, 'error' => "Serper API 錯誤 (HTTP $httpCode)"];
    }

    $data = json_decode($response, true);
    if (!$data) {
        return ['success' => false, 'error' => '搜尋結果解析失敗'];
    }

    $results = [];

    // knowledgeGraph 摘要（若有）
    if (!empty($data['knowledgeGraph'])) {
        $kg = $data['knowledgeGraph'];
        $results[] = [
            'title' => $kg['title'] ?? '',
            'snippet' => $kg['description'] ?? '',
            'link' => $kg['website'] ?? '',
            'imageUrl' => $kg['imageUrl'] ?? null,
        ];
    }

    // 一般搜尋結果
    foreach ($data['organic'] ?? [] as $item) {
        if (count($results) >= $numResults) break;
        $results[] = [
            'title' => $item['title'] ?? '',
            'snippet' => $item['snippet'] ?? '',
            'link' => $item['link'] ?? '',
            'imageUrl' => $item['imageUrl'] ?? null,
        ];
    }

    // 移除 imageUrl 為 null 的欄位，保持結果乾淨
    $results = array_map(function (array $r): array {
        if ($r['imageUrl'] === null) unset($r['imageUrl']);
        return $r;
    }, $results);

    return [
        'success' => true,
        'results' => $results,
        'count' => count($results),
    ];
}

/**
 * uploadMediaFromUrl：下載圖片/影片後上傳至 DriveServer，回傳 drive_udid
 */
function aiToolUploadMediaFromUrl(array $args, string $userToken, string $username): array {
    $url = trim($args['url'] ?? '');

    if (!preg_match('#^https?://#i', $url)) {
        return ['success' => false, 'error' => 'URL 必須以 https:// 開頭'];
    }

    // 1. 取得 HEAD 資訊：Content-Type 與 Content-Length
    $chHead = curl_init($url);
    curl_setopt_array($chHead, [
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'MyDesk-AI/1.0',
    ]);
    curl_exec($chHead);
    $mimeType = curl_getinfo($chHead, CURLINFO_CONTENT_TYPE);
    $contentLength = (int) curl_getinfo($chHead, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
    $finalUrl = curl_getinfo($chHead, CURLINFO_EFFECTIVE_URL);
    curl_close($chHead);

    // 清理 MIME（去掉 charset 等附加資訊）
    $mimeType = strtolower(trim(explode(';', $mimeType ?? '')[0]));

    $allowedMimePrefixes = ['image/', 'video/', 'audio/', 'application/pdf', 'application/octet-stream'];
    $allowed = false;
    foreach ($allowedMimePrefixes as $prefix) {
        if (str_starts_with($mimeType, $prefix)) {
            $allowed = true;
            break;
        }
    }
    if (!$allowed) {
        return ['success' => false, 'error' => "不支援的媒體類型: $mimeType（僅支援 image/video/audio/pdf）"];
    }

    $maxBytes = 20 * 1024 * 1024; // 20 MB
    if ($contentLength > $maxBytes) {
        return ['success' => false, 'error' => '檔案超過 20MB 限制'];
    }

    // 2. 推斷檔名
    $filename = trim($args['filename'] ?? '');
    if ($filename === '') {
        $urlPath = parse_url($finalUrl ?: $url, PHP_URL_PATH) ?? '';
        $filename = basename($urlPath);
        if ($filename === '' || !preg_match('/\.[a-z0-9]{2,5}$/i', $filename)) {
            // 從 MIME 推斷副檔名
            $ext = explode('/', $mimeType)[1] ?? 'bin';
            $ext = preg_replace('/[^a-z0-9]/', '', $ext);
            $filename = 'media_' . time() . '.' . $ext;
        }
    }

    // 3. 下載檔案到暫存路徑
    $tmpFile = tempnam(sys_get_temp_dir(), 'mydesk_media_');
    $fh = fopen($tmpFile, 'wb');

    $downloaded = 0;
    $chGet = curl_init($finalUrl ?: $url);
    curl_setopt_array($chGet, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'MyDesk-AI/1.0',
        CURLOPT_WRITEFUNCTION => function ($ch, $data) use ($fh, &$downloaded, $maxBytes) {
            $downloaded += strlen($data);
            if ($downloaded > $maxBytes) {
                return -1; // abort
            }
            fwrite($fh, $data);
            return strlen($data);
        },
    ]);

    curl_exec($chGet);
    $curlError = curl_error($chGet);
    curl_close($chGet);
    fclose($fh);

    if ($curlError && strpos($curlError, 'Failed writing body') === false) {
        @unlink($tmpFile);
        return ['success' => false, 'error' => "下載失敗: $curlError"];
    }

    if ($downloaded > $maxBytes) {
        @unlink($tmpFile);
        return ['success' => false, 'error' => '檔案超過 20MB 限制，已中止下載'];
    }

    if ($downloaded === 0) {
        @unlink($tmpFile);
        return ['success' => false, 'error' => '下載內容為空'];
    }

    // 4. 上傳至 DriveServer
    $driveUrl = rtrim(DRIVE_BASE_URL, '/') . '/api/upload.php';

    $postFields = [
        'account'  => $username,
        'token'    => $userToken,
        'filename' => $filename,
        'file'     => new CURLFile($tmpFile, $mimeType, $filename),
    ];

    $chUp = curl_init($driveUrl);
    curl_setopt_array($chUp, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
    ]);

    $upResponse = curl_exec($chUp);
    $upHttpCode = curl_getinfo($chUp, CURLINFO_HTTP_CODE);
    $upCurlError = curl_error($chUp);
    curl_close($chUp);
    @unlink($tmpFile);

    if ($upCurlError) {
        return ['success' => false, 'error' => "上傳失敗: $upCurlError"];
    }

    $upData = json_decode($upResponse, true);
    if (!$upData) {
        return ['success' => false, 'error' => "DriveServer 回應解析失敗 (HTTP $upHttpCode): $upResponse"];
    }

    $status = $upData['status'] ?? $upData['result'] ?? '';
    if (!in_array($status, ['success', 'ok'], true)) {
        $msg = $upData['message'] ?? $upData['error'] ?? json_encode($upData, JSON_UNESCAPED_UNICODE);
        return ['success' => false, 'error' => "DriveServer 上傳失敗: $msg"];
    }

    $driveUdid = $upData['udid'] ?? $upData['drive_udid'] ?? '';
    $savedFilename = $upData['filename'] ?? $filename;

    // 根據 MIME 給 AI 提示建立哪種 Cell
    $cellTypeHint = 18; // 檔案
    if (str_starts_with($mimeType, 'image/')) $cellTypeHint = 2;
    elseif (str_starts_with($mimeType, 'video/')) $cellTypeHint = 3;
    elseif (str_starts_with($mimeType, 'audio/')) $cellTypeHint = 4;

    return [
        'success'   => true,
        'drive_udid' => $driveUdid,
        'filename'  => $savedFilename,
        'mime_type' => $mimeType,
        'hint'      => "請用 createCell(cell_type=$cellTypeHint, content_json={\"drive_udid\":\"$driveUdid\",\"url\":\"\",\"mime_type\":\"$mimeType\"}) 建立媒體 Cell",
    ];
}

function buildCellContent(?int $cellType, array $args) {
    $content = $args['content_json'] ?? null;
    if ($content !== null) {
        if (is_string($content)) {
            $decoded = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        } elseif (is_array($content)) {
            return $content;
        }
    }

    $text = $args['content'] ?? $args['text'] ?? null;
    if (!is_string($text)) {
        return null;
    }
    $text = trim($text);
    if ($text === '') {
        return null;
    }

    $type = $cellType ?? (int) ($args['cell_type'] ?? 1);
    switch ($type) {
        case 1: // text
            return ['plain_text' => $text];
        case 7: // json text
            return ['json_content' => $text];
        case 10: // sticky note
            return ['text' => $text];
        case 14: // code
            $lang = $args['language'] ?? '';
            return ['code' => $text, 'language' => $lang];
        case 15: // html
            return ['html' => $text];
        case 16: // table
            return ['markdown_table' => $text];
        default:
            return ['text' => $text];
    }
}
