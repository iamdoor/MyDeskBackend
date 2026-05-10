-- ============================================================
-- 資料單 scope 欄位 Migration
-- 階段四：資料單 scope 區分（library / workspace）
-- 執行環境：MyDeskDev 與 MyDesk 兩個 DB 均需執行
-- 可重複執行（已存在時跳過）
-- ============================================================

DROP PROCEDURE IF EXISTS _add_datasheet_scope;

DELIMITER $$
CREATE PROCEDURE _add_datasheet_scope()
BEGIN
    -- 檢查 scope 欄位是否已存在
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'data_sheets'
          AND COLUMN_NAME  = 'scope'
    ) THEN
        ALTER TABLE `data_sheets`
            ADD COLUMN `scope` ENUM('library','workspace') NOT NULL DEFAULT 'library'
                COMMENT 'library=長期知識庫, workspace=桌面工作暫用'
                AFTER `is_smart`;
    END IF;

    -- 檢查 index 是否已存在
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'data_sheets'
          AND INDEX_NAME   = 'idx_user_scope'
    ) THEN
        ALTER TABLE `data_sheets`
            ADD KEY `idx_user_scope` (`user_id`, `scope`);
    END IF;
END$$
DELIMITER ;

CALL _add_datasheet_scope();
DROP PROCEDURE IF EXISTS _add_datasheet_scope;

-- 後端原本無 desktop_origin 欄位，全部預設 library，無需額外 UPDATE
