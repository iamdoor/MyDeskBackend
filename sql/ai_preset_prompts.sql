-- AI 預設問題
-- 需先執行 ALTER TABLE 將 context_type ENUM 加入 'cell'
ALTER TABLE `ai_preset_prompts` MODIFY `context_type` ENUM('cell', 'datasheet', 'desktop') NOT NULL;

-- Cell 預設問題
INSERT INTO `ai_preset_prompts` (`context_type`, `prompt_text`, `sort_order`) VALUES
('cell', '幫我整理這個 Cell 的內容', 1),
('cell', '幫我加上適當的標籤', 2),
('cell', '幫我修改標題，讓它更簡潔明瞭', 3);

-- DataSheet 預設問題
INSERT IGNORE INTO `ai_preset_prompts` (`context_type`, `prompt_text`, `sort_order`) VALUES
('datasheet', '幫我整理這個資料單的 Cell 排序', 1),
('datasheet', '幫我建立一個新的 Cell 加到這個資料單', 2),
('datasheet', '幫我為這個資料單加上適當的標籤', 3);
