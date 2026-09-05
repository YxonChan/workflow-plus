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

脚本会：

1. `git fetch` + **快进**合并 `origin/main`
2. 备份 `.env` 与 MySQL（`/www/backup/ai-workflow/...`）
3. `npm ci && npm run build` → `public/admin`
4. 必要时 `composer install --no-dev`
5. 应用积分 SQL / 腾讯生图默认 options（失败不阻断）
6. 重载 PHP-FPM + 重启 `ai-workflow-worker@*`
7. `curl` 验收 `/admin/` 与 `/api/auth/login`

## 目录约定

| 路径 | 说明 |
|------|------|
| `/www/wwwroot/ai-workflow` | 推荐：此处直接是 Git 仓库（与亚朵一致） |
| `/www/wwwroot/ai-workflow/current` | 若这里是 Git 仓库也可 |
| `/www/wwwroot/ai-workflow/git` + `releases/` + `current` | 旧发布模式：脚本会 rsync 到新 release 并切软链 |
| `/www/backup/ai-workflow` | 部署备份 |

`.env` 放在运行目录（`LIVE_DIR/.env` 或 `/www/wwwroot/ai-workflow/.env`）。

## 可选环境变量

```bash
AI_WORKFLOW_BRANCH=main \
AI_WORKFLOW_APP_ROOT=/www/wwwroot/ai-workflow \
AI_WORKFLOW_SITE_URL=https://ai.taptalk.live \
SKIP_FRONTEND=1 \
SKIP_COMPOSER=1 \
FORCE_COMPOSER=1 \
bash /www/wwwroot/ai-workflow/deploy/deploy.sh
```

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
- 本脚本**默认不覆盖业务库**；只做备份。若要整库覆盖，需另行显式导入 SQL。
- 前端产物目录是 `public/admin/`（不是 `frontend/dist`）。
