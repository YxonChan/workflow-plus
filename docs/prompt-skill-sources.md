# 提示词 Skills 来源筛选

核查日期：2026-10-15。本文记录适配建议，未新增或安装以下 Skills。

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
| [coreyhaines31/marketingskills](https://github.com/coreyhaines31/marketingskills) | `image`、`video`、`ad-creative`、`product-marketing` | 营销需求补问、商品卖点、广告创意、图片与视频组织 | 首选；当前六个内置方法已经基于其中部分内容适配，可继续补强参考资料 |
| [anthropics/skills](https://github.com/anthropics/skills/tree/dbd4588f9e1033efb41dad4bef2f7947c8993d44/skills/skill-creator) | `skill-creator` 的需求采集、测试案例、对照评估、反馈迭代 | 改进自动创建私有 Skill 的质量流程 | 作为后台创建与验证方法参考，不加入普通创作匹配目录 |
| [inference-sh/skills](https://github.com/inference-sh/skills) | `video-prompting-guide`、`storyboard-creation`、`ai-product-photography`、`image-to-video` | 运镜词汇、分镜连续性、商品摄影、图生视频动作描述 | 内容相关，列为待适配候选；原版依赖 `belt` CLI，需要拆出纯创作方法并先确认授权 |

已核查的版本：

- `coreyhaines31/marketingskills`：`55ff469422c3592dac45bcad08dfb40221981c02`。
- `anthropics/skills`：`dbd4588f9e1033efb41dad4bef2f7947c8993d44`。
- `inference-sh/skills`：`0cbf91c26ada28702c79962406286f0c14292150`。

许可证核查：`marketingskills` 有 MIT LICENSE；Anthropic 的 `skill-creator/LICENSE.txt` 为 Apache-2.0，不能将仓库内所有 Skills 一概视为同一许可。`inference-sh/skills` README 标注 MIT，但此次未找到独立 LICENSE 文件，GitHub API 也未识别许可证；正式内置前需确认完整授权与声明。

## 推荐补强顺序

1. 营销需求采集与广告创意：完善现有产品图片、产品营销视频方法，已有信息不重复询问。
2. 图生视频：固定参考图中的身份、产品形态与初始画面，明确需要发生的动作及保持项。
3. 多镜头连续性：补强人物位置、视线、运动方向、道具状态和镜头衔接。
4. 产品摄影：补强白底主图、材质细节、使用场景和产品外观保持。

这些是方法层面的建议，尚未验证其对实际出图或出片质量的提升。模型能力、时长、规格和可用参数仍以本项目配置为准，不采用开源文档中未经验证的模型排名或参数表。适配时不授予 Shell 或任意外部服务权限，继续遵守当前画风、素材归属和关键事实确认规则。

另外核查了 [remotion-dev/skills](https://github.com/remotion-dev/skills)：主要面向用代码制作、渲染视频，更适合以后开发可控字幕、界面演示或模板视频时评估，当前提示词助手不优先接入。
