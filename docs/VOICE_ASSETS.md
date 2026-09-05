# 角色音色资产

## 当前范围

音色属于角色设定，数据关系为 `voice_assets.asset_id → assets.id`，并且目标资产必须为 `character`。

每个角色只有一个当前有效音色。重新上传时旧音色标记为 `archived`，删除时标记为 `deleted`；历史文件和供应商绑定不会立即物理删除，便于后续视频任务使用冻结快照。

本版本提供上传、FFprobe 校验、试听、替换、删除和用户隔离。剧集视频生成创建新任务时，会为当前镜头实际出现的角色冻结有效音色快照；点击“重新生成这一段”时会按当前角色资产重新刷新音色快照，旧任务直接重试且没有音色字段时也会在 Worker 执行前补齐。Ark Seedance 2.0 请求使用 `audio_url` + `reference_audio` 多模态内容携带音色，并在文本提示中建立 `@音频N` 与角色名的映射。快速创作暂未接入角色音色。

## 默认限制

```env
VOICE_ASSET_ENABLED=true
VOICE_UPLOAD_MAX_MB=20
VOICE_UPLOAD_MIN_SECONDS=5
VOICE_UPLOAD_MAX_SECONDS=30
VOICE_UPLOAD_EXTENSIONS=wav,mp3,m4a
FFPROBE_BINARY=ffprobe
```

## 宝塔部署

1. 备份数据库。
2. 确认 PHP 8.2 的 `exec` 函数可用。
3. 确认 `ffprobe -version` 执行成功；若缺失，安装 FFmpeg。
4. 执行：

```bash
mysql -u数据库用户 -p 数据库名 < database/sql/20260711_add_voice_assets.sql
mysql -u数据库用户 -p 数据库名 < database/sql/20260711_fix_voice_asset_character_binding.sql
```

5. 部署 PHP 源码和 `public/admin`，随后重载 PHP-FPM 并重启视频 Worker。

## 手工验收

1. 打开人物资产编辑弹窗，应看到“角色音色”；场景和物品不显示。
2. 新建但尚未保存的角色只能看到“请先保存角色资产”。
3. 保存角色后上传 5–30 秒 WAV、MP3 或 M4A，应能试听。
4. 给两个不同角色上传不同音色，两者不得相互覆盖。
5. 替换某个角色音色后，只更新该角色当前音色。
6. 超大小、超时长、伪造扩展名和损坏音频应被拒绝。
7. 删除角色音色后，该角色恢复未绑定状态。
8. 另一个用户不能查询、替换或删除本用户角色的音色。
9. 新建包含已绑定音色角色的剧集视频任务后，`request_context_json.voice_assets` 应存在对应快照；Ark 请求 `content` 应包含 `audio_url` 且 `role=reference_audio`。
10. 对历史视频任务点击“重新生成这一段”，重新排队后的任务上下文也必须刷新 `voice_assets`，不得继续沿用缺少音色的旧上下文。

项目规则禁止自动浏览器测试，以上步骤由 Yxon 手工执行。

## 回滚

设置 `VOICE_ASSET_ENABLED=false` 并重载 PHP-FPM。保留新增表和音频文件，不在回滚阶段执行破坏性删除。
