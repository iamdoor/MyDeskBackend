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
    `category_id` INT UNSIGNED NOT NULL,
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
    FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Tag 系統（共用池）
-- ============================================================

CREATE TABLE `tags` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_user_tag` (`user_id`, `name`),
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
    `tag_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`cell_id`, `tag_id`),
    FOREIGN KEY (`cell_id`) REFERENCES `cells`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`tag_id`) REFERENCES `tags`(`id`) ON DELETE CASCADE
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
    `category_id` INT UNSIGNED DEFAULT NULL,
    `sub_category_id` INT UNSIGNED DEFAULT NULL,
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
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`sub_category_id`) REFERENCES `sub_categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `data_sheet_tags` (
    `data_sheet_id` INT UNSIGNED NOT NULL,
    `tag_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`data_sheet_id`, `tag_id`),
    FOREIGN KEY (`data_sheet_id`) REFERENCES `data_sheets`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`tag_id`) REFERENCES `tags`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `data_sheet_cells` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `data_sheet_id` INT UNSIGNED NOT NULL,
    `cell_local_udid` VARCHAR(36) NOT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_sheet_cell` (`data_sheet_id`, `cell_local_udid`),
    FOREIGN KEY (`data_sheet_id`) REFERENCES `data_sheets`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `smart_sheet_conditions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `data_sheet_id` INT UNSIGNED NOT NULL,
    `condition_type` ENUM('tag', 'keyword', 'reference') NOT NULL,
    `condition_value` VARCHAR(500) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_sheet` (`data_sheet_id`),
    FOREIGN KEY (`data_sheet_id`) REFERENCES `data_sheets`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 桌面 (Desktop)
-- ============================================================

CREATE TABLE `desktops` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `server_id` VARCHAR(36) NOT NULL,
    `local_udid` VARCHAR(36) NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(500) NOT NULL DEFAULT '',
    `description` TEXT,
    `importance` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `category_id` INT UNSIGNED DEFAULT NULL,
    `sub_category_id` INT UNSIGNED DEFAULT NULL,
    `ui_type` VARCHAR(50) NOT NULL DEFAULT 'list',
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
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`sub_category_id`) REFERENCES `sub_categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `desktop_tags` (
    `desktop_id` INT UNSIGNED NOT NULL,
    `tag_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`desktop_id`, `tag_id`),
    FOREIGN KEY (`desktop_id`) REFERENCES `desktops`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`tag_id`) REFERENCES `tags`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `desktop_components` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `server_id` VARCHAR(36) NOT NULL,
    `local_udid` VARCHAR(36) NOT NULL,
    `desktop_id` INT UNSIGNED NOT NULL,
    `component_type` VARCHAR(100) NOT NULL,
    `ref_type` ENUM('cell', 'datasheet', 'temp') NOT NULL,
    `ref_local_udid` VARCHAR(36) DEFAULT NULL,
    `config_json` JSON DEFAULT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_server_id` (`server_id`),
    KEY `idx_desktop` (`desktop_id`),
    FOREIGN KEY (`desktop_id`) REFERENCES `desktops`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `desktop_temp_cells` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `server_id` VARCHAR(36) NOT NULL,
    `local_udid` VARCHAR(36) NOT NULL,
    `desktop_id` INT UNSIGNED NOT NULL,
    `component_id` INT UNSIGNED NOT NULL,
    `cell_type` INT UNSIGNED NOT NULL,
    `title` VARCHAR(500) NOT NULL DEFAULT '',
    `description` TEXT,
    `content_json` JSON DEFAULT NULL,
    `promoted_to_cell_udid` VARCHAR(36) DEFAULT NULL COMMENT '轉正後的 Cell UDID',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_server_id` (`server_id`),
    KEY `idx_desktop` (`desktop_id`),
    KEY `idx_component` (`component_id`),
    FOREIGN KEY (`desktop_id`) REFERENCES `desktops`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`component_id`) REFERENCES `desktop_components`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 同步
-- ============================================================

CREATE TABLE `sync_log` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `device_id` INT UNSIGNED DEFAULT NULL,
    `entity_type` ENUM(
        'cell', 'datasheet', 'desktop',
        'category', 'sub_category', 'tag',
        'desktop_component', 'desktop_temp_cell',
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
    `context_type` ENUM('datasheet', 'desktop') NOT NULL,
    `prompt_text` TEXT NOT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_context_active` (`context_type`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 系統資料
-- ============================================================

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
