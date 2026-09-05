-- Mini23 云 GPU 视频模型配置（请在目标数据库中手工执行）。
-- 风险提示：当前 Mini23 外网接口使用 HTTP，正式上线前必须改为 HTTPS 域名。
START TRANSACTION;

SET @mini23_endpoint = 'http://43.166.255.143:18090/api/v1/videos';
SET @mini23_api_key = 'REPLACE_WITH_MINI23_API_KEY';

INSERT INTO `model_configs` (
  `user_id`, `scope`, `type`, `name`, `model_id`, `endpoint`, `api_key`,
  `is_default`, `enabled`, `options`, `sort`, `create_time`, `update_time`
)
SELECT
  0, 'global', 'video', 'Mini23 云 GPU（H3）', 'mini23-h3', @mini23_endpoint, @mini23_api_key,
  0, 1,
  JSON_OBJECT(
    'provider', 'mini23',
    'width', 864,
    'height', 480,
    'poll_interval', 5,
    'poll_attempts', 180,
    'max_reference_images', 9,
    'force_model_options', JSON_ARRAY('provider', 'width', 'height', 'poll_interval', 'poll_attempts', 'max_reference_images')
  ),
  90, NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1
  FROM `model_configs`
  WHERE `type` = 'video'
    AND JSON_UNQUOTE(JSON_EXTRACT(`options`, '$.provider')) = 'mini23'
);

COMMIT;

-- 验证（不会暴露 api_key）：
SELECT `id`, `name`, `model_id`, `endpoint`, `enabled`, `options`
FROM `model_configs`
WHERE `type` = 'video'
  AND JSON_UNQUOTE(JSON_EXTRACT(`options`, '$.provider')) = 'mini23';
