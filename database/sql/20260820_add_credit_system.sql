-- Credit system phase 1 (2026-08-20)
-- 1 credit = ¥0.01; admin top-up + consume on successful AI calls.

SET NAMES utf8mb4;

-- users.credit_balance
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'credit_balance'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `users` ADD COLUMN `credit_balance` DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT ''积分余额，1积分=0.01元'' AFTER `status`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ai_request_logs.credits_charged
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ai_request_logs'
    AND COLUMN_NAME = 'credits_charged'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `ai_request_logs` ADD COLUMN `credits_charged` DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT ''本次成功扣费积分'' AFTER `total_tokens`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `credit_ledger` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `entry_type` varchar(32) NOT NULL COMMENT 'topup|consume|refund|adjust',
  `amount` decimal(14,2) NOT NULL COMMENT '入账为正，扣费为负',
  `balance_after` decimal(14,2) NOT NULL,
  `modality` varchar(16) NOT NULL DEFAULT '' COMMENT 'text|image|video|',
  `model_config_id` int unsigned DEFAULT NULL,
  `model_id` varchar(120) NOT NULL DEFAULT '',
  `ref_type` varchar(32) NOT NULL DEFAULT '' COMMENT 'ai_request_log|video_job|asset_image_job|admin',
  `ref_id` bigint unsigned NOT NULL DEFAULT 0,
  `operator_id` int unsigned NOT NULL DEFAULT 0,
  `description` varchar(255) NOT NULL DEFAULT '',
  `meta_json` json DEFAULT NULL,
  `create_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_credit_ledger_user_time` (`user_id`, `create_time`),
  KEY `idx_credit_ledger_ref` (`ref_type`, `ref_id`),
  KEY `idx_credit_ledger_type_time` (`entry_type`, `create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='积分流水';
