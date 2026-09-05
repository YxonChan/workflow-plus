import type { PromptTemplate, WorkflowScope } from '@/types'
import type { NodeKind } from '@/utils/workflowGraph'

// ── Built-in fallback prompts (moved out of WorkflowView) ──────────────────────
export const SERIES_ASSET_PROMPT = `你是AI短剧资产拆解专家。请只根据完整剧本、分集规划和上游结构拆解结果提取可复用资产，输出严格 JSON。

核心目标：
1. 为后续 AI 生图/视频保持角色、场景、道具一致性。
2. 根据上下文补全“视觉上可执行”的人物、场景、物品描述。
3. 不要只列名称，description 和 image_prompt 必须有内容。
4. 只提取资产库需要长期复用的实体，不提取一次性镜头动作、情绪瞬间或后期效果。

分类规则：
- characters：只放人物基础形态。必须从上下文提取或保守推断：年龄段、性别、气质、关系身份、脸部、发型、固定外貌特征、体型，并结合剧情时代、世界观、身份与阶层写出古代/民国/现代/未来等时代外观线索。characters 的 image_prompt 只能使用白底棚拍、无手持物、统一穿素色中性长袖长裤连体服与简洁平底鞋；不要写剧情服装、外套造型、盔甲、武器、手机、包、文件、场景光线或动作姿势。
- locations：只放地点/空间/环境。比如公寓厨房、楼道、病房、办公室、街道、车内。凡是房间、建筑、环境、场所，都必须放在 locations，不能放进 props。
- props：只放可被角色拿取、穿戴、交付、查看或反复出现的实体物件。服装、造型、装备、饰品、信封、离婚协议、医疗报告、戒指、手机都必须放进 props。地点和环境绝对不能放进 props。

禁止提取：
- 镜头语言/剪辑/特效：特写、慢镜头、镜头推进、黑屏字幕、转场、VFX、回忆闪现。
- 动作或情绪瞬间：眼泪滑落、信封放置、泪水晕墨、情绪沉淀、沉默凝视。
- 只描述画面效果但不能作为独立参考图长期复用的内容。

自检要求：
1. locations 不能出现在 props。
2. props 不能包含房间、楼道、建筑、城市、环境、氛围。
3. 人物不能只写名字，必须写可视化描述。
4. 每个资产都必须有 name、type、description、image_prompt、tags。`

export const EPISODE_ASSET_PROMPT = `请基于本集“剧情扩写”后的完整剧情提取本集需要长期复用的人物、场景、道具资产，并参考 asset_library 中已有资产做增量合并。

输出严格 JSON：
{
  "assets": [ { "name": "...", "type": "character|scene|prop", "description": "...", "image_prompt": "...", "tags": [], "match_name": "已有资产名或空" } ],
  "character_looks": [ { "character_name": "...", "look_name": "...", "description": "...", "image_prompt": "...", "tags": [], "match_character": "已有角色名或空" } ]
}。

要求：
1. 如果本集实体已经存在于 asset_library，请沿用已有 name，并在 match_name / match_character 写已有资产名；不要换同义词造成重复资产。
2. character 只描述角色基础形态：年龄、性别、脸部、发型、体型、固定外貌、气质，并结合剧情时代、世界观、身份与阶层写出时代外观线索；参考图默认白底棚拍、无手持物、统一穿素色中性长袖长裤连体服与简洁平底鞋。
3. character 禁止写剧情服装、外套、盔甲、武器、手机、包、文件、场景光线、动作姿势；这些都不要写进 prop，而是放入 character_looks。
4. prop 只包含非服装类道具、装备、手持物和关键物件；scene 只描述地点/空间/环境。
5. 只有明确是人类或类人可穿搭角色的 character，才必须至少输出 1 套 character_looks；如果剧情没有明说服装，请根据时代、身份、职业/阶层、场景保守推断，并在 tags 加 inferred_look。
6. 动物、宠物、怪物、坐骑、非人类生物仍然可以归类为 character，但默认不要强行输出 character_looks；除非剧情明确要求其具备稳定可穿戴造型，并在 tags 标记 humanoid 或 anthropomorphic。
7. character_looks 的 image_prompt（全风格统一）：输出左右分栏造型板。左栏正、侧、背三个无头全身（头从衣领处切除，只看服装）；右栏同一人物放大特写（头部五官完整）。禁止红笔涂脸；禁止只出一张带头正面全身。白底棚拍，保持同一角色五官发型体型稳定，重点变化在服装与整体造型。写实造型的人像入库由系统在展示图完成后自动处理，不要写进 image_prompt。
8. 服装必须是可公开外穿的完整套装，至少包含上衣、裤装/裙装、鞋履，可包含帽子/发饰/腰带/外套；禁止只输出内衣、内裤、贴身打底服或裸露身体。
9. 不要提取镜头动作、情绪瞬间、转场或后期特效。
10. 硬性：每个 character_looks 的 character_name / match_character 必须在 assets 里有一条同名 type=character。禁止只输出造型、漏掉人物本体。`

export const CALL_SHEET_PROMPT = `根据上游剧情或分镜内容生成拍摄通告单 HTML。必须严格基于已有内容，不要编造人物、场景、服装或道具；不明确的信息可以做保守推断并标注 [inferred]。输出完整 HTML 文档，不要 Markdown 包裹。`

export const SERIES_ASSET_PROMPT_EN = `You are an AI short-drama asset breakdown specialist. Use only the full script, episode plan, and upstream structure results to extract reusable assets and return strict JSON.

Goals:
1. Keep characters, locations, and props consistent for later image and video generation.
2. Fill in visually actionable descriptions from context.
3. Do not return name-only items; description and image_prompt must be meaningful.
4. Extract only assets worth reusing across shots, not one-off actions, emotions, or post effects.

Rules:
- characters: base identity only. Infer age range, gender, temperament, role, facial features, hairstyle, fixed appearance, and build conservatively, and include era cues from the story setting, social class, and worldbuilding. image_prompt must be a white-background studio reference with no handheld items, using a plain neutral long-sleeve full-body jumpsuit and simple flat shoes. Do not include story costumes, armor, weapons, phones, bags, files, scene lighting, or action poses.
- locations: places, rooms, buildings, and environments only.
- props: only reusable physical objects that can be held, worn, delivered, viewed, or repeatedly shown. Costumes, styling items, gear, accessories, envelopes, contracts, reports, rings, and phones belong here. Never put places or environments in props.

Do not extract:
- camera/editing/VFX language
- momentary actions or emotions
- pure visual effects that cannot serve as reusable reference assets

Validation:
1. locations must not appear in props.
2. props must not contain rooms, buildings, cities, or atmosphere.
3. characters must be visually described, not just named.
4. Every asset must include name, type, description, image_prompt, and tags.`

export const EPISODE_ASSET_PROMPT_EN = `Based on this episode's fully expanded plot, extract reusable characters, scenes, props, and character looks needed for the episode, then merge incrementally with existing items from asset_library.

Return strict JSON:
{
  "assets": [ { "name": "...", "type": "character|scene|prop", "description": "...", "image_prompt": "...", "tags": [], "match_name": "existing asset name or empty" } ],
  "character_looks": [ { "character_name": "...", "look_name": "...", "description": "...", "image_prompt": "...", "tags": [], "match_character": "existing character name or empty" } ]
}

Requirements:
1. If an entity already exists in asset_library, reuse the existing name and fill match_name / match_character to avoid duplicates.
2. character should describe only base appearance: age, gender, face, hairstyle, build, fixed traits, temperament, plus era cues grounded in the story setting, worldbuilding, identity, and class. Reference images should default to white-background studio shots with no handheld items and a plain neutral long-sleeve full-body jumpsuit with simple flat shoes.
3. Story outfits, coats, armor, weapons, phones, bags, files, scene lighting, and poses must not go into character base assets. Put wearable styling into character_looks instead.
4. prop is for non-clothing props and key objects; scene is for place, space, and environment.
5. Human or humanoid characters should usually have at least one character_look. If clothes are not explicit, infer conservatively from era, identity, class, or setting and add inferred_look to tags.
6. Animals, pets, monsters, mounts, or other non-human beings may still be character assets, but should not force character_looks unless the story clearly requires a stable wearable design. Tag humanoid or anthropomorphic when relevant.
7. character_look image_prompt (all styles): complete full-body look with head and facial features fully visible — no headless bodies, no red marks. White studio background; keep identity stable while changing only clothing/styling. Hidden colored-pencil video refs for realistic series are generated by the system; do not put them in image_prompt.
8. Outfits must be complete public outerwear sets with at least top, bottoms or skirt, and shoes. Hats, hair accessories, belts, or coats are optional. Do not output underwear-only or exposed-body looks.
9. Do not extract camera actions, emotional beats, transitions, or post effects.`

export const CALL_SHEET_PROMPT_EN = 'Generate a shooting call sheet HTML document from the upstream plot or storyboard. Stay strictly grounded in the provided content. Do not invent characters, locations, costumes, or props. You may make conservative inferences and mark them with [inferred]. Return a complete HTML document, not Markdown.'

const PLOT_EXPANSION_PROMPT = '请根据剧集剧情简介扩写为一集 60-90 秒短剧完整剧情，包含人物、场景、动作、对白、情绪转折和结尾钩子。'
const PLOT_EXPANSION_PROMPT_EN = 'Expand the episode summary into a complete 60-90 second short-drama plot with characters, scenes, actions, dialogue, emotional turns, and an ending hook.'

const STORYBOARD_PROMPT = `请把上游剧情拆分为 6-10 个可直接用于图生视频模型的长分镜视频节点，不要输出 JSON，不要 Markdown 表格，不要代码块。

我要的不是简短摘要式分镜，而是可以直接投喂视频模型的“高密度长分镜文案”。
每个【视频节点】都要像专业影视摄影脚本，明确写出主体、空间、动作节拍、镜头语言、声音、光色、空气感和最后定格。

格式：
【视频节点01｜15s｜一句话概括本节点的核心动作与戏剧点】
引用资产：@人物名 @人物名·造型名 @场景名 @道具名

镜头1（0-3s · wide shot / medium shot / close-up / extreme close-up · 运镜与机位说明）：
主体：本阶段出现的人物/主体。
场景：空间结构、材质、光线、空气状态、背景细节。
动作：角色在这一阶段的主要动作与状态变化。
镜头：景别、机位、运镜方式、运动强度、构图关系。
声音：环境声、动作声、必要对白。
光色：色温、主导光源、冷暖对比、阴影关系。

镜头2：继续同一条连续镜头内的动作推进。
镜头3：关键戏剧瞬间，可写极近景/局部变化。
镜头4：动作收束 / 转入结尾。

定焦画面：最后 1 秒必须是什么稳定画面；不要 fade out，不要 motion blur，不要失焦，也不要把定格拆成新节点。

要求：
1. 一个视频节点只能是一条连续镜头，不允许切镜，不允许多机位剪辑。
2. 必须覆盖完整上游剧情，从开端、冲突、关键转折到结尾钩子都要拆进去；不要只写第一场或第一个地点。除非上游剧情极短，否则至少输出 3 个视频节点，推荐 6-10 个。
3. 允许在同一节点内部写“镜头1/镜头2/镜头3/镜头4”这种阶段化描述，但它们必须属于同一条连续镜头里的动作递进。
4. 文案必须足够长、足够具体，不能只写一句话摘要。
5. 尽量明确写出景别、机位、运镜、构图位置、速度感、色温、光线来源、冷暖关系、阴影状态、空气中的雾气/尘粒/冰晶/布料/呼吸等可视化细节。
6. 人物动作要写成视频模型能直接理解的可视化动作过程。
7. 不要写字幕、UI、分屏、拼图、故事板。
8. 每个视频节点标题下必须写“引用资产：”，只列该节点真实出现的 asset_library 资产；每个引用都必须加 @，例如 引用资产：@林逸 @林逸·第1集常服 @圣辉魔法学院魔力测试广场 @《龙虎归元图》。
9. 严禁输出“图片1：资产名 / 图片2：资产名 / Image 1: name”；引用资产行和正文都只能写 @真实资产名。英文名若含空格，只在完整名字开头加一个 @，例如 @Jude Parker、@Jude Parker·Episode 1 Casual Travel Wear；禁止拆成 @Jude @Parker。
10. 正文里出现对应人物、人物造型、场景或道具时，必须保留 @ 前缀，方便用户编辑时直接看到引用。
11. 如果 asset_library 里有明确的人物造型候选，且镜头要指定穿搭，必须写完整 @人物名·造型名；只写 @人物名 不会命中造型图，也不要编造不存在的造型名。
12. 同一视频节点内，只要某角色已经使用了 @人物名·造型名，后续所有指代该角色的动作、站位、表情、对白都继续写完整 @人物名·造型名，不要简写回人物本名。
13. 每个 15s 视频节点内部镜头时间码必须连续覆盖 0-15s（如 0-5s、5-9s、9-14s、14-15s）；禁止 0-1s/1-2s/2-3s 碎片切镜后再跳到 14-15s。`

const STORYBOARD_PROMPT_EN = `Split the upstream plot into 6-10 long-form storyboard video segments that can be used directly for image-to-video generation. Do not output JSON, Markdown tables, or code fences.

This should not be a short summary. Each segment must read like a dense professional cinematography script with subject, space, action beats, camera language, sound, lighting, atmosphere, and final freeze frame.

Format:
[Video Segment 01 | 15s | one-sentence dramatic summary]
Referenced assets: @character @character.look @scene @prop

Shot 1 (0-3s | wide shot / medium shot / close-up / extreme close-up | camera move and angle):
Subject:
Scene:
Action:
Camera:
Sound:
Lighting:

Shot 2: continue the same continuous shot.
Shot 3: key dramatic beat.
Shot 4: settle the action and land the ending.

Final frame: define the exact stable image for the last second. No fade out, no motion blur, no defocus, and do not split the ending freeze into another segment.

Requirements:
1. No cuts and no multi-camera editing.
2. Cover the full upstream plot from setup to conflict to turning point to ending hook. Unless the source plot is extremely short, produce at least 3 segments and preferably 6-10.
3. Shot 1/2/3/4 are phased beats inside the same continuous shot.
4. The writing must be long, specific, and directly usable by a video model.
5. Be explicit about shot size, angle, movement, framing, speed, color temperature, light sources, warm/cool contrast, shadows, and atmospheric details.
6. Character actions must be described as visible motion sequences a video model can understand.
7. Do not add subtitles, UI, split screens, collages, or storyboard meta notes.
8. Every segment title must include "Referenced assets:" and list only real asset_library items that appear in that segment, each prefixed with @. Example: Referenced assets: @Jude Parker @"Jude Parker·Episode 1 Casual Travel Wear" @Dusty Road. Multi-word names stay one mention: write @Jude Parker, never @Jude @Parker, and never put @ on later words of the same name.
9. Never write "Image 1: name / 图片1：资产名". Only @ExactAssetName is allowed in the assets line and body.
10. Keep the @ prefixes in the body whenever a referenced character, look, scene, or prop appears.
11. If asset_library already has a matching character look and the shot specifies clothing, use the full @character.look form. Do not invent look names.
12. Once a character is introduced as @character.look inside a segment, keep using the full @character.look form for that character throughout the segment.
13. Inside each 15s node, shot timers must continuously cover 0-15s (e.g. 0-5s, 5-9s, 9-14s, 14-15s). Forbidden: 0-1s / 1-2s / 2-3s fragment chains that jump to 14-15s.`

const IMAGE_PROMPT = '根据上游分镜内容生成每个镜头的首帧图片；如果涉及人物、人物造型、场景或物品，优先参考资产库保持一致性。'
const IMAGE_PROMPT_EN = 'Generate the first-frame image for each shot from the upstream storyboard. When characters, character looks, scenes, or props are involved, prioritize the asset library to keep them consistent.'

const VIDEO_PROMPT = '基于当前分镜描述生成视频片段；如涉及人物、人物造型、场景或道具，优先参考资产库保持一致性。画面不要字幕、不要烧录字幕、不要屏幕文字。'
const VIDEO_PROMPT_EN = 'Generate a video clip from the current storyboard description. When characters, character looks, scenes, or props are involved, prioritize the asset library to keep them consistent. No subtitles, no burned-in captions, no on-screen text.'

const LABEL_ALIASES = new Map<string, string>([
  ['资产提取', 'series_asset'],
  ['Asset Extraction', 'series_asset'],
  ['资产提取与合并', 'episode_asset'],
  ['资产提取与生成', 'episode_asset'],
  ['Asset Extraction & Merge', 'episode_asset'],
  ['Asset Extraction & Generation', 'episode_asset'],
  ['通告单生成', 'call_sheet'],
  ['Call Sheet Generation', 'call_sheet'],
  ['剧情扩写', 'plot_expansion'],
  ['Plot Expansion', 'plot_expansion'],
  ['分镜处理', 'storyboard'],
  ['Storyboard Processing', 'storyboard'],
  ['帧图生成', 'image_generation'],
  ['Image Generation', 'image_generation'],
  ['视频生成', 'video_generation'],
  ['Video Generation', 'video_generation'],
])

function canonicalNodeLabel(label: string): string {
  return LABEL_ALIASES.get(String(label || '').trim()) ?? String(label || '').trim()
}

function shouldUseEnglishPrompt(label: string): boolean {
  if (/[A-Za-z]/.test(label)) return true
  return false
}

export function fallbackPromptForNode(label: string, kind: NodeKind, scope: WorkflowScope): string {
  const canonical = canonicalNodeLabel(label)
  const english = shouldUseEnglishPrompt(label)
  if (canonical === 'series_asset' && scope === 'series') return english ? SERIES_ASSET_PROMPT_EN : SERIES_ASSET_PROMPT
  if (canonical === 'episode_asset' && scope === 'episode') return english ? EPISODE_ASSET_PROMPT_EN : EPISODE_ASSET_PROMPT
  if (canonical === 'call_sheet') return english ? CALL_SHEET_PROMPT_EN : CALL_SHEET_PROMPT
  if (canonical === 'plot_expansion') return english ? PLOT_EXPANSION_PROMPT_EN : PLOT_EXPANSION_PROMPT
  if (canonical === 'storyboard') return english ? STORYBOARD_PROMPT_EN : STORYBOARD_PROMPT
  if (canonical === 'image_generation' || kind === 'image') return english ? IMAGE_PROMPT_EN : IMAGE_PROMPT
  if (canonical === 'video_generation' || kind === 'video') return english ? VIDEO_PROMPT_EN : VIDEO_PROMPT
  return ''
}

function templateMatches(template: PromptTemplate, scope: WorkflowScope, kind: NodeKind, label: string): boolean {
  const nodeLabel = canonicalNodeLabel(template.node_label.trim())
  const currentLabel = canonicalNodeLabel(label)
  return (template.scope === 'all' || template.scope === scope)
    && (template.node_kind === 'all' || template.node_kind === kind)
    && (nodeLabel === '' || nodeLabel === currentLabel)
}

function sortPromptTemplates(list: PromptTemplate[], scope: WorkflowScope, kind: NodeKind, label: string): PromptTemplate[] {
  return [...list].sort((a, b) => {
    const score = (item: PromptTemplate) => {
      let value = 0
      if (canonicalNodeLabel(item.node_label.trim()) === canonicalNodeLabel(label)) value += 100
      if (item.node_kind === kind) value += 20
      if (item.scope === scope) value += 10
      if (item.is_system) value += 1
      return value
    }
    return score(b) - score(a) || a.sort - b.sort || a.id - b.id
  })
}

export function promptTemplatesForNode(
  templates: PromptTemplate[],
  scope: WorkflowScope,
  kind: NodeKind,
  label: string,
): PromptTemplate[] {
  return sortPromptTemplates(
    templates.filter((template) => templateMatches(template, scope, kind, label)),
    scope,
    kind,
    label,
  )
}

export function isSystemManagedPromptNode(scope: WorkflowScope, kind: NodeKind, label: string): boolean {
  const title = canonicalNodeLabel(label)
  if (kind === 'input' || kind === 'output' || kind === 'image' || kind === 'video' || kind === 'voice') {
    return true
  }
  if (kind !== 'text') {
    return true
  }
  if (['series_asset', 'episode_asset', 'call_sheet', 'storyboard'].includes(title)) return true
  if (String(label || '').includes('资产') && String(label || '').includes('绑定') && String(label || '').includes('分镜')) return true
  if (String(label || '').toLowerCase().includes('storyboard') && String(label || '').toLowerCase().includes('asset')) return true
  return false
}

export function isAssetExtractionNode(scope: WorkflowScope, kind: NodeKind, label: string): boolean {
  if (kind !== 'text') return false
  const title = canonicalNodeLabel(label)
  return (scope === 'series' && title === 'series_asset')
    || (scope === 'episode' && title === 'episode_asset')
}

export function canEditNodePrompt(
  scope: WorkflowScope,
  kind: NodeKind,
  label: string,
  executionMode?: string,
): boolean {
  void scope
  void label
  if (kind !== 'text') return false
  if (String(executionMode || '') === 'local') return false
  return true
}

/** 新步骤的默认提示词：优先匹配的模板，否则内置兜底。 */
export function defaultPromptFor(
  templates: PromptTemplate[],
  scope: WorkflowScope,
  kind: NodeKind,
  label: string,
): string {
  const matched = promptTemplatesForNode(templates, scope, kind, label)
  return matched[0]?.prompt ?? ''
}
