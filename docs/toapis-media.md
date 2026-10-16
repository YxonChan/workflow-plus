# ToAPIs Media Integration

Verified against the ToAPIs documentation on 2026-10-16:

- [Ordinary images](https://docs.toapis.com/docs/cn/api-reference/images/gpt-image-2.5/generation)
- [Official images](https://docs.toapis.com/docs/cn/api-reference/images/gpt-image-2.5-official/generation)
- [Image task billing](https://docs.toapis.com/docs/cn/api-reference/tasks/image-status)
- [Video media review](https://docs.toapis.com/docs/cn/api-reference/videos/private-assets)

## Models And Parameters

Global defaults use the Flare and Sunburst models, with ordinary and `-official` variants. The series name `gpt-image-2.5-official` is not a valid model ID. API keys stay in model configuration. Migration reuses an existing ToAPIs key, never an old provider's key, and preserves user-owned model configurations.

Ordinary requests send ratio `size`, `resolution` (1K/2K/4K), fixed `quality=high`, and optional JSON `reference_images`. Official requests send pixel `size`, one of `auto/low/medium/high/xhigh/max`, and optional JSON `image_urls`. Both support `background=transparent`. Each provider request has `n=1`; a batch creates separate tasks. Official custom sizes have local resource limits (256-4096 per edge, multiples of 16); the provider still validates supported sizes.

Both variants use asynchronous POST `/v1/images/generations` followed by GET `/v1/images/generations/{id}`. A saved task ID is resumed through GET without uploading references or submitting again. Result images are copied to application storage before the provider URL expires.

## Credits

The existing conversion is retained: USD x 7.2 x 1.25 / 0.01. Ordinary images cost 13.5/18/22.5 credits for 1K/2K/4K. References incur no additional ordinary fee. The old additional 2x image multiplier is removed.

Official has no fixed per-image price. The displayed estimate uses prompt length, reference count, pixel area and a quality-dependent output-token allowance. This is a heuristic, not a provider quote or spending cap. Final credits use `billing.cost_usd` only when billing is settled, retaining account discounts. Token usage is logged for inspection, not used to recompute the final bill. Explicit settled zero and refunded zero are accepted; missing or pending amounts are not interpreted as zero.

Completed images with pending billing are revisited by the Quick Create worker every minute, at most three per pass. This queries the original task, never regenerates it. Settlement continues with an owned model's credentials even if that model has been disabled. Ledger deduplication happens under the user balance lock, using both request-log references and provider task IDs. Actual Official settlement records the complete charge even when it exceeds the estimate, so a balance can become negative; further generation requires sufficient credits and topups cover the outstanding amount.

## Video Review

Videos containing media send `private_asset_review=true` and a stable `client_business_id`. Public source URLs are passed directly; local images are uploaded only when a public URL is unavailable. Legacy owned `asset://` bindings resolve back to their source image. Missing or foreign sources fail before submission. Independent private-avatar creation and manual face-check endpoints are retired. Review and generation share the video task and polling heartbeat, with a longer polling window for integrated review.

## Verification

`php scripts/test_toapis_media.php` runs parameter and pricing policy checks. With `PROMPT_TEST_MYSQL_DSN` and `PROMPT_TEST_HTTP_URL`, it creates and drops a dedicated test database and exercises the real image/video HTTP adapters against `scripts/fixtures/toapis_media_http.php`. Run these fixtures on an isolated internal network with the fixture host aliased as `api.toapis.cn`; do not point this suite at a paid provider endpoint.

`frontend/scripts/test-toapis-media.cjs` uses Playwright with fully mocked API responses to verify ordinary/Official controls, multiple uploads, batch quotes and outgoing parameters on desktop and mobile. `PLAYWRIGHT_MODULE_PATH` can point at an existing Playwright installation.
