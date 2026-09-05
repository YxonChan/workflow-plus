# Hermes 剧本评分 API

## 接入规则

- 支持两种 Bearer Token：门户首页“Agent 接入”显示的当前登录令牌，或“剧本创作 → 外部评分接入”生成的专用令牌。
- 首页令牌适合已经按 `AGENT_API.md` 登录并维护 Refresh Token 的可信 Agent；它随用户会话过期，并具有该用户的完整接口权限。
- 专用令牌以 `mlh_` 开头，只允许剧本拉取和评分，可独立重置或撤销；完整令牌只显示一次，服务端仅保存 SHA-256 摘要。
- 令牌只能访问其所属用户的、状态为 `completed` 且具有 `final_content` 的剧本。
- 列表接口不返回正文；先拉取列表，再按 ID 获取单个剧本，避免一次请求携带全部长文本。
- 请求体上限 1 MB；列表每页最多 50 条；所有评分必须在 0 到 100 之间。

## 通用请求头

```http
Authorization: Bearer <首页登录令牌或 mlh_专用令牌>
Content-Type: application/json
```

成功响应统一为：

```json
{
  "code": 0,
  "message": "success",
  "data": {}
}
```

## 1. 获取可评分剧本列表

```http
POST /api/external/v1/scripts/list
```

```json
{
  "limit": 20,
  "cursor": 0
}
```

响应中的 `content_sha256` 用于判断剧本正文是否发生变化；`next_cursor` 传给下一页，`has_more=false` 时结束。

如果使用首页令牌，Agent 可直接复用 `AGENT_API.md` 中登录或刷新后获得的 `session.token`，请求格式无需改变。

```json
{
  "code": 0,
  "message": "success",
  "data": {
    "projects": [
      {
        "id": 18,
        "title": "示例剧本",
        "genre": "都市悬疑",
        "output_language": "zh-CN",
        "status": "completed",
        "content_bytes": 42891,
        "content_sha256": "...",
        "latest_score": null,
        "update_time": "2026-07-13 14:00:00"
      }
    ],
    "has_more": false,
    "next_cursor": null
  }
}
```

## 2. 获取单个剧本正文

```http
POST /api/external/v1/scripts/detail
```

```json
{
  "id": 18
}
```

响应的 `data.project.final_content` 是需要评分的完整定稿剧本。

## 3. 回传评分

```http
POST /api/external/v1/scripts/score
```

```json
{
  "id": 18,
  "request_id": "hermes-18-20260713-v1",
  "scorer_name": "Boss Hermes Agent",
  "score_version": "prompt-v1",
  "overall_score": 86.5,
  "dimensions": {
    "结构": { "score": 88, "comment": "三幕转折清晰。" },
    "人物": { "score": 84, "comment": "主角动机完整，反派仍可加强。" },
    "对白": 87,
    "可拍摄性": 85
  },
  "summary": "整体完成度较高，具备进入制片评估的条件。",
  "strengths": ["开场钩子明确", "中段冲突持续升级"],
  "weaknesses": ["第二集支线略多"],
  "suggestions": ["压缩第二集支线", "强化终局视觉动作"]
}
```

`dimensions` 必须是对象，最多 20 个维度；每个值可以直接是数字，也可以是 `{score, comment}`。`strengths`、`weaknesses`、`suggestions` 支持字符串或字符串数组。

幂等规则：

- 相同 `project_id + client + score_version` 会更新原评分，不会产生重复记录。
- `request_id` 在同一个客户端内唯一；同一请求重试安全，拿去给其他剧本或版本会返回 HTTP 409。

## cURL 示例

```bash
curl -X POST 'https://你的域名/api/external/v1/scripts/detail' \
  -H 'Authorization: Bearer 你的完整令牌' \
  -H 'Content-Type: application/json' \
  --data '{"id":18}'
```

## 状态码

- `401`：首页令牌已过期，或专用令牌缺失、无效、已撤销。
- `404`：剧本不存在、不属于该令牌用户、未完成或没有最终正文。
- `409`：`request_id` 冲突。
- `413`：请求体超过 1 MB。
- `422`：ID、评分、版本或分项格式不合法。

## 安全与运维

- 两种令牌都不得写入 Git、AI 提示词、前端构建产物或普通日志。
- 首页令牌到期后按 `AGENT_API.md` 使用 Refresh Token 续期；专用令牌不会跟随登录会话刷新。
- 怀疑泄露时，在页面重置或撤销令牌；旧令牌立即失效。
- Nginx 建议对 `/api/external/v1/scripts/` 配置独立限流，并保留标准访问日志用于审计。
- Hermes 接口同步处理，不需要新增常驻进程；原 `script-creation:worker` 仍只负责剧本生成。
