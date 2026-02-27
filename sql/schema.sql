-- MyDesk Database Schema
-- MySQL 8.0+
-- charset: utf8mb4

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 開發環境: MyDeskDev / 正式環境: MyDesk
CREATE DATABASE IF NOT EXISTS `MyDeskDev` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `MyDeskDev`;

-- ============================================================
-- 帳號與裝置
-- ============================================================

CREATE TABLE `users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(100) NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `status` ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_username` (`username`),
    UNIQUE KEY `uk_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `devices` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `device_udid` VARCHAR(100) NOT NULL,
    `device_name` VARCHAR(200) NOT NULL DEFAULT '',
    `platform` ENUM('ios', 'android') NOT NULL,
    `last_sync_at` DATETIME DEFAULT NULL,
    `last_sync_version` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `push_token` VARCHAR(500) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_user_device` (`user_id`, `device_udid`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 分類系統（資料單與桌面各自獨立）
-- ============================================================

CREATE TABLE `categories` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `server_id` VARCHAR(36) NOT NULL,
    `local_udid` VARCHAR(36) NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `type` ENUM('datasheet', 'desktop') NOT NULL,
    `name` VARCHAR(200) NOT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
    `deleted_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_server_id` (`server_id`),
    UNIQUE KEY `uk_user_local_udid` (`user_id`, `local_udid`),
    KEY `idx_user_type` (`user_id`, `type`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sub_categories` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `server_id` VARCHAR(36) NOT NULL,
    `local_udid` VARCHAR(36) NOT NULL,
    `category_id` VARCHAR(36) NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(200) NOT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
    `deleted_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_server_id` (`server_id`),
    UNIQUE KEY `uk_user_local_udid` (`user_id`, `local_udid`),
    KEY `idx_category` (`category_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Tag 系統（共用池）
-- ============================================================

CREATE TABLE `tags` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `local_udid` VARCHAR(36) NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_user_tag_name` (`user_id`, `name`),
    UNIQUE KEY `uk_user_tag_local` (`user_id`, `local_udid`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Cell
-- ============================================================

CREATE TABLE `cells` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `server_id` VARCHAR(36) NOT NULL,
    `local_udid` VARCHAR(36) NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `cell_type` INT UNSIGNED NOT NULL COMMENT '1~18+，可擴充',
    `title` VARCHAR(500) NOT NULL DEFAULT '',
    `description` TEXT,
    `importance` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0~5',
    `content_json` JSON DEFAULT NULL,
    `custom_id` VARCHAR(100) DEFAULT NULL COMMENT '自訂序號（英數字），供使用者在資料單內辨識用',
    `desktop_origin` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = 在桌面情境中建立的 Cell',
    `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
    `deleted_at` DATETIME DEFAULT NULL,
    `scheduled_delete` TINYINT(1) NOT NULL DEFAULT 0,
    `scheduled_delete_at` DATETIME DEFAULT NULL,
    `ai_edited` TINYINT(1) NOT NULL DEFAULT 0,
    `ai_edited_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_server_id` (`server_id`),
    UNIQUE KEY `uk_user_local_udid` (`user_id`, `local_udid`),
    KEY `idx_user_type` (`user_id`, `cell_type`),
    KEY `idx_user_deleted` (`user_id`, `is_deleted`),
    KEY `idx_user_updated` (`user_id`, `updated_at`),
    KEY `idx_scheduled_delete` (`scheduled_delete`, `scheduled_delete_at`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cell_tags` (
    `cell_id` INT UNSIGNED NOT NULL,
    `tag_local_udid` VARCHAR(36) NOT NULL,
    PRIMARY KEY (`cell_id`, `tag_local_udid`),
    FOREIGN KEY (`cell_id`) REFERENCES `cells`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 資料單 (DataSheet)
-- ============================================================

CREATE TABLE `data_sheets` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `server_id` VARCHAR(36) NOT NULL,
    `local_udid` VARCHAR(36) NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(500) NOT NULL DEFAULT '',
    `description` TEXT,
    `importance` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `category_id` VARCHAR(36) DEFAULT NULL,
    `sub_category_id` VARCHAR(36) DEFAULT NULL,
    `is_smart` TINYINT(1) NOT NULL DEFAULT 0,
    `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
    `deleted_at` DATETIME DEFAULT NULL,
    `scheduled_delete` TINYINT(1) NOT NULL DEFAULT 0,
    `scheduled_delete_at` DATETIME DEFAULT NULL,
    `ai_edited` TINYINT(1) NOT NULL DEFAULT 0,
    `ai_edited_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_server_id` (`server_id`),
    UNIQUE KEY `uk_user_local_udid` (`user_id`, `local_udid`),
    KEY `idx_user_category` (`user_id`, `category_id`),
    KEY `idx_user_deleted` (`user_id`, `is_deleted`),
    KEY `idx_user_updated` (`user_id`, `updated_at`),
    KEY `idx_scheduled_delete` (`scheduled_delete`, `scheduled_delete_at`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `data_sheet_tags` (
    `data_sheet_id` INT UNSIGNED NOT NULL,
    `tag_local_udid` VARCHAR(36) NOT NULL,
    PRIMARY KEY (`data_sheet_id`, `tag_local_udid`),
    FOREIGN KEY (`data_sheet_id`) REFERENCES `data_sheets`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `data_sheet_cells` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `data_sheet_local_udid` VARCHAR(36) NOT NULL,
    `cell_local_udid` VARCHAR(36) NOT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_sheet_cell` (`data_sheet_local_udid`, `cell_local_udid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `smart_sheet_conditions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `data_sheet_local_udid` VARCHAR(36) NOT NULL,
    `condition_type` ENUM('tag', 'keyword', 'reference') NOT NULL,
    `condition_value` VARCHAR(500) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_sheet` (`data_sheet_local_udid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 桌面 (Desktop)
-- ============================================================

CREATE TABLE `desktop_types` (
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

INSERT INTO `desktop_types` (`code`, `name`, `max_columns`, `config_schema`, `is_active`, `sort_order`) VALUES
('single_column',   '單排列表',   1, NULL, 1, 1),
('double_column',   '雙排列表',   2, NULL, 1, 2),
('triple_column',   '三排列表',   3, NULL, 1, 3),
('horizontal_list', '橫向列表',   0, '{"rows": []}', 1, 4),
('mixed',           '橫直混合',   3, '{"horizontal_rows": [], "vertical_columns": 1}', 1, 5);

CREATE TABLE `component_types` (
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

INSERT INTO `component_types` (`code`, `name`, `category`, `allowed_cell_types`, `is_active`, `sort_order`) VALUES
('free_block',        '自由方塊',     'general', NULL,      1, 1),
('list_block',        '列表方塊',     'general', NULL,      1, 2),
('album_block',       '相簿方塊',     'general', '[2,3,5]', 1, 3),
('image_text_block',  '圖文方塊',     'general', NULL,      1, 4),
('webview_block',     'WebView 方塊', 'general', '[15]',    1, 5),
('text_op_block',     '文字操作方塊', 'special', '[1]',     1, 6),
('api_button_block',  'API 按鈕',     'special', '[1]',     1, 7);

CREATE TABLE `color_schemes` (
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

INSERT INTO `color_schemes` (`name`, `bg_color`, `primary_color`, `secondary_color`, `accent_color`, `text_color`, `sort_order`) VALUES
('預設白',   '#FFFFFF', '#1A1A1A', '#6B6B6B', '#007AFF', '#1A1A1A', 1),
('深夜黑',   '#1C1C1E', '#FFFFFF', '#AEAEB2', '#0A84FF', '#FFFFFF', 2),
('海洋藍',   '#EBF4FF', '#1A3A5C', '#4A7FA5', '#0066CC', '#1A3A5C', 3),
('森林綠',   '#EDF5ED', '#1A3A1A', '#4A7A4A', '#2E7D32', '#1A3A1A', 4),
('暖橘橙',   '#FFF4ED', '#3A1A00', '#A0522D', '#FF6600', '#3A1A00', 5),
('薰衣紫',   '#F4EDFF', '#2A0A5C', '#7A4FA5', '#6200EA', '#2A0A5C', 6),
('玫瑰粉',   '#FFEDEE', '#5C0A0A', '#A54A4A', '#E53935', '#5C0A0A', 7),
('沙漠金',   '#FFF9E6', '#3A2A00', '#8B6914', '#F9A825', '#3A2A00', 8);

CREATE TABLE `desktops` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `server_id` VARCHAR(36) NOT NULL,
    `local_udid` VARCHAR(36) NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(500) NOT NULL DEFAULT '',
    `description` TEXT,
    `importance` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `category_id` VARCHAR(36) DEFAULT NULL,
    `sub_category_id` VARCHAR(36) DEFAULT NULL,
    `desktop_type_code` VARCHAR(50) NOT NULL DEFAULT 'single_column',
    `mixed_vertical_columns` TINYINT DEFAULT NULL,
    `color_scheme_id` INT UNSIGNED DEFAULT NULL,
    `custom_bg_color` VARCHAR(20) DEFAULT NULL,
    `custom_primary_color` VARCHAR(20) DEFAULT NULL,
    `custom_secondary_color` VARCHAR(20) DEFAULT NULL,
    `custom_accent_color` VARCHAR(20) DEFAULT NULL,
    `custom_text_color` VARCHAR(20) DEFAULT NULL,
    `is_favorite` TINYINT(1) NOT NULL DEFAULT 0,
    `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
    `deleted_at` DATETIME DEFAULT NULL,
    `scheduled_delete` TINYINT(1) NOT NULL DEFAULT 0,
    `scheduled_delete_at` DATETIME DEFAULT NULL,
    `ai_edited` TINYINT(1) NOT NULL DEFAULT 0,
    `ai_edited_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_server_id` (`server_id`),
    UNIQUE KEY `uk_user_local_udid` (`user_id`, `local_udid`),
    KEY `idx_user_favorite` (`user_id`, `is_favorite`),
    KEY `idx_user_deleted` (`user_id`, `is_deleted`),
    KEY `idx_user_updated` (`user_id`, `updated_at`),
    KEY `idx_scheduled_delete` (`scheduled_delete`, `scheduled_delete_at`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `desktop_tags` (
    `desktop_local_udid` VARCHAR(36) NOT NULL,
    `tag_local_udid` VARCHAR(36) NOT NULL,
    PRIMARY KEY (`desktop_local_udid`, `tag_local_udid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `desktop_cells` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `desktop_local_udid` VARCHAR(36) NOT NULL,
    `ref_type` ENUM('cell', 'datasheet') NOT NULL DEFAULT 'cell',
    `ref_local_udid` VARCHAR(36) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_desktop_ref` (`desktop_local_udid`, `ref_local_udid`),
    KEY `idx_desktop` (`desktop_local_udid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `desktop_components` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `server_id` VARCHAR(36) NOT NULL,
    `local_udid` VARCHAR(36) NOT NULL,
    `desktop_local_udid` VARCHAR(36) NOT NULL,
    `component_type_code` VARCHAR(50) NOT NULL DEFAULT 'free_block',
    `bg_color` VARCHAR(20) DEFAULT NULL,
    `border_color` VARCHAR(20) DEFAULT NULL,
    `border_width` TINYINT NOT NULL DEFAULT 0,
    `corner_radius` TINYINT NOT NULL DEFAULT 0,
    `config_json` JSON DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_server_id` (`server_id`),
    UNIQUE KEY `uk_local_udid` (`local_udid`),
    KEY `idx_desktop` (`desktop_local_udid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `desktop_component_links` (
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
-- 同步
-- ============================================================

CREATE TABLE `sync_log` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `device_udid` VARCHAR(100) DEFAULT NULL,
    `entity_type` ENUM(
        'cell', 'datasheet', 'desktop',
        'category', 'sub_category', 'tag',
        'desktop_component', 'api_template',
        'desktop_cells', 'desktop_component_links',
        'data_sheet_cells', 'smart_sheet_conditions',
        'ai_conversation', 'ai_message',
        'cell_tags', 'data_sheet_tags', 'desktop_tags'
    ) NOT NULL,
    `entity_server_id` VARCHAR(36) NOT NULL DEFAULT '',
    `entity_local_udid` VARCHAR(36) NOT NULL DEFAULT '',
    `action` ENUM('create', 'update', 'delete') NOT NULL,
    `sync_version` BIGINT UNSIGNED NOT NULL,
    `payload_json` JSON DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_version` (`user_id`, `sync_version`),
    KEY `idx_device` (`device_udid`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- AI
-- ============================================================

CREATE TABLE `ai_conversations` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `server_id` VARCHAR(36) NOT NULL,
    `local_udid` VARCHAR(36) NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `context_type` ENUM('cell', 'datasheet', 'desktop') NOT NULL,
    `context_local_udid` VARCHAR(36) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_server_id` (`server_id`),
    KEY `idx_user_context` (`user_id`, `context_type`, `context_local_udid`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ai_messages` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `server_id` VARCHAR(36) NOT NULL,
    `local_udid` VARCHAR(36) NOT NULL,
    `conversation_id` INT UNSIGNED NOT NULL,
    `role` ENUM('user', 'assistant') NOT NULL,
    `content` LONGTEXT NOT NULL,
    `referenced_udids` JSON DEFAULT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_server_id` (`server_id`),
    KEY `idx_conversation` (`conversation_id`, `sort_order`),
    FOREIGN KEY (`conversation_id`) REFERENCES `ai_conversations`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ai_preset_prompts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `context_type` ENUM('cell', 'datasheet', 'desktop') NOT NULL,
    `prompt_text` TEXT NOT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_context_active` (`context_type`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- API 模板
-- ============================================================

CREATE TABLE `api_templates` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `server_id` VARCHAR(36) NOT NULL,
    `local_udid` VARCHAR(36) NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(200) NOT NULL DEFAULT '',
    `template_json` LONGTEXT NOT NULL,
    `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
    `deleted_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_server_id` (`server_id`),
    KEY `idx_user_local` (`user_id`, `local_udid`),
    KEY `idx_user_updated` (`user_id`, `updated_at`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 系統資料
-- ============================================================

-- ============================================================
-- App 主題
-- ============================================================

CREATE TABLE `app_themes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `server_id` VARCHAR(36) NOT NULL,
    `local_udid` VARCHAR(36) NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(200) NOT NULL DEFAULT '',
    `accent_hex` VARCHAR(7) NOT NULL DEFAULT '#0D9488',
    `bg_hex` VARCHAR(7) NOT NULL DEFAULT '#FFFFFF',
    `surface_hex` VARCHAR(7) NOT NULL DEFAULT '#E8F7F6',
    `text_hex` VARCHAR(7) NOT NULL DEFAULT '#1A2B4A',
    `warning_hex` VARCHAR(7) NOT NULL DEFAULT '#FFB347',
    `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
    `deleted_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_server_id` (`server_id`),
    KEY `idx_user_local` (`user_id`, `local_udid`),
    KEY `idx_user_updated` (`user_id`, `updated_at`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `system_config` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `config_key` VARCHAR(200) NOT NULL,
    `config_value` JSON DEFAULT NULL,
    `description` VARCHAR(500) NOT NULL DEFAULT '',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_config_key` (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
