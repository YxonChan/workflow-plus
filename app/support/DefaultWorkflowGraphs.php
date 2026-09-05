<?php

declare(strict_types=1);

namespace app\support;

/**
 * 内置工作流的默认画布构造，集中一处，供 WorkflowController 与
 * WorkflowBundleController 的 seed 复用，避免漂移。
 *
 * 节点/边结构与前端 utils/workflowGraph.ts 的线性步骤可无损互转。
 */
class DefaultWorkflowGraphs
{
    /** 剧集段 E1：剧情概要 → 资产提取与合并 → 分镜 → 视频 → 导出 */
    public static function episode(): array
    {
        $id = self::idFactory('ep');
        $nodes = [
            self::node($id('plot-input'), 0, '剧情概要', 'EditPen', 'input', '占位：剧情在剧集页填写后注入',
                ['prompt' => '', 'content' => '']),
            self::node($id('asset-prep'), 280, '资产提取与合并', 'Files', 'text', '按本集扩写剧情增量提取人物/场景/道具；已有资产复用，新资产写入并排队参考图',
                ['modelId' => null, 'prompt' => '', 'executionMode' => 'ai', 'maxTokens' => 8192]),
            self::node($id('shot-split'), 560, '分镜处理', 'Scissor', 'text', '按剧情节奏拆分镜头，不限定固定秒数',
                ['modelId' => null, 'prompt' => self::shotSplitPrompt(), 'executionMode' => 'ai', 'maxTokens' => 16384]),
            self::node($id('video-gen'), 840, '视频生成', 'VideoCamera', 'video', '基于分镜描述和资产参考生成视频片段；时长跟随分镜（如 8s/15s）',
                ['modelId' => null, 'prompt' => '', 'duration' => 0, 'aspect_ratio' => '16:9', 'resolution' => '480p',
                    'chainShots' => false, 'generate_audio' => true, 'enable_web_search' => false, 'video_style_prompt' => self::videoStylePrompt()]),
            self::node($id('export-video'), 1120, '输出视频', 'Download', 'output', '渲染最终 MP4',
                ['prompt' => '', 'format' => 'mp4', 'resolution' => '1920x1080']),
        ];

        return self::wire($nodes);
    }

    /** 宣传片剧集段：一句话创意 → 资产准备 → 1-4 段分镜 → 15-60 秒视频 → 导出 */
    public static function promoEpisode(): array
    {
        $id = self::idFactory('promo');
        $nodes = [
            self::node($id('plot-input'), 0, '宣传片创意', 'EditPen', 'input', '输入一句话宣传创意；创建单集时自动注入',
                ['prompt' => '', 'content' => '']),
            self::node($id('asset-prep'), 280, '资产提取与合并', 'Files', 'text', '从宣传创意中提取产品、人物、场景和关键道具；已有品牌资产优先复用',
                ['modelId' => null, 'prompt' => '', 'executionMode' => 'ai', 'maxTokens' => 8192]),
            self::node($id('storyboard'), 560, '宣传片分镜处理', 'Scissor', 'text', '按本次配置拆成 1-4 个 15 秒视频节点，总时长约 15-60 秒',
                ['modelId' => null, 'prompt' => self::promoShotSplitPrompt(), 'executionMode' => 'ai', 'maxTokens' => 8192]),
            self::node($id('video-gen'), 840, '视频生成', 'VideoCamera', 'video', '基于宣传片分镜和品牌资产生成 1-4 段视频',
                ['modelId' => null, 'prompt' => '', 'duration' => 15, 'aspect_ratio' => '16:9', 'resolution' => '480p',
                    'chainShots' => false, 'generate_audio' => true, 'enable_web_search' => false, 'video_style_prompt' => self::videoStylePrompt()]),
            self::node($id('export-video'), 1120, '输出视频', 'Download', 'output', '合并并导出约 15-60 秒 MP4',
                ['prompt' => '', 'format' => 'mp4', 'resolution' => '1920x1080']),
        ];

        return self::wire($nodes);
    }
    /**
     * Walk graph nodes and force chainShots=false on video nodes only.
     * Deep-copies the graph; does not add chainShots to non-video nodes.
     */
    public static function disableVideoChainShots(array $graph): array
    {
        $copy = json_decode(json_encode($graph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true);
        if (!is_array($copy)) {
            return $graph;
        }
        if (!isset($copy['nodes']) || !is_array($copy['nodes'])) {
            return $copy;
        }
        foreach ($copy['nodes'] as $i => $node) {
            if (!is_array($node)) {
                continue;
            }
            $kind = strtolower((string) ($node['data']['kind'] ?? ''));
            if ($kind !== 'video') {
                continue;
            }
            if (!isset($copy['nodes'][$i]['data']) || !is_array($copy['nodes'][$i]['data'])) {
                $copy['nodes'][$i]['data'] = [];
            }
            if (!isset($copy['nodes'][$i]['data']['params']) || !is_array($copy['nodes'][$i]['data']['params'])) {
                $copy['nodes'][$i]['data']['params'] = [];
            }
            $copy['nodes'][$i]['data']['params']['chainShots'] = false;
        }
        return $copy;
    }

    /** 剧本段 S1（完整剧本）：小说导入 → 结构拆解 → 剧集规划 → 写入剧集 */
    public static function seriesFull(): array
    {
        $id = self::idFactory('sf');
        $nodes = [
            self::node($id('novel-input'), 0, '小说导入', 'EditPen', 'input', '输入完整小说/剧本正文',
                ['prompt' => '', 'content' => '']),
            self::node($id('structure-split'), 280, '结构拆解', 'MagicStick', 'text', '拆解主线、人物关系、关键场景和生产约束',
                ['modelId' => null, 'prompt' => '', 'executionMode' => 'local']),
            self::node($id('episode-plan'), 560, '剧集规划', 'Tickets', 'text', '把完整故事拆成可生产的分集概要',
                ['modelId' => null, 'prompt' => self::fullScriptEpisodePlanPrompt(), 'executionMode' => 'ai']),
            self::node($id('write-result'), 840, '写入结果', 'Download', 'output', '写入剧集管理；资产将在每集剧情扩写后增量生成',
                ['prompt' => '', 'target' => 'series']),
        ];

        return self::wire($nodes);
    }

    /** 剧本段 S2（一句话→剧本）：一句话创意 → 扩写成完整剧本 → 结构拆解 → 剧集规划 → 写入剧集 */
    public static function seriesOneLine(): array
    {
        $id = self::idFactory('so');
        $nodes = [
            self::node($id('idea-input'), 0, '一句话创意', 'EditPen', 'input', '输入一句话故事创意',
                ['prompt' => '', 'content' => '']),
            self::node($id('expand-novel'), 280, '扩写成完整剧本', 'MagicStick', 'text', '把一句话创意扩写成完整剧本',
                ['modelId' => null, 'prompt' => '请把下面这一句话故事创意扩写成一部结构完整的短剧剧本：明确人物、关系、核心冲突、起承转合，并按剧情自然分集，给出足以后续拆解的完整正文。', 'executionMode' => 'ai']),
            self::node($id('structure-split'), 560, '结构拆解', 'MagicStick', 'text', '拆解主线、人物关系、关键场景和生产约束',
                ['modelId' => null, 'prompt' => self::structureSplitPrompt(), 'executionMode' => 'ai']),
            self::node($id('episode-plan'), 840, '剧集规划', 'Tickets', 'text', '把完整故事拆成可生产的分集概要',
                ['modelId' => null, 'prompt' => self::episodePlanPrompt(), 'executionMode' => 'ai']),
            self::node($id('write-result'), 1120, '写入结果', 'Download', 'output', '写入剧集管理；资产将在每集剧情扩写后增量生成',
                ['prompt' => '', 'target' => 'series']),
        ];

        return self::wire($nodes);
    }

    // ── helpers ────────────────────────────────────────────────────────────────
    private static function idFactory(string $prefix): \Closure
    {
        return fn (string $key) => "{$key}-{$prefix}";
    }

    private static function node(string $id, int $x, string $label, string $icon, string $kind, string $desc, array $params): array
    {
        return [
            'id' => $id,
            'type' => 'studio',
            'position' => ['x' => $x, 'y' => 200],
            'label' => $label,
            'data' => ['icon' => $icon, 'kind' => $kind, 'desc' => $desc, 'isCustom' => false, 'params' => $params],
        ];
    }

    private static function wire(array $nodes): array
    {
        $edges = [];
        for ($i = 0; $i < count($nodes) - 1; $i++) {
            $s = $nodes[$i]['id'];
            $t = $nodes[$i + 1]['id'];
            $edges[] = ['id' => "e-{$s}-{$t}", 'source' => $s, 'target' => $t, 'animated' => true, 'markerEnd' => 'arrowclosed'];
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    public static function fullScriptEpisodePlanPrompt(): string
    {
        return <<<'PROMPT'
Please split the story into short-drama episodes based on the upstream structural breakdown and the complete source text. Output strictly valid JSON. Do not wrap the output in Markdown.

Requirements:
1. Each episode must carry the main storyline forward completely, without jumps or repetition.
2. Each episode must have a clear conflict, a hook, and a filmable plot summary.
3. If the user specifies a target number of episodes, strictly follow that number. If no target number is specified, divide the story reasonably according to the amount of plot content.
4. The plot_input field must be usable directly as the input for the first node of the episode workflow.
5. All output content must be written in English, including episode titles, summaries, plot_input, and hooks.
6. Preserve character names, place names, organization names, deity names, species names, and key prop names from the source text. Do not translate or localize proper nouns unless the source text already provides an official English name.

Output JSON:
{
  "episodes": [
    {
      "number": 1,
      "title": "Episode 1 title",
      "summary": "One-sentence summary of this episode",
      "plot_input": "Complete plot summary for this episode, including characters, locations, conflict, and ending hook",
      "hook": "Ending hook"
    }
  ]
}
PROMPT;
    }

    public static function videoStylePrompt(): string
    {
        return <<<'PROMPT'
Style: 8K IMAX. Photorealistic — no 3D render, no game engine, no game-cutscene aesthetic.

Cinematography: Anamorphic large-format cinematic look — Panavision C-Series widescreen, 2x squeeze, oval bokeh, slight lens breathing, shallow depth of field when appropriate, strong subject isolation, rich cinematic latitude, 70-80s epic Hollywood visual language.

Lighting: Motivated cinematic lighting based on the script and location. Use natural light, practical light, window light, street light, firelight, neon, candlelight, or artificial light only when it logically belongs to the scene. Lighting must feel physically present in the environment, not like flat studio lighting.

Color: Follow the story world and scene mood. Use a clear 60:30:10 color structure — dominant color / secondary color / accent color. Do not introduce random colors that break the established visual tone.

Camera: Physical cine lens look. 180° shutter motion blur. Natural handheld movement, tripod stability, dolly movement, crane movement, or locked-off framing should be chosen according to the emotional rhythm of the scene.

Skin and Texture: Real human skin with pore-level realism — vellus hair, fine lines, asymmetric details, natural blemishes, subtle capillary flush, sweat, tears, dust, dirt, cold breath, or environmental effects only when appropriate to the scene.

Acting: Naturalistic cinematic performance — micro-pauses before reactions, precise eye-line, restrained facial changes, wet living eyes with catch-lights, visible breathing, believable body tension, and emotionally motivated movement.

Physics: Gravity and inertia respected. Bodies, clothing, props, weapons, vehicles, water, fire, smoke, dust, snow, rain, glass, metal, fabric, and debris must have real weight, contact, resistance, and believable physical behavior.

Continuity: Maintain stable character identity, costume continuity, prop continuity, scene geography, screen direction, lighting direction, and emotional continuity across all shots. No identity drift, no random costume changes, no inconsistent props.

Technical: 24fps cinematic motion. 8K detail. Sharp subject detail where focus is intended. No jitter, no AI smudge, no compression artifacts, no unstable faces, no warped hands.

Text/Subtitles: Absolutely no subtitles, no burned-in captions, no on-screen dialogue text, no title cards, no lyrics, no watermark text, no UI text. Keep the frame clean of any readable text overlays.

Audio: Environmental SFX only unless dialogue is explicitly present in the script. Use location-appropriate sound such as footsteps, breath, cloth movement, wind, room tone, traffic, machinery, water, fire, crowd noise, distant impacts, or object handling. No music unless the script explicitly requires music.
PROMPT;
    }

    /**
     * 剧集段「资产提取与合并」节点的默认 prompt。
     * 资产来自本集扩写剧情，并与已有剧本资产库增量合并。
     */
    public static function episodeAssetPrompt(string $visualStyle = 'realistic'): string
    {
        $lookPromptRule = '10. character_looks 的 image_prompt（全风格统一）：输出左右分栏造型板。左栏正、侧、背三个无头全身（头从衣领处切除，只看服装结构）；右栏同一人物放大特写（头部五官完整）。禁止红笔涂脸；禁止只出一张带头正面全身。白底棚拍，保持人物脸部、发型、体型稳定，重点变化在服装与整体造型，不混入剧情动作和环境。写实造型的人像入库由系统在展示图完成后自动处理，不要写进 image_prompt。';
        $style = strtolower(trim($visualStyle));
        $scenePropStyleRule = match ($style) {
            'anime' => '15. 本作品 visual_style=anime：scene/prop 的 image_prompt 必须写动漫手绘关键词，禁止写实/真人/摄影与纯3D CGI 措辞。',
            '3d' => '15. 本作品 visual_style=3d：scene/prop 的 image_prompt 必须写三维渲染关键词，禁止2D动漫平涂与手机实拍措辞。',
            default => '15. 本作品 visual_style=realistic：scene/prop 的 image_prompt 必须写写实摄影关键词，禁止动漫/卡通/赛璐璐措辞。',
        };

        return <<<PROMPT
请基于本集“剧情扩写”后的完整剧情提取本集需要长期复用的人物、场景、道具资产，并参考 asset_library 中已有资产做增量合并。只输出严格 JSON，不要 Markdown，不要解释。

输出严格 JSON：
{
  "assets": [ { "name": "...", "type": "character|scene|prop", "description": "...", "image_prompt": "...", "tags": [], "match_name": "已有资产名或空" } ],
  "character_looks": [ { "character_name": "...", "look_name": "...", "description": "...", "image_prompt": "...", "tags": [], "match_character": "已有角色名或空" } ]
}

要求：
1. 只提取可复用实体；不要提取镜头动作、情绪瞬间、转场或后期特效。
2. 如果本集实体已经存在于 asset_library，请沿用已有 name，并在 match_name 写已有资产名；不要换同义词造成重复资产。
3. 新人物、场景、道具必须输出 name、type、description、image_prompt、tags。
4. character 只描述角色基础形态：年龄、性别、脸部、发型、体型、固定外貌、气质；必须结合剧情时代、世界观、身份与阶层，写出能区分古代/民国/现代/未来的外观线索。参考图默认白底棚拍、无手持物、统一穿素色中性长袖长裤连体服与简洁平底鞋。
5. character 的 image_prompt 禁止写剧情服装、外套造型、盔甲、武器、手机、包、文件、场景光线、动作姿势；这些都不要放进 prop，而是放进 character_looks。
6. prop 只包含非服装类关键道具、装备、手持物和剧情物件；服装不再作为独立 prop。
7. 只有明确是人类或类人可穿搭角色的 character，才必须至少输出 1 套 character_looks；look_name 建议用“第N集常服/制服/礼服”等。
8. 动物、宠物、怪物、坐骑、非人类生物仍然可以归类为 character，但默认不要强行输出 character_looks；除非剧情明确要求其具备稳定可穿戴造型，并在 tags 标记 humanoid 或 anthropomorphic。
9. 造型版本必须是可公开外穿的完整套装，至少包含上衣、裤装/裙装、鞋履，可包含帽子/发饰/腰带/外套；禁止只输出内衣、内裤、贴身打底服或裸露身体。
{$lookPromptRule}
11. scene 只描述地点/空间/环境，不混入人物动作和一次性事件；scene/prop 的 image_prompt 禁止人物、人脸、背影、手部，scene 按无人空场景写。
12. 每个 description 建议 30-120 字，image_prompt 建议 20-100 字，不要粘贴剧情段落；禁止“用于展示”“场景落地”“剧情片段画面”等营销或元描述。
13. 字符串内部不要直接使用英文双引号，如必须表示引号，请改用中文书名号/单引号，或正确转义。
14. 硬性：每个 character_looks 的 character_name / match_character 必须在 assets 中有一条同名 type=character。禁止只输出造型、漏掉人物本体。
{$scenePropStyleRule}
PROMPT;
    }

    /**
     * 构造一个可插入既有剧集工作流的「资产提取与生成」节点（供存量工作流升级使用）。
     */
    public static function episodeAssetNode(string $nodeId, int $x): array
    {
        return self::node($nodeId, $x, '资产提取与合并', 'Files', 'text',
            '按本集扩写剧情增量提取人物/场景/道具；已有资产复用，新资产写入并排队参考图',
            ['modelId' => null, 'prompt' => self::episodeAssetPrompt(), 'executionMode' => 'ai']);
    }

    private static function shotSplitPrompt(): string
    {
        return <<<'PROMPT'
Please split the upstream story into 10-12 15-second video nodes that can be used directly for Seedance / image-to-video models. Output language MUST follow the series content_region: china → Chinese storyboard text; western → English storyboard text. Do not force English just because this instruction is written in English. Do not output JSON, Markdown tables, or code blocks.

The goal is not a plot summary, but high-density storyboard prompts that can be fed directly into the video model.

【Core Rules】

* Each episode must output 10-12 video nodes. Default to 12 nodes.
* Each video node must be exactly 15 seconds.
* Each “Video Node NN” is an independent video generation unit.
* “Shot 1 / Shot 2 / Shot 3...” are internal editing shots within the same 15-second video node. They may change shot size, camera position, and focus, but they must not be split into separate video nodes.
* Do not fix every node to exactly 4 shots. The number of shots must be determined according to the rhythm of the script.
* Each video node may contain 2-5 shots.
* Slow emotional, contemplative, sad, or farewell-type nodes may use 2-3 shots.
* Tense, chase, fight, conflict, or information-dense nodes may use 4-5 shots.
* Do not default to a one-take shot unless the script clearly requires a long take.
* Shot timing must be continuous, for example: 0-5s, 5-9s, 9-14s, 14-15s.
* The final 1 second of each node must be a locked-off final frame, but it is only the ending frame of the current node, not a new node.
* Do not output only 5 nodes. Do not compress multiple plot sections into one long node.

【Reference Image and Asset Output Rules｜Highest Priority】

* In the final output, only keep the characters, scenes, props, and continuity references that truly appear in the current video node.
* If the current node only features @Young Lyra, do not mention @Adult Lyra, Adult Lyra, Grown Lyra, Image 3, or any adult-version-related description.
* If the current node only features @Adult Lyra, do not mention @Young Lyra, Young Lyra, Child Lyra, Image 2, or any childhood-version-related description.
* Only when both age versions truly appear in the same video node may both character assets be referenced at the same time.
* Do not output “Reference images:”.
* Do not output “Image X represents...”.
* Do not output “The character in Image X must remain visually consistent...”.
* Do not output character assets or Image numbers that do not appear in the current node.
* If there is a continuity reference image from the previous shot, it may only be used as visual continuity reference. Do not re-declare any character in that image as a current-node character if that character does not appear in the current node.
* Do not output explanatory text such as “this node features...”, “does not feature...”, “is carried by this asset...”, or “must clearly be...”.
* Only naturally write the asset names that truly appear in the current shot description, such as @Young Lyra, @Adult Lyra, @Ice Tunnel, @Worn Map.

【Characters and Proper Nouns】

* All character names, place names, organization names, deity names, species names, and key prop names must strictly preserve the original spelling from the upstream script.
* Do not translate, transliterate, or localize English names.
* If the same character has different age versions, the visual description must clearly distinguish them.
* For the childhood version, use the corresponding asset name, for example @Young Lyra.
* For the adult version, use the corresponding asset name, for example @Adult Lyra.
* Do not use only Lyra in visual descriptions unless both the upstream script and the asset library contain only one version of Lyra.
* Dialogue is not affected by the age-version rule. Dialogue inside quotation marks must preserve the original script text.

【Dialogue Rules】

* All dialogue must strictly preserve the original text from the upstream script.
* English dialogue stays in English.
* Do not translate, rewrite, polish, compress, or expand dialogue.
* You may write it as “@Young Lyra whispers: ‘original dialogue’”, but the dialogue inside quotation marks must be exactly the original text.
* If only part of the dialogue is used, you may only extract continuous original sentences. Do not reorganize the wording.

【Referenced Assets｜Strict Format】

* Under each video node title, write exactly one line:
  - Chinese series: 引用资产：@资产名 @人物名·造型名 @场景名 @道具名
  - English series: Referenced assets: @Jude Parker @Jude Parker·Episode 1 Casual Travel Wear @Dusty Road
* Only list assets that truly appear in the current node.
* Every item MUST start with @ and must exactly match asset_library names.
* Multi-word English names are ONE mention. Write @Jude Parker, never @Jude @Parker. Do not put @ on later words of the same name.
* FORBIDDEN formats (never output these):
  - 图片1：xxx / 图片2：xxx / Image 1: xxx / Image2: xxx
  - 图片1、图片2 without the real asset name
  - bare names without @
  - “Reference images:” explanations
* If a character has a specific look, write the full name as @CharacterName·LookName. If there is no specific look, write @CharacterName.
* Do not invent look names, scene names, or prop names that do not exist in the asset library.
* When the corresponding character, look, scene, or prop appears in the body text, keep the @ prefix.
* Within the same node, if the character has already been written as @CharacterName·LookName, all later actions, expressions, and dialogue must continue using the full name. Do not shorten it.

【Shot Timing Rules｜Highest Priority】

* Inside each 15s video node, internal shot timers MUST be continuous and cover the full 0-15s without gaps or overlaps.
* Good examples: 0-5s, 5-9s, 9-14s, 14-15s  OR  0-4s, 4-8s, 8-12s, 12-15s  OR  0-6s, 6-11s, 11-14s, 14-15s
* FORBIDDEN: 1-second fragment chains such as 0-1s / 1-2s / 2-3s / … then jumping to 14-15s.
* Each internal shot (except the final locked frame) should usually last about 2-6 seconds according to rhythm.
* The final locked-off frame is always exactly the last 1 second: 14-15s.
* Do not leave unexplained empty time between the last action shot and 14s.

【Shot Size Labeling Rules】

* Every shot title must clearly include the shot size.
* The shot size must appear after the timecode, in this format:
  - Chinese: 镜头1（0-5s｜景别：中近景）
  - English: Shot 1（0-5s｜Shot size: Medium Close-Up）
* Shot size must be automatically determined according to the rhythm of the script. Do not use the same shot size for every shot.
* Common shot sizes include: Extreme Wide Shot, Wide Shot, Medium Wide Shot, Medium Shot, Medium Close-Up, Close-Up, Extreme Close-Up, Insert Shot, Over-the-Shoulder Shot, Low-Angle Wide Shot, High-Angle Overhead Shot.
* When establishing the environment, prioritize Extreme Wide Shot, Wide Shot, or Medium Wide Shot.
* For character movement and physical action, prioritize Medium Shot, Medium Wide Shot, or tracking Medium Shot.
* For dialogue and emotional reactions, prioritize Medium Close-Up, Close-Up, or Close-Up.
* For hands, props, maps, energy pulses, ice cracks, eyes, and other key information, use Insert Shot or Extreme Close-Up.
* The shot size must match the content of the shot description.

【Visual Description Requirements】

* Each shot must be written as a complete natural-language paragraph. Do not split it into small items such as “Subject / Scene / Action / Camera / Sound / Color”.
* Each shot must include: scene space, character action, emotional state, shot size, camera position, camera movement, lighting, atmosphere, and sound.
* Actions must be specific and visual. Describe body weight, footsteps, hand movements, breathing, pauses, eye direction, and emotional shifts.
* Scene description must include spatial structure, material texture, light direction, foreground / midground / background relationship, fog, ice crystals, snow dust, and visible breath.
* Do not write summary-style lines such as “@Young Lyra walks out of the cave.” Describe how she walks, how the camera films her, what her physical state is, and how the environment affects her.
* If the script describes the palm glowing, prioritize describing it as “orange energy pulses under the skin,” not exposed flames, unless the script clearly states that the flame has been released externally.
* The locked-off final frame should only describe the final 1-second image itself. Do not explain its function.

【Seedance Output Cleanup】

* The output is a video prompt that will be fed directly into Seedance.
* Only write what truly appears or can be heard within the current 15-second image.
* Do not write explanatory, instructional, procedural, or purpose-oriented text.
* Do not output “Reference images:” or “Image X represents...”.
* Do not output “The character in Image X must remain visually consistent...”.
* Do not output characters, character assets, or Image numbers that do not appear.
* Do not output explanations such as “this node features...”, “does not feature...”, “is carried by this asset...”, or “character lock”.
* Do not output explanations such as “for the next node continuity”, “prepares for the next node”, “next-node continuation point”, “convenient for later generation”, or “used for continuity”.
* Do not default to writing one continuous shot.
* Only when the script clearly requires a one-take shot may continuous shot be written.
* Do not write “no scene cuts” unless the script clearly requires a one-take shot.
* Do not write UI, subtitles, split screen, collage, storyboard panels, or text labels.
* Do not write explanations such as “this shot is intended to...”.

【Story Splitting】

* Cover the complete upstream story in chronological order.
* Each video node should express only one main plot action or one clear emotional shift.
* Character entrances, discoveries, reactions, spoken lines, turning around, leaving, running, chasing, pauses, and staring can all be split into independent nodes.
* Important dialogue must be presented through shots. Do not compress it into a large summary.
* Emotional turning points must become separate nodes. Do not merge them into action nodes.
* Do not add new plot, change character relationships, or alter script causality.
* You may break down actions and emotional reactions from the original story in more detail, but you must not add new events or new dialogue.

【Output Format｜Chinese example when content_region=china】

【视频节点01｜15s｜本节点核心动作与戏剧点一句话】
引用资产：@人物名 @人物名·造型名 @场景名 @道具名

镜头1（0-5s｜景别：中景）：用完整自然语言段落写画面、人物、动作、情绪、机位、运镜、声音、光线与气氛。对白可自然叙述，但引号内对白必须保留上游原文。

镜头2（5-9s｜景别：中近景）：继续同一节点内部剪辑镜头，可换景别/机位/焦点，但仍服务同一剧情动作或情绪转折。

镜头3（9-14s｜景别：特写）：如剧情需要，写关键戏剧点、反应特写、手部/道具细节或环境物理变化。

定焦画面（14-15s｜景别：中近景）：最后 1 秒稳定定格，只写画面中真实存在的主体位置、朝向、清晰度、手中道具、视线、情绪与前后景关系。

【Output Format｜English example when content_region=western】

【Video Node 01｜15s｜One-sentence summary of the core action and dramatic point of this node】
Referenced assets: @CharacterName @CharacterName·LookName @SceneName @PropName

Shot 1（0-5s｜Shot size: Medium Shot）：One complete natural-language paragraph.

Shot 2（5-9s｜Shot size: Medium Close-Up）：Continue the same node.

Shot 3（9-14s｜Shot size: Close-Up）：Key beat if needed.

Final shot（14-15s｜Shot size: Medium Close-Up） Locked-off final frame: only the stable final image.

【Output Requirements】

* Only output the video node text.
* Do not explain the splitting logic.
* Do not summarize.
* Output no fewer than 10 video nodes. Prioritize 12 nodes.
* Preserve the structure of “Video Node NN” and “Shot 1 / Shot 2 / Shot 3...”.
* Each node must automatically decide 2-5 shots according to the rhythm of the script. Do not fix every node to 4 shots.
* Every shot title must include the shot size.
* Shots may change shot size and camera position, but they must not be split into independent video nodes.
* The locked-off final frame should only describe the final 1-second image itself. Do not explain its function.
* In the final output, do not include internal judgment notes, character-lock notes, Reference images explanations, non-appearing character assets, non-appearing Image numbers, one continuous shot, or no scene cuts.
PROMPT;
    }

    private static function structureSplitPrompt(): string
    {
        return <<<'PROMPT'
请根据完整小说/剧本正文做“机器可消费”的结构拆解。只输出严格 JSON，不要 Markdown，不要解释。

要求：
1. 只基于输入内容，不要新增人物、关系或剧情。
2. 字段值尽量短，不要粘贴完整剧本段落；每个字符串建议 20-120 字。
3. 字符串内部不要直接使用英文双引号，如必须表示引号，请改用中文书名号/单引号，或正确转义。
4. characters、locations、props、turning_points 都只保留关键项，通常 3-12 条。
5. 给后续“剧集规划”和“资产提取”节点使用，字段要稳定、可解析。

输出 JSON：
{
  "storyline": "一句话主线",
  "genre": "题材/类型",
  "main_conflict": "核心冲突",
  "characters": [{"name": "人物名", "role": "角色定位", "relationship": "人物关系", "traits": ["性格/身份/外貌线索"]}],
  "locations": [{"name": "场景名", "description": "空间描述", "story_function": "剧情作用"}],
  "props": [{"name": "道具名", "description": "道具描述", "story_function": "剧情作用"}],
  "turning_points": [{"order": 1, "event": "关键转折", "emotion": "情绪变化"}],
  "warnings": []
}
PROMPT;
    }

    private static function promoShotSplitPrompt(): string
    {
        return <<<'PROMPT'
You are the storyboard director for a concise product or brand promotional video.

The user payload contains promo_segment_count. It is an integer from 1 to 4 and is the authoritative number of video nodes for this run. Output exactly that number of video nodes — never fewer and never more. If promo_segment_count is 1, output only 【视频节点01】 and stop. Each node is exactly 15 seconds, so the final duration is promo_segment_count × 15 seconds. Do not invent extra nodes to fill a three-act structure.

PROMOTIONAL STRUCTURE BY SEGMENT COUNT
* 1 node: compress hook, product or solution reveal, visible benefit, payoff, and call to action into one clear 15-second arc.
* 2 nodes: Node 01 establishes the hook and problem; Node 02 reveals the solution, demonstrates value, and ends with proof and a call to action.
* 3 nodes: Node 01 establishes the hook and problem; Node 02 reveals the product or solution and demonstrates its main value; Node 03 shows proof or emotional payoff and ends with a call to action.
* 4 nodes: Node 01 delivers the hook; Node 02 develops the problem or desire; Node 03 reveals and demonstrates the product or solution; Node 04 shows proof or payoff and ends with a call to action.

GROUNDING AND BRAND SAFETY
* Use only product facts, names, prices, metrics, certifications, functions, and claims stated in the upstream input or available assets. Never invent factual selling points.
* Preserve all product names, brand names, proper nouns, logos, packaging, colors, and distinctive visual features exactly when they are provided.
* Prefer existing assets from asset_library. Do not invent asset names or @ references.
* When product details are sparse, create a high-level visual concept without adding unsupported claims.
* Write visual descriptions in the series content_region language (china → Chinese, western → English). Spoken copy may follow the upstream input language, and proper nouns must remain unchanged.

VISUAL AND EDITING RULES
* Each 15-second video node may contain 2-4 internal editing shots. Every shot heading must contain a continuous time range and a specific shot size.
* Time ranges inside each node must cover 0-15 seconds without gaps or overlaps.
* The final second of every node, 14-15s, must be a stable locked-off final frame suitable for visual continuity.
* Make all video nodes feel like one campaign: consistent product appearance, characters, wardrobe, location logic, lighting direction, color palette, and screen direction.
* Do not generate subtitles, UI, watermarks, split screens, storyboard panels, or unreliable in-frame marketing text.
* Audio may include environmental sound and brief spoken copy grounded in the input. Do not assume a continuous music bed because the nodes are generated separately.

OUTPUT FORMAT
Chinese (content_region=china):
【视频节点01｜15s｜一句话宣传节拍】
引用资产：@ExactAssetName
镜头1（0-Xs｜景别：具体景别）：完整自然语言段落。
定焦画面（14-15s｜景别：具体景别）：只写最后 1 秒稳定画面。

English (content_region=western):
【Video Node 01｜15s｜One-sentence promotional beat】
Referenced assets: @ExactAssetName
Shot 1（0-Xs｜Shot size: specific shot size）: One complete natural-language paragraph.
Final frame（14-15s｜Shot size: specific shot size）: Only the stable final image.

STRICT:
* Referenced assets / 引用资产 must use @ExactAssetName only.
* Never write 图片1：xxx / Image 1: xxx.
* Shot timers must be continuous across 0-15s (no 0-1s / 1-2s fragment chains).

Repeat the same structure until the exact promo_segment_count is reached.

Output only the requested video nodes. Do not add a preamble, explanation, summary, JSON, Markdown table, or code fence.
PROMPT;
    }

    private static function episodePlanPrompt(): string
    {
        return <<<'PROMPT'
请根据上游结构拆解和完整正文，将故事拆分为短剧分集。只输出严格 JSON，不要 Markdown，不要解释。

要求：
1. 每集剧情必须承接完整主线，不能跳跃或重复。
2. 每集都要有明确冲突、钩子和可拍摄剧情简介。
3. 如果用户指定目标集数，严格按目标集数输出；没有指定时按剧情容量合理拆分。
4. plot_input 要能直接作为剧集工作流第一个节点输入，但不要写成完整剧本正文；中文建议 150-400 字，英文建议 80-180 words。
5. 字符串内部不要直接使用英文双引号，如必须表示引号，请改用中文书名号/单引号，或正确转义。

输出 JSON：
{
  "episodes": [
    {
      "number": 1,
      "title": "第1集标题",
      "plot_input": "本集完整剧情简介，包含人物、场景、冲突、结尾钩子",
      "hook": "结尾钩子"
    }
  ]
}
PROMPT;
    }

    private static function seriesAssetPrompt(): string
    {
        return <<<'PROMPT'
你是 AI 短剧资产拆解专家。请只根据完整剧本、分集规划和上游结构拆解结果提取可复用资产。只输出严格 JSON，不要 Markdown，不要解释。

分类规则：
- characters：只放人物基础形态。必须写年龄段、性别、气质、关系身份、脸部、发型、固定外貌特征、体型，并结合剧情时代、世界观、身份与阶层写出古代/民国/现代/未来等时代外观线索；image_prompt 只能使用白底棚拍、无手持物、统一穿素色中性长袖长裤连体服与简洁平底鞋，不能写剧情服装、外套、盔甲、武器、手机、包、文件、场景光线或动作姿势。
- locations：只放地点/空间/环境，不能放进 props。
- props：只放可被角色拿取、穿戴、交付、查看或反复出现的实体物件；服装、造型、装备、饰品和手持物都归入 props。

输出约束：
1. 只保留后续资产库需要复用的关键实体，通常每类 3-12 条。
2. description 建议 30-120 字，image_prompt 建议 20-100 字，不要粘贴剧情段落；必须是可拍摄的具体视觉内容，禁止“用于展示”“场景落地”“剧情片段画面”等营销或元描述。
3. locations/props 的 image_prompt 禁止人物、人脸、背影、手部；locations 按无人空场景写：地点 + 时间/光线 + 2-4 个关键陈设。
4. 字符串内部不要直接使用英文双引号，如必须表示引号，请改用中文书名号/单引号，或正确转义。

输出 JSON：
{
  "characters": [{"name": "人物名", "type": "character", "description": "可视化描述", "image_prompt": "白底角色参考图提示词", "tags": []}],
  "locations": [{"name": "场景名", "type": "scene", "description": "场景描述", "image_prompt": "简短场景提示词", "tags": []}],
  "props": [{"name": "道具名", "type": "prop", "description": "道具描述", "image_prompt": "道具参考图提示词", "tags": []}],
  "warnings": []
}

禁止提取镜头语言、剪辑特效、一次性动作、情绪瞬间。
PROMPT;
    }
}
