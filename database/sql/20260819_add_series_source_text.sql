-- Persist full novel/script body for series workflow without stuffing varchar(1000) description.
-- MySQL 8.0 compatible (no IF NOT EXISTS on ADD COLUMN).
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'series'
    AND COLUMN_NAME = 'source_text'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `series` ADD COLUMN `source_text` mediumtext NULL COMMENT ''完整剧本/小说正文'' AFTER `description`',
  'SELECT ''series.source_text already exists'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
