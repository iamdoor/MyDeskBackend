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
        return $cellTools;
    }
    // datasheet: 包含全部工具
    return array_merge($cellTools, $dataSheetTools);
}

/**
 * 執行 AI 工具呼叫
 * @return array 執行結果
 */
function executeAITool(string $name, array $args, int $userId): array {
    logAIToolDebug($userId, $name, $args);

    $cellHelper = new CellAIHelper($userId);
    $dsHelper = new DataSheetAIHelper($userId);

    switch ($name) {
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
