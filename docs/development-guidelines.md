# Development Guidelines

## Multilingual UI

- Frontend user-facing text must go through `vue-i18n` with `t(...)` / `$t(...)`; do not hard-code visible Chinese or English in Vue templates, Element Plus messages, placeholders, tooltips, dialogs, or buttons.
- Chinese UI text is the translation key by default. When adding a new key, add the English mapping in `frontend/src/i18n/locales/en-US.ts` in the same change.
- If a feature adds backend error text that may be shown directly in the frontend, either keep the message intentionally Chinese-only for operational APIs or add a frontend localization layer before display.
- Feature work involving UI text is incomplete until `npm run type-check` passes and the i18n key scan reports no missing English mappings.
- Before delivery, scan recent additions for raw Chinese strings outside `t(...)` / `$t(...)`, especially in `ElMessage`, placeholders, button labels, and empty-state text.

## Local Docker Persistent Processes

- Backend changes that affect PHP long-running commands or queued jobs must be followed by a local Docker restart of the affected persistent containers before verification.
- At minimum, changes under worker-consumed code paths must restart the relevant worker containers, such as `malulu-episode-node-worker-1`, `malulu-episode-worker-1`, `malulu-video-worker-1`, `malulu-asset-worker-1`, `malulu-agent-worker-1`, or `malulu-worker-1`.
- Video generation code changes must restart both `malulu-episode-node-worker-1` and `malulu-video-worker-1`, because one creates video jobs and the other compiles and submits final video prompts.
- PHP-FPM request-path changes may also require restarting `malulu-php-1` if opcache or process state can keep old code loaded.
- Verification must include `docker ps` or `docker inspect` evidence that the affected containers have fresh start times, then a functional check against the changed feature.
