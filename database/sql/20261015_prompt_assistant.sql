CREATE TABLE IF NOT EXISTS `prompt_assistant_sessions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `title` varchar(120) NOT NULL DEFAULT '',
  `status` varchar(24) NOT NULL DEFAULT 'idle',
  `settings_json` json NOT NULL,
  `brief_json` json NOT NULL,
  `messages_json` json NOT NULL,
  `run_token` varchar(64) NOT NULL DEFAULT '',
  `request_id` varchar(64) NOT NULL DEFAULT '',
  `error_message` varchar(1000) NOT NULL DEFAULT '',
  `create_time` datetime NOT NULL,
  `update_time` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_prompt_sessions_user` (`user_id`, `update_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `prompt_assistant_results` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `session_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `version` int unsigned NOT NULL,
  `content_json` json NOT NULL,
  `create_time` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_prompt_result_version` (`session_id`, `version`),
  KEY `idx_prompt_results_user` (`user_id`, `session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `prompt_assistant_skills` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `name` varchar(64) NOT NULL,
  `description` varchar(1024) NOT NULL,
  `modes_json` json NOT NULL,
  `instructions` mediumtext NOT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'draft',
  `evaluations_json` json NOT NULL,
  `source_session_id` int unsigned DEFAULT NULL,
  `create_time` datetime NOT NULL,
  `update_time` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_prompt_skills_user` (`user_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `prompt_assistant_references` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `name` varchar(200) NOT NULL,
  `url` varchar(1500) NOT NULL,
  `create_time` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_prompt_references_user` (`user_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
