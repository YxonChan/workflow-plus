-- 速创图片编辑/重新生成：记录源消息及源结果索引，便于版本追踪。

SET @quick_create_parent_message_id_missing := (
  SELECT COUNT(*) = 0
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'quick_create_messages'
    AND COLUMN_NAME = 'parent_message_id'
);

SET @quick_create_parent_message_id_sql := IF(
  @quick_create_parent_message_id_missing,
  'ALTER TABLE `quick_create_messages` ADD COLUMN `parent_message_id` bigint unsigned DEFAULT NULL COMMENT ''源速创消息 ID'' AFTER `model_config_id`',
  'SELECT 1'
);

PREPARE quick_create_parent_message_id_stmt FROM @quick_create_parent_message_id_sql;
EXECUTE quick_create_parent_message_id_stmt;
DEALLOCATE PREPARE quick_create_parent_message_id_stmt;

SET @quick_create_parent_result_index_missing := (
  SELECT COUNT(*) = 0
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'quick_create_messages'
    AND COLUMN_NAME = 'parent_result_index'
);

SET @quick_create_parent_result_index_sql := IF(
  @quick_create_parent_result_index_missing,
  'ALTER TABLE `quick_create_messages` ADD COLUMN `parent_result_index` tinyint unsigned DEFAULT NULL COMMENT ''源消息结果索引'' AFTER `parent_message_id`',
  'SELECT 1'
);

PREPARE quick_create_parent_result_index_stmt FROM @quick_create_parent_result_index_sql;
EXECUTE quick_create_parent_result_index_stmt;
DEALLOCATE PREPARE quick_create_parent_result_index_stmt;

SET @quick_create_edit_instruction_missing := (
  SELECT COUNT(*) = 0
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'quick_create_messages'
    AND COLUMN_NAME = 'edit_instruction'
);

SET @quick_create_edit_instruction_sql := IF(
  @quick_create_edit_instruction_missing,
  'ALTER TABLE `quick_create_messages` ADD COLUMN `edit_instruction` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT ''本次图片编辑要求'' AFTER `prompt`',
  'SELECT 1'
);

PREPARE quick_create_edit_instruction_stmt FROM @quick_create_edit_instruction_sql;
EXECUTE quick_create_edit_instruction_stmt;
DEALLOCATE PREPARE quick_create_edit_instruction_stmt;

SET @quick_create_parent_index_missing := (
  SELECT COUNT(*) = 0
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'quick_create_messages'
    AND INDEX_NAME = 'idx_quick_create_parent'
);

SET @quick_create_parent_index_sql := IF(
  @quick_create_parent_index_missing,
  'ALTER TABLE `quick_create_messages` ADD KEY `idx_quick_create_parent` (`parent_message_id`,`parent_result_index`)',
  'SELECT 1'
);

PREPARE quick_create_parent_index_stmt FROM @quick_create_parent_index_sql;
EXECUTE quick_create_parent_index_stmt;
DEALLOCATE PREPARE quick_create_parent_index_stmt;
