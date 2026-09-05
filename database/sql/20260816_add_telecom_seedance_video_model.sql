-- 电信网关 Doubao Seedance 2.0 视频模型配置。
-- 请将 @telecom_api_key 替换为目标环境的密钥后再执行；不要提交真实密钥。
START TRANSACTION;

SET @telecom_api_key = 'REPLACE_WITH_TELECOM_API_KEY';

INSERT INTO `model_configs` (
  `user_id`, `scope`, `type`, `name`, `model_id`, `endpoint`, `api_key`,
  `is_default`, `enabled`, `options`, `sort`, `create_time`, `update_time`
)
SELECT
  0,
  'global',
  'video',
  '电信 Doubao Seedance 2.0',
  'doubao-seedance-2-0-260128',
  'https://aigw.telecomjs.com/v1/videos/generations',
  @telecom_api_key,
  0,
  1,
  JSON_OBJECT(
    'provider', 'yinhe_async',
    'result_endpoint', 'https://aigw.telecomjs.com/v1/videos/generations/task/{id}',
    'duration', 5,
    'ratio', '16:9',
    'generate_audio', CAST('false' AS JSON),
    'watermark', CAST('false' AS JSON),
    'image_role', 'reference_image',
    'max_reference_images', 9,
    'poll_interval', 5,
    'poll_attempts', 180,
    'allowed_params', JSON_ARRAY('duration', 'resolution', 'ratio', 'generate_audio', 'watermark'),
    'force_model_options', JSON_ARRAY('provider', 'result_endpoint', 'image_role', 'max_reference_images', 'poll_interval', 'poll_attempts', 'allowed_params')
  ),
  100,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1
  FROM `model_configs`
  WHERE `type` = 'video'
    AND `scope` = 'global'
    AND `user_id` = 0
    AND `model_id` = 'doubao-seedance-2-0-260128'
    AND `endpoint` = 'https://aigw.telecomjs.com/v1/videos/generations'
);

COMMIT;

-- 验证（不会返回 api_key）：
SELECT `id`, `name`, `model_id`, `endpoint`, `enabled`, `options`
FROM `model_configs`
WHERE `type` = 'video'
  AND `model_id` = 'doubao-seedance-2-0-260128'
  AND `endpoint` = 'https://aigw.telecomjs.com/v1/videos/generations';
