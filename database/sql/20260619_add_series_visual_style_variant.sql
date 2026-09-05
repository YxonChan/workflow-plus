SET @series_visual_style_variant_missing := (
  SELECT COUNT(*) = 0
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'series'
    AND COLUMN_NAME = 'visual_style_variant'
);

SET @series_visual_style_variant_sql := IF(
  @series_visual_style_variant_missing,
  'ALTER TABLE `series` ADD COLUMN `visual_style_variant` varchar(64) NOT NULL DEFAULT '''' COMMENT ''二级视觉风格'' AFTER `visual_style`',
  'SELECT 1'
);

PREPARE stmt FROM @series_visual_style_variant_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
