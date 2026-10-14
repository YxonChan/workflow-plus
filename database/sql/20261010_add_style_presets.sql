-- Visual style presets. The application also creates this table lazily
-- through StylePresetController::ensureTable for existing installations.
CREATE TABLE IF NOT EXISTS `style_presets` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT 'users.id',
  `code` varchar(64) DEFAULT NULL COMMENT '官方风格稳定键，用户自建为空',
  `name` varchar(120) NOT NULL DEFAULT '' COMMENT '风格名称',
  `summary` varchar(255) NOT NULL DEFAULT '' COMMENT '一句话说明',
  `visual_styles` json DEFAULT NULL COMMENT '适用画风 realistic/anime/3d',
  `parameters` json DEFAULT NULL COMMENT '八组整片质感参数',
  `technique_prompt` mediumtext NOT NULL COMMENT '画法提示词，人物和道具也会使用',
  `prompt` mediumtext NOT NULL COMMENT '整片质感提示词',
  `lighting_prompt` mediumtext NOT NULL COMMENT '可选的片段光影',
  `is_system` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '1=官方默认',
  `sort` int NOT NULL DEFAULT 0,
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_style_presets_user` (`user_id`, `is_system`, `sort`),
  UNIQUE KEY `uniq_style_presets_user_code` (`user_id`, `code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='风格化预设';

-- Existing installations add the new columns through StylePresetController::ensureTable
-- and AuthService::ensureSeriesStyleSchema (`series.style_preset_id`).
