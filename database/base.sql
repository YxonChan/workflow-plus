-- MySQL dump 10.13  Distrib 8.0.46, for Linux (x86_64)
--
-- Host: localhost    Database: aimage
-- ------------------------------------------------------
-- Server version	8.0.46

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `admin_operation_logs`
--

DROP TABLE IF EXISTS `admin_operation_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin_operation_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `operator_user_id` int unsigned NOT NULL DEFAULT '0' COMMENT '实际操作人 users.id',
  `operator_name_snapshot` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '操作时用户名/显示名快照',
  `action` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '如 series.create',
  `target_type` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'series/episode/asset',
  `target_id` bigint unsigned NOT NULL DEFAULT '0',
  `target_name_snapshot` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `series_id` int unsigned DEFAULT NULL,
  `episode_id` int unsigned DEFAULT NULL,
  `workflow_run_id` bigint unsigned DEFAULT NULL,
  `result` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'success' COMMENT 'success/failed',
  `error_message` varchar(2000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `ip` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `user_agent` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `request_id` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `meta_json` json DEFAULT NULL,
  `before_json` json DEFAULT NULL,
  `after_json` json DEFAULT NULL,
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `idx_admin_operation_logs_operator_time` (`operator_user_id`,`create_time`) USING BTREE,
  KEY `idx_admin_operation_logs_action_time` (`action`,`create_time`) USING BTREE,
  KEY `idx_admin_operation_logs_target` (`target_type`,`target_id`,`create_time`) USING BTREE,
  KEY `idx_admin_operation_logs_series` (`series_id`,`create_time`) USING BTREE,
  KEY `idx_admin_operation_logs_episode` (`episode_id`,`create_time`) USING BTREE,
  KEY `idx_admin_operation_logs_result` (`result`,`create_time`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=395 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='管理员可读业务操作审计日志';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `agent_tasks`
--

DROP TABLE IF EXISTS `agent_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_tasks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT '1' COMMENT 'users.id',
  `task_no` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `agent_id` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `title` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `source_type` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'script_text',
  `source_file_token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `series_id` int unsigned DEFAULT NULL,
  `series_workflow_id` int unsigned DEFAULT NULL,
  `episode_workflow_id` int unsigned DEFAULT NULL,
  `series_workflow_run_id` bigint unsigned DEFAULT NULL,
  `episode_count` int unsigned DEFAULT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'created',
  `phase` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'created',
  `checkpoint` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `progress` tinyint unsigned NOT NULL DEFAULT '0',
  `request_json` json DEFAULT NULL,
  `review_payload_json` json DEFAULT NULL,
  `result_json` json DEFAULT NULL,
  `meta_json` json DEFAULT NULL,
  `callback_url` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `error_message` varchar(2000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uniq_agent_tasks_task_no` (`task_no`) USING BTREE,
  KEY `idx_agent_tasks_user_status` (`user_id`,`status`,`id`) USING BTREE,
  KEY `idx_agent_tasks_series` (`series_id`) USING BTREE,
  KEY `idx_agent_tasks_run` (`series_workflow_run_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='Agent-facing independent task state';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ai_request_logs`
--

DROP TABLE IF EXISTS `ai_request_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_request_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT '1' COMMENT 'users.id',
  `source` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '业务来源，如 series_workflow',
  `workflow_run_id` bigint unsigned DEFAULT NULL COMMENT 'workflow_runs.id',
  `workflow_run_node_id` bigint unsigned DEFAULT NULL COMMENT 'workflow_run_nodes.id',
  `model_config_id` int unsigned DEFAULT NULL COMMENT 'model_configs.id',
  `llm_model` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '上游模型名（如 gpt-4o）',
  `finish_reason` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'stop|length',
  `max_tokens` int unsigned NOT NULL DEFAULT '0' COMMENT 'max_tokens',
  `usage_json` json DEFAULT NULL COMMENT 'token usage',
  `prompt_tokens` int unsigned NOT NULL DEFAULT '0' COMMENT 'usage.prompt_tokens',
  `completion_tokens` int unsigned NOT NULL DEFAULT '0' COMMENT 'usage.completion_tokens',
  `total_tokens` int unsigned NOT NULL DEFAULT '0' COMMENT 'usage.total_tokens',
  `endpoint` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '实际请求 URL',
  `context_json` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT '业务上下文 JSON（series_id、node_label 等，不含密钥）',
  `request_json` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT '请求体 JSON（不含 Authorization）',
  `http_status` smallint unsigned NOT NULL DEFAULT '0',
  `response_body` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT '原始响应体（截断存储）',
  `curl_errno` int NOT NULL DEFAULT '0',
  `curl_error` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `request_ok` tinyint unsigned NOT NULL DEFAULT '0' COMMENT '1=业务判定成功拿到 assistant 内容',
  `error_message` varchar(2000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '应用层错误说明',
  `duration_ms` int unsigned NOT NULL DEFAULT '0',
  `content_preview` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '解析出的 assistant 内容前若干字',
  `assistant_content` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'assistant 全文',
  `create_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `idx_ai_logs_source` (`source`) USING BTREE,
  KEY `idx_ai_logs_create_time` (`create_time`) USING BTREE,
  KEY `idx_ai_logs_model_config` (`model_config_id`) USING BTREE,
  KEY `idx_ai_logs_request_ok` (`request_ok`) USING BTREE,
  KEY `idx_ai_logs_workflow_run` (`workflow_run_id`) USING BTREE,
  KEY `idx_ai_logs_workflow_run_node` (`workflow_run_node_id`) USING BTREE,
  KEY `idx_ai_request_logs_user_id` (`user_id`) USING BTREE,
  KEY `idx_ai_logs_user_time` (`user_id`,`create_time`) USING BTREE,
  KEY `idx_ai_logs_user_model_time` (`user_id`,`model_config_id`,`create_time`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=2128 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='AI HTTP 请求审计';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_image_jobs`
--

DROP TABLE IF EXISTS `asset_image_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_image_jobs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT '1' COMMENT 'users.id',
  `asset_id` int unsigned DEFAULT NULL,
  `asset_image_id` int unsigned DEFAULT NULL,
  `model_config_id` int unsigned NOT NULL DEFAULT '0',
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  `prompt` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `final_prompt` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `view_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'main',
  `main_image_url` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `result_url` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `attempts` int unsigned NOT NULL DEFAULT '0',
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  `retry_after` datetime DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `idx_status_id` (`status`,`id`) USING BTREE,
  KEY `idx_asset_id` (`asset_id`) USING BTREE,
  KEY `idx_model_config_id` (`model_config_id`) USING BTREE,
  KEY `idx_asset_image_jobs_user_id` (`user_id`) USING BTREE,
  KEY `idx_asset_image_jobs_image` (`asset_image_id`) USING BTREE,
  KEY `idx_asset_image_jobs_retry` (`status`, `retry_after`, `id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=822 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_image_versions`
--

DROP TABLE IF EXISTS `asset_image_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_image_versions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT '0',
  `asset_id` int unsigned NOT NULL DEFAULT '0',
  `asset_image_id` int unsigned DEFAULT NULL,
  `model_config_id` int unsigned NOT NULL DEFAULT '0',
  `view_type` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'reference',
  `url` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `prompt` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `source` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'generated',
  `is_selected` tinyint(1) NOT NULL DEFAULT '0',
  `job_id` bigint unsigned DEFAULT NULL,
  `ai_request_log_id` bigint unsigned DEFAULT NULL,
  `meta_json` json DEFAULT NULL,
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `idx_aiv_asset_view` (`asset_id`,`view_type`,`id`) USING BTREE,
  KEY `idx_aiv_asset_image` (`asset_image_id`) USING BTREE,
  KEY `idx_aiv_job` (`job_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=659 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='Asset image generated versions';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_images`
--

DROP TABLE IF EXISTS `asset_images`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_images` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT '1' COMMENT 'users.id',
  `asset_id` int unsigned NOT NULL,
  `view_type` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'reference' COMMENT 'front/side/back/three_view/reference',
  `url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `note` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `image_prompt` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'è¯¥å›¾ç‰‡/è§†å›¾çš„ç”Ÿå›¾æç¤ºè¯',
  `sort` int unsigned NOT NULL DEFAULT '0',
  `reference_role` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'view',
  `variant_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `reference_key` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `video_ref_url` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'hidden video ref (realistic colored-pencil); UI uses url only',
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `idx_asset_sort` (`asset_id`,`sort`) USING BTREE,
  KEY `idx_asset_images_user_id` (`user_id`) USING BTREE,
  KEY `idx_asset_images_reference` (`asset_id`,`reference_role`,`reference_key`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=648 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='Asset image variants';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_shares`
--

DROP TABLE IF EXISTS `asset_shares`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_shares` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `asset_id` int unsigned NOT NULL COMMENT 'assets.id',
  `owner_user_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'owner users.id at share time',
  `shared_with_user_id` int unsigned NOT NULL DEFAULT '0' COMMENT '0=shared with all regular users; otherwise users.id',
  `create_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uniq_asset_share` (`asset_id`,`shared_with_user_id`) USING BTREE,
  KEY `idx_asset_shares_shared_with` (`shared_with_user_id`) USING BTREE,
  KEY `idx_asset_shares_owner` (`owner_user_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='Asset share relations';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `assets`
--

DROP TABLE IF EXISTS `assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `assets` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT '1' COMMENT 'users.id',
  `series_id` int unsigned NOT NULL,
  `type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'character/scene/prop',
  `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `tags` json DEFAULT NULL,
  `is_hidden` tinyint unsigned NOT NULL DEFAULT '0',
  `sort` int unsigned NOT NULL DEFAULT '0',
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `idx_series_type_sort` (`series_id`,`type`,`sort`) USING BTREE,
  KEY `idx_assets_user_id` (`user_id`) USING BTREE,
  KEY `idx_assets_series_hidden` (`series_id`,`is_hidden`,`type`,`sort`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=752 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='Assets: character/scene/prop';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `custom_nodes`
--

DROP TABLE IF EXISTS `custom_nodes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `custom_nodes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT '1' COMMENT 'users.id',
  `label` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `icon` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Menu',
  `kind` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'text',
  `scope` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'episode' COMMENT 'episode|series',
  `desc` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `is_fixed` tinyint unsigned NOT NULL DEFAULT '0',
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `idx_custom_nodes_user_id` (`user_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=112 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='User defined custom nodes';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `episode_workflow_node_states`
--

DROP TABLE IF EXISTS `episode_workflow_node_states`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `episode_workflow_node_states` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT '1' COMMENT 'users.id',
  `series_id` int unsigned NOT NULL DEFAULT '0',
  `episode_id` int unsigned NOT NULL DEFAULT '0',
  `workflow_id` int unsigned NOT NULL DEFAULT '0',
  `workflow_run_id` bigint unsigned DEFAULT NULL COMMENT 'workflow_runs.id',
  `workflow_run_node_id` bigint unsigned DEFAULT NULL COMMENT 'workflow_run_nodes.id',
  `workflow_node_id` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `label` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `kind` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `sort` int unsigned NOT NULL DEFAULT '0',
  `status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued' COMMENT 'queued|running|success|failed|skipped|stale',
  `input_json` json DEFAULT NULL,
  `output_json` json DEFAULT NULL,
  `raw_output` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `upstream_snapshot_json` json DEFAULT NULL COMMENT 'å½“å‰æ€ä½¿ç”¨çš„ä¸Šæ¸¸æ‘˜è¦',
  `version_token` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `error_message` varchar(2000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `duration_ms` int unsigned NOT NULL DEFAULT '0',
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uniq_episode_workflow_node` (`episode_id`,`workflow_node_id`) USING BTREE,
  KEY `idx_episode_workflow_node_episode_sort` (`episode_id`,`sort`) USING BTREE,
  KEY `idx_episode_workflow_node_status` (`status`) USING BTREE,
  KEY `idx_episode_workflow_node_run_node` (`workflow_run_node_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=3095 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='å‰§é›†å·¥ä½œæµèŠ‚ç‚¹å½“å‰çŠ¶æ€';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `episodes`
--

DROP TABLE IF EXISTS `episodes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `episodes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT '1' COMMENT 'users.id',
  `series_id` int unsigned NOT NULL,
  `number` int unsigned NOT NULL DEFAULT '1' COMMENT '集序号，如 1、2',
  `title` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft' COMMENT 'draft|production|done',
  `workflow_id` int unsigned DEFAULT NULL COMMENT '关联 workflows.id',
  `plot_input` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT '本集剧情/剧本，注入工作流输入节点',
  `promo_segment_count` tinyint unsigned DEFAULT NULL COMMENT '宣传片每集视频段数 1-4',
  `current_storyboard_revision_id` bigint unsigned DEFAULT NULL COMMENT 'å½“å‰åˆ†é•œä¿®è®¢ç‰ˆ storyboard_revisions.id',
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `idx_episodes_series_id` (`series_id`) USING BTREE,
  KEY `idx_episodes_workflow_id` (`workflow_id`) USING BTREE,
  KEY `idx_episodes_user_id` (`user_id`) USING BTREE,
  KEY `idx_episodes_storyboard_revision` (`current_storyboard_revision_id`) USING BTREE,
  CONSTRAINT `fk_episodes_series` FOREIGN KEY (`series_id`) REFERENCES `series` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=661 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='分集';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `model_configs`
--

DROP TABLE IF EXISTS `model_configs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `model_configs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT '1' COMMENT 'users.id',
  `scope` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'global' COMMENT 'global|user',
  `type` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'text/image/video/voice',
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_id` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `endpoint` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `api_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `enabled` tinyint unsigned NOT NULL DEFAULT '1' COMMENT '1=enabled',
  `options` json DEFAULT NULL,
  `sort` int unsigned NOT NULL DEFAULT '0',
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `idx_type_default` (`type`,`is_default`) USING BTREE,
  KEY `idx_type_sort` (`type`,`sort`) USING BTREE,
  KEY `idx_model_configs_user_id` (`user_id`) USING BTREE,
  KEY `idx_model_configs_scope_user_type` (`scope`,`user_id`,`type`,`enabled`,`is_default`,`sort`,`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=66 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='Studio model configurations';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `prompt_templates`
--

DROP TABLE IF EXISTS `prompt_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `prompt_templates` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT '1' COMMENT 'users.id',
  `title` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '模板名称',
  `scope` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'episode' COMMENT 'episode|series|all',
  `node_kind` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'text' COMMENT 'text|image|video|voice|input|output|all',
  `node_label` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '推荐匹配的节点名称，空表示通用',
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '用途说明',
  `prompt` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '提示词正文',
  `tags` json DEFAULT NULL COMMENT '标签',
  `is_system` tinyint unsigned NOT NULL DEFAULT '0' COMMENT '系统内置模板',
  `sort` int NOT NULL DEFAULT '0',
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `idx_prompt_templates_scope_kind` (`scope`,`node_kind`) USING BTREE,
  KEY `idx_prompt_templates_label` (`node_label`) USING BTREE,
  KEY `idx_prompt_templates_system` (`is_system`,`sort`) USING BTREE,
  KEY `idx_prompt_templates_user_id` (`user_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=148 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='工作流提示词模板库';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `quick_create_messages`
--

DROP TABLE IF EXISTS `quick_create_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `quick_create_messages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'users.id',
  `mode` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'video' COMMENT 'image|video',
  `prompt` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `edit_instruction` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT '本次图片编辑要求',
  `model_config_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'model_configs.id，0=用户默认模型',
  `parent_message_id` bigint unsigned DEFAULT NULL COMMENT '源速创消息 ID',
  `parent_result_index` tinyint unsigned DEFAULT NULL COMMENT '源消息结果索引',
  `asset_refs_json` json DEFAULT NULL COMMENT '@引用的资产与上传文件',
  `options_json` json DEFAULT NULL COMMENT '比例/分辨率/时长/条数/声音等参数',
  `status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued' COMMENT 'queued|running|success|failed',
  `result_urls_json` json DEFAULT NULL COMMENT '生成结果 URL 列表',
  `error_message` varchar(2000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `ai_request_log_id` bigint unsigned DEFAULT NULL COMMENT 'ai_request_logs.id（最后一次请求）',
  `attempts` int unsigned NOT NULL DEFAULT '0',
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `idx_quick_create_user` (`user_id`,`id`) USING BTREE,
  KEY `idx_quick_create_status` (`status`) USING BTREE,
  KEY `idx_quick_create_parent` (`parent_message_id`,`parent_result_index`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=37 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='灵感速创对话消息（对话式快速生成）';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `script_ai_configs`
--

DROP TABLE IF EXISTS `script_ai_configs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `script_ai_configs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT '0',
  `config_key` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `name` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `model_config_id` int unsigned NOT NULL DEFAULT '0',
  `system_prompt` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `task_prompt` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `temperature` decimal(4,2) DEFAULT NULL,
  `max_tokens` int unsigned DEFAULT NULL,
  `enabled` tinyint unsigned NOT NULL DEFAULT '1',
  `sort` int unsigned NOT NULL DEFAULT '0',
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uniq_script_ai_config_user_key` (`user_id`,`config_key`) USING BTREE,
  KEY `idx_script_ai_config_user_sort` (`user_id`,`sort`,`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='剧本创作 AI 默认与角色配置';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `script_ai_projects`
--

DROP TABLE IF EXISTS `script_ai_projects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `script_ai_projects` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT '0',
  `title` varchar(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `genre` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `output_language` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'zh-CN',
  `region_style` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'mainland',
  `synopsis` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `requirements` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `current_step_key` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'planning',
  `waiting_message` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `pause_requested` tinyint unsigned NOT NULL DEFAULT '0',
  `run_no` int unsigned NOT NULL DEFAULT '0',
  `final_content` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `idx_script_ai_project_user_status` (`user_id`,`status`,`id`) USING BTREE,
  KEY `idx_script_ai_project_worker` (`status`,`pause_requested`,`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='AI 驱动剧本创作项目';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `script_ai_scores`
--

DROP TABLE IF EXISTS `script_ai_scores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `script_ai_scores` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint unsigned NOT NULL DEFAULT '0',
  `external_client_id` bigint unsigned NOT NULL DEFAULT '0',
  `scorer_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Hermes Agent',
  `score_version` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'v1',
  `request_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `overall_score` decimal(5,2) NOT NULL DEFAULT '0.00',
  `dimensions_json` json DEFAULT NULL,
  `summary` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `strengths` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `weaknesses` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `suggestions` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `raw_payload_json` json DEFAULT NULL,
  `status` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'submitted',
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uniq_script_score_version` (`project_id`,`external_client_id`,`score_version`) USING BTREE,
  UNIQUE KEY `uniq_script_score_request` (`external_client_id`,`request_id`) USING BTREE,
  KEY `idx_script_score_project_time` (`project_id`,`update_time`,`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='外部 Agent 剧本评分';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `script_ai_steps`
--

DROP TABLE IF EXISTS `script_ai_steps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `script_ai_steps` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint unsigned NOT NULL,
  `step_key` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `step_name` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `agent_config_key` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `agent_name` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `sort` int unsigned NOT NULL DEFAULT '0',
  `run_no` int unsigned NOT NULL DEFAULT '0',
  `status` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `attempts` int unsigned NOT NULL DEFAULT '0',
  `model_config_id` int unsigned NOT NULL DEFAULT '0',
  `system_prompt_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `task_prompt_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `input_content` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `output_content` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `duration_ms` int unsigned NOT NULL DEFAULT '0',
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uniq_script_ai_step` (`project_id`,`step_key`) USING BTREE,
  KEY `idx_script_ai_step_project_sort` (`project_id`,`sort`,`id`) USING BTREE,
  KEY `idx_script_ai_step_worker` (`status`,`project_id`,`sort`,`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='AI 剧本创作运行步骤';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `script_external_clients`
--

DROP TABLE IF EXISTS `script_external_clients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `script_external_clients` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT '0',
  `client_key` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'hermes',
  `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Hermes Agent',
  `token_prefix` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `token_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `status` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `last_used_at` datetime DEFAULT NULL,
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uniq_script_external_client_user_key` (`user_id`,`client_key`) USING BTREE,
  KEY `idx_script_external_client_token` (`token_prefix`,`status`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='剧本外部评分客户端';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `script_project_members`
--

DROP TABLE IF EXISTS `script_project_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `script_project_members` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `role` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `create_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_script_member_role` (`project_id`,`user_id`,`role`),
  KEY `idx_script_members_user_project` (`user_id`,`project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='剧本协作成员';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `script_project_stages`
--

DROP TABLE IF EXISTS `script_project_stages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `script_project_stages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint unsigned NOT NULL,
  `stage_key` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `stage_name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `sort` int unsigned NOT NULL DEFAULT '0',
  `assignee_user_id` int unsigned NOT NULL DEFAULT '0',
  `status` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT 'pending|in_progress|reviewing|revising|approved',
  `content` longtext COLLATE utf8mb4_unicode_ci,
  `submitted_version_id` bigint unsigned DEFAULT NULL,
  `approved_version_id` bigint unsigned DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_script_stage` (`project_id`,`stage_key`),
  KEY `idx_script_stage_project_sort` (`project_id`,`sort`),
  KEY `idx_script_stage_assignee` (`assignee_user_id`,`status`,`project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='剧本协作阶段';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `script_projects`
--

DROP TABLE IF EXISTS `script_projects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `script_projects` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT '0',
  `title` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `genre` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `synopsis` text COLLATE utf8mb4_unicode_ci,
  `requirements` text COLLATE utf8mb4_unicode_ci,
  `coordinator_user_id` int unsigned NOT NULL DEFAULT '0',
  `status` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft' COMMENT 'draft|reviewing|revising|finalized',
  `current_stage` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'brief',
  `current_version_id` bigint unsigned DEFAULT NULL,
  `score_status` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none' COMMENT 'none|pending|processing|scored|failed',
  `latest_score_task_id` bigint unsigned DEFAULT NULL,
  `finalized_at` datetime DEFAULT NULL,
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_script_projects_user_status` (`user_id`,`status`,`id`),
  KEY `idx_script_projects_coordinator` (`coordinator_user_id`,`status`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='剧本协作项目';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `script_reviews`
--

DROP TABLE IF EXISTS `script_reviews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `script_reviews` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint unsigned NOT NULL,
  `stage_id` bigint unsigned NOT NULL,
  `version_id` bigint unsigned NOT NULL,
  `reviewer_user_id` int unsigned NOT NULL DEFAULT '0',
  `decision` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'comment' COMMENT 'comment|approved|rejected',
  `feedback` text COLLATE utf8mb4_unicode_ci,
  `create_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_script_reviews_project_id` (`project_id`,`id`),
  KEY `idx_script_reviews_version_id` (`version_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='剧本审核与评论';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `script_score_tasks`
--

DROP TABLE IF EXISTS `script_score_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `script_score_tasks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint unsigned NOT NULL,
  `version_id` bigint unsigned NOT NULL,
  `content_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `status` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT 'pending|processing|scored|failed',
  `overall_score` decimal(5,2) DEFAULT NULL,
  `dimensions_json` json DEFAULT NULL,
  `analysis` longtext COLLATE utf8mb4_unicode_ci,
  `lease_token` char(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `lease_owner` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `lease_expires_at` datetime DEFAULT NULL,
  `attempts` int unsigned NOT NULL DEFAULT '0',
  `idempotency_key` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `hermes_job_id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `claimed_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_script_score_version` (`version_id`),
  KEY `idx_script_score_claim` (`status`,`lease_expires_at`,`id`),
  KEY `idx_script_score_project` (`project_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Hermes剧本评分任务';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `script_versions`
--

DROP TABLE IF EXISTS `script_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `script_versions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint unsigned NOT NULL,
  `stage_id` bigint unsigned NOT NULL,
  `version_no` int unsigned NOT NULL DEFAULT '1',
  `stage_revision_no` int unsigned NOT NULL DEFAULT '1',
  `author_user_id` int unsigned NOT NULL DEFAULT '0',
  `content` longtext COLLATE utf8mb4_unicode_ci,
  `content_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `status` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT 'pending|approved|rejected',
  `submitted_at` datetime DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_script_project_version` (`project_id`,`version_no`),
  KEY `idx_script_version_stage_revision` (`stage_id`,`stage_revision_no`),
  KEY `idx_script_version_hash` (`content_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='剧本内容版本';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `series`
--

DROP TABLE IF EXISTS `series`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `series` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT '1' COMMENT 'users.id',
  `title` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `description` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `series_workflow_id` int unsigned DEFAULT NULL COMMENT '剧本级工作流 workflows.id',
  `visual_style` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'realistic' COMMENT '视觉风格：realistic(真人/写实), anime(动漫/二次元), 3d(3D渲染)',
  `visual_style_variant` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '二级视觉风格',
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  `region` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `idx_series_create_time` (`create_time`) USING BTREE,
  KEY `idx_series_user_id` (`user_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=117 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='剧集项目';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `series_shares`
--

DROP TABLE IF EXISTS `series_shares`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `series_shares` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `series_id` int unsigned NOT NULL COMMENT 'series.id',
  `owner_user_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'owner users.id at share time',
  `shared_with_user_id` int unsigned NOT NULL DEFAULT '0' COMMENT '0=shared with all regular users; otherwise users.id',
  `create_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uniq_series_share` (`series_id`,`shared_with_user_id`) USING BTREE,
  KEY `idx_series_shares_shared_with` (`shared_with_user_id`) USING BTREE,
  KEY `idx_series_shares_owner` (`owner_user_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='Series-level asset share relations';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `shot_media_versions`
--

DROP TABLE IF EXISTS `shot_media_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shot_media_versions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT '0',
  `series_id` int unsigned NOT NULL DEFAULT '0',
  `episode_id` int unsigned NOT NULL DEFAULT '0',
  `storyboard_revision_id` bigint unsigned DEFAULT NULL,
  `shot_id` int unsigned NOT NULL DEFAULT '0',
  `workflow_run_node_id` bigint unsigned DEFAULT NULL,
  `video_job_id` bigint unsigned DEFAULT NULL,
  `parent_version_id` bigint unsigned DEFAULT NULL COMMENT 'æœ¬è§†é¢‘ç‰ˆæœ¬åŸºäºŽä¸Šæ¸¸é•œå¤´å“ªä¸ªè¢«é€‰ä¸­ç‰ˆæœ¬ç”Ÿæˆï¼Œç”¨äºŽå°¾å¸§è¡”æŽ¥çš„ç²¾ç¡®è¡€ç¼˜è”åŠ¨',
  `model_config_id` int unsigned NOT NULL DEFAULT '0',
  `media_type` enum('image','video') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `url` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `poster_url` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `end_frame_url` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `prompt` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `source` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'generated',
  `is_selected` tinyint(1) NOT NULL DEFAULT '0',
  `ai_request_log_id` bigint unsigned DEFAULT NULL,
  `meta_json` json DEFAULT NULL,
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `idx_smv_shot_type` (`shot_id`,`media_type`,`id`) USING BTREE,
  KEY `idx_smv_episode` (`episode_id`,`media_type`) USING BTREE,
  KEY `idx_smv_video_job` (`video_job_id`) USING BTREE,
  KEY `idx_smv_parent` (`parent_version_id`) USING BTREE,
  KEY `idx_smv_revision_type` (`storyboard_revision_id`,`media_type`,`shot_id`,`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=247 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='Shot image/video generated versions';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `shots`
--

DROP TABLE IF EXISTS `shots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shots` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT '1' COMMENT 'users.id',
  `episode_id` int unsigned NOT NULL,
  `storyboard_revision_id` bigint unsigned DEFAULT NULL,
  `index` smallint unsigned NOT NULL DEFAULT '0' COMMENT '镜头序号',
  `desc` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT '镜头描述',
  `duration` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '如 3s',
  `status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT 'pending|generating|done',
  `image_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `video_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `video_end_frame_url` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uniq_shots_revision_index` (`storyboard_revision_id`,`index`) USING BTREE,
  KEY `idx_shots_episode_id` (`episode_id`) USING BTREE,
  KEY `idx_shots_user_id` (`user_id`) USING BTREE,
  KEY `idx_shots_episode_revision` (`episode_id`,`storyboard_revision_id`,`index`) USING BTREE,
  CONSTRAINT `fk_shots_episode` FOREIGN KEY (`episode_id`) REFERENCES `episodes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1493 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='分集镜头';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `storyboard_revisions`
--

DROP TABLE IF EXISTS `storyboard_revisions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `storyboard_revisions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT '1' COMMENT 'users.id',
  `series_id` int unsigned NOT NULL DEFAULT '0',
  `episode_id` int unsigned NOT NULL DEFAULT '0',
  `workflow_run_id` bigint unsigned DEFAULT NULL,
  `workflow_run_node_id` bigint unsigned DEFAULT NULL,
  `raw_output` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `content_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `shot_count` int unsigned NOT NULL DEFAULT '0',
  `status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'current' COMMENT 'current|archived',
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `idx_storyboard_revisions_episode_status` (`episode_id`,`status`,`id`) USING BTREE,
  KEY `idx_storyboard_revisions_run_node` (`workflow_run_node_id`) USING BTREE,
  KEY `idx_storyboard_revisions_user_id` (`user_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=142 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='Storyboard processing revisions';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `translation_cache`
--

DROP TABLE IF EXISTS `translation_cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `translation_cache` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'users.id that first requested this translation',
  `source_locale` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'zh-CN',
  `target_locale` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'en-US',
  `source_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_text` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_text` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_length` int unsigned NOT NULL DEFAULT '0',
  `hit_count` int unsigned NOT NULL DEFAULT '0',
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_translation_cache_hash` (`source_locale`,`target_locale`,`source_hash`),
  KEY `idx_translation_cache_user` (`user_id`,`target_locale`),
  KEY `idx_translation_cache_update_time` (`update_time`)
) ENGINE=InnoDB AUTO_INCREMENT=220 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='UI and dynamic content translation cache';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `display_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `password_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `role` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user' COMMENT 'admin|user',
  `preferred_locale` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'zh-CN' COMMENT 'zh-CN|en-US',
  `status` tinyint unsigned NOT NULL DEFAULT '1' COMMENT '1=enabled',
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uniq_users_username` (`username`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='系统登录用户';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `video_jobs`
--

DROP TABLE IF EXISTS `video_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `video_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT '1' COMMENT 'users.id',
  `series_id` int unsigned NOT NULL DEFAULT '0',
  `episode_id` int unsigned NOT NULL DEFAULT '0',
  `storyboard_revision_id` bigint unsigned DEFAULT NULL,
  `shot_id` int unsigned NOT NULL DEFAULT '0',
  `workflow_id` int unsigned NOT NULL DEFAULT '0',
  `workflow_run_id` bigint unsigned DEFAULT NULL,
  `workflow_run_node_id` bigint unsigned DEFAULT NULL,
  `model_config_id` int unsigned NOT NULL DEFAULT '0',
  `node_id` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `node_label` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `node_prompt` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `shot_index` int unsigned NOT NULL DEFAULT '0',
  `total_shots` int unsigned NOT NULL DEFAULT '0',
  `duration` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `source_image_url` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `input_image_url` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `previous_end_frame_url` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `video_url` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `end_frame_url` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued' COMMENT 'queued|blocked|running|success|failed|cancelled',
  `depends_on_job_id` bigint unsigned DEFAULT NULL,
  `chain_shots` tinyint(1) NOT NULL DEFAULT '1',
  `shot_data_json` json DEFAULT NULL,
  `assets_json` json DEFAULT NULL,
  `video_options_json` json DEFAULT NULL,
  `request_context_json` json DEFAULT NULL,
  `ai_request_log_id` bigint unsigned DEFAULT NULL,
  `attempts` int unsigned NOT NULL DEFAULT '0',
  `error_message` varchar(2000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `idx_video_jobs_status` (`status`) USING BTREE,
  KEY `idx_video_jobs_episode` (`episode_id`) USING BTREE,
  KEY `idx_video_jobs_run_node` (`workflow_run_node_id`) USING BTREE,
  KEY `idx_video_jobs_depends` (`depends_on_job_id`) USING BTREE,
  KEY `idx_video_jobs_shot` (`shot_id`) USING BTREE,
  KEY `idx_video_jobs_user_id` (`user_id`) USING BTREE,
  KEY `idx_video_jobs_revision` (`storyboard_revision_id`,`workflow_run_node_id`,`shot_index`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=642 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='视频生成异步任务';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `voice_asset_bindings`
--

DROP TABLE IF EXISTS `voice_asset_bindings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `voice_asset_bindings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `voice_asset_id` bigint unsigned NOT NULL DEFAULT '0',
  `model_config_id` int unsigned NOT NULL DEFAULT '0',
  `provider` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `provider_asset_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `provider_payload_json` json DEFAULT NULL,
  `error_message` varchar(2000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uk_voice_binding_model` (`voice_asset_id`,`model_config_id`) USING BTREE,
  KEY `idx_voice_binding_status` (`model_config_id`,`status`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='音色资产供应商绑定';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `voice_assets`
--

DROP TABLE IF EXISTS `voice_assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `voice_assets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'users.id',
  `asset_id` int unsigned NOT NULL DEFAULT '0' COMMENT '角色 assets.id',
  `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `source_url` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `original_filename` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `mime_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `extension` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `file_size` bigint unsigned NOT NULL DEFAULT '0',
  `duration_ms` int unsigned NOT NULL DEFAULT '0',
  `sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ready',
  `rights_confirmed` tinyint unsigned NOT NULL DEFAULT '0',
  `provider_meta_json` json DEFAULT NULL,
  `error_message` varchar(2000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `deleted_at` datetime DEFAULT NULL,
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `idx_voice_assets_character_status` (`asset_id`,`status`,`id`) USING BTREE,
  KEY `idx_voice_assets_user_status` (`user_id`,`status`,`id`) USING BTREE,
  KEY `idx_voice_assets_user_hash` (`user_id`,`sha256`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='用户音色资产';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `workflow_bundles`
--

DROP TABLE IF EXISTS `workflow_bundles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_bundles` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT '0',
  `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `series_workflow_id` int unsigned DEFAULT NULL COMMENT 'å‰§æœ¬æ®µ workflows.idï¼›null=æ— å‰§æœ¬æ®µ(å•é›†å¥—é¤)',
  `episode_workflow_id` int unsigned NOT NULL COMMENT 'å‰§é›†æ®µ workflows.id',
  `is_system` tinyint unsigned NOT NULL DEFAULT '0' COMMENT '1=å®˜æ–¹å†…ç½®ï¼Œåªè¯»ä¸å¯åˆ æ”¹',
  `sort` int unsigned NOT NULL DEFAULT '0',
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `idx_bundles_user` (`user_id`) USING BTREE,
  KEY `idx_bundles_sort` (`sort`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=64 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='å®Œæ•´æµç¨‹å¥—é¤';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `workflow_run_nodes`
--

DROP TABLE IF EXISTS `workflow_run_nodes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_run_nodes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT '1' COMMENT 'users.id',
  `run_id` bigint unsigned NOT NULL COMMENT 'workflow_runs.id',
  `workflow_node_id` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `label` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `kind` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `sort` int unsigned NOT NULL DEFAULT '0',
  `status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued' COMMENT 'queued|running|success|failed|skipped',
  `depends_on_json` json DEFAULT NULL,
  `input_json` json DEFAULT NULL,
  `output_json` json DEFAULT NULL,
  `raw_output` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `ai_request_log_id` bigint unsigned DEFAULT NULL COMMENT 'ai_request_logs.id',
  `request_payload_json` json DEFAULT NULL COMMENT '请求快照',
  `ai_meta_json` json DEFAULT NULL COMMENT 'AI 元数据',
  `error_message` varchar(2000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `duration_ms` int unsigned NOT NULL DEFAULT '0',
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `idx_workflow_run_nodes_run` (`run_id`) USING BTREE,
  KEY `idx_workflow_run_nodes_status` (`status`) USING BTREE,
  KEY `idx_workflow_run_nodes_ai_log` (`ai_request_log_id`) USING BTREE,
  KEY `idx_workflow_run_nodes_user_id` (`user_id`) USING BTREE,
  CONSTRAINT `fk_workflow_run_nodes_run` FOREIGN KEY (`run_id`) REFERENCES `workflow_runs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3977 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='剧本工作流异步节点任务';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `workflow_runs`
--

DROP TABLE IF EXISTS `workflow_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT '1' COMMENT 'users.id',
  `series_id` int unsigned NOT NULL COMMENT 'series.id',
  `workflow_id` int unsigned NOT NULL COMMENT '剧本工作流 workflows.id',
  `episode_workflow_id` int unsigned DEFAULT NULL COMMENT '生成剧集绑定的剧集工作流 workflows.id',
  `status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued' COMMENT 'queued|running|success|failed|cancelled',
  `source_text` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT '完整剧本/小说正文',
  `target_episode_count` int unsigned DEFAULT NULL COMMENT '目标集数，NULL 表示交给 AI',
  `payload_json` json DEFAULT NULL COMMENT '创建任务时的原始参数',
  `result_json` json DEFAULT NULL COMMENT '执行完成后的摘要结果',
  `progress` tinyint unsigned NOT NULL DEFAULT '0',
  `current_node_label` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `error_message` varchar(2000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `idx_workflow_runs_status` (`status`) USING BTREE,
  KEY `idx_workflow_runs_series` (`series_id`) USING BTREE,
  KEY `idx_workflow_runs_workflow` (`workflow_id`) USING BTREE,
  KEY `idx_workflow_runs_create_time` (`create_time`) USING BTREE,
  KEY `idx_workflow_runs_user_id` (`user_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=735 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='剧本工作流异步任务';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `workflows`
--

DROP TABLE IF EXISTS `workflows`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflows` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT '1' COMMENT 'users.id',
  `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `graph` json DEFAULT NULL COMMENT '{ nodes: [], edges: [] }',
  `viewport` json DEFAULT NULL COMMENT '{ x, y, zoom }',
  `is_default` tinyint unsigned NOT NULL DEFAULT '0',
  `scope` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'episode' COMMENT 'episode|series',
  `sort` int unsigned NOT NULL DEFAULT '0',
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `idx_sort` (`sort`) USING BTREE,
  KEY `idx_workflows_user_id` (`user_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=132 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='Studio workflow templates';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping events for database 'aimage'
--

--
-- Dumping routines for database 'aimage'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-08-15  7:57:22
