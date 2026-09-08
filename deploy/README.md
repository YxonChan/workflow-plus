# 部署入口（宝塔一键）

风格对齐亚朵 `bash /www/wwwroot/yaduo/deploy/deploy.sh`：服务器上一条命令完成更新。

## 日常更新

本机先推送：

```bash
git push origin main
```

服务器（宝塔终端 / SSH）执行：

```bash
bash /www/wwwroot/ai-workflow/deploy/deploy.sh
```

脚本默认会：

1. `git fetch` + **快进**合并 `origin/main`
2. 在 Docker 多阶段构建中执行 `npm ci && npm run build`
3. 在 Docker 多阶段构建中执行 `composer install --no-dev`
4. 应用积分 SQL / 腾讯生图默认 options（失败不阻断）
5. `docker compose up -d --build --remove-orphans`，启动 PHP-FPM、Nginx、Redis 与全部 Worker；默认也会启动本地 MySQL
6. `curl` 验收 `/admin/` 与 `/api/auth/login`

日常更新**不备份 MySQL**。业务库在容器外时，重建或销毁 PHP/Nginx/Worker 容器不会动库。

脚本通过 `AI_WORKFLOW_USE_DOCKER=1`（默认值）进入 Docker 模式。只有明确设置 `AI_WORKFLOW_USE_DOCKER=0` 时，才执行旧的宿主机 PHP-FPM/systemd 流程。

## 目录约定

| 路径 | 说明 |
|------|------|
| `/www/wwwroot/ai-workflow` | 推荐：此处直接是 Git 仓库（与亚朵一致） |
| `/www/wwwroot/ai-workflow/current` | 若这里是 Git 仓库也可 |
| `/www/wwwroot/ai-workflow/git` + `releases/` + `current` | 旧发布模式：脚本会 rsync 到新 release 并切软链 |

`.env` 放在运行目录（`LIVE_DIR/.env` 或 `/www/wwwroot/ai-workflow/.env`）。

## 可选环境变量

```bash
AI_WORKFLOW_BRANCH=main \
AI_WORKFLOW_APP_ROOT=/www/wwwroot/ai-workflow \
AI_WORKFLOW_SITE_URL=https://ai.taptalk.live \
AI_WORKFLOW_USE_DOCKER=1 \
bash /www/wwwroot/ai-workflow/deploy/deploy.sh
```

生产环境使用宝塔 MySQL（推荐）：

```bash
AI_WORKFLOW_EXTERNAL_DB=1 \
bash /www/wwwroot/ai-workflow/deploy/deploy.sh
```

外部数据库模式使用 `docker-compose.external.yml`，不会启动 Compose 内的 `db` 容器。`.env` 中必须填写可从容器访问的数据库地址：

```env
DB_HOST=host.docker.internal
# 仅供宝塔宿主机执行增量 SQL 时使用；容器仍使用 DB_HOST
DB_BACKUP_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=aimage
DB_USER=workflow_app
DB_PASS=强密码
AI_WORKFLOW_EXTERNAL_DB=1
```

宝塔 MySQL 需要允许 Docker 网桥或服务器内网地址访问，防火墙不要对公网开放 3306。首次部署前请先在宝塔数据库中导入 `database/base.sql`，再按顺序执行 `database/sql/` 中的增量 SQL。部署脚本会尝试执行积分迁移，**不会 dump 业务库**；默认模型同步需在容器启动后执行。

## 首次把线上改成「Git 目录」模式（若还是 tar 发布）

若当前只有 `releases/` + `current` 软链、没有 Git：

```bash
cd /www/wwwroot
# 示例：旁路克隆，再切站点根目录/软链到该目录（按你宝塔实际站点配置调整）
git clone https://github.com/YxonChan/workflow.git ai-workflow-git
cp -a /www/wwwroot/ai-workflow/current/.env /www/wwwroot/ai-workflow-git/.env
# 把 Nginx/宝塔运行目录指到 ai-workflow-git，或把脚本 APP_ROOT 指过去
# 之后日常只用：
bash /www/wwwroot/ai-workflow-git/deploy/deploy.sh
```

## 注意

- 生产工作区有未提交改动时脚本会拒绝 pull（避免覆盖手工改动）。
- 本脚本**不备份、不覆盖业务库**。若要整库导入，需另行显式执行 SQL。
- 前端产物目录是 `public/admin/`（不是 `frontend/dist`）。
