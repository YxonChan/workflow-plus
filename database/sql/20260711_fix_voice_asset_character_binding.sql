SET @voice_asset_id_column_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'voice_assets' AND COLUMN_NAME = 'asset_id'
);
SET @voice_asset_id_column_sql = IF(
  @voice_asset_id_column_exists = 0,
  'ALTER TABLE `voice_assets` ADD COLUMN `asset_id` int unsigned NOT NULL DEFAULT 0 COMMENT ''角色 assets.id'' AFTER `user_id`',
  'SELECT 1'
);
PREPARE voice_asset_id_column_stmt FROM @voice_asset_id_column_sql;
EXECUTE voice_asset_id_column_stmt;
DEALLOCATE PREPARE voice_asset_id_column_stmt;

SET @voice_asset_character_index_exists = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'voice_assets' AND INDEX_NAME = 'idx_voice_assets_character_status'
);
SET @voice_asset_character_index_sql = IF(
  @voice_asset_character_index_exists = 0,
  'ALTER TABLE `voice_assets` ADD INDEX `idx_voice_assets_character_status` (`asset_id`,`status`,`id`)',
  'SELECT 1'
);
PREPARE voice_asset_character_index_stmt FROM @voice_asset_character_index_sql;
EXECUTE voice_asset_character_index_stmt;
DEALLOCATE PREPARE voice_asset_character_index_stmt;
