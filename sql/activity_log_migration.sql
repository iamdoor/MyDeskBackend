-- Activity Log & Device Log Settings Migration (2026-03-22)

ALTER TABLE `sync_log`
    MODIFY COLUMN `entity_type` ENUM(
        'cell', 'datasheet', 'desktop',
        'category', 'sub_category', 'tag',
        'desktop_component', 'api_template',
        'desktop_cells', 'desktop_component_links',
        'data_sheet_cells', 'smart_sheet_conditions',
        'ai_conversation', 'ai_message',
        'cell_tags', 'data_sheet_tags', 'desktop_tags',
        'activity_log', 'device_log_setting'
    ) NOT NULL;

CREATE TABLE IF NOT EXISTS `activity_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `device_udid` VARCHAR(100) NOT NULL,
    `platform` ENUM('ios', 'android') NOT NULL DEFAULT 'ios',
    `device_name_snapshot` VARCHAR(200) NOT NULL DEFAULT '',
    `event_code` ENUM('app_launch','settings_change','desktop_tab_created','desktop_tab_updated','desktop_tab_switched','custom_note') NOT NULL,
    `action_title` VARCHAR(200) NOT NULL,
    `desktop_local_udid` VARCHAR(36) DEFAULT NULL,
    `desktop_name_snapshot` VARCHAR(200) DEFAULT NULL,
    `tab_local_udid` VARCHAR(36) DEFAULT NULL,
    `tab_name_snapshot` VARCHAR(200) DEFAULT NULL,
    `details_json` JSON DEFAULT NULL,
    `change_summary` TEXT,
    `custom_note` TEXT,
    `consent_required` TINYINT(1) NOT NULL DEFAULT 0,
    `consent_status` ENUM('accepted','rejected','auto_applied') NOT NULL DEFAULT 'accepted',
    `consent_decided_at` DATETIME DEFAULT NULL,
    `occurred_at` DATETIME NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `client_temp_id` VARCHAR(64) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_activity_client` (`client_temp_id`),
    KEY `idx_activity_user_time` (`user_id`, `occurred_at`),
    KEY `idx_activity_expire` (`expires_at`),
    CONSTRAINT `fk_activity_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `device_log_settings` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `device_udid` VARCHAR(100) NOT NULL,
    `platform` ENUM('ios','android') NOT NULL DEFAULT 'ios',
    `device_name` VARCHAR(200) NOT NULL,
    `require_consent` TINYINT(1) NOT NULL DEFAULT 0,
    `default_consent` ENUM('accept','reject') NOT NULL DEFAULT 'accept',
    `enabled_events` JSON NOT NULL,
    `last_updated_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_device_settings` (`user_id`, `device_udid`),
    KEY `idx_device_settings_updated` (`updated_at`),
    CONSTRAINT `fk_device_settings_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
