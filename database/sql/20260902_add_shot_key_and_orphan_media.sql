-- 镜头稳定身份 + 未挂载历史成片
-- 幂等：可重复执行

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shots' AND COLUMN_NAME = 'shot_key') = 0,
  'ALTER TABLE `shots` ADD COLUMN `shot_key` char(36) NOT NULL DEFAULT '''' COMMENT ''跨分镜修订的稳定镜头身份'' AFTER `storyboard_revision_id`, ADD INDEX `idx_shots_episode_shot_key` (`episode_id`,`shot_key`,`storyboard_revision_id`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shot_media_versions' AND COLUMN_NAME = 'shot_key') = 0,
  'ALTER TABLE `shot_media_versions` ADD COLUMN `shot_key` char(36) DEFAULT NULL COMMENT ''所属镜头稳定身份'' AFTER `shot_id`, ADD INDEX `idx_smv_episode_shot_key` (`episode_id`,`shot_key`,`media_type`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shot_media_versions' AND COLUMN_NAME = 'orphaned') = 0,
  'ALTER TABLE `shot_media_versions` ADD COLUMN `orphaned` tinyint(1) NOT NULL DEFAULT 0 COMMENT ''1=未挂载历史成片，仅预览不可选用'' AFTER `is_selected`, ADD INDEX `idx_smv_episode_orphaned` (`episode_id`,`orphaned`,`media_type`,`id`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 回填：desc 为保留字必须反引号
UPDATE `shots`
SET `shot_key` = LOWER(CONCAT(
  SUBSTR(MD5(CONCAT('shot:', id, ':', episode_id, ':', IFNULL(storyboard_revision_id, 0), ':', `index`)), 1, 8), '-',
  SUBSTR(MD5(CONCAT('shot:', id, ':a')), 1, 4), '-',
  '4', SUBSTR(MD5(CONCAT('shot:', id, ':b')), 1, 3), '-',
  SUBSTR(CONCAT('8', SUBSTR(MD5(CONCAT('shot:', id, ':c')), 1, 3)), 1, 4), '-',
  SUBSTR(MD5(CONCAT('shot:', id, ':', IFNULL(`desc`, ''), ':', IFNULL(create_time, ''))), 1, 12)
))
WHERE `shot_key` IS NULL OR `shot_key` = '';

UPDATE `shot_media_versions` smv
INNER JOIN `shots` s ON s.`id` = smv.`shot_id`
SET smv.`shot_key` = s.`shot_key`
WHERE (smv.`shot_key` IS NULL OR smv.`shot_key` = '')
  AND s.`shot_key` IS NOT NULL
  AND s.`shot_key` <> '';
