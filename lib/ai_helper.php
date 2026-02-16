<?php
/**
 * AI Helper 類別
 * 提供給 AI Agent 操作 Cell、資料單、桌面的函數
 */
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/sync_helper.php';
require_once __DIR__ . '/category_helper.php';
require_once __DIR__ . '/tag_helper.php';

class CellAIHelper {
    /** @var PDO */
    private $db;
    /** @var int */
    private $userId;

    public function __construct(int $userId) {
        $this->db = getDB();
        $this->userId = $userId;
    }

    public function create(string $localUdid, int $cellType, string $title, ?string $description = null, int $importance = 0, $contentJson = null, array $tags = []): array {
        $serverId = generateUUID();

        if (is_array($contentJson)) {
            $contentJson = json_encode($contentJson, JSON_UNESCAPED_UNICODE);
        }

        $stmt = $this->db->prepare('
            INSERT INTO cells (server_id, local_udid, user_id, cell_type, title, description, importance, content_json, ai_edited, ai_edited_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
        ');
        $stmt->execute([$serverId, $localUdid, $this->userId, $cellType, $title, $description, $importance, $contentJson]);

        $cellId = (int) $this->db->lastInsertId();

        // 處理 tags
        foreach ($tags as $tagName) {
            $this->addTag($localUdid, $tagName);
        }

        writeSyncLog($this->userId, null, 'cell', $serverId, $localUdid, 'create', [
            'server_id' => $serverId, 'cell_type' => $cellType, 'title' => $title, 'ai_created' => true,
        ]);

        return ['cell_id' => $cellId, 'server_id' => $serverId, 'local_udid' => $localUdid];
    }

    public function update(string $localUdid, array $fields): bool {
        $stmt = $this->db->prepare('SELECT id, server_id FROM cells WHERE user_id = ? AND local_udid = ? AND is_deleted = 0');
        $stmt->execute([$this->userId, $localUdid]);
        $cell = $stmt->fetch();
        if (!$cell) return false;

        $allowed = ['cell_type', 'title', 'description', 'importance', 'content_json'];
        $updates = ['ai_edited = 1', 'ai_edited_at = NOW()'];
        $params = [];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $fields)) {
                $value = $fields[$field];
                if ($field === 'content_json' && is_array($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                }
                $updates[] = "`$field` = ?";
                $params[] = $value;
            }
        }

        $params[] = $cell['id'];
        $this->db->prepare('UPDATE cells SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($params);

        writeSyncLog($this->userId, null, 'cell', $cell['server_id'], $localUdid, 'update', array_merge($fields, ['ai_edited' => true]));

        return true;
    }

    public function delete(string $localUdid): bool {
        $stmt = $this->db->prepare('SELECT id, server_id FROM cells WHERE user_id = ? AND local_udid = ? AND is_deleted = 0');
        $stmt->execute([$this->userId, $localUdid]);
        $cell = $stmt->fetch();
        if (!$cell) return false;

        $this->db->prepare('UPDATE cells SET is_deleted = 1, deleted_at = NOW(), ai_edited = 1, ai_edited_at = NOW() WHERE id = ?')->execute([$cell['id']]);
        writeSyncLog($this->userId, null, 'cell', $cell['server_id'], $localUdid, 'delete', ['ai_deleted' => true]);

        return true;
    }

    public function get(string $localUdid): ?array {
        $stmt = $this->db->prepare('SELECT * FROM cells WHERE user_id = ? AND local_udid = ? AND is_deleted = 0');
        $stmt->execute([$this->userId, $localUdid]);
        $cell = $stmt->fetch();
        if (!$cell) return null;

        if ($cell['content_json']) {
            $cell['content_json'] = json_decode($cell['content_json'], true);
        }
        return $cell;
    }

    public function list(array $filters = []): array {
        $where = ['user_id = ?', 'is_deleted = 0'];
        $params = [$this->userId];

        if (isset($filters['cell_type'])) {
            $where[] = 'cell_type = ?';
            $params[] = (int) $filters['cell_type'];
        }

        $stmt = $this->db->prepare('SELECT * FROM cells WHERE ' . implode(' AND ', $where) . ' ORDER BY updated_at DESC LIMIT 100');
        $stmt->execute($params);
        $cells = $stmt->fetchAll();

        foreach ($cells as &$c) {
            if ($c['content_json']) $c['content_json'] = json_decode($c['content_json'], true);
        }
        return $cells;
    }

    public function addTag(string $localUdid, string $tagName): bool {
        $tagName = trim($tagName);
        if ($tagName === '') return false;

        $stmt = $this->db->prepare('SELECT id FROM cells WHERE user_id = ? AND local_udid = ?');
        $stmt->execute([$this->userId, $localUdid]);
        $cell = $stmt->fetch();
        if (!$cell) return false;

        $tagLocalUdid = ensureTagLocalUdid($this->db, $this->userId, $tagName);
        $this->db->prepare('INSERT IGNORE INTO cell_tags (cell_id, tag_local_udid) VALUES (?, ?)')->execute([$cell['id'], $tagLocalUdid]);
        return true;
    }

    public function removeTag(string $localUdid, string $tagName): bool {
        $stmt = $this->db->prepare('
            DELETE ct FROM cell_tags ct
            INNER JOIN cells c ON c.id = ct.cell_id
            INNER JOIN tags t ON t.local_udid = ct.tag_local_udid
            WHERE c.user_id = ? AND c.local_udid = ? AND t.name = ?
        ');
        $stmt->execute([$this->userId, $localUdid, $tagName]);
        return $stmt->rowCount() > 0;
    }
}

class DataSheetAIHelper {
    /** @var PDO */
    private $db;
    /** @var int */
    private $userId;

    public function __construct(int $userId) {
        $this->db = getDB();
        $this->userId = $userId;
    }

    public function create(string $localUdid, string $title, ?string $description = null, int $importance = 0, $categoryIdentifier = null, $subCategoryIdentifier = null, array $tags = []): array {
        $serverId = generateUUID();

        $categoryLocalUdid = null;
        if ($categoryIdentifier !== null && $categoryIdentifier !== '') {
            $category = findCategory($this->db, $this->userId, $categoryIdentifier, 'datasheet');
            if (!$category) {
                jsonError('分類不存在', 404);
            }
            $categoryLocalUdid = $category['local_udid'];
        }

        $subCategoryLocalUdid = null;
        if ($subCategoryIdentifier !== null && $subCategoryIdentifier !== '') {
            $sub = findSubCategory($this->db, $this->userId, $subCategoryIdentifier);
            if (!$sub) {
                jsonError('子分類不存在', 404);
            }
            $parent = findCategory($this->db, $this->userId, $sub['category_id'], 'datasheet');
            if (!$parent) {
                jsonError('分類不存在', 404);
            }
            if ($categoryLocalUdid && $parent['local_udid'] !== $categoryLocalUdid) {
                jsonError('子分類不屬於指定的大分類');
            }
            $categoryLocalUdid = $categoryLocalUdid ?: $parent['local_udid'];
            $subCategoryLocalUdid = $sub['local_udid'];
        }

        $stmt = $this->db->prepare('
            INSERT INTO data_sheets (server_id, local_udid, user_id, title, description, importance, category_id, sub_category_id, ai_edited, ai_edited_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
        ');
        $stmt->execute([$serverId, $localUdid, $this->userId, $title, $description, $importance, $categoryLocalUdid, $subCategoryLocalUdid]);

        $sheetId = (int) $this->db->lastInsertId();

        foreach ($tags as $tagName) {
            $this->addTag($localUdid, $tagName);
        }

        writeSyncLog($this->userId, null, 'datasheet', $serverId, $localUdid, 'create', [
            'server_id' => $serverId, 'title' => $title, 'ai_created' => true,
        ]);

        return ['data_sheet_id' => $sheetId, 'server_id' => $serverId, 'local_udid' => $localUdid];
    }

    public function update(string $localUdid, array $fields): bool {
        $stmt = $this->db->prepare('SELECT id, server_id FROM data_sheets WHERE user_id = ? AND local_udid = ? AND is_deleted = 0');
        $stmt->execute([$this->userId, $localUdid]);
        $sheet = $stmt->fetch();
        if (!$sheet) return false;

        $allowed = ['title', 'description', 'importance', 'category_id', 'sub_category_id'];
        $updates = ['ai_edited = 1', 'ai_edited_at = NOW()'];
        $params = [];

        $categoryLocalUdid = null;

        if (array_key_exists('category_id', $fields)) {
            $value = $fields['category_id'];
            if ($value === null || $value === '') {
                $updates[] = '`category_id` = NULL';
                $updates[] = '`sub_category_id` = NULL';
            } else {
                $category = findCategory($this->db, $this->userId, $value, 'datasheet');
                if (!$category) {
                    jsonError('分類不存在', 404);
                }
                $categoryLocalUdid = $category['local_udid'];
                $updates[] = '`category_id` = ?';
                $params[] = $categoryLocalUdid;
            }
            unset($fields['category_id']);
        }

        if (array_key_exists('sub_category_id', $fields)) {
            $value = $fields['sub_category_id'];
            if ($value === null || $value === '') {
                $updates[] = '`sub_category_id` = NULL';
            } else {
                $sub = findSubCategory($this->db, $this->userId, $value);
                if (!$sub) {
                    jsonError('子分類不存在', 404);
                }
                $parent = findCategory($this->db, $this->userId, $sub['category_id'], 'datasheet');
                if (!$parent) {
                    jsonError('分類不存在', 404);
                }
                if ($categoryLocalUdid !== null && $parent['local_udid'] !== $categoryLocalUdid) {
                    jsonError('子分類不屬於指定的大分類');
                }
                if ($categoryLocalUdid === null) {
                    $categoryLocalUdid = $parent['local_udid'];
                    $updates[] = '`category_id` = ?';
                    $params[] = $categoryLocalUdid;
                }
                $updates[] = '`sub_category_id` = ?';
                $params[] = $sub['local_udid'];
            }
            unset($fields['sub_category_id']);
        }

        foreach ($allowed as $field) {
            if (array_key_exists($field, $fields)) {
                $updates[] = "`$field` = ?";
                $params[] = $fields[$field];
            }
        }

        $params[] = $sheet['id'];
        $this->db->prepare('UPDATE data_sheets SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($params);

        writeSyncLog($this->userId, null, 'datasheet', $sheet['server_id'], $localUdid, 'update', array_merge($fields, ['ai_edited' => true]));
        return true;
    }

    public function delete(string $localUdid): bool {
        $stmt = $this->db->prepare('SELECT id, server_id FROM data_sheets WHERE user_id = ? AND local_udid = ? AND is_deleted = 0');
        $stmt->execute([$this->userId, $localUdid]);
        $sheet = $stmt->fetch();
        if (!$sheet) return false;

        $this->db->prepare('UPDATE data_sheets SET is_deleted = 1, deleted_at = NOW(), ai_edited = 1, ai_edited_at = NOW() WHERE id = ?')->execute([$sheet['id']]);
        writeSyncLog($this->userId, null, 'datasheet', $sheet['server_id'], $localUdid, 'delete', ['ai_deleted' => true]);
        return true;
    }

    public function get(string $localUdid): ?array {
        $stmt = $this->db->prepare('SELECT * FROM data_sheets WHERE user_id = ? AND local_udid = ? AND is_deleted = 0');
        $stmt->execute([$this->userId, $localUdid]);
        return $stmt->fetch() ?: null;
    }

    public function list(array $filters = []): array {
        $where = ['user_id = ?', 'is_deleted = 0'];
        $params = [$this->userId];

        $stmt = $this->db->prepare('SELECT * FROM data_sheets WHERE ' . implode(' AND ', $where) . ' ORDER BY updated_at DESC LIMIT 100');
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function addTag(string $localUdid, string $tagName): bool {
        $tagName = trim($tagName);
        if ($tagName === '') return false;

        $stmt = $this->db->prepare('SELECT id FROM data_sheets WHERE user_id = ? AND local_udid = ?');
        $stmt->execute([$this->userId, $localUdid]);
        $sheet = $stmt->fetch();
        if (!$sheet) return false;

        $tagLocalUdid = ensureTagLocalUdid($this->db, $this->userId, $tagName);
        $this->db->prepare('INSERT IGNORE INTO data_sheet_tags (data_sheet_id, tag_local_udid) VALUES (?, ?)')->execute([$sheet['id'], $tagLocalUdid]);
        return true;
    }

    public function removeTag(string $localUdid, string $tagName): bool {
        $stmt = $this->db->prepare('
            DELETE dst FROM data_sheet_tags dst
            INNER JOIN data_sheets ds ON ds.id = dst.data_sheet_id
            INNER JOIN tags t ON t.local_udid = dst.tag_local_udid
            WHERE ds.user_id = ? AND ds.local_udid = ? AND t.name = ?
        ');
        $stmt->execute([$this->userId, $localUdid, $tagName]);
        return $stmt->rowCount() > 0;
    }

    public function addCellToSheet(string $sheetLocalUdid, string $cellLocalUdid, ?int $sortOrder = null): bool {
        $stmt = $this->db->prepare('SELECT id FROM data_sheets WHERE user_id = ? AND local_udid = ? AND is_deleted = 0');
        $stmt->execute([$this->userId, $sheetLocalUdid]);
        $sheet = $stmt->fetch();
        if (!$sheet) return false;

        if ($sortOrder === null) {
            $stmt = $this->db->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM data_sheet_cells WHERE data_sheet_local_udid = ?');
            $stmt->execute([$sheetLocalUdid]);
            $sortOrder = (int) $stmt->fetchColumn();
        }

        $this->db->prepare('INSERT IGNORE INTO data_sheet_cells (data_sheet_local_udid, cell_local_udid, sort_order) VALUES (?, ?, ?)')->execute([$sheetLocalUdid, $cellLocalUdid, $sortOrder]);
        $this->db->prepare('UPDATE data_sheets SET ai_edited = 1, ai_edited_at = NOW() WHERE id = ?')->execute([$sheet['id']]);
        return true;
    }

    public function removeCellFromSheet(string $sheetLocalUdid, string $cellLocalUdid): bool {
        $stmt = $this->db->prepare('SELECT id FROM data_sheets WHERE user_id = ? AND local_udid = ? AND is_deleted = 0');
        $stmt->execute([$this->userId, $sheetLocalUdid]);
        $sheet = $stmt->fetch();
        if (!$sheet) return false;

        $stmt = $this->db->prepare('DELETE FROM data_sheet_cells WHERE data_sheet_local_udid = ? AND cell_local_udid = ?');
        $stmt->execute([$sheetLocalUdid, $cellLocalUdid]);
        return $stmt->rowCount() > 0;
    }
}

class DesktopAIHelper {
    /** @var PDO */
    private $db;
    /** @var int */
    private $userId;

    public function __construct(int $userId) {
        $this->db = getDB();
        $this->userId = $userId;
    }

    public function create(string $localUdid, string $title, string $uiType = 'list', ?string $description = null, int $importance = 0, array $tags = [], $categoryIdentifier = null, $subCategoryIdentifier = null): array {
        $serverId = generateUUID();

        $categoryLocalUdid = null;
        if ($categoryIdentifier !== null && $categoryIdentifier !== '') {
            $category = findCategory($this->db, $this->userId, $categoryIdentifier, 'desktop');
            if (!$category) {
                jsonError('分類不存在', 404);
            }
            $categoryLocalUdid = $category['local_udid'];
        }

        $subCategoryLocalUdid = null;
        if ($subCategoryIdentifier !== null && $subCategoryIdentifier !== '') {
            $sub = findSubCategory($this->db, $this->userId, $subCategoryIdentifier);
            if (!$sub) {
                jsonError('子分類不存在', 404);
            }
            $parent = findCategory($this->db, $this->userId, $sub['category_id'], 'desktop');
            if (!$parent) {
                jsonError('分類不存在', 404);
            }
            if ($categoryLocalUdid && $parent['local_udid'] !== $categoryLocalUdid) {
                jsonError('子分類不屬於指定的大分類');
            }
            $categoryLocalUdid = $categoryLocalUdid ?: $parent['local_udid'];
            $subCategoryLocalUdid = $sub['local_udid'];
        }

        $stmt = $this->db->prepare('
            INSERT INTO desktops (server_id, local_udid, user_id, title, description, importance, ui_type, category_id, sub_category_id, ai_edited, ai_edited_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
        ');
        $stmt->execute([$serverId, $localUdid, $this->userId, $title, $description, $importance, $uiType, $categoryLocalUdid, $subCategoryLocalUdid]);

        $id = (int) $this->db->lastInsertId();

        foreach ($tags as $tagName) {
            $this->addTag($localUdid, $tagName);
        }

        writeSyncLog($this->userId, null, 'desktop', $serverId, $localUdid, 'create', [
            'server_id' => $serverId, 'title' => $title, 'ai_created' => true,
        ]);

        return ['desktop_id' => $id, 'server_id' => $serverId, 'local_udid' => $localUdid];
    }

    public function update(string $localUdid, array $fields): bool {
        $stmt = $this->db->prepare('SELECT id, server_id FROM desktops WHERE user_id = ? AND local_udid = ? AND is_deleted = 0');
        $stmt->execute([$this->userId, $localUdid]);
        $desktop = $stmt->fetch();
        if (!$desktop) return false;

        $allowed = ['title', 'description', 'importance', 'ui_type', 'is_favorite'];
        $updates = ['ai_edited = 1', 'ai_edited_at = NOW()'];
        $params = [];

        $logPayload = $fields;

        $categoryLocalUdid = null;

        if (array_key_exists('category_id', $fields)) {
            $value = $fields['category_id'];
            if ($value === null || $value === '') {
                $updates[] = '`category_id` = NULL';
                $updates[] = '`sub_category_id` = NULL';
                $logPayload['category_id'] = null;
                $logPayload['sub_category_id'] = null;
            } else {
                $category = findCategory($this->db, $this->userId, $value, 'desktop');
                if (!$category) {
                    jsonError('分類不存在', 404);
                }
                $categoryLocalUdid = $category['local_udid'];
                $updates[] = '`category_id` = ?';
                $params[] = $categoryLocalUdid;
                $logPayload['category_id'] = $categoryLocalUdid;
            }
            unset($fields['category_id']);
        }

        if (array_key_exists('sub_category_id', $fields)) {
            $value = $fields['sub_category_id'];
            if ($value === null || $value === '') {
                $updates[] = '`sub_category_id` = NULL';
                $logPayload['sub_category_id'] = null;
            } else {
                $sub = findSubCategory($this->db, $this->userId, $value);
                if (!$sub) {
                    jsonError('子分類不存在', 404);
                }
                $parent = findCategory($this->db, $this->userId, $sub['category_id'], 'desktop');
                if (!$parent) {
                    jsonError('分類不存在', 404);
                }
                if ($categoryLocalUdid !== null && $parent['local_udid'] !== $categoryLocalUdid) {
                    jsonError('子分類不屬於指定的大分類');
                }
                if ($categoryLocalUdid === null) {
                    $categoryLocalUdid = $parent['local_udid'];
                    $updates[] = '`category_id` = ?';
                    $params[] = $categoryLocalUdid;
                    $logPayload['category_id'] = $categoryLocalUdid;
                }
                $updates[] = '`sub_category_id` = ?';
                $params[] = $sub['local_udid'];
                $logPayload['sub_category_id'] = $sub['local_udid'];
            }
            unset($fields['sub_category_id']);
        }

        foreach ($allowed as $field) {
            if (array_key_exists($field, $fields)) {
                $updates[] = "`$field` = ?";
                $params[] = $fields[$field];
            }
        }

        $params[] = $desktop['id'];
        $this->db->prepare('UPDATE desktops SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($params);

        writeSyncLog($this->userId, null, 'desktop', $desktop['server_id'], $localUdid, 'update', array_merge($logPayload, ['ai_edited' => true]));
        return true;
    }

    public function delete(string $localUdid): bool {
        $stmt = $this->db->prepare('SELECT id, server_id FROM desktops WHERE user_id = ? AND local_udid = ? AND is_deleted = 0');
        $stmt->execute([$this->userId, $localUdid]);
        $desktop = $stmt->fetch();
        if (!$desktop) return false;

        $this->db->prepare('UPDATE desktops SET is_deleted = 1, deleted_at = NOW(), ai_edited = 1, ai_edited_at = NOW() WHERE id = ?')->execute([$desktop['id']]);
        writeSyncLog($this->userId, null, 'desktop', $desktop['server_id'], $localUdid, 'delete', ['ai_deleted' => true]);
        return true;
    }

    public function get(string $localUdid): ?array {
        $stmt = $this->db->prepare('SELECT * FROM desktops WHERE user_id = ? AND local_udid = ? AND is_deleted = 0');
        $stmt->execute([$this->userId, $localUdid]);
        return $stmt->fetch() ?: null;
    }

    public function list(array $filters = []): array {
        $stmt = $this->db->prepare('SELECT * FROM desktops WHERE user_id = ? AND is_deleted = 0 ORDER BY updated_at DESC LIMIT 100');
        $stmt->execute([$this->userId]);
        return $stmt->fetchAll();
    }

    public function addTag(string $localUdid, string $tagName): bool {
        $tagName = trim($tagName);
        if ($tagName === '') return false;

        $stmt = $this->db->prepare('SELECT local_udid FROM desktops WHERE user_id = ? AND local_udid = ?');
        $stmt->execute([$this->userId, $localUdid]);
        $desktop = $stmt->fetch();
        if (!$desktop) return false;

        $tagLocalUdid = ensureTagLocalUdid($this->db, $this->userId, $tagName);
        $this->db->prepare('INSERT IGNORE INTO desktop_tags (desktop_local_udid, tag_local_udid) VALUES (?, ?)')->execute([$desktop['local_udid'], $tagLocalUdid]);
        return true;
    }

    public function removeTag(string $localUdid, string $tagName): bool {
        $stmt = $this->db->prepare('SELECT local_udid FROM tags WHERE user_id = ? AND name = ?');
        $stmt->execute([$this->userId, $tagName]);
        $tag = $stmt->fetch();
        if (!$tag) return false;

        $stmt = $this->db->prepare('DELETE FROM desktop_tags WHERE desktop_local_udid = ? AND tag_local_udid = ?');
        $stmt->execute([$localUdid, $tag['local_udid']]);
        return $stmt->rowCount() > 0;
    }

    public function addComponent(string $desktopLocalUdid, string $componentLocalUdid, string $componentType, string $refType, ?string $refLocalUdid = null, array $config = [], int $sortOrder = 0): ?array {
        $stmt = $this->db->prepare('SELECT id, local_udid FROM desktops WHERE user_id = ? AND local_udid = ? AND is_deleted = 0');
        $stmt->execute([$this->userId, $desktopLocalUdid]);
        $desktop = $stmt->fetch();
        if (!$desktop) return null;

        $serverId = generateUUID();

        $stmt = $this->db->prepare('
            INSERT INTO desktop_components (server_id, local_udid, desktop_local_udid, component_type, ref_type, ref_local_udid, config_json, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $serverId, $componentLocalUdid, $desktop['local_udid'], $componentType,
            $refType, $refLocalUdid, json_encode($config, JSON_UNESCAPED_UNICODE), $sortOrder,
        ]);

        return ['component_id' => (int) $this->db->lastInsertId(), 'server_id' => $serverId];
    }

    public function removeComponent(string $componentLocalUdid): bool {
        $stmt = $this->db->prepare('
            DELETE dc FROM desktop_components dc
            INNER JOIN desktops d ON d.local_udid = dc.desktop_local_udid
            WHERE d.user_id = ? AND dc.local_udid = ?
        ');
        $stmt->execute([$this->userId, $componentLocalUdid]);
        return $stmt->rowCount() > 0;
    }
}
