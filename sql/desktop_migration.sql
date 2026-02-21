-- MyDesk Desktop Migration
-- 將既有 DB 升級至桌面完整結構
-- 適用於已有 MyDeskDev / MyDesk 資料庫的升級

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- 1. ALTER TABLE desktops
--    重命名 ui_type → desktop_type_code
--    新增 mixed_vertical_columns / color_scheme_id / custom_*color
-- ============================================================

ALTER TABLE `desktops`
    CHANGE `ui_type` `desktop_type_code` VARCHAR(50) NOT NULL DEFAULT 'single_column',
    ADD COLUMN `mixed_vertical_columns` TINYINT DEFAULT NULL AFTER `desktop_type_code`,
    ADD COLUMN `color_scheme_id` INT UNSIGNED DEFAULT NULL AFTER `mixed_vertical_columns`,
    ADD COLUMN `custom_bg_color` VARCHAR(20) DEFAULT NULL AFTER `color_scheme_id`,
    ADD COLUMN `custom_primary_color` VARCHAR(20) DEFAULT NULL AFTER `custom_bg_color`,
    ADD COLUMN `custom_secondary_color` VARCHAR(20) DEFAULT NULL AFTER `custom_primary_color`,
    ADD COLUMN `custom_accent_color` VARCHAR(20) DEFAULT NULL AFTER `custom_secondary_color`,
    ADD COLUMN `custom_text_color` VARCHAR(20) DEFAULT NULL AFTER `custom_accent_color`;

-- ============================================================
-- 2. ALTER TABLE desktop_components
--    重命名 component_type → component_type_code
--    移除 ref_type / ref_local_udid / sort_order
--    新增 bg_color / border_color / border_width / corner_radius
-- ============================================================

ALTER TABLE `desktop_components`
    CHANGE `component_type` `component_type_code` VARCHAR(50) NOT NULL DEFAULT 'free_block',
    DROP COLUMN `ref_type`,
    DROP COLUMN `ref_local_udid`,
    DROP COLUMN `sort_order`,
    ADD COLUMN `bg_color` VARCHAR(20) DEFAULT NULL AFTER `component_type_code`,
    ADD COLUMN `border_color` VARCHAR(20) DEFAULT NULL AFTER `bg_color`,
    ADD COLUMN `border_width` TINYINT NOT NULL DEFAULT 0 AFTER `border_color`,
    ADD COLUMN `corner_radius` TINYINT NOT NULL DEFAULT 0 AFTER `border_width`;

-- ============================================================
-- 3. ALTER TABLE desktop_temp_cells
--    移除 component_local_udid（此欄位不在規格內）
-- ============================================================

ALTER TABLE `desktop_temp_cells`
    DROP COLUMN `component_local_udid`;

-- ============================================================
-- 4. ALTER TABLE sync_log
--    新增 desktop_cells / desktop_component_links 到 entity_type ENUM
-- ============================================================

ALTER TABLE `sync_log`
    MODIFY COLUMN `entity_type` ENUM(
        'cell', 'datasheet', 'desktop',
        'category', 'sub_category', 'tag',
        'desktop_component', 'desktop_temp_cell',
        'desktop_cells', 'desktop_component_links',
        'data_sheet_cells', 'smart_sheet_conditions',
        'ai_conversation', 'ai_message',
        'cell_tags', 'data_sheet_tags', 'desktop_tags'
    ) NOT NULL;

-- ============================================================
-- 5. CREATE TABLE desktop_types（系統表，存 5 種桌面類型）
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
-- 6. CREATE TABLE component_types（系統表，存組件類型）
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

-- NULL = 允許全部（含資料單）；有值則限定 cell_type 編號；"datasheet" 代表資料單
INSERT IGNORE INTO `component_types` (`code`, `name`, `category`, `allowed_cell_types`, `is_active`, `sort_order`) VALUES
('free_block',        '自由方塊', 'general', NULL,        1, 1),
('list_block',        '列表方塊', 'general', NULL,        1, 2),
('album_block',       '相簿方塊', 'general', '[2,3,5]',   1, 3),
('image_text_block',  '圖文方塊', 'general', NULL,        1, 4);

-- ============================================================
-- 7. CREATE TABLE color_schemes（後台管理配色表）
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
-- 8. CREATE TABLE desktop_cells（桌面 Cell 池）
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
-- 9. CREATE TABLE desktop_component_links（Cell ↔ 組件多對多連結）
-- ============================================================

CREATE TABLE IF NOT EXISTS `desktop_component_links` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `local_udid` VARCHAR(36) NOT NULL,
    `component_local_udid` VARCHAR(36) NOT NULL,
    `ref_type` ENUM('cell', 'datasheet', 'temp') NOT NULL DEFAULT 'cell',
    `ref_local_udid` VARCHAR(36) NOT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_local_udid` (`local_udid`),
    UNIQUE KEY `uk_component_ref` (`component_local_udid`, `ref_local_udid`),
    KEY `idx_component` (`component_local_udid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
