CREATE TABLE IF NOT EXISTS `ecommerce_brief_requests` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT,
 `user_id` bigint unsigned NOT NULL,
 `project_id` bigint unsigned NOT NULL,
 `request_key` varchar(80) NOT NULL,
 `input_hash` char(64) NOT NULL,
 `status` varchar(16) NOT NULL,
 `result_json` mediumtext NULL,
 `create_time` datetime NOT NULL,
 `update_time` datetime NOT NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `owner_request` (`user_id`,`request_key`), KEY `project` (`project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `ecommerce_copy_requests` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT,
 `user_id` bigint unsigned NOT NULL,
 `project_id` bigint unsigned NOT NULL,
 `request_key` varchar(80) NOT NULL,
 `input_hash` char(64) NOT NULL,
 `status` varchar(16) NOT NULL,
 `result_json` mediumtext NULL,
 `create_time` datetime NOT NULL,
 `update_time` datetime NOT NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `owner_request` (`user_id`,`request_key`), KEY `project` (`project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `ecommerce_story_requests` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT,
 `user_id` bigint unsigned NOT NULL,
 `project_id` bigint unsigned NOT NULL,
 `request_key` varchar(80) NOT NULL,
 `input_hash` char(64) NOT NULL,
 `status` varchar(16) NOT NULL,
 `result_json` mediumtext NULL,
 `create_time` datetime NOT NULL,
 `update_time` datetime NOT NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `owner_request` (`user_id`,`request_key`), KEY `project` (`project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `ecommerce_projects` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT,
 `user_id` bigint unsigned NOT NULL,
 `name` varchar(160) NOT NULL,
 `revision` int unsigned NOT NULL DEFAULT 1,
 `state_json` longtext NOT NULL,
 `create_time` datetime NOT NULL,
 `update_time` datetime NOT NULL,
 PRIMARY KEY (`id`), KEY `owner_updated` (`user_id`,`update_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `ecommerce_media` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT,
 `user_id` bigint unsigned NOT NULL,
 `project_id` bigint unsigned NOT NULL,
 `name` varchar(200) NOT NULL,
 `url` text NOT NULL,
 `width` int unsigned NOT NULL DEFAULT 1,
 `height` int unsigned NOT NULL DEFAULT 1,
 `create_time` datetime NOT NULL,
 PRIMARY KEY (`id`), KEY `owner_project` (`user_id`,`project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `ecommerce_runs` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT,
 `user_id` bigint unsigned NOT NULL,
 `project_id` bigint unsigned NOT NULL,
 `request_key` varchar(80) NOT NULL,
 `snapshot_json` longtext NOT NULL,
 `paused` tinyint NOT NULL DEFAULT 0,
 `create_time` datetime NOT NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `owner_request` (`user_id`,`request_key`), KEY `project` (`project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `ecommerce_jobs` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT,
 `run_id` bigint unsigned NOT NULL,
 `user_id` bigint unsigned NOT NULL,
 `project_id` bigint unsigned NOT NULL,
 `kind` varchar(16) NOT NULL,
 `page_key` varchar(80) NOT NULL,
 `title` varchar(200) NOT NULL,
 `mode` varchar(8) NOT NULL,
 `model_config_id` bigint unsigned NOT NULL,
 `request_json` longtext NOT NULL,
 `status` varchar(16) NOT NULL DEFAULT 'queued',
 `provider_task_id` varchar(255) NOT NULL DEFAULT '',
 `result_endpoint` text NULL,
 `result_url` text NULL,
 `ai_request_log_id` bigint unsigned NULL,
 `asset_id` bigint unsigned NULL,
 `error_message` text NULL,
 `started_at` datetime NULL,
 `create_time` datetime NOT NULL,
 `update_time` datetime NOT NULL,
 PRIMARY KEY (`id`), KEY `queue` (`status`,`update_time`), KEY `owner_project` (`user_id`,`project_id`), KEY `run` (`run_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
