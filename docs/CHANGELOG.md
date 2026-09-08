# CHANGELOG

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
