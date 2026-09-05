-- 完整流程套餐(Bundle)：把剧本段(series)+剧集段(episode)两条工作流配对成一个完整流程
-- 不改 workflows / workflow_runs / 运行时；仅作为配对与展示实体。
CREATE TABLE IF NOT EXISTS `workflow_bundles` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int UNSIGNED NOT NULL DEFAULT 0,
  `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `series_workflow_id` int UNSIGNED NULL DEFAULT NULL COMMENT '剧本段 workflows.id；null=无剧本段(单集套餐)',
  `episode_workflow_id` int UNSIGNED NOT NULL COMMENT '剧集段 workflows.id',
  `is_system` tinyint UNSIGNED NOT NULL DEFAULT 0 COMMENT '1=官方内置，只读不可删改',
  `sort` int UNSIGNED NOT NULL DEFAULT 0,
  `create_time` datetime NULL DEFAULT NULL,
  `update_time` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_bundles_user`(`user_id` ASC) USING BTREE,
  INDEX `idx_bundles_sort`(`sort` ASC) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = '完整流程套餐' ROW_FORMAT = Dynamic;
