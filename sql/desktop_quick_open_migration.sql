-- 階段三：移除參考資料單機制 → 快速開啟資料單
-- 執行於 MyDesk 與 MyDeskDev 兩個資料庫
--
-- 說明：
--   1. desktops 表新增 quick_open_datasheet_udid 欄位，預設 NULL
--   2. 既有的 desktop_cells.ref_type='datasheet' 資料保留不刪除（歷史資料）
--   3. 新欄位對所有現有桌面為 NULL，使用者可於 App 設定頁重新指定

ALTER TABLE `desktops`
    ADD COLUMN `quick_open_datasheet_udid` VARCHAR(36) DEFAULT NULL
    AFTER `custom_text_color`;
