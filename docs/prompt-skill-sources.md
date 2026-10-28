# 提示词 Skills 来源筛选

## 图片用途与模板关联（2026-10-21）

再次读取本文记录的固定版本 Beatra `product-photo-studio/SKILL.md` 与 Marketing Skills `image/SKILL.md`：前者按白底商品、场景生活和局部改图分流，后者先确定用途、投放位置和规格，再决定风格、参考图和排版。本次只参考创作方法，继续使用平台已有生成通道与积分逻辑。

`app/resources/ecommerce_image_designs.json` 是前后端共享的用途、模板兼容表与详情版式定义。选中详情模板后，商品大图、细节、场景与文案按模板整体结构组成一张完整图片，不拆成多张图片或要求用户选择页数：

| 用途 | 内容 | 可选方向 |
| --- | --- | --- |
| 商品主图 | 单张方形 M1 | 纯白主图、卖点主图、真实场景主图 |
| 商品详情图 | 一张竖版完整详情图，所有图文模块在同一画布内 | 模块科技、品牌详情、A+、自然、美妆画册等整体模板 |
| 宣传海报 | 单张或 2/4 个独立方案 | 卖点、生活或空间海报 |
| 模特试穿 | 单张或 2/4 个独立方案 | 干净棚拍、自然街拍、日常生活；环境缩略图用于选择摄影方向 |

详情模板作为独立参考图传入模型，商品参考只决定真实商品外观。已确认标题、卖点在模型中按模板文字区排版，不叠加通用海报的头尾文字层；没有文案的区域用商品图像和留白完成，不编造文字。一次详情生成只提交一个图片任务，不再按多页套图累加；积分仍按当前模型、清晰度与参考素材规则动态报价。纯白 M1 不加营销排版，同时排除人物与场景参考。Amazon / A+ 是视觉方向名称，不能代替目标类目当前的上架审核。

旧的商品套图、主图与详情草稿自动迁移为商品详情图，旧成品和分页快照仍可编辑、下载与导出。分页代码仅用于这些历史结果，不再出现在新建流程。新请求冻结用途、模板及 `layout: template`，局部改图沿用该快照。服务端报价前校验用途、模板、参考图、一张图片及无分页信息，并组合现有内置 Skills。私有 Skills 不加入这张共享表。

核查日期：2026-10-20。平台共享内置库已由 6 个扩展到 12 个方法，同时接入提示词助手与电商宝盒。只适配创作方法与规则，不安装上游 CLI、执行脚本或接入上游收费服务。

## 用户隔离规则

- 平台内置库由项目维护，位于 `app/resources/prompt_skills.json`。
- 自动创建的 Skill 记录创建者 `user_id`，默认是私有候选。
- 私有候选可服务于本次任务；验证通过后，仅参与创建者本人的后续匹配。
- 验证通过不等于加入平台内置库，不会供其他客户匹配、读取或使用。
- 当前没有自动共享或将私有 Skill 提升为内置 Skill 的流程。平台扩充内置库应独立维护，不自动吸收客户内容。
- 服务端生成匹配目录时按当前用户查询；即使模型返回其他用户的私有 ID，也会被白名单校验拒绝。

对应代码：`PromptAssistantService::skills()`、`turn()` 和 `PromptAssistantPolicy::plan()`。测试覆盖候选及验证通过后的跨用户访问与匹配。

## 优先来源

| 来源 | 核查内容 | 适配价值 | 接入判断 |
| --- | --- | --- | --- |
| [coreyhaines31/marketingskills](https://github.com/coreyhaines31/marketingskills) | `image`、`video`、`ad-creative`、`product-marketing` | 营销需求补问、商品卖点、广告创意、图片与视频组织 | 已适配；保留原 6 个方法，新增详情与海报版式、商品图生视频、生活种草与带货短片 |
| [beatra-ai/ai-product-photography-skill](https://github.com/beatra-ai/ai-product-photography-skill) | `product-photo-studio/SKILL.md`、`references/scene-craft.md` | 商品外观锚定、材质布光、干净边缘、接触阴影和场景合成 | 已适配商品棚拍与主图、材质与工艺、场景与使用展示；不采用其账号、MCP、Python、模型排名和价格配置 |
| [anthropics/skills](https://github.com/anthropics/skills/tree/dbd4588f9e1033efb41dad4bef2f7947c8993d44/skills/skill-creator) | `skill-creator` 的需求采集、测试案例、对照评估、反馈迭代 | 改进自动创建私有 Skill 的质量流程 | 作为后台创建与验证方法参考，不加入普通创作匹配目录 |
| [inference-sh/skills](https://github.com/inference-sh/skills) | `video-prompting-guide`、`storyboard-creation`、`ai-product-photography`、`image-to-video` | 运镜词汇、分镜连续性、商品摄影、图生视频动作描述 | 内容相关，列为待适配候选；原版依赖 `belt` CLI，需要拆出纯创作方法并先确认授权 |

已核查的版本：

- `coreyhaines31/marketingskills`：`55ff469422c3592dac45bcad08dfb40221981c02`。
- `beatra-ai/ai-product-photography-skill`：`5c64090847d96830e49927924281f04bd576253d`。
- `anthropics/skills`：`dbd4588f9e1033efb41dad4bef2f7947c8993d44`。
- `inference-sh/skills`：`0cbf91c26ada28702c79962406286f0c14292150`。

许可证核查：`marketingskills` 有 MIT LICENSE；Beatra 有 MIT-0 LICENSE，两份原始声明均保存在 `app/resources/prompt_skills.LICENSE.txt`，目录按方法保留来源、版本与许可证。Anthropic 的 `skill-creator/LICENSE.txt` 为 Apache-2.0，不能将仓库内所有 Skills 一概视为同一许可。`inference-sh/skills` README 标注 MIT，但此次未找到独立 LICENSE 文件，GitHub API 也未识别许可证；本次未内置该来源。

## 当前接入方式

共享目录由 `PromptSkillCatalog::builtins()` 读取。提示词助手保留 AI 匹配：模型只能从当前用户可见目录选择，新增方法自动参与匹配，私有方法的访问规则不变。

电商宝盒由 `EcommerceSkills::apply()` 按制作目标、单页用途及视频方向选择服务端白名单方法，将 `generation_rules` 写入实际生成提示词，不额外调用决策模型。普通商品主图选棚拍方法；材质页选细节方法；场景/试穿页选使用展示方法；详情与海报选版式方法；视频组合商品营销、参考图转视频和分镜规则，生活种草方向另加 UGC 方法。局部改图只加商品保持规则，避免强制重排原图。

编译在素材归属验证后、费用预估前执行。规则文本参与 Official 文本用量预估，规则与方法版本随任务请求冻结；重新报价或重试会重建保留规则块，避免重复叠加。客户端传入的方法 ID、私有 Skill 或规则快照不能替换服务端选择。商品提取、模特和场景参考生成不套用成品方法。

新增的 6 个方法均为维护者编写的纯创作规则，不包含用户内容。界面不展示来源、许可证、匹配过程等内部信息。

## 推荐补强顺序

1. 营销需求采集与广告创意：完善现有产品图片、产品营销视频方法，已有信息不重复询问。
2. 图生视频：固定参考图中的身份、产品形态与初始画面，明确需要发生的动作及保持项。
3. 多镜头连续性：补强人物位置、视线、运动方向、道具状态和镜头衔接。
4. 产品摄影：补强白底主图、材质细节、使用场景和产品外观保持。

本次已完成上述方法的代码适配与隔离回归，尚未通过真实付费生成验证其对实际出图或出片质量的提升。模型能力、时长、规格和可用参数仍以本项目配置为准，不采用开源文档中未经验证的模型排名或参数表。适配不授予 Shell 或任意外部服务权限，继续遵守当前画风、素材归属和关键事实确认规则。

另外核查了 [remotion-dev/skills](https://github.com/remotion-dev/skills)：主要面向用代码制作、渲染视频，更适合以后开发可控字幕、界面演示或模板视频时评估，当前提示词助手不优先接入。


## 电商模板扩充与图片交接（2026-10-28）

本次把 8 个主图、12 个单画布详情方向登记到 `app/resources/ecommerce_image_designs.json` 的 `commerce-v1` 包。由于用户选择自行生成参考图，新增方向全部保持 `enabled: false`，不展示、不接受新生成提交。图片经人工视觉复核及 `scripts/import_ecommerce_templates.py` 校验后按批启用，不以占位图片冒充成品。旧模板及历史成品继续保留。完整文件名、逐图提示词和交接步骤在 `docs/ecommerce-template-pack`。

已下载并阅读以下 MIT 来源的相关创作方法，没有执行仓库脚本、导入示例图、安装上游 Codex Skill 或接入上游 API：

| 来源 | 固定版本 | 采用范围 |
| --- | --- | --- |
| https://github.com/xianyu110/ecommerce-image-skills | `f4566fdc812584425728862ad8e08c90883d81ee` | 商品身份锁定、单个卖点的信息层级、合理场景、详情节奏；不采用多页生成和拼接流程 |
| https://github.com/buluslan/gpt-image2-ecommerce | `73abd50bf2b689b5a453f28ddd7b0016415deec6` | hero、flat-lay、luxury-atmospherics、A+ 的构图方向；不采用示例功效、参数、成分、认证、模型推荐或平台尺寸声明 |
| https://github.com/oldred-byte/ec-visual-skill | `fead6fa34679a920ed67c7119e2df526ed2d139c` | 商品/品牌优先配色、可见证据、避免重复构图、画面与卖点对应；不采用强制 5 主图 + 10 屏、推断缺失事实或禁止系统排字等全局流程 |

三份上游 MIT 原始版权声明保存在 `app/resources/ecommerce_templates.LICENSE.txt`。仓库许可证不代表其示例照片、品牌和肖像已经授权，本次不复用这些图片。共享定义记录来源、固定提交、原路径和许可；现有 `builtin:product-image` 与 `builtin:product-detail-layout` 升为 `1.2`，扩充真实外观、配色、单个视觉重点及图像模块节奏规则，并记录 `source_adaptations`。规则仍经服务端白名单编译进实际提示词，私有 Skill 隔离、模型通道、素材权限和计费保持原样。新模板的 `generationRules` 只从服务端维护目录读取，客户端不能伪造启用或替换规则；局部编辑不重新套用模板构图。

此次尚无用户交付的 20 张参考图，不声称已经完成扩充模板的实际付费出图质量验证。自动测试验证的是目录、启用门槛、请求、参考图顺序、导入格式和兼容性，不能替代视觉审核。

2026-10-28 下午更新：用户已交付上述全部 20 张参考图，经过文件校验、总览及重点大图检查后导入并启用，现有 12 个主图和 20 个详情图选项。原图保持不变，源资源、本地构建与 HTTP 返回已核对一致。25 项前端领域测试、582 项后端隔离检查、3 项导入测试、桌面/手机图库专项测试及构建通过。仍未发起真实模型的付费效果测试，不把模板集成验证等同于实际生成质量承诺。详见 `docs/ecommerce-template-pack/验证记录.md`。
