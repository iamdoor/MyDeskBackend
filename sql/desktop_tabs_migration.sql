-- ============================================================
-- Desktop Tabs Migration
-- Run on both MyDeskDev and MyDesk databases
-- ============================================================

-- 1. Create desktop_tabs table
CREATE TABLE IF NOT EXISTS `desktop_tabs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `server_id` VARCHAR(36) NOT NULL,
    `local_udid` VARCHAR(36) NOT NULL,
    `desktop_local_udid` VARCHAR(36) NOT NULL,
    `title` VARCHAR(100) NOT NULL DEFAULT 'Tab',
    `icon` VARCHAR(50) DEFAULT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `desktop_type_code` VARCHAR(50) NOT NULL DEFAULT 'single_column',
    `mixed_vertical_columns` INT DEFAULT NULL,
    `color_scheme_id` INT DEFAULT NULL,
    `custom_bg_color` VARCHAR(20) DEFAULT NULL,
    `custom_primary_color` VARCHAR(20) DEFAULT NULL,
    `custom_secondary_color` VARCHAR(20) DEFAULT NULL,
    `custom_accent_color` VARCHAR(20) DEFAULT NULL,
    `custom_text_color` VARCHAR(20) DEFAULT NULL,
    `is_deleted` TINYINT NOT NULL DEFAULT 0,
    `deleted_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_server_id` (`server_id`),
    UNIQUE KEY `uk_local_udid` (`local_udid`),
    KEY `idx_desktop` (`desktop_local_udid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Add tab_local_udid to desktop_components (ignore error if already exists)
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'desktop_components'
      AND COLUMN_NAME = 'tab_local_udid'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `desktop_components` ADD COLUMN `tab_local_udid` VARCHAR(36) DEFAULT NULL AFTER `desktop_local_udid`, ADD KEY `idx_tab` (`tab_local_udid`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. Add local_udid to desktop_cells (needed for tab-scoped sync)
SET @col_exists2 = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'desktop_cells'
      AND COLUMN_NAME = 'local_udid'
);
SET @sql2 = IF(@col_exists2 = 0,
    'ALTER TABLE `desktop_cells` ADD COLUMN `local_udid` VARCHAR(36) DEFAULT NULL AFTER `id`',
    'SELECT 1'
);
PREPARE stmt2 FROM @sql2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;

-- 4. Add tab_local_udid to desktop_cells
SET @col_exists3 = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'desktop_cells'
      AND COLUMN_NAME = 'tab_local_udid'
);
SET @sql3 = IF(@col_exists3 = 0,
    'ALTER TABLE `desktop_cells` ADD COLUMN `tab_local_udid` VARCHAR(36) DEFAULT NULL AFTER `desktop_local_udid`',
    'SELECT 1'
);
PREPARE stmt3 FROM @sql3; EXECUTE stmt3; DEALLOCATE PREPARE stmt3;

-- 5. Populate local_udid for existing rows
UPDATE `desktop_cells` SET `local_udid` = UUID() WHERE `local_udid` IS NULL OR `local_udid` = '';

-- 6. Rebuild UNIQUE constraint on desktop_cells to include tab_local_udid
-- Drop old constraint (ignore if not exists)
SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'desktop_cells'
      AND INDEX_NAME = 'uk_desktop_ref'
);
SET @sql4 = IF(@idx_exists > 0,
    'ALTER TABLE `desktop_cells` DROP INDEX `uk_desktop_ref`',
    'SELECT 1'
);
PREPARE stmt4 FROM @sql4; EXECUTE stmt4; DEALLOCATE PREPARE stmt4;

-- Add new unique constraint (tab-scoped)
SET @idx_exists2 = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'desktop_cells'
      AND INDEX_NAME = 'uk_desktop_tab_ref'
);
SET @sql5 = IF(@idx_exists2 = 0,
    'ALTER TABLE `desktop_cells` ADD UNIQUE KEY `uk_desktop_tab_ref` (`desktop_local_udid`, `tab_local_udid`, `ref_local_udid`)',
    'SELECT 1'
);
PREPARE stmt5 FROM @sql5; EXECUTE stmt5; DEALLOCATE PREPARE stmt5;

-- 7. Add desktop_tab to sync_log entity_type ENUM
ALTER TABLE `sync_log`
MODIFY COLUMN `entity_type` ENUM(
    'cell', 'datasheet', 'desktop',
    'category', 'sub_category', 'tag',
    'desktop_component', 'api_template',
    'desktop_cells', 'desktop_component_links',
    'data_sheet_cells', 'smart_sheet_conditions',
    'ai_conversation', 'ai_message',
    'cell_tags', 'data_sheet_tags', 'desktop_tags',
    'desktop_tab'
) NOT NULL;
