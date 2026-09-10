# CHANGELOG

## 2026-09-10

- 速创参考图/参考视频与成片统一本站 `MediaStorage` 落盘，不再写入 Supabase；避免 ToAPIs 人像入库跨海拉取外链超时（`DownloadFailed` / TOS deadline）。
- 人像入库 `source_url`：本站 `/storage/` 优先本地直传或 `MEDIA_PUBLIC_BASE_URL`；历史 Supabase 等外链先中转上传到 ToAPIs 再入库。
- `SupabaseStorage::isEnabledForQuickCreate()` 固定返回 false；环境默认 `QUICK_CREATE_STORAGE_DRIVER=local`。

## 2026-09-09

- 修复刷新后历史参考图“人脸已通过”状态条脱离缩略图定位并横向撑满页面的问题。
- 修复速创视频引用已通过人脸验证的图片：优先使用验证返回的 `asset://` 访问地址，并在视频请求前清理 Markdown 链接包装，避免 ToAPIs 收到不可解析的 `[URL](URL)`。
- 速创人脸检测通过后鼠标不再显示等待转圈：仅检测中使用 wait 光标，通过态恢复默认光标。
- 速创人脸检测按钮修复首次点击无反馈：按参考图 key/URL 回写状态，点击后立即显示检测中，并通过提示与颜色区分排队/通过/失败。
- 速创上传图片新增人脸检测任务：支持按钮提交、SSE 状态推送、失败重试，以及通过后的 `asset://` 持久化。
- 人脸验证任务按用户和图片地址复用 queued/running/passed 状态；历史回填和重新生成会继承验证引用。
- 人脸检测改由独立 `quick-create:face-worker` 执行，避免人脸入库轮询阻塞普通速创 worker；Docker Compose 与宝塔 systemd 部署脚本已加入该 worker。
- 增量迁移兼容旧版人脸验证表：为历史记录回填稳定哈希后再创建唯一索引，避免重复空值导致升级失败。
- 人脸 Worker 增加运行中任务超时恢复；SSE 断连时持续轮询，直到任务进入通过或失败状态。
- 修正 PHP 镜像构建使用不可解析腾讯 Debian 镜像源的问题，恢复官方 Debian 源以兼容不同服务器 DNS 环境。

## 2026-09-05

- 修复刷新后丢失页面位置：作品及剧集、资产作品筛选及编辑详情、流程编辑、剧本项目及步骤通过网址恢复；登录失效跳转保留原地址。
- 作品父级与生产台共享 `episode_id`，刷新或切换剧集时上下文保持一致，并防止旧详情请求覆盖当前剧集。

- 本仓库本地开发端口固定：Vite 前端 `5176`（`strictPort`），Docker 后端 `WEB_PORT=8086`；开发代理默认指向 `http://localhost:8086`。
- 宝塔日常更新脚本不再 dump MySQL / 备份 `.env`；业务库由外部 MySQL 自行持久化。

- 系统语言固定为简体中文：前端、接口偏好、服务端提示词与剧本输出均不再提供英文分支；启动时会将存量用户偏好和剧本输出语言规范为 `zh-CN`。
- 默认关闭 SQL 调试日志，并将日志文件保留上限设为 30，避免常驻 Worker 持续占满磁盘。
- 速创历史消息接口支持 `limit` 与 `before_id` 游标分页，并返回 `has_more` 和 `oldest_id`，与前端加载更多逻辑一致。
- Docker 编排改为多阶段生产镜像：PHP-FPM、Nginx、MySQL、Redis 与全部后台 Worker 均由 Compose 管理；加入依赖健康检查、持久化运行时卷与容器内前端/Composer 构建。
- `.example.env` 默认使用 Compose 服务名 `db`/`redis`，避免容器内误连 `127.0.0.1`。
- 新增外部 MySQL 生产模式：`docker-compose.external.yml` 与 `AI_WORKFLOW_EXTERNAL_DB=1`。
- Compose 数据库连接参数改为环境变量，Worker 不再硬依赖内部 `db` 服务。
- 宝塔部署脚本支持外部数据库模式与积分迁移；日常更新不 dump 业务库。
