-- 视频版本血缘：记录本视频版本基于上游镜头哪个被选中版本生成。
-- 用于「尾帧衔接」开启时，切换某镜头视频版本能精确联动后续镜头切回对应那一批版本，
-- 取代原先靠 end_frame_url 字符串模糊匹配的不可靠做法。
ALTER TABLE `shot_media_versions`
  ADD COLUMN `parent_version_id` bigint unsigned DEFAULT NULL
    COMMENT '本视频版本基于上游镜头哪个被选中版本生成，用于尾帧衔接的精确血缘联动'
    AFTER `video_job_id`,
  ADD INDEX `idx_smv_parent` (`parent_version_id`) USING BTREE;
