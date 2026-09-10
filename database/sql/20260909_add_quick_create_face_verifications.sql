CREATE TABLE IF NOT EXISTS `quick_create_face_verifications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT '0',
  `source_url` varchar(1000) NOT NULL DEFAULT '',
  `source_hash` char(64) NOT NULL DEFAULT '' COMMENT '规范化图片地址 SHA-256',
  `asset_id` int unsigned NOT NULL DEFAULT '0',
  `asset_image_id` int unsigned NOT NULL DEFAULT '0',
  `asset_json` json DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'queued' COMMENT 'queued|running|passed|failed',
  `asset_url` varchar(128) NOT NULL DEFAULT '',
  `error_message` varchar(2000) NOT NULL DEFAULT '',
  `attempts` int unsigned NOT NULL DEFAULT '0',
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_qc_face_user_status` (`user_id`,`status`,`id`),
  KEY `idx_qc_face_source` (`user_id`,`source_url`(191)),
  UNIQUE KEY `uq_qc_face_user_source_hash` (`user_id`,`source_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='灵感速创上传图片人脸验证任务';

SET @qc_face_source_hash_missing := (
  SELECT COUNT(*) = 0 FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quick_create_face_verifications' AND COLUMN_NAME = 'source_hash'
);
SET @qc_face_source_hash_sql := IF(
  @qc_face_source_hash_missing,
  'ALTER TABLE `quick_create_face_verifications` ADD COLUMN `source_hash` char(64) NOT NULL DEFAULT '''' COMMENT ''规范化图片地址 SHA-256'' AFTER `source_url`',
  'SELECT 1'
);
PREPARE qc_face_source_hash_stmt FROM @qc_face_source_hash_sql;
EXECUTE qc_face_source_hash_stmt;
DEALLOCATE PREPARE qc_face_source_hash_stmt;

-- 旧版本表没有 source_hash，新增字段后历史记录会全部是空字符串；
-- 使用 source_url 与主键生成稳定且唯一的回填值，避免建唯一索引时冲突。
UPDATE `quick_create_face_verifications`
SET `source_hash` = SHA2(CONCAT(`source_url`, '#', `id`), 256)
WHERE `source_hash` = '';

SET @qc_face_source_unique_missing := (
  SELECT COUNT(*) = 0 FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quick_create_face_verifications' AND INDEX_NAME = 'uq_qc_face_user_source_hash'
);
SET @qc_face_source_unique_sql := IF(
  @qc_face_source_unique_missing,
  'ALTER TABLE `quick_create_face_verifications` ADD UNIQUE KEY `uq_qc_face_user_source_hash` (`user_id`,`source_hash`)',
  'SELECT 1'
);
PREPARE qc_face_source_unique_stmt FROM @qc_face_source_unique_sql;
EXECUTE qc_face_source_unique_stmt;
DEALLOCATE PREPARE qc_face_source_unique_stmt;
