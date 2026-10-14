# 作品风格 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 创建作品时选择作品风格，图片和视频按这条风格生成，并去掉流程里的视频前置提示词。

**Architecture:** `SeriesStylePrompt` 负责按画风和生成类型拼风格段。风格库存适用画风和画法提示词。作品用 `style_preset_id` 指向风格。视频通用规则内置，不再读流程节点上的 `video_style_prompt`。

**Tech Stack:** ThinkPHP 8、PHP 8.2、Vue 3、Element Plus、vue-i18n。

## Global Constraints

- 风格可选；不选时只走画风默认规则。
- 画风冲突靠 `visual_styles` 过滤，提示词里画风规则仍是最高优先级。
- 细分风格并入官方风格，共 22 条；`visual_style_variant` 列保留但不再读取，新作品写空字符串。
- 画法进所有图和视频；整片质感只进场景、首帧、视频；片段光影只进首帧和视频。
- 老作品不迁移。
- 人物、道具设定图保持白底棚拍，不带整片质感。
- 前端文案走 `t()`，新 key 写入 `frontend/src/i18n/locales/zh-CN.ts`。
- 不提交 git，除非用户明确要求。

---

### Task 1: 风格拼装

**Files:**
- Create: `app/support/SeriesStylePrompt.php`
- Test: `scripts/test_series_style_prompt_rules.php`

- [ ] 纯函数 `compose`、`assembleVideoPrompt`、`rerunUsesCurrentStyle`，以及官方目录校验。
- [ ] 运行 `docker exec` 里的 php 脚本，预期全部 PASS。

### Task 2: 风格库数据与接口

**Files:**
- Modify: `app/support/StylePresetDefaults.php`
- Modify: `app/model/StylePreset.php`
- Modify: `app/controller/StylePresetController.php`
- Modify: `database/sql/20261010_add_style_presets.sql`
- Modify: `app/support/AuthService.php`

- [ ] 22 条官方风格，含 `visual_styles` 与 `technique_prompt`。
- [ ] 在用风格不能删，也不能取消正在使用的画风。

### Task 3: 作品字段与生成链路

**Files:**
- Modify: `app/model/Series.php`
- Modify: `app/controller/SeriesController.php`
- Modify: `app/controller/AssetController.php`
- Modify: `app/support/DefaultWorkflowGraphs.php`
- Modify: `app/support/AgentTaskService.php`
- Modify: `app/controller/AdminProductionController.php`

- [ ] 创建、更新校验 `style_preset_id`；改画风时清空不适用的风格。
- [ ] 视频、首帧、资产图按类型带风格；去掉 `video_style_prompt` 读取。
- [ ] 重新生成：提示词与上次 `final_prompt` 一致时按当前风格重拼。

### Task 4: 前端

**Files:**
- Create: `frontend/src/components/series/StylePresetSelect.vue`
- Modify: 风格化页、创建向导、作品编辑、生产看板、流程卡片、类型与 i18n。

- [ ] `npm run type-check` 与 `npm run check:i18n` 通过。

### Task 5: 验证

- [ ] 重跑提示词脚本和资产提示词脚本。
- [ ] 按 `docs/development-guidelines.md` 重启 php、资产 worker、剧集节点 worker、视频 worker。
