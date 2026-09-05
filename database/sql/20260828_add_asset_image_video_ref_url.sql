-- 人物造型隐藏视频参考图（写实彩铅转绘）。前台只读 url；视频组装优先 video_ref_url。
ALTER TABLE `asset_images`
  ADD COLUMN `video_ref_url` varchar(1000) NOT NULL DEFAULT '' AFTER `reference_key`;
