-- Migration: Add soft-delete support to desktop_components
-- Run on both MyDeskDev and MyDesk databases

ALTER TABLE `desktop_components`
    ADD COLUMN `is_deleted` TINYINT(1) NOT NULL DEFAULT 0 AFTER `config_json`,
    ADD COLUMN `deleted_at` DATETIME DEFAULT NULL AFTER `is_deleted`;
