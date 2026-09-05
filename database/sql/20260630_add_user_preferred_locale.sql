ALTER TABLE `users`
  ADD COLUMN `preferred_locale` varchar(16) NOT NULL DEFAULT 'zh-CN' COMMENT 'zh-CN|en-US' AFTER `role`;

UPDATE `users`
SET `preferred_locale` = 'zh-CN'
WHERE `preferred_locale` NOT IN ('zh-CN', 'en-US') OR `preferred_locale` IS NULL OR `preferred_locale` = '';
