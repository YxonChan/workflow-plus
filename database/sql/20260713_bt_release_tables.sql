-- Malulu 宝塔生产环境发布迁移
-- 日期：2026-07-13
-- 作用：只创建/补齐 AI 剧本创作、Hermes 外部评分与角色音色所需的 7 张表。
-- 安全性：不删除表、不清空数据、不导入本地测试数据，可重复执行。
-- 要求：MySQL 8.0；执行前请先在宝塔备份当前数据库。

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `script_ai_configs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `config_key` varchar(32) NOT NULL DEFAULT '',
  `name` varchar(80) NOT NULL DEFAULT '',
  `description` varchar(255) NOT NULL DEFAULT '',
  `model_config_id` int unsigned NOT NULL DEFAULT 0 COMMENT '0=继承默认配置或用户默认文本模型',
  `system_prompt` longtext,
  `task_prompt` longtext,
  `temperature` decimal(4,2) DEFAULT NULL COMMENT 'NULL=继承默认配置',
  `max_tokens` int unsigned DEFAULT NULL COMMENT 'NULL=继承默认配置',
  `enabled` tinyint unsigned NOT NULL DEFAULT 1,
  `sort` int unsigned NOT NULL DEFAULT 0,
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_script_ai_config_user_key` (`user_id`,`config_key`),
  KEY `idx_script_ai_config_user_sort` (`user_id`,`sort`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='剧本创作 AI 默认与角色配置';

CREATE TABLE IF NOT EXISTS `script_ai_projects` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `title` varchar(180) NOT NULL DEFAULT '',
  `genre` varchar(80) NOT NULL DEFAULT '',
  `output_language` varchar(16) NOT NULL DEFAULT 'zh-CN' COMMENT 'zh-CN|en',
  `region_style` varchar(16) NOT NULL DEFAULT 'mainland' COMMENT 'mainland|overseas',
  `synopsis` text,
  `requirements` text,
  `status` varchar(24) NOT NULL DEFAULT 'draft' COMMENT 'draft|queued|running|paused|completed|failed|cancelled',
  `current_step_key` varchar(32) NOT NULL DEFAULT 'planning',
  `waiting_message` varchar(255) NOT NULL DEFAULT '',
  `pause_requested` tinyint unsigned NOT NULL DEFAULT 0,
  `run_no` int unsigned NOT NULL DEFAULT 0,
  `final_content` longtext,
  `error_message` text,
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_script_ai_project_user_status` (`user_id`,`status`,`id`),
  KEY `idx_script_ai_project_worker` (`status`,`pause_requested`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='AI 驱动剧本创作项目';

CREATE TABLE IF NOT EXISTS `script_ai_steps` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint unsigned NOT NULL,
  `step_key` varchar(32) NOT NULL DEFAULT '',
  `step_name` varchar(80) NOT NULL DEFAULT '',
  `agent_config_key` varchar(32) NOT NULL DEFAULT '',
  `agent_name` varchar(80) NOT NULL DEFAULT '',
  `sort` int unsigned NOT NULL DEFAULT 0,
  `run_no` int unsigned NOT NULL DEFAULT 0,
  `status` varchar(24) NOT NULL DEFAULT 'pending' COMMENT 'pending|queued|running|completed|failed|skipped',
  `attempts` int unsigned NOT NULL DEFAULT 0,
  `model_config_id` int unsigned NOT NULL DEFAULT 0,
  `system_prompt_snapshot` longtext,
  `task_prompt_snapshot` longtext,
  `input_content` longtext,
  `output_content` longtext,
  `error_message` text,
  `duration_ms` int unsigned NOT NULL DEFAULT 0,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_script_ai_step` (`project_id`,`step_key`),
  KEY `idx_script_ai_step_project_sort` (`project_id`,`sort`,`id`),
  KEY `idx_script_ai_step_worker` (`status`,`project_id`,`sort`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='AI 剧本创作运行步骤';

CREATE TABLE IF NOT EXISTS `script_external_clients` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `client_key` varchar(32) NOT NULL DEFAULT 'hermes',
  `name` varchar(120) NOT NULL DEFAULT 'Hermes Agent',
  `token_prefix` varchar(20) NOT NULL DEFAULT '',
  `token_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `status` varchar(24) NOT NULL DEFAULT 'active' COMMENT 'active|revoked',
  `last_used_at` datetime DEFAULT NULL,
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_script_external_client_user_key` (`user_id`,`client_key`),
  KEY `idx_script_external_client_token` (`token_prefix`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='剧本外部评分客户端';

CREATE TABLE IF NOT EXISTS `script_ai_scores` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint unsigned NOT NULL DEFAULT 0,
  `external_client_id` bigint unsigned NOT NULL DEFAULT 0,
  `scorer_name` varchar(120) NOT NULL DEFAULT 'Hermes Agent',
  `score_version` varchar(64) NOT NULL DEFAULT 'v1',
  `request_id` varchar(100) DEFAULT NULL,
  `overall_score` decimal(5,2) NOT NULL DEFAULT 0.00,
  `dimensions_json` json DEFAULT NULL,
  `summary` text,
  `strengths` text,
  `weaknesses` text,
  `suggestions` text,
  `raw_payload_json` json DEFAULT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'submitted',
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_script_score_version` (`project_id`,`external_client_id`,`score_version`),
  UNIQUE KEY `uniq_script_score_request` (`external_client_id`,`request_id`),
  KEY `idx_script_score_project_time` (`project_id`,`update_time`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='外部 Agent 剧本评分';

CREATE TABLE IF NOT EXISTS `voice_assets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT 'users.id',
  `asset_id` int unsigned NOT NULL DEFAULT 0 COMMENT '角色 assets.id',
  `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `source_url` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `original_filename` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `mime_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `extension` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `file_size` bigint unsigned NOT NULL DEFAULT 0,
  `duration_ms` int unsigned NOT NULL DEFAULT 0,
  `sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ready',
  `rights_confirmed` tinyint unsigned NOT NULL DEFAULT 0,
  `provider_meta_json` json DEFAULT NULL,
  `error_message` varchar(2000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `deleted_at` datetime DEFAULT NULL,
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_voice_assets_character_status` (`asset_id`,`status`,`id`),
  KEY `idx_voice_assets_user_status` (`user_id`,`status`,`id`),
  KEY `idx_voice_assets_user_hash` (`user_id`,`sha256`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户音色资产';

CREATE TABLE IF NOT EXISTS `voice_asset_bindings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `voice_asset_id` bigint unsigned NOT NULL DEFAULT 0,
  `model_config_id` int unsigned NOT NULL DEFAULT 0,
  `provider` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `provider_asset_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `provider_payload_json` json DEFAULT NULL,
  `error_message` varchar(2000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_voice_binding_model` (`voice_asset_id`,`model_config_id`),
  KEY `idx_voice_binding_status` (`model_config_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='音色资产供应商绑定';

-- 兼容曾经手动创建过旧结构的 script_ai_projects。
SET @script_project_output_language_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'script_ai_projects' AND COLUMN_NAME = 'output_language'
);
SET @script_project_output_language_sql = IF(
  @script_project_output_language_exists = 0,
  'ALTER TABLE `script_ai_projects` ADD COLUMN `output_language` varchar(16) NOT NULL DEFAULT ''zh-CN'' AFTER `genre`',
  'SELECT 1'
);
PREPARE script_project_output_language_stmt FROM @script_project_output_language_sql;
EXECUTE script_project_output_language_stmt;
DEALLOCATE PREPARE script_project_output_language_stmt;

SET @script_project_region_style_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'script_ai_projects' AND COLUMN_NAME = 'region_style'
);
SET @script_project_region_style_sql = IF(
  @script_project_region_style_exists = 0,
  'ALTER TABLE `script_ai_projects` ADD COLUMN `region_style` varchar(16) NOT NULL DEFAULT ''mainland'' AFTER `output_language`',
  'SELECT 1'
);
PREPARE script_project_region_style_stmt FROM @script_project_region_style_sql;
EXECUTE script_project_region_style_stmt;
DEALLOCATE PREPARE script_project_region_style_stmt;

SET @script_project_run_no_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'script_ai_projects' AND COLUMN_NAME = 'run_no'
);
SET @script_project_run_no_sql = IF(
  @script_project_run_no_exists = 0,
  'ALTER TABLE `script_ai_projects` ADD COLUMN `run_no` int unsigned NOT NULL DEFAULT 0 AFTER `pause_requested`',
  'SELECT 1'
);
PREPARE script_project_run_no_stmt FROM @script_project_run_no_sql;
EXECUTE script_project_run_no_stmt;
DEALLOCATE PREPARE script_project_run_no_stmt;

SET @script_step_run_no_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'script_ai_steps' AND COLUMN_NAME = 'run_no'
);
SET @script_step_run_no_sql = IF(
  @script_step_run_no_exists = 0,
  'ALTER TABLE `script_ai_steps` ADD COLUMN `run_no` int unsigned NOT NULL DEFAULT 0 AFTER `sort`',
  'SELECT 1'
);
PREPARE script_step_run_no_stmt FROM @script_step_run_no_sql;
EXECUTE script_step_run_no_stmt;
DEALLOCATE PREPARE script_step_run_no_stmt;

-- 兼容曾经创建过“用户级音色”旧结构的 voice_assets。
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

-- 执行结果应返回 7 行；缺少任何一行均表示迁移未完整完成。
SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'script_ai_configs',
    'script_ai_projects',
    'script_ai_scores',
    'script_ai_steps',
    'script_external_clients',
    'voice_assets',
    'voice_asset_bindings'
  )
ORDER BY TABLE_NAME;
