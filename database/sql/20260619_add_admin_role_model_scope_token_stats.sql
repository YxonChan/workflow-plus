ALTER TABLE `users`
  ADD COLUMN `role` varchar(20) NOT NULL DEFAULT 'user' COMMENT 'admin|user' AFTER `password_hash`;

ALTER TABLE `model_configs`
  ADD COLUMN `scope` varchar(16) NOT NULL DEFAULT 'global' COMMENT 'global|user' AFTER `user_id`,
  ADD COLUMN `enabled` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '1=enabled' AFTER `is_default`,
  ADD INDEX `idx_model_configs_scope_user_type` (`scope`,`user_id`,`type`,`enabled`,`is_default`,`sort`,`id`);

UPDATE `model_configs` SET `scope` = 'global', `user_id` = 0;

ALTER TABLE `ai_request_logs`
  ADD COLUMN `prompt_tokens` int unsigned NOT NULL DEFAULT 0 COMMENT 'usage.prompt_tokens' AFTER `usage_json`,
  ADD COLUMN `completion_tokens` int unsigned NOT NULL DEFAULT 0 COMMENT 'usage.completion_tokens' AFTER `prompt_tokens`,
  ADD COLUMN `total_tokens` int unsigned NOT NULL DEFAULT 0 COMMENT 'usage.total_tokens' AFTER `completion_tokens`,
  ADD INDEX `idx_ai_logs_user_time` (`user_id`,`create_time`),
  ADD INDEX `idx_ai_logs_user_model_time` (`user_id`,`model_config_id`,`create_time`);

UPDATE `ai_request_logs`
SET
  `prompt_tokens` = COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`usage_json`, '$.prompt_tokens')), JSON_UNQUOTE(JSON_EXTRACT(`usage_json`, '$.input_tokens')), 0),
  `completion_tokens` = COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`usage_json`, '$.completion_tokens')), JSON_UNQUOTE(JSON_EXTRACT(`usage_json`, '$.output_tokens')), 0),
  `total_tokens` = COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`usage_json`, '$.total_tokens')), 0)
WHERE `usage_json` IS NOT NULL;

UPDATE `ai_request_logs`
SET `total_tokens` = `prompt_tokens` + `completion_tokens`
WHERE `total_tokens` = 0 AND (`prompt_tokens` > 0 OR `completion_tokens` > 0);
