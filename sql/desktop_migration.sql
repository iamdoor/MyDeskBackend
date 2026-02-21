-- MyDesk Desktop Migration
-- 將既有 DB 升級至桌面完整結構
-- 適用於已有 MyDeskDev / MyDesk 資料庫的升級
-- ★ 冪等設計：每個 ALTER 步驟先檢查欄位/資料表是否已存在，可重複執行

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- 共用：用 Stored Procedure 包裝條件式 DDL，執行後立即刪除
-- ============================================================

DROP PROCEDURE IF EXISTS _mydesk_exec;
DELIMITER $$
CREATE PROCEDURE _mydesk_exec(IN p_sql TEXT)
BEGIN
    SET @_sql = p_sql;
    PREPARE _stmt FROM @_sql;
    EXECUTE _stmt;
    DEALLOCATE PREPARE _stmt;
END$$
DELIMITER ;

DROP PROCEDURE IF EXISTS _mydesk_col_exists;
DELIMITER $$
CREATE PROCEDURE _mydesk_col_exists(
    IN p_table VARCHAR(64),
    IN p_col   VARCHAR(64),
    OUT p_result TINYINT
)
BEGIN
    SELECT COUNT(*) INTO p_result
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = p_table
      AND COLUMN_NAME  = p_col;
END$$
DELIMITER ;

-- ============================================================
-- 1. ALTER TABLE desktops
--    a. 若舊版有 ui_type → 改名為 desktop_type_code
--    b. 若缺少新欄位 → 依序新增
-- ============================================================

DROP PROCEDURE IF EXISTS _migrate_desktops;
DELIMITER $$
CREATE PROCEDURE _migrate_desktops()
BEGIN
    DECLARE v_has INT;

    -- a. ui_type → desktop_type_code
    CALL _mydesk_col_exists('desktops', 'ui_type', v_has);
    IF v_has > 0 THEN
        CALL _mydesk_exec('ALTER TABLE `desktops` CHANGE `ui_type` `desktop_type_code` VARCHAR(50) NOT NULL DEFAULT ''single_column''');
    END IF;

    -- b. mixed_vertical_columns
    CALL _mydesk_col_exists('desktops', 'mixed_vertical_columns', v_has);
    IF v_has = 0 THEN
        CALL _mydesk_exec('ALTER TABLE `desktops` ADD COLUMN `mixed_vertical_columns` TINYINT DEFAULT NULL AFTER `desktop_type_code`');
    END IF;

    -- c. color_scheme_id
    CALL _mydesk_col_exists('desktops', 'color_scheme_id', v_has);
    IF v_has = 0 THEN
        CALL _mydesk_exec('ALTER TABLE `desktops` ADD COLUMN `color_scheme_id` INT UNSIGNED DEFAULT NULL AFTER `mixed_vertical_columns`');
    END IF;

    -- d. custom_bg_color
    CALL _mydesk_col_exists('desktops', 'custom_bg_color', v_has);
    IF v_has = 0 THEN
        CALL _mydesk_exec('ALTER TABLE `desktops` ADD COLUMN `custom_bg_color` VARCHAR(20) DEFAULT NULL AFTER `color_scheme_id`');
    END IF;

    -- e. custom_primary_color
    CALL _mydesk_col_exists('desktops', 'custom_primary_color', v_has);
    IF v_has = 0 THEN
        CALL _mydesk_exec('ALTER TABLE `desktops` ADD COLUMN `custom_primary_color` VARCHAR(20) DEFAULT NULL AFTER `custom_bg_color`');
    END IF;

    -- f. custom_secondary_color
    CALL _mydesk_col_exists('desktops', 'custom_secondary_color', v_has);
    IF v_has = 0 THEN
        CALL _mydesk_exec('ALTER TABLE `desktops` ADD COLUMN `custom_secondary_color` VARCHAR(20) DEFAULT NULL AFTER `custom_primary_color`');
    END IF;

    -- g. custom_accent_color
    CALL _mydesk_col_exists('desktops', 'custom_accent_color', v_has);
    IF v_has = 0 THEN
        CALL _mydesk_exec('ALTER TABLE `desktops` ADD COLUMN `custom_accent_color` VARCHAR(20) DEFAULT NULL AFTER `custom_secondary_color`');
    END IF;

    -- h. custom_text_color
    CALL _mydesk_col_exists('desktops', 'custom_text_color', v_has);
    IF v_has = 0 THEN
        CALL _mydesk_exec('ALTER TABLE `desktops` ADD COLUMN `custom_text_color` VARCHAR(20) DEFAULT NULL AFTER `custom_accent_color`');
    END IF;
END$$
DELIMITER ;

CALL _migrate_desktops();
DROP PROCEDURE IF EXISTS _migrate_desktops;

-- ============================================================
-- 2. ALTER TABLE desktop_components
--    a. 若舊版有 component_type → 改名為 component_type_code
--    b. 若舊版有 ref_type / ref_local_udid / sort_order → DROP
--    c. 若缺少新欄位 → 新增
-- ============================================================

DROP PROCEDURE IF EXISTS _migrate_desktop_components;
DELIMITER $$
CREATE PROCEDURE _migrate_desktop_components()
BEGIN
    DECLARE v_has INT;

    -- a. component_type → component_type_code
    CALL _mydesk_col_exists('desktop_components', 'component_type', v_has);
    IF v_has > 0 THEN
        CALL _mydesk_exec('ALTER TABLE `desktop_components` CHANGE `component_type` `component_type_code` VARCHAR(50) NOT NULL DEFAULT ''free_block''');
    END IF;

    -- b. DROP ref_type（舊版）
    CALL _mydesk_col_exists('desktop_components', 'ref_type', v_has);
    IF v_has > 0 THEN
        CALL _mydesk_exec('ALTER TABLE `desktop_components` DROP COLUMN `ref_type`');
    END IF;

    -- b. DROP ref_local_udid（舊版）
    CALL _mydesk_col_exists('desktop_components', 'ref_local_udid', v_has);
    IF v_has > 0 THEN
        CALL _mydesk_exec('ALTER TABLE `desktop_components` DROP COLUMN `ref_local_udid`');
    END IF;

    -- b. DROP sort_order（舊版）
    CALL _mydesk_col_exists('desktop_components', 'sort_order', v_has);
    IF v_has > 0 THEN
        CALL _mydesk_exec('ALTER TABLE `desktop_components` DROP COLUMN `sort_order`');
    END IF;

    -- c. bg_color
    CALL _mydesk_col_exists('desktop_components', 'bg_color', v_has);
    IF v_has = 0 THEN
        CALL _mydesk_exec('ALTER TABLE `desktop_components` ADD COLUMN `bg_color` VARCHAR(20) DEFAULT NULL AFTER `component_type_code`');
    END IF;

    -- c. border_color
    CALL _mydesk_col_exists('desktop_components', 'border_color', v_has);
    IF v_has = 0 THEN
        CALL _mydesk_exec('ALTER TABLE `desktop_components` ADD COLUMN `border_color` VARCHAR(20) DEFAULT NULL AFTER `bg_color`');
    END IF;

    -- c. border_width
    CALL _mydesk_col_exists('desktop_components', 'border_width', v_has);
    IF v_has = 0 THEN
        CALL _mydesk_exec('ALTER TABLE `desktop_components` ADD COLUMN `border_width` TINYINT NOT NULL DEFAULT 0 AFTER `border_color`');
    END IF;

    -- c. corner_radius
    CALL _mydesk_col_exists('desktop_components', 'corner_radius', v_has);
    IF v_has = 0 THEN
        CALL _mydesk_exec('ALTER TABLE `desktop_components` ADD COLUMN `corner_radius` TINYINT NOT NULL DEFAULT 0 AFTER `border_width`');
    END IF;
END$$
DELIMITER ;

CALL _migrate_desktop_components();
DROP PROCEDURE IF EXISTS _migrate_desktop_components;

-- ============================================================
-- 3. ALTER TABLE cells：新增 desktop_origin（若尚不存在）
-- ============================================================

DROP PROCEDURE IF EXISTS _migrate_cells;
DELIMITER $$
CREATE PROCEDURE _migrate_cells()
BEGIN
    DECLARE v_has INT;
    CALL _mydesk_col_exists('cells', 'desktop_origin', v_has);
    IF v_has = 0 THEN
        CALL _mydesk_exec('ALTER TABLE `cells` ADD COLUMN `desktop_origin` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = 在桌面情境中建立的 Cell'' AFTER `content_json`');
    END IF;
END$$
DELIMITER ;

CALL _migrate_cells();
DROP PROCEDURE IF EXISTS _migrate_cells;

-- ============================================================
-- 4. ALTER TABLE sync_log：更新 entity_type ENUM
--    MODIFY COLUMN 是冪等的，直接執行
-- ============================================================

ALTER TABLE `sync_log`
    MODIFY COLUMN `entity_type` ENUM(
        'cell', 'datasheet', 'desktop',
        'category', 'sub_category', 'tag',
        'desktop_component',
        'desktop_cells', 'desktop_component_links',
        'data_sheet_cells', 'smart_sheet_conditions',
        'ai_conversation', 'ai_message',
        'cell_tags', 'data_sheet_tags', 'desktop_tags'
    ) NOT NULL;

-- ============================================================
-- 5. CREATE TABLE desktop_types（CREATE IF NOT EXISTS，冪等）
-- ============================================================

CREATE TABLE IF NOT EXISTS `desktop_types` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(50) NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `max_columns` TINYINT NOT NULL DEFAULT 1,
    `config_schema` JSON DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `desktop_types` (`code`, `name`, `max_columns`, `config_schema`, `is_active`, `sort_order`) VALUES
('single_column',   '單排列表',   1, NULL, 1, 1),
('double_column',   '雙排列表',   2, NULL, 1, 2),
('triple_column',   '三排列表',   3, NULL, 1, 3),
('horizontal_list', '橫向列表',   0, '{"rows": []}', 1, 4),
('mixed',           '橫直混合',   3, '{"horizontal_rows": [], "vertical_columns": 1}', 1, 5);

-- ============================================================
-- 6. CREATE TABLE component_types（CREATE IF NOT EXISTS，冪等）
-- ============================================================

CREATE TABLE IF NOT EXISTS `component_types` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(50) NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `category` ENUM('general', 'special') NOT NULL DEFAULT 'general',
    `allowed_cell_types` JSON DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- INSERT IGNORE 確保重複執行不會報錯
INSERT IGNORE INTO `component_types` (`code`, `name`, `category`, `allowed_cell_types`, `is_active`, `sort_order`) VALUES
('free_block',        '自由方塊',     'general', NULL,      1, 1),
('list_block',        '列表方塊',     'general', NULL,      1, 2),
('album_block',       '相簿方塊',     'general', '[2,3,5]', 1, 3),
('image_text_block',  '圖文方塊',     'general', NULL,      1, 4),
('webview_block',     'WebView 方塊', 'general', '[15]',    1, 5),
('text_op_block',     '文字操作方塊', 'special', '[1]',     1, 6);

-- ============================================================
-- 7. CREATE TABLE color_schemes（CREATE IF NOT EXISTS，冪等）
-- ============================================================

CREATE TABLE IF NOT EXISTS `color_schemes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `bg_color` VARCHAR(20) NOT NULL DEFAULT '#FFFFFF',
    `primary_color` VARCHAR(20) NOT NULL DEFAULT '#000000',
    `secondary_color` VARCHAR(20) NOT NULL DEFAULT '#666666',
    `accent_color` VARCHAR(20) NOT NULL DEFAULT '#007AFF',
    `text_color` VARCHAR(20) NOT NULL DEFAULT '#000000',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `color_schemes` (`name`, `bg_color`, `primary_color`, `secondary_color`, `accent_color`, `text_color`, `sort_order`) VALUES
('預設白',   '#FFFFFF', '#1A1A1A', '#6B6B6B', '#007AFF', '#1A1A1A', 1),
('深夜黑',   '#1C1C1E', '#FFFFFF', '#AEAEB2', '#0A84FF', '#FFFFFF', 2),
('海洋藍',   '#EBF4FF', '#1A3A5C', '#4A7FA5', '#0066CC', '#1A3A5C', 3),
('森林綠',   '#EDF5ED', '#1A3A1A', '#4A7A4A', '#2E7D32', '#1A3A1A', 4),
('暖橘橙',   '#FFF4ED', '#3A1A00', '#A0522D', '#FF6600', '#3A1A00', 5),
('薰衣紫',   '#F4EDFF', '#2A0A5C', '#7A4FA5', '#6200EA', '#2A0A5C', 6),
('玫瑰粉',   '#FFEDEE', '#5C0A0A', '#A54A4A', '#E53935', '#5C0A0A', 7),
('沙漠金',   '#FFF9E6', '#3A2A00', '#8B6914', '#F9A825', '#3A2A00', 8);

-- ============================================================
-- 8. CREATE TABLE desktop_cells（CREATE IF NOT EXISTS，冪等）
-- ============================================================

CREATE TABLE IF NOT EXISTS `desktop_cells` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `desktop_local_udid` VARCHAR(36) NOT NULL,
    `ref_type` ENUM('cell', 'datasheet') NOT NULL DEFAULT 'cell',
    `ref_local_udid` VARCHAR(36) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_desktop_ref` (`desktop_local_udid`, `ref_local_udid`),
    KEY `idx_desktop` (`desktop_local_udid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 9. CREATE TABLE desktop_component_links（CREATE IF NOT EXISTS，冪等）
-- ============================================================

CREATE TABLE IF NOT EXISTS `desktop_component_links` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `local_udid` VARCHAR(36) NOT NULL,
    `component_local_udid` VARCHAR(36) NOT NULL,
    `ref_type` ENUM('cell', 'datasheet') NOT NULL DEFAULT 'cell',
    `ref_local_udid` VARCHAR(36) NOT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_local_udid` (`local_udid`),
    UNIQUE KEY `uk_component_ref` (`component_local_udid`, `ref_local_udid`),
    KEY `idx_component` (`component_local_udid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 清除共用輔助 Procedure
-- ============================================================

DROP PROCEDURE IF EXISTS _mydesk_col_exists;
DROP PROCEDURE IF EXISTS _mydesk_exec;

SET FOREIGN_KEY_CHECKS = 1;
