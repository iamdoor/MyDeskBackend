-- Migration: 新增 app_themes 表
-- 執行環境：開發(MyDeskDev) 和 正式(MyDesk)

CREATE TABLE IF NOT EXISTS `app_themes` (
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
