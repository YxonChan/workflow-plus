# Agent API Protocol

This document is for an external agent that needs to create AI video-series production tasks in this project.

## Base

- Base URL: provided by operator.
- Request body: JSON unless endpoint says `multipart/form-data`.
- Response envelope:

```json
{
  "code": 0,
  "message": "success",
  "data": {}
}
```

- Success condition: `code === 0`.
- Error condition: `code !== 0` or non-2xx HTTP status.
- Auth header for protected endpoints:

```http
Authorization: Bearer <access_token>
```

## Auth

### Login

Endpoint:

```http
POST /api/auth/login
Content-Type: application/json
```

Request:

```json
{
  "username": "user1",
  "password": "123456"
}
```

Response data:

```json
{
  "token": "<access_token>",
  "access_token": "<access_token>",
  "expires_at": 1780000000,
  "expires_in": 604800,
  "refresh_token": "<refresh_token>",
  "refresh_expires_at": 1782000000,
  "refresh_expires_in": 2592000,
  "user": {
    "id": 1,
    "username": "user1",
    "display_name": "用户1"
  }
}
```

Rules:

- Persist `token`, `refresh_token`, `expires_at`, `refresh_expires_at`, and `user`.
- Use `token` as the Bearer token for all `/api/agent/*` endpoints.
- `token` TTL is 7 days.
- `refresh_token` TTL is 30 days.
- Tokens are user-scoped. A task created by one user token cannot be read or modified by another user token.

### Refresh

Endpoint:

```http
POST /api/auth/refresh
Content-Type: application/json
```

Request:

```json
{
  "refresh_token": "<refresh_token>"
}
```

Response data: same shape as login response.

Refresh policy:

```text
Before every business request:
  if expires_at - now_unix_seconds < 86400:
    call /api/auth/refresh
    replace local token, refresh_token, expires_at, refresh_expires_at

On any protected endpoint returning HTTP 401:
  call /api/auth/refresh once
  retry the original request once with the new token
  if retry fails, login again or stop with auth_required
```

Never call `/api/auth/refresh` recursively if the refresh endpoint itself returns 401.

## Create Task From Script Text

Endpoint:

```http
POST /api/agent/tasks/create
Authorization: Bearer <access_token>
Content-Type: application/json
```

Request:

```json
{
  "title": "小龙虾主人",
  "source_text": "完整剧本文本",
  "episode_count": 80,
  "series_workflow_id": 7,
  "episode_workflow_id": 6,
  "visual_style": "realistic",
  "region": "china",
  "agent_id": "hermes"
}
```

Required:

- `title`
- one of `source_text` or `source_file_token`

Optional:

- `episode_count`: integer, 1 to 100.
- `series_workflow_id`: if omitted, backend chooses the default series workflow for the current user.
- `episode_workflow_id`: if omitted, backend chooses the default episode workflow for the current user.
- `visual_style`: default `realistic`.
- `region`: `china` or `western`, default `china`.
- `agent_id`: external agent identifier.
- `callback_url`: currently stored only; callback delivery may be implemented later.

Response data example:

```json
{
  "id": 1,
  "task_no": "agt_20260601153000_ab12cd34",
  "agent_id": "hermes",
  "title": "小龙虾主人",
  "status": "series_queued",
  "phase": "series_workflow",
  "checkpoint": "",
  "progress": 3,
  "series_id": 123,
  "series_workflow_run_id": 456,
  "episode_count": 80,
  "asset_stats": null,
  "episode_stats": null,
  "review_payload": {},
  "result": {
    "series_id": 123,
    "series_workflow_run_id": 456
  },
  "error_message": "",
  "callback_url": "",
  "create_time": "2026-06-01 15:30:00",
  "update_time": "2026-06-01 15:30:00"
}
```

Persist `task_no`. Use `task_no` for all later operations.

## Create Task From Uploaded File

### Upload Script File

Endpoint:

```http
POST /api/agent/script/upload
Authorization: Bearer <access_token>
Content-Type: multipart/form-data
```

Form:

```text
file=<txt-or-pdf-file>
```

Accepted extensions:

- `txt`
- `pdf`

Max size:

- 100 MB

Response data:

```json
{
  "filename": "script.txt",
  "file_token": "0123456789abcdef0123456789abcdef",
  "extension": "txt",
  "size": 123456
}
```

### Create Task With File Token

Endpoint:

```http
POST /api/agent/tasks/create
Authorization: Bearer <access_token>
Content-Type: application/json
```

Request:

```json
{
  "title": "小龙虾主人",
  "source_file_token": "0123456789abcdef0123456789abcdef",
  "episode_count": 80,
  "series_workflow_id": 7,
  "episode_workflow_id": 6,
  "visual_style": "realistic",
  "region": "china",
  "agent_id": "hermes"
}
```

Response data: same shape as create-from-text.

## Get Task Detail

Endpoint:

```http
POST /api/agent/tasks/detail
Authorization: Bearer <access_token>
Content-Type: application/json
```

Request:

```json
{
  "task_no": "agt_20260601153000_ab12cd34"
}
```

Alternative:

```json
{
  "id": 1
}
```

Response data: task object.

Poll strategy:

```text
If status is queued/running/generating:
  poll every 5 to 15 seconds

If status is waiting_asset_review:
  stop polling aggressively
  notify operator or make a decision

If status is completed/failed/cancelled:
  stop polling
```

## Task Status

Known statuses:

```text
created
series_queued
series_running
asset_generating
waiting_asset_review
episode_ready
episode_running
video_generating
completed
failed
cancelled
```

Meaning:

```text
created               task row created
series_queued         internal series workflow queued
series_running        internal series workflow running
asset_generating      asset image jobs queued/running or missing core images
waiting_asset_review  asset images ready; external confirmation required
episode_ready         asset review approved; task can continue to episode phase
episode_running       episode workflow phase running
video_generating      video jobs queued/running
completed             all tracked production is complete
failed                unrecoverable or manual-resume-needed failure
cancelled             task cancelled
```

Known checkpoints:

```text
""             no checkpoint
asset_review   asset image review required
manual_resume  manual intervention or retry required
```

## Asset Review

When detail returns:

```json
{
  "status": "waiting_asset_review",
  "checkpoint": "asset_review"
}
```

Read:

```json
{
  "review_payload": {
    "checkpoint": "asset_review",
    "message": "资产图片已生成，请确认人物、场景和道具是否可以继续用于剧集生产。",
    "series_id": 123,
    "review_url": "/admin/assets?series_id=123",
    "asset_stats": {
      "total": 10,
      "with_core_images": 10,
      "missing_core_images": 0,
      "queued_or_running": 0
    },
    "assets": [
      {
        "id": 1,
        "type": "character",
        "name": "角色名",
        "description": "描述",
        "image_url": "https://..."
      }
    ]
  }
}
```

Agent behavior options:

```text
Option A: approve directly if assets are acceptable.
Option B: notify a human operator to edit assets in admin UI, then call resume or approve.
Option C: stop and return waiting_for_human_review to caller.
```

### Approve Asset Review

Endpoint:

```http
POST /api/agent/tasks/approve
Authorization: Bearer <access_token>
Content-Type: application/json
```

Request:

```json
{
  "task_no": "agt_20260601153000_ab12cd34",
  "checkpoint": "asset_review",
  "note": "资产图已确认，可以继续"
}
```

Response data: updated task object.

### Resume After Manual Edit

Endpoint:

```http
POST /api/agent/tasks/resume
Authorization: Bearer <access_token>
Content-Type: application/json
```

Request:

```json
{
  "task_no": "agt_20260601153000_ab12cd34",
  "note": "人工已修改资产，继续执行"
}
```

Response data: updated task object.

Use `resume` when a human modified assets, selected image versions, added images, or recovered from a manual checkpoint.

## Cancel Task

Endpoint:

```http
POST /api/agent/tasks/cancel
Authorization: Bearer <access_token>
Content-Type: application/json
```

Request:

```json
{
  "task_no": "agt_20260601153000_ab12cd34"
}
```

Response data: updated task object with `status = cancelled`.

## Result Shape

Task detail includes `result`.

Example:

```json
{
  "result": {
    "series_id": 123,
    "series_workflow_run_id": 456,
    "episodes": [
      {
        "id": 1,
        "number": 1,
        "title": "第1集",
        "status": "done",
        "video_urls": [
          "https://..."
        ]
      }
    ]
  }
}
```

Use `result.episodes[*].video_urls` as produced shot/video segment URLs when available.

## Script Scoring API

The same account access token used for `/api/agent/*` can directly call the script scoring endpoints:

```text
POST /api/external/v1/scripts/list
POST /api/external/v1/scripts/detail
POST /api/external/v1/scripts/score
Authorization: Bearer <access_token>
```

No second login is required. Continue using the normal refresh flow when the account access token expires. A separately revocable `mlh_` scoped token is also supported when the Agent should only read completed scripts and submit scores.

The complete request schemas, pagination, idempotency rules, scoring payload, and status codes are documented in `docs/HERMES_SCRIPT_SCORING_API.md`.

## Recommended Agent State Machine

```text
login_or_restore_session
  -> ensure_fresh_token
  -> create_task
  -> poll_detail
    -> series_queued/series_running/asset_generating: continue polling
    -> waiting_asset_review:
         inspect review_payload
         if auto_approve_allowed: approve asset_review
         else return waiting_for_human_review with review_url
    -> episode_ready:
         continue polling or hand off to next production step
    -> video_generating:
         continue polling
    -> completed:
         return result
    -> failed:
         return failure with error_message and checkpoint
    -> cancelled:
         return cancelled
```

## Minimal Pseudocode

```js
async function ensureFreshToken(session) {
  const now = Math.floor(Date.now() / 1000)
  if (session.expires_at - now >= 86400) return session

  const refreshed = await post('/api/auth/refresh', {
    refresh_token: session.refresh_token
  })
  return refreshed.data
}

async function authorizedPost(session, url, body) {
  session = await ensureFreshToken(session)
  let res = await post(url, body, {
    Authorization: `Bearer ${session.token}`
  })
  if (res.httpStatus === 401) {
    session = await post('/api/auth/refresh', {
      refresh_token: session.refresh_token
    }).then(r => r.data)
    res = await post(url, body, {
      Authorization: `Bearer ${session.token}`
    })
  }
  return { session, data: res.data }
}

async function createVideoSeriesTask(session, input) {
  const created = await authorizedPost(session, '/api/agent/tasks/create', input)
  session = created.session
  const taskNo = created.data.task_no

  while (true) {
    await sleep(10000)
    const detail = await authorizedPost(session, '/api/agent/tasks/detail', {
      task_no: taskNo
    })
    session = detail.session
    const task = detail.data

    if (task.status === 'waiting_asset_review') {
      return {
        type: 'waiting_asset_review',
        task_no: task.task_no,
        review_url: task.review_payload?.review_url,
        assets: task.review_payload?.assets || []
      }
    }

    if (task.status === 'completed') {
      return {
        type: 'completed',
        task_no: task.task_no,
        result: task.result
      }
    }

    if (task.status === 'failed' || task.status === 'cancelled') {
      return {
        type: task.status,
        task_no: task.task_no,
        error_message: task.error_message,
        checkpoint: task.checkpoint
      }
    }
  }
}
```

## Important Constraints

- Always persist the newest `token` and `refresh_token` after login or refresh.
- Always send `Authorization: Bearer <token>` for `/api/agent/*`.
- Never use a token from user A to read or mutate user B tasks.
- Do not create duplicate tasks for the same script unless explicitly requested.
- Persist `task_no` immediately after task creation.
- Poll with backoff; do not poll faster than every 5 seconds.
- Stop and surface `waiting_asset_review` to operator unless auto approval is explicitly allowed.
- If `refresh_token` is expired, login again.
- If task status is `failed`, inspect `error_message` and `checkpoint`.
