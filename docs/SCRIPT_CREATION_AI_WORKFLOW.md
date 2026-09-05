# AI 驱动剧本创作

## 产品规则

- 用户只配置 AI、填写项目目标、启动或控制流程，不直接扮演导演、写手或审核负责人。
- `default` 是独立的全局兜底配置；角色未指定模型或参数时继承默认配置。
- 默认流程依次执行：统筹策划 AI → 导演 AI → 编剧 AI → 审校 AI。
- 上一步会同时产出「完整交付」与「精简交接包」；下一步默认只读取交接包，避免全文层层叠加导致 token 爆仓。
- 审校阶段若正文含 `第N集` / `EPISODE N`，按集修订后由系统合并；审校输出成为最终剧本。
- 已完成剧本可导出 TXT（推荐导入新建作品）与 Letter 标准 PDF；Hermes Agent 可通过首页登录令牌或独立专用令牌拉取正文并回传评分。

## 数据库

在 MySQL 8.0 执行：

```sql
SOURCE database/sql/20260713_bt_release_tables.sql;
```

新功能仅使用：

- `script_ai_configs`
- `script_ai_projects`
- `script_ai_steps`
- `script_external_clients`
- `script_ai_scores`

旧版 `script_projects`、`script_project_members` 等表不会被删除，避免部署时破坏已有数据。

## Docker 本地环境

```bash
docker compose up -d --build php web script-creation-worker
docker compose logs -f script-creation-worker
```

代码或提示词执行逻辑变更后重启：

```bash
docker compose restart php script-creation-worker
```

## 宝塔面板

1. 备份数据库后，在站点数据库执行 `database/sql/20260713_bt_release_tables.sql`。
2. 确认站点 PHP CLI 版本与 Web PHP 版本一致，均为 PHP 8.0 或更高版本，并启用 `curl`、`mbstring`、`pdo_mysql`。
3. 在宝塔“进程守护管理器”新增常驻进程：

```bash
cd /www/wwwroot/你的站点目录 && /www/server/php/82/bin/php think script-creation:worker --sleep=2 --stale-minutes=30
```

4. 运行目录填写站点根目录，进程数量设为 `1`，开启自动启动与异常重启。
5. 发布后同时重启 PHP-FPM 和该 Worker。若宝塔 PHP 版本不是 8.2，请将命令中的 `/82/` 改为实际版本目录。
6. Hermes 外部评分是同步 HTTP API，不需要新增进程；接口说明见 `docs/HERMES_SCRIPT_SCORING_API.md`。

## 状态规则

- 项目：`draft → queued → running → completed`
- 可控分支：`running/queued → paused → queued`
- 异常分支：`running → failed → queued`
- 取消分支：`queued/running/paused → cancelled`
- 步骤：`pending → queued → running → completed`

项目列表直接显示最新 Hermes 总分；页面每 2.5 秒刷新项目详情，并展示当前步骤、当前 AI、等待原因、步骤输入输出、交接包、实际提示词快照、耗时、错误信息、分项评分、总结、优缺点和修改建议。已完成且有最终正文的项目显示“导出 TXT”与“导出 PDF”。新建作品若走本地解析，分集标记需独立成行，支持 `EPISODE 1` / `Episode 1` / `第1集`。

模型输出约定：

```
<<<DELIVERABLE>>>
完整交付正文
<<<HANDOFF>>>
给下一步的精简交接包
```

若模型未按标记输出，系统会把全文视为交付，并自动生成兜底交接摘要。
