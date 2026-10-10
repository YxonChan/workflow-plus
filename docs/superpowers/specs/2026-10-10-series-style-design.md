# 作品风格设计（风格化接入作品）

日期：2026-10-10
状态：设计已确认，待评审文档

## 背景

风格化页面已经做好（`/admin/styles`），支持增删改查和官方默认风格。这一步把风格接进作品：创建作品时选一个作品风格，之后这部作品的图片和视频都按这个风格生成。

目前和"画面长什么样"有关的设置分散在三处，而且互相冲突：

- **画风**（`series.visual_style`：写实 / 动漫 / 3D）：只用于资产图和镜头首帧。
- **细分风格**（`series.visual_style_variant`，19 个选项）：同样只用于资产图和首帧，包括人物设定图。
- **视频前置提示词**（流程里视频生成节点的 `video_style_prompt`）：只用于视频，放在提示词里「视觉制作规则」那一段。官方流程预填的是 `DefaultWorkflowGraphs::videoStylePrompt()`，内容是 8K IMAX 照片级写实；为空时退回 `defaultVideoStylePrompt()`（live-action cinematic realism）。

视频提示词完全不读作品画风，所以动漫和 3D 作品的视频一直被要求"照片级写实"，和它们的资产图、首帧相反。另外，流程是多个作品共用的模板，风格写在流程里，没法让两部作品用不同风格。

## 已确认的决定

1. **风格跟着作品走。** 创建作品时选作品风格，可以不选；去掉流程里的「视频前置提示词」。
2. **画风冲突靠过滤解决。** 每条风格标注适用画风，作品里只列出适用的风格；提示词里画风规则仍然写明最高优先级，作为兜底。
3. **细分风格并入作品风格。** 去掉细分风格这个选项，19 个细分风格全部转成官方风格。
4. **每条风格分两段提示词。** 画法提示词进所有图和视频，包括人物、道具设定图；整片质感提示词只进场景图、首帧和视频。
5. **老作品不迁移。** 没有作品风格的作品只按画风默认规则生成。
6. **场景参考图带整片质感。** 视频会要求和场景图"光影和氛围一致"，场景图不带色调的话，视频会被中性光的场景图拉回去。

## 一、风格库

### 数据结构

`style_presets` 增加两列：

| 列 | 类型 | 说明 |
| --- | --- | --- |
| `visual_styles` | json | 适用画风，`realistic` / `anime` / `3d` 的非空子集 |
| `technique_prompt` | mediumtext | 画法提示词，可为空，上限 8000 字 |

- 现有 `prompt` 列语义不变，界面上叫「整片质感提示词」；`lighting_prompt`（片段光影）不变。
- `StylePresetController::ensureTable()` 在建表语句里加上这两列；表已存在时按 `AuthService::columnExists` 的写法补列。
- 旧数据 `visual_styles` 为空时按 `['realistic']` 处理。
- `database/sql/20261010_add_style_presets.sql` 同步更新。

### 官方风格

官方风格从 2 条扩到 22 条，按每个用户各存一份，沿用现有 `seedDefaults` 的写法（按 `code` 写入，内容变化时更新）。

| 画风 | sort | code | 名称 | 来源 |
| --- | --- | --- | --- | --- |
| 写实 | 10 | `short_drama` | 短剧写实 | 细分风格 |
| 写实 | 20 | `cinematic` | 电影感写实 | 细分风格 |
| 写实 | 30 | `documentary` | 纪实摄影 | 细分风格 |
| 写实 | 40 | `commercial` | 商业广告片 | 细分风格 |
| 写实 | 50 | `noir` | 暗调悬疑 | 细分风格 |
| 写实 | 60 | `period_comedy_honey` | 古装甜宠轻喜剧 | 文档（已有） |
| 写实 | 70 | `rainy_city_night` | 雨夜都市冷调 | 文档（已有） |
| 写实 | 80 | `widescreen_epic` | 宽银幕史诗电影 | 原视频前置提示词默认值 |
| 动漫 | 110 | `showa_anime` | 昭和动漫风 | 细分风格 |
| 动漫 | 120 | `classic_american_cartoon` | 40-50年代美式手绘卡通 | 细分风格 |
| 动漫 | 130 | `heisei_classic` | 平成经典动漫风 | 细分风格 |
| 动漫 | 140 | `moe` | 萌系动漫风 | 细分风格 |
| 动漫 | 150 | `kyoto_animation` | 京都动画风 | 细分风格 |
| 动漫 | 160 | `shinkai` | 新海诚风 | 细分风格 |
| 动漫 | 170 | `modern_mobile_game` | 现代手游二次元风 | 细分风格 |
| 动漫 | 180 | `guoman` | 国漫风 | 细分风格 |
| 动漫 | 190 | `cel_shaded` | 赛璐璐动画风 | 细分风格 |
| 3D | 210 | `animated_feature` | 动画电影3D | 细分风格 |
| 3D | 220 | `unreal` | 虚幻引擎写实 | 细分风格 |
| 3D | 230 | `stylized_3d` | 风格化3D | 细分风格 |
| 3D | 240 | `clay` | 黏土动画 | 细分风格 |
| 3D | 250 | `low_poly` | 低多边形 | 细分风格 |

每条官方风格的 `visual_styles` 只包含它所在的画风。`code` 与原细分风格的取值相同，外部接口兼容时按 `code` 对应。

文本要求：

- **名称和简介**沿用 `frontend/src/utils/visualStyle.ts` 里细分风格的 `label` 和 `desc`。
- **写实的 5 条**以 `seriesVisualStylePromptRule()` 里对应的一句话为基础，按文档的整片质感格式补全：介质、色调、对比度、光线、颗粒、氛围、镜头。同时填好参数，画法留空。
- **动漫和 3D 的 14 条**把原来的一句话拆成两段。画法只写线条、上色、材质、造型比例；整片质感只写色调、对比度、光线、氛围，不得出现胶片型号、焦段、景深等摄影术语。昭和动漫风和美式手绘卡通原有的复古胶片颗粒感可以保留，写成"复古胶片颗粒感"，不写具体胶片规格。参数只填色调、对比度、光线、氛围四组。`classic_american_cartoon` 保留"不要复刻任何既有角色、标志或受版权保护形象"。
- **宽银幕史诗电影**取原 `videoStylePrompt()` 里的 Style（只取 8K IMAX）、Cinematography、Lighting、Color、Camera 几段，改写成整片质感，画法留空。原文其余部分的去向见第三节。

### 风格化页面

- **列表页：** 顶部加画风筛选（全部 / 写实 / 动漫 / 3D），官方风格和我的风格两个区域都按它过滤；卡片上显示适用画风。
- **编辑页：**
  - 加「适用画风」，写实、动漫、3D 多选，至少选一个，新建时默认写实。
  - 加「画法提示词」输入框，提示文案：人物、道具设定图也会使用，只写线条、上色、材质、造型比例，不写色调和光线。
  - 原「提示词」改名「整片质感提示词」，提示文案：用于场景图、镜头首帧和视频。
  - 没勾写实时，隐藏拍摄介质、颗粒与纹理、镜头、年代与导演四组参数，保存时清空这四组；「按参数写入提示词」也只用可见的参数组。
- **详情和复制：** 详情展开里显示适用画风和画法提示词；「复制为我的风格」一并复制这两项。

### 删除和修改保护

- 「我的风格」正被作品使用时不能删除，返回 422：「有 N 个作品正在使用这个风格，请先在作品里换掉」。
- 修改「我的风格」的适用画风时，如果有在用的作品的画风被取消，返回 422：「有作品正在以{画风}使用这个风格，不能取消{画风}」。
- 正常编辑内容不受限制，改动对在用作品之后的生成生效。

### 接口

`style-presets/*` 的请求和返回增加 `visual_styles: string[]` 和 `technique_prompt: string`，校验规则如上。

## 二、作品

### 数据结构

- `series` 增加 `style_preset_id`（int unsigned，可空，加索引），在 `AuthService` 补 `visual_style_variant` 列的同一处补列，并写进迁移 SQL。
- `visual_style_variant` 列保留不删，但不再读取，新作品写空字符串。

### 创建作品

`NewWorkWizard.vue` 的作品设置里，「细分风格」换成「作品风格」：

- 选择器只列出 `visual_styles` 包含当前画风的风格，分「官方风格」「我的风格」两组，每项显示名称和简介。
- 可以清空，占位文案「不设风格，按画风默认」；默认不选。
- 下拉底部有「管理风格」入口，新标签页打开风格化页面，不打断当前向导。每次展开下拉都重新拉取列表，这样在新标签页里新建的风格，回来就能选到。
- 切换画风时，已选风格不适用就清空，并提示「原风格不适用于{画风}，已清空」。
- 风格列表加载失败时，选择器显示失败状态和重试，不影响不选风格直接创建。

这个选择器做成共享组件 `components/series/StylePresetSelect.vue`，创建向导和编辑弹窗共用。

### 修改作品

`SeriesView.vue` 的剧本编辑弹窗同样把细分风格换成作品风格，规则与创建时相同，另外：

- 选择器下方常驻提示：「更换后只影响之后的生成；已生成的场景图、首帧和视频不会自动重做。」
- 新旧风格的画法提示词不同时，追加提示：「画法也变了，建议重新生成人物和道具设定图。」

### 展示

- 生产看板（`ProductionDashboard.vue`）原来显示细分风格的位置改成显示风格名；没设置时显示「未设置风格」。
- 后台作品列表（`AdminProductionController`）返回风格名，不再返回细分风格。

### 接口校验

- 创建和更新接受 `style_preset_id`（可为 null）。所选风格必须属于作品所有者，并且适用于作品画风，否则返回 422：「所选风格不存在或不适用于当前画风」。
- 更新时只改画风、不传风格的情况下，如果原风格不适用于新画风，后端同样自动清空，不依赖前端先清。
- 作品数据返回 `style_preset_id` 和 `style_preset: {id, name, visual_styles} | null`。

### 外部接口兼容

`AgentTaskService` 创建作品时按顺序取风格：`style_preset_id`、`style_code`，最后才是旧字段 `visual_style_variant`。后两种按 `code` 找作品所有者的官方风格，必要时先写入官方风格；不适用于画风时忽略。

### 老作品

不做迁移。`style_preset_id` 为空的作品只按画风默认规则生成，带来以下变化：

- 原来选过的细分风格不再生效。
- 写实作品的视频不再带宽银幕史诗那段写法，改为第三节的写实画风视频规则。
- 想要原来的效果，在作品设置里选同名官方风格或宽银幕史诗电影即可。

## 三、生成

### 各类生成带哪些内容

| 生成内容 | 画风规则 | 画法 | 整片质感 | 片段光影 |
| --- | --- | --- | --- | --- |
| 人物、道具设定图 | 带 | 带 | 不带 | 不带 |
| 场景参考图 | 带 | 带 | 带 | 不带 |
| 镜头首帧 | 带 | 带 | 带 | 带 |
| 视频 | 带（视频版） | 带 | 带 | 带 |

人物、道具设定图继续沿用现有的白底、均匀棚拍光规则。

### 新的支持类

新增 `app/support/SeriesStylePrompt.php`，`SeriesController` 和 `AssetController` 里重复的画风规则收进这里：

- **读取风格：** 按 `series.style_preset_id` 读风格，校验属于作品所有者、适用于当前画风，任一不满足就当作没有风格，并写一条 warning 日志。
- **组合提示词：** 按画风、风格和生成类型（人物、道具、场景、首帧、视频）返回风格部分的提示词，规则见上表。这部分是纯函数，方便测试。
- **集中保存规则文字：** 图片用的画风规则（原 `seriesVisualStylePromptRule()` 的基础部分，去掉细分风格）、视频用的画风规则、视频通用规则。

原来的 `normalizeVisualStyleVariant()`、`seriesVisualStylePromptRule()` 里的细分风格部分、`videoStylePromptFromNodeParams()`、`videoStylePromptForPrompt()`、`videoStylePromptForQueuedJob()`、`defaultVideoStylePrompt()` 删除。

### 视频用的画风规则

在原默认文字基础上整理，每条都写明最高优先级：

- **写实：** 真人实拍质感，不要 3D 渲染、游戏引擎、动漫或卡通；真实皮肤纹理（毛孔、细纹、轻微瑕疵）；物理电影镜头的景深表现；自然表演，视线准确，有反应前的停顿和可见的呼吸。
- **动漫：** 二维动画质感，线条、上色和角色设定在各帧之间保持稳定；表演清楚、姿态易读；严禁照片级写实、真人实拍、照片皮肤质感和镜头景深，严禁 3D CGI 质感。
- **3D：** 三维动画渲染质感，角色模型、材质在各帧之间保持一致；动作风格化但符合物理；严禁二维平涂或赛璐璐质感，严禁真人实拍。

### 视频通用规则（系统内置）

取原默认文字里和风格无关的部分，每个视频都自动带上：

- **物理：** 重力和惯性真实，身体、衣物、道具、水、火、烟、碎片有真实的重量、接触和阻力。
- **连续性：** 角色身份、服装、道具、场景空间、画面方向、光线方向在各镜头之间保持稳定。
- **画面质量：** 24fps 电影运动感，对焦处主体清晰，不抖动、不糊、无压缩瑕疵、脸不变形、手不变形。
- **音效：** 剧本没有台词时只要环境音效，声音符合场景；剧本没写就不加音乐。

禁字幕继续由现有的 `videoNoSubtitleHardRule()` 负责，不重复。

### 视频提示词顺序

`buildVideoPrompt()` 改成：

1. 硬性禁止字幕（现有）
2. 画面风格（全片统一）：视频画风规则、画法、整片质感、片段光影
3. 参考图说明（现有）
4. 场景（现有）
5. 时间线（现有）
6. 制作要求：视频通用规则，替代原「视觉制作规则」
7. 要求：画面稳定……（现有）
8. 硬性禁止字幕（现有）

### 镜头首帧和资产图

- **镜头首帧：** `buildFrameImagePrompt()` 里的「视觉风格要求」改为：画风规则、画法、整片质感、片段光影。
- **资产图：** `AssetController` 里各条资产生图路径不再读 `visual_style_variant`。人物和道具只拼画风规则加画法，场景拼画风规则、画法加整片质感。`assetStylePriorityPrefix()` 的「画风最高优先级」前缀保留。

### 去掉视频前置提示词

- **前端：** `WorkflowStepCard.vue` 删掉这一栏，`utils/workflowGraph.ts` 删掉默认参数，`api/series.ts` 删掉相关类型。
- **官方流程：** `DefaultWorkflowGraphs` 两个视频节点删掉 `video_style_prompt`，删掉 `videoStylePrompt()`。
- **后端：** 不再读取流程节点或任务上下文里的 `video_style_prompt`，已有数据留在库里不删；重新生成时也不再往上下文写这个字段。

### 提示词预览

视频提示词预览接口去掉 `video_style_prompt`，改为返回：

- `style_name`：作品风格名，没有时为空。
- `style_prompt`：本次实际用到的「画面风格」整段。

`SeriesView.vue` 里原「视频前置提示词」一栏改成「作品风格：{名称}」，下面显示内容；没设置风格时标题显示「未设置作品风格，按画风默认」。

### 重新生成

现在点「重新生成」时，前端默认提交上次实际发送的完整提示词（`final_prompt`），后端把它当作手写提示词原样发送。作品换了风格后，重新生成仍会带着旧风格。

改为在 `doRerunEpisodeVideoShot()` 里比较。现有代码重新排队前会清掉上下文里的 `final_prompt`，所以比较要放在清掉之前：

- 提交的提示词与该任务上下文里上次的 `final_prompt` 去掉首尾空白后完全一致，视为没改过。这时 `node_prompt` 写回默认节点提示词，按普通生成重新拼，带上当前作品风格。
- 不一致视为手写，保持现在的原样发送。

前端不用改。

## 四、不做的事

- 镜头级的片段光影编辑。
- 速创里的风格选择。
- 老作品迁移，以及删除 `visual_style_variant` 列。
- 自动重新生成已有的资产图、首帧和视频。

## 五、测试与验证

- **提示词规则脚本：** 新增 `scripts/test_series_style_prompt_rules.php`，写法参照 `scripts/test_asset_scene_prop_prompt_rules.php`，在 php 容器里运行。覆盖：
  - 人物、道具设定图带画风规则和画法，不带整片质感和片段光影。
  - 场景参考图带画法和整片质感，不带片段光影。
  - 首帧和视频四样都带；视频里风格段在镜头内容之前，通用规则在之后。
  - 动漫作品不设风格时，视频提示词里没有 `8K IMAX`、`Photorealistic`。
  - 风格不适用于画风、不属于作品所有者、已不存在时，按没有风格处理。
  - 官方风格共 22 条，`code` 唯一且覆盖全部 19 个原细分风格取值，每条 `visual_styles` 非空；动漫和 3D 官方风格的画法非空，整片质感里没有 `Kodak`、`35mm`、`85mm` 这类摄影术语。
  - 重新生成时，提示词没改过就重新拼，改过就原样发送。
- **接口检查：** 用本地令牌调接口，覆盖：
  - 风格增删改带新字段。
  - 在用风格不能删，也不能取消在用的画风。
  - 作品创建、更新的风格校验。
  - 改画风后自动清空不适用的风格。
- **前端：** `npm run type-check` 和 `npm run check:i18n` 通过；新文案全部走 `t()`，在 `zh-CN.ts` 加 key（仓库目前只有这一个语言包）。手工检查：
  - 创建向导和编辑弹窗按画风过滤，切换画风时清空提示正确。
  - 风格化页面的画风筛选正常，编辑页按适用画风隐藏参数组。
  - 视频提示词预览显示作品风格。
- **容器重启：** 按 `docs/development-guidelines.md`，后端改完后重启 php、资产 worker、剧集节点 worker 和视频 worker 容器，用 `docker ps` 确认启动时间已更新，再做功能检查。

## 六、受影响文件

后端：

- `app/support/SeriesStylePrompt.php`（新增）
- `app/support/StylePresetDefaults.php`
- `app/controller/StylePresetController.php`
- `app/model/StylePreset.php`
- `app/controller/SeriesController.php`
- `app/controller/AssetController.php`
- `app/support/DefaultWorkflowGraphs.php`
- `app/support/AgentTaskService.php`
- `app/controller/AdminProductionController.php`
- `app/support/AuthService.php`
- `app/model/Series.php`
- `database/sql/20261010_add_style_presets.sql`
- `scripts/test_series_style_prompt_rules.php`（新增）

前端：

- `src/components/series/StylePresetSelect.vue`（新增）
- `src/components/series/NewWorkWizard.vue`
- `src/views/SeriesView.vue`
- `src/components/series/ProductionDashboard.vue`
- `src/views/StylePresetView.vue`
- `src/utils/stylePresetCatalog.ts`
- `src/utils/visualStyle.ts`（删掉细分风格相关内容）
- `src/components/workflow/WorkflowStepCard.vue`
- `src/utils/workflowGraph.ts`
- `src/api/stylePreset.ts`
- `src/api/series.ts`
- `src/api/adminProduction.ts`
- `src/types/index.ts`
- `src/i18n/locales/zh-CN.ts`
