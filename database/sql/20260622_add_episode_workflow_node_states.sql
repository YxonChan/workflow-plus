CREATE TABLE IF NOT EXISTS `episode_workflow_node_states` (
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
  `upstream_snapshot_json` json DEFAULT NULL COMMENT '当前态使用的上游摘要',
  `version_token` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `error_message` varchar(2000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `duration_ms` int unsigned NOT NULL DEFAULT '0',
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_episode_workflow_node` (`episode_id`,`workflow_node_id`),
  KEY `idx_episode_workflow_node_episode_sort` (`episode_id`,`sort`),
  KEY `idx_episode_workflow_node_status` (`status`),
  KEY `idx_episode_workflow_node_run_node` (`workflow_run_node_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='剧集工作流节点当前状态';

INSERT INTO `episode_workflow_node_states` (
  `user_id`,
  `series_id`,
  `episode_id`,
  `workflow_id`,
  `workflow_run_id`,
  `workflow_run_node_id`,
  `workflow_node_id`,
  `label`,
  `kind`,
  `sort`,
  `status`,
  `input_json`,
  `output_json`,
  `raw_output`,
  `error_message`,
  `started_at`,
  `finished_at`,
  `duration_ms`,
  `version_token`,
  `create_time`,
  `update_time`
)
SELECT
  r.`user_id`,
  r.`series_id`,
  CAST(JSON_UNQUOTE(JSON_EXTRACT(r.`payload_json`, '$.episode_id')) AS UNSIGNED) AS `episode_id`,
  r.`workflow_id`,
  r.`id`,
  n.`id`,
  n.`workflow_node_id`,
  n.`label`,
  n.`kind`,
  n.`sort`,
  n.`status`,
  n.`input_json`,
  n.`output_json`,
  n.`raw_output`,
  n.`error_message`,
  n.`started_at`,
  n.`finished_at`,
  n.`duration_ms`,
  CONCAT('legacy:', n.`id`),
  NOW(),
  NOW()
FROM `workflow_run_nodes` n
JOIN `workflow_runs` r ON r.`id` = n.`run_id`
LEFT JOIN `episode_workflow_node_states` s
  ON s.`episode_id` = CAST(JSON_UNQUOTE(JSON_EXTRACT(r.`payload_json`, '$.episode_id')) AS UNSIGNED)
  AND s.`workflow_node_id` = n.`workflow_node_id`
WHERE JSON_UNQUOTE(JSON_EXTRACT(r.`payload_json`, '$.scope')) = 'episode'
  AND CAST(JSON_UNQUOTE(JSON_EXTRACT(r.`payload_json`, '$.episode_id')) AS UNSIGNED) > 0
  AND s.`id` IS NULL;
