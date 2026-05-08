-- ============================================================
-- 資料單 scope 欄位 Migration
-- 階段四：資料單 scope 區分（library / workspace）
-- 執行環境：MyDeskDev 與 MyDesk 兩個 DB 均需執行
-- ============================================================

ALTER TABLE `data_sheets`
    ADD COLUMN `scope` ENUM('library','workspace') NOT NULL DEFAULT 'library'
        COMMENT 'library=長期知識庫, workspace=桌面工作暫用'
        AFTER `is_smart`,
    ADD KEY `idx_user_scope` (`user_id`, `scope`);

-- 後端原本無 desktop_origin 欄位，全部預設 library，無需額外 UPDATE
