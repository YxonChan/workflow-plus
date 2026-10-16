ALTER TABLE `style_presets`
  ADD COLUMN `prompt_mode` varchar(16) NOT NULL DEFAULT 'manual' COMMENT 'manual/parameters',
  ADD COLUMN `prompt_extra` mediumtext NULL COMMENT '参数模式补充描述';
