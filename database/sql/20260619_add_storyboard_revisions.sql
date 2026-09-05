-- 分镜修订版：把每次“分镜处理”产物隔离成一代，避免旧视频版本混入新分镜。

CREATE TABLE IF NOT EXISTS `storyboard_revisions` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='Storyboard processing revisions';

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'episodes' AND COLUMN_NAME = 'current_storyboard_revision_id') = 0,
  'ALTER TABLE `episodes` ADD COLUMN `current_storyboard_revision_id` bigint unsigned DEFAULT NULL COMMENT ''当前分镜修订版 storyboard_revisions.id'' AFTER `plot_input`, ADD INDEX `idx_episodes_storyboard_revision` (`current_storyboard_revision_id`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shots' AND COLUMN_NAME = 'storyboard_revision_id') = 0,
  'ALTER TABLE `shots` ADD COLUMN `storyboard_revision_id` bigint unsigned DEFAULT NULL AFTER `episode_id`, ADD UNIQUE KEY `uniq_shots_revision_index` (`storyboard_revision_id`,`index`), ADD INDEX `idx_shots_episode_revision` (`episode_id`,`storyboard_revision_id`,`index`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shot_media_versions' AND COLUMN_NAME = 'storyboard_revision_id') = 0,
  'ALTER TABLE `shot_media_versions` ADD COLUMN `storyboard_revision_id` bigint unsigned DEFAULT NULL AFTER `episode_id`, ADD INDEX `idx_smv_revision_type` (`storyboard_revision_id`,`media_type`,`shot_id`,`id`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'video_jobs' AND COLUMN_NAME = 'storyboard_revision_id') = 0,
  'ALTER TABLE `video_jobs` ADD COLUMN `storyboard_revision_id` bigint unsigned DEFAULT NULL AFTER `episode_id`, ADD INDEX `idx_video_jobs_revision` (`storyboard_revision_id`,`workflow_run_node_id`,`shot_index`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT INTO `storyboard_revisions` (
  `user_id`,
  `series_id`,
  `episode_id`,
  `workflow_run_id`,
  `workflow_run_node_id`,
  `raw_output`,
  `content_hash`,
  `shot_count`,
  `status`,
  `create_time`,
  `update_time`
)
SELECT
  e.`user_id`,
  e.`series_id`,
  e.`id`,
  NULL,
  NULL,
  CONCAT('legacy storyboard revision for episode ', e.`id`),
  SHA2(CONCAT('legacy:', e.`id`, ':', COUNT(s.`id`)), 256),
  COUNT(s.`id`),
  'current',
  NOW(),
  NOW()
FROM `episodes` e
JOIN `shots` s ON s.`episode_id` = e.`id`
LEFT JOIN `storyboard_revisions` existing
  ON existing.`episode_id` = e.`id`
  AND existing.`status` = 'current'
WHERE e.`current_storyboard_revision_id` IS NULL
  AND existing.`id` IS NULL
GROUP BY e.`id`, e.`user_id`, e.`series_id`;

UPDATE `episodes` e
JOIN (
  SELECT `episode_id`, MAX(`id`) AS `revision_id`
  FROM `storyboard_revisions`
  WHERE `status` = 'current'
  GROUP BY `episode_id`
) r ON r.`episode_id` = e.`id`
SET e.`current_storyboard_revision_id` = r.`revision_id`
WHERE e.`current_storyboard_revision_id` IS NULL;

UPDATE `shots` s
JOIN `episodes` e ON e.`id` = s.`episode_id`
SET s.`storyboard_revision_id` = e.`current_storyboard_revision_id`
WHERE s.`storyboard_revision_id` IS NULL
  AND e.`current_storyboard_revision_id` IS NOT NULL;

UPDATE `shot_media_versions` smv
JOIN `shots` s ON s.`id` = smv.`shot_id`
SET smv.`storyboard_revision_id` = s.`storyboard_revision_id`
WHERE smv.`storyboard_revision_id` IS NULL
  AND s.`storyboard_revision_id` IS NOT NULL;

UPDATE `video_jobs` vj
JOIN `shots` s ON s.`id` = vj.`shot_id`
SET vj.`storyboard_revision_id` = s.`storyboard_revision_id`
WHERE vj.`storyboard_revision_id` IS NULL
  AND s.`storyboard_revision_id` IS NOT NULL;
