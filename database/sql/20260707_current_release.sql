SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `asset_shares` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `asset_id` int unsigned NOT NULL COMMENT 'assets.id',
  `owner_user_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'owner users.id at share time',
  `shared_with_user_id` int unsigned NOT NULL DEFAULT '0' COMMENT '0=shared with all regular users; otherwise users.id',
  `create_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_asset_share` (`asset_id`,`shared_with_user_id`),
  KEY `idx_asset_shares_shared_with` (`shared_with_user_id`),
  KEY `idx_asset_shares_owner` (`owner_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Asset share relations';

CREATE TABLE IF NOT EXISTS `series_shares` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `series_id` int unsigned NOT NULL COMMENT 'series.id',
  `owner_user_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'owner users.id at share time',
  `shared_with_user_id` int unsigned NOT NULL DEFAULT '0' COMMENT '0=shared with all regular users; otherwise users.id',
  `create_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_series_share` (`series_id`,`shared_with_user_id`),
  KEY `idx_series_shares_shared_with` (`shared_with_user_id`),
  KEY `idx_series_shares_owner` (`owner_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Series-level asset share relations';

INSERT INTO `model_configs`
  (`user_id`, `scope`, `type`, `name`, `model_id`, `endpoint`, `api_key`, `is_default`, `enabled`, `options`, `sort`, `create_time`, `update_time`)
SELECT
  0,
  'global',
  'image',
  'image2',
  'gpt-image-2',
  'https://toapis.com/v1/images/generations',
  '',
  1,
  1,
  JSON_OBJECT(
    'size', '16:9',
    'quality', 'high',
    'n', 1,
    'aspect_ratio', '16:9',
    'resolution', '1K'
  ),
  0,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1
  FROM `model_configs`
  WHERE `type` = 'image'
    AND `scope` = 'global'
    AND `user_id` = 0
    AND `model_id` = 'gpt-image-2'
    AND `endpoint` = 'https://toapis.com/v1/images/generations'
);

UPDATE `model_configs`
SET
  `name` = 'image2',
  `is_default` = 1,
  `enabled` = 1,
  `options` = JSON_OBJECT(
    'size', '16:9',
    'quality', 'high',
    'n', 1,
    'aspect_ratio', '16:9',
    'resolution', '1K'
  ),
  `sort` = 0,
  `update_time` = NOW()
WHERE `type` = 'image'
  AND `scope` = 'global'
  AND `user_id` = 0
  AND `model_id` = 'gpt-image-2'
  AND `endpoint` = 'https://toapis.com/v1/images/generations';

UPDATE `model_configs` AS target
JOIN (
  SELECT MAX(`api_key`) AS `api_key`
  FROM `model_configs`
  WHERE `type` = 'image'
    AND `api_key` <> ''
    AND (
      `model_id` IN ('gpt-image-2', 'gpt-image-2-official')
      OR `endpoint` = 'https://toapis.com/v1/images/generations'
    )
) AS source
SET
  target.`api_key` = source.`api_key`,
  target.`update_time` = NOW()
WHERE target.`type` = 'image'
  AND target.`scope` = 'global'
  AND target.`user_id` = 0
  AND target.`model_id` = 'gpt-image-2'
  AND target.`endpoint` = 'https://toapis.com/v1/images/generations'
  AND target.`api_key` = ''
  AND source.`api_key` IS NOT NULL
  AND source.`api_key` <> '';

CREATE TABLE IF NOT EXISTS `model_configs_image_backup_20260707` LIKE `model_configs`;

INSERT IGNORE INTO `model_configs_image_backup_20260707`
SELECT *
FROM `model_configs`
WHERE `type` = 'image'
  AND NOT (
    `scope` = 'global'
    AND `user_id` = 0
    AND `model_id` = 'gpt-image-2'
    AND `endpoint` = 'https://toapis.com/v1/images/generations'
  );

INSERT IGNORE INTO `model_configs_image_backup_20260707`
SELECT duplicate_model.*
FROM `model_configs` AS duplicate_model
JOIN `model_configs` AS keep_model
  ON keep_model.`id` < duplicate_model.`id`
  AND keep_model.`type` = duplicate_model.`type`
  AND keep_model.`scope` = duplicate_model.`scope`
  AND keep_model.`user_id` = duplicate_model.`user_id`
  AND keep_model.`model_id` = duplicate_model.`model_id`
  AND keep_model.`endpoint` = duplicate_model.`endpoint`
WHERE duplicate_model.`type` = 'image'
  AND duplicate_model.`scope` = 'global'
  AND duplicate_model.`user_id` = 0
  AND duplicate_model.`model_id` = 'gpt-image-2'
  AND duplicate_model.`endpoint` = 'https://toapis.com/v1/images/generations';

DELETE duplicate_model
FROM `model_configs` AS duplicate_model
JOIN `model_configs` AS keep_model
  ON keep_model.`id` < duplicate_model.`id`
  AND keep_model.`type` = duplicate_model.`type`
  AND keep_model.`scope` = duplicate_model.`scope`
  AND keep_model.`user_id` = duplicate_model.`user_id`
  AND keep_model.`model_id` = duplicate_model.`model_id`
  AND keep_model.`endpoint` = duplicate_model.`endpoint`
WHERE duplicate_model.`type` = 'image'
  AND duplicate_model.`scope` = 'global'
  AND duplicate_model.`user_id` = 0
  AND duplicate_model.`model_id` = 'gpt-image-2'
  AND duplicate_model.`endpoint` = 'https://toapis.com/v1/images/generations';

DELETE FROM `model_configs`
WHERE `type` = 'image'
  AND NOT (
    `scope` = 'global'
    AND `user_id` = 0
    AND `model_id` = 'gpt-image-2'
    AND `endpoint` = 'https://toapis.com/v1/images/generations'
  );

INSERT INTO `model_configs`
  (`user_id`, `scope`, `type`, `name`, `model_id`, `endpoint`, `api_key`, `is_default`, `enabled`, `options`, `sort`, `create_time`, `update_time`)
SELECT
  0,
  'global',
  'video',
  'C-Dance (CN Official)',
  'doubao-seedance-2-0-260128',
  'https://ark.cn-beijing.volces.com/api/v3/contents/generations/tasks',
  '',
  0,
  1,
  JSON_OBJECT(
    'provider', 'ark',
    'vendor', 'volcengine',
    'region', 'cn-beijing',
    'channel', 'cn',
    'duration', 5,
    'resolution', '480p',
    'ratio', '16:9',
    'generate_audio', false,
    'watermark', false,
    'image_field', 'ark_content',
    'image_role', 'reference_image',
    'max_reference_images', 9,
    'poll_attempts', 160,
    'poll_interval', 5,
    'allowed_params', JSON_ARRAY(
      'duration',
      'resolution',
      'ratio',
      'generate_audio',
      'watermark',
      'return_last_frame',
      'priority',
      'safety_identifier',
      'execution_expires_after',
      'tools'
    ),
    'force_model_options', JSON_ARRAY(
      'provider',
      'image_field',
      'image_role',
      'max_reference_images',
      'allowed_params'
    ),
    'aspect_ratio_options', JSON_ARRAY('21:9', '16:9', '4:3', '1:1', '3:4', '9:16')
  ),
  15,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1
  FROM `model_configs`
  WHERE `type` = 'video'
    AND `scope` = 'global'
    AND `user_id` = 0
    AND `model_id` = 'doubao-seedance-2-0-260128'
    AND `endpoint` = 'https://ark.cn-beijing.volces.com/api/v3/contents/generations/tasks'
);

UPDATE `model_configs`
SET
  `name` = 'C-Dance (CN Official)',
  `enabled` = 1,
  `options` = JSON_OBJECT(
    'provider', 'ark',
    'vendor', 'volcengine',
    'region', 'cn-beijing',
    'channel', 'cn',
    'duration', 5,
    'resolution', '480p',
    'ratio', '16:9',
    'generate_audio', false,
    'watermark', false,
    'image_field', 'ark_content',
    'image_role', 'reference_image',
    'max_reference_images', 9,
    'poll_attempts', 160,
    'poll_interval', 5,
    'allowed_params', JSON_ARRAY(
      'duration',
      'resolution',
      'ratio',
      'generate_audio',
      'watermark',
      'return_last_frame',
      'priority',
      'safety_identifier',
      'execution_expires_after',
      'tools'
    ),
    'force_model_options', JSON_ARRAY(
      'provider',
      'image_field',
      'image_role',
      'max_reference_images',
      'allowed_params'
    ),
    'aspect_ratio_options', JSON_ARRAY('21:9', '16:9', '4:3', '1:1', '3:4', '9:16')
  ),
  `sort` = 15,
  `update_time` = NOW()
WHERE `type` = 'video'
  AND `scope` = 'global'
  AND `user_id` = 0
  AND `model_id` = 'doubao-seedance-2-0-260128'
  AND `endpoint` = 'https://ark.cn-beijing.volces.com/api/v3/contents/generations/tasks';

INSERT INTO `model_configs`
  (`user_id`, `scope`, `type`, `name`, `model_id`, `endpoint`, `api_key`, `is_default`, `enabled`, `options`, `sort`, `create_time`, `update_time`)
SELECT
  0,
  'global',
  'video',
  'C-Dance (Overseas Official)',
  'dreamina-seedance-2-0-260128',
  'https://ark.ap-southeast.bytepluses.com/api/v3/contents/generations/tasks',
  '',
  0,
  1,
  JSON_OBJECT(
    'provider', 'ark',
    'vendor', 'byteplus',
    'region', 'ap-southeast-1',
    'channel', 'overseas',
    'duration', 5,
    'resolution', '480p',
    'ratio', '16:9',
    'generate_audio', false,
    'watermark', false,
    'image_field', 'ark_content',
    'image_role', 'reference_image',
    'max_reference_images', 9,
    'poll_attempts', 160,
    'poll_interval', 5,
    'allowed_params', JSON_ARRAY(
      'duration',
      'resolution',
      'ratio',
      'generate_audio',
      'watermark',
      'return_last_frame',
      'priority',
      'safety_identifier',
      'execution_expires_after',
      'tools'
    ),
    'force_model_options', JSON_ARRAY(
      'provider',
      'image_field',
      'image_role',
      'max_reference_images',
      'allowed_params'
    ),
    'aspect_ratio_options', JSON_ARRAY('21:9', '16:9', '4:3', '1:1', '3:4', '9:16')
  ),
  16,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1
  FROM `model_configs`
  WHERE `type` = 'video'
    AND `scope` = 'global'
    AND `user_id` = 0
    AND `model_id` = 'dreamina-seedance-2-0-260128'
    AND `endpoint` = 'https://ark.ap-southeast.bytepluses.com/api/v3/contents/generations/tasks'
);

UPDATE `model_configs`
SET
  `name` = 'C-Dance (Overseas Official)',
  `enabled` = 1,
  `options` = JSON_OBJECT(
    'provider', 'ark',
    'vendor', 'byteplus',
    'region', 'ap-southeast-1',
    'channel', 'overseas',
    'duration', 5,
    'resolution', '480p',
    'ratio', '16:9',
    'generate_audio', false,
    'watermark', false,
    'image_field', 'ark_content',
    'image_role', 'reference_image',
    'max_reference_images', 9,
    'poll_attempts', 160,
    'poll_interval', 5,
    'allowed_params', JSON_ARRAY(
      'duration',
      'resolution',
      'ratio',
      'generate_audio',
      'watermark',
      'return_last_frame',
      'priority',
      'safety_identifier',
      'execution_expires_after',
      'tools'
    ),
    'force_model_options', JSON_ARRAY(
      'provider',
      'image_field',
      'image_role',
      'max_reference_images',
      'allowed_params'
    ),
    'aspect_ratio_options', JSON_ARRAY('21:9', '16:9', '4:3', '1:1', '3:4', '9:16')
  ),
  `sort` = 16,
  `update_time` = NOW()
WHERE `type` = 'video'
  AND `scope` = 'global'
  AND `user_id` = 0
  AND `model_id` = 'dreamina-seedance-2-0-260128'
  AND `endpoint` = 'https://ark.ap-southeast.bytepluses.com/api/v3/contents/generations/tasks';

UPDATE `model_configs` AS target
JOIN (
  SELECT `model_id`, MAX(`api_key`) AS `api_key`
  FROM `model_configs`
  WHERE `type` = 'video'
    AND `model_id` IN ('doubao-seedance-2-0-260128', 'dreamina-seedance-2-0-260128')
    AND `api_key` <> ''
  GROUP BY `model_id`
) AS source ON source.`model_id` = target.`model_id`
SET
  target.`api_key` = source.`api_key`,
  target.`update_time` = NOW()
WHERE target.`type` = 'video'
  AND target.`scope` = 'global'
  AND target.`user_id` = 0
  AND target.`api_key` = ''
  AND (
    (target.`model_id` = 'doubao-seedance-2-0-260128'
      AND target.`endpoint` = 'https://ark.cn-beijing.volces.com/api/v3/contents/generations/tasks')
    OR (target.`model_id` = 'dreamina-seedance-2-0-260128'
      AND target.`endpoint` = 'https://ark.ap-southeast.bytepluses.com/api/v3/contents/generations/tasks')
  );

CREATE TABLE IF NOT EXISTS `model_configs_video_backup_20260707` LIKE `model_configs`;

INSERT IGNORE INTO `model_configs_video_backup_20260707`
SELECT *
FROM `model_configs`
WHERE `type` = 'video'
  AND NOT (
    `scope` = 'global'
    AND `user_id` = 0
    AND (
      (`model_id` = 'doubao-seedance-2-0-260128'
        AND `endpoint` = 'https://ark.cn-beijing.volces.com/api/v3/contents/generations/tasks')
      OR (`model_id` = 'dreamina-seedance-2-0-260128'
        AND `endpoint` = 'https://ark.ap-southeast.bytepluses.com/api/v3/contents/generations/tasks')
    )
  );

INSERT IGNORE INTO `model_configs_video_backup_20260707`
SELECT duplicate_model.*
FROM `model_configs` AS duplicate_model
JOIN `model_configs` AS keep_model
  ON keep_model.`id` < duplicate_model.`id`
  AND keep_model.`type` = duplicate_model.`type`
  AND keep_model.`scope` = duplicate_model.`scope`
  AND keep_model.`user_id` = duplicate_model.`user_id`
  AND keep_model.`model_id` = duplicate_model.`model_id`
  AND keep_model.`endpoint` = duplicate_model.`endpoint`
WHERE duplicate_model.`type` = 'video'
  AND duplicate_model.`scope` = 'global'
  AND duplicate_model.`user_id` = 0
  AND (
    (duplicate_model.`model_id` = 'doubao-seedance-2-0-260128'
      AND duplicate_model.`endpoint` = 'https://ark.cn-beijing.volces.com/api/v3/contents/generations/tasks')
    OR (duplicate_model.`model_id` = 'dreamina-seedance-2-0-260128'
      AND duplicate_model.`endpoint` = 'https://ark.ap-southeast.bytepluses.com/api/v3/contents/generations/tasks')
  );

DELETE duplicate_model
FROM `model_configs` AS duplicate_model
JOIN `model_configs` AS keep_model
  ON keep_model.`id` < duplicate_model.`id`
  AND keep_model.`type` = duplicate_model.`type`
  AND keep_model.`scope` = duplicate_model.`scope`
  AND keep_model.`user_id` = duplicate_model.`user_id`
  AND keep_model.`model_id` = duplicate_model.`model_id`
  AND keep_model.`endpoint` = duplicate_model.`endpoint`
WHERE duplicate_model.`type` = 'video'
  AND duplicate_model.`scope` = 'global'
  AND duplicate_model.`user_id` = 0
  AND (
    (duplicate_model.`model_id` = 'doubao-seedance-2-0-260128'
      AND duplicate_model.`endpoint` = 'https://ark.cn-beijing.volces.com/api/v3/contents/generations/tasks')
    OR (duplicate_model.`model_id` = 'dreamina-seedance-2-0-260128'
      AND duplicate_model.`endpoint` = 'https://ark.ap-southeast.bytepluses.com/api/v3/contents/generations/tasks')
  );

DELETE FROM `model_configs`
WHERE `type` = 'video'
  AND NOT (
    `scope` = 'global'
    AND `user_id` = 0
    AND (
      (`model_id` = 'doubao-seedance-2-0-260128'
        AND `endpoint` = 'https://ark.cn-beijing.volces.com/api/v3/contents/generations/tasks')
      OR (`model_id` = 'dreamina-seedance-2-0-260128'
        AND `endpoint` = 'https://ark.ap-southeast.bytepluses.com/api/v3/contents/generations/tasks')
    )
  );
