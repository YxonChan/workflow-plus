-- MiniMax H3 2K 本地模型配置
-- 请通过同目录的 run_add_minimax_h3_model_config.ps1 执行。
-- 执行器会在内存中替换下一行占位符，不要把真实 API Key 写入此文件。
SET @minimax_api_key := 'PASTE_MINIMAX_API_KEY_HERE';

START TRANSACTION;

INSERT INTO model_configs (
    user_id,
    scope,
    type,
    name,
    model_id,
    endpoint,
    api_key,
    is_default,
    enabled,
    options,
    sort,
    create_time,
    update_time
)
SELECT
    1,
    'global',
    'video',
    'MiniMax H3 2K',
    'MiniMax-H3',
    'https://api.minimaxi.com/v2/video_generation',
    @minimax_api_key,
    0,
    1,
    JSON_OBJECT(
        'provider', 'minimax_v2',
        'result_endpoint', 'https://api.minimaxi.com/v2/query/video_generation/{id}',
        'poll_interval', 5,
        'poll_attempts', 180,
        'max_reference_images', 9,
        'max_reference_videos', 3,
        'include_model', CAST('true' AS JSON),
        'image_role', 'reference_image',
        'watermark', CAST('false' AS JSON),
        'allowed_params', JSON_ARRAY('duration', 'resolution', 'ratio', 'watermark')
    ),
    0,
    NOW(),
    NOW()
FROM DUAL
WHERE @minimax_api_key <> 'PASTE_MINIMAX_API_KEY_HERE'
  AND CHAR_LENGTH(TRIM(@minimax_api_key)) >= 20
  AND NOT EXISTS (
      SELECT 1
      FROM model_configs
      WHERE type = 'video'
        AND model_id = 'MiniMax-H3'
        AND endpoint = 'https://api.minimaxi.com/v2/video_generation'
  );

SET @minimax_inserted_rows := ROW_COUNT();

COMMIT;

-- 脱敏验证：不会显示 API Key 内容。
SELECT
    CASE
        WHEN @minimax_inserted_rows = 1 THEN 'inserted'
        WHEN @minimax_api_key = 'PASTE_MINIMAX_API_KEY_HERE'
          OR CHAR_LENGTH(TRIM(@minimax_api_key)) < 20 THEN 'rejected_invalid_key'
        ELSE 'skipped_existing_config'
    END AS write_status;

SELECT
    id,
    name,
    type,
    model_id,
    endpoint,
    scope,
    enabled,
    is_default,
    (CHAR_LENGTH(api_key) >= 20) AS api_key_present,
    JSON_UNQUOTE(JSON_EXTRACT(options, '$.provider')) AS provider,
    JSON_UNQUOTE(JSON_EXTRACT(options, '$.result_endpoint')) AS result_endpoint
FROM model_configs
WHERE type = 'video'
  AND model_id = 'MiniMax-H3'
  AND endpoint = 'https://api.minimaxi.com/v2/video_generation';

-- 回滚前先执行以下只读查询确认精确目标；删除语句不自动执行。
-- SELECT id, name, model_id, endpoint, enabled, is_default
-- FROM model_configs
-- WHERE type = 'video'
--   AND model_id = 'MiniMax-H3'
--   AND endpoint = 'https://api.minimaxi.com/v2/video_generation';
--
-- 经二次确认后方可手动回滚：
-- DELETE FROM model_configs
-- WHERE type = 'video'
--   AND model_id = 'MiniMax-H3'
--   AND endpoint = 'https://api.minimaxi.com/v2/video_generation';
