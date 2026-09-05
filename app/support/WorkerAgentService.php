<?php

declare(strict_types=1);

namespace app\support;

use app\model\AgentTask;
use app\model\AiRequestLog;
use app\model\Asset;
use app\model\AssetImageJob;
use app\model\Episode;
use app\model\EpisodeWorkflowNodeState;
use app\model\ModelConfig;
use app\model\Series;
use app\model\Shot;
use app\model\VideoJob;
use app\model\WorkflowRun;
use app\model\WorkflowRunNode;
use think\App;
use think\facade\Db;

/**
 * 数字员工对话 Agent。
 *
 * 每位员工 = 固定人设 + 服务端硬性工具白名单：
 * 模型通过 JSON 协议申请调用工具，工具一律按当前用户隔离执行；
 * 不在白名单内的工具调用直接拒绝，从机制上限定职能范围。
 */
class WorkerAgentService
{
    /** 单轮对话最多允许的工具调用次数，防失控。 */
    private const MAX_TOOL_CALLS = 5;

    /** 带进上下文的历史消息条数上限。 */
    private const MAX_HISTORY = 12;

    public function chat(App $app, int $userId, string $workerKey, array $history, string $message, array $context = []): array
    {
        $persona = $this->personas()[$workerKey] ?? null;
        if ($persona === null) {
            abort(422, '未知的员工角色');
        }

        if (($persona['key'] ?? '') === 'assistant' && $this->isCapabilityQuestion($message)) {
            return [
                'worker' => $workerKey,
                'reply' => $this->capabilityReply(),
                'actions' => [],
            ];
        }

        $model = $this->resolveTextModel($userId);
        if (!$model instanceof ModelConfig) {
            abort(422, '还没有可用的文本模型，请联系管理员配置');
        }

        $trustedContext = $this->resolvePageContext($userId, $context);
        $messages = [['role' => 'system', 'content' => $this->systemPrompt($persona, $trustedContext)]];
        foreach ($this->sanitizeHistory($history) as $item) {
            $messages[] = $item;
        }
        $messages[] = ['role' => 'user', 'content' => $message];

        $actions = [];
        $successfulLookups = 0;
        $deferredNudgeCount = 0;
        for ($i = 0; $i < self::MAX_TOOL_CALLS; $i++) {
            $raw = $this->completeChat($userId, $model, $messages, $workerKey);
            $decision = $this->parseDecision($raw);

            if ($decision['type'] === 'reply') {
                // 模型常把「我先看一下」当最终回复，实际没调工具——状态类问题直接打回要求 tool。
                if (
                    $successfulLookups === 0
                    && $this->isProductionStatusQuestion($message)
                    && $this->isDeferredStatusReply($decision['text'])
                    && $deferredNudgeCount < 2
                ) {
                    $deferredNudgeCount++;
                    $messages[] = ['role' => 'assistant', 'content' => $raw];
                    $messages[] = [
                        'role' => 'user',
                        'content' => 'TOOL_ERROR: 你刚才只是口头说“先看/这就查”，没有调用工具，不算完成查询。'
                            . '用户问的是作品/分镜/图片/任务状态。请立刻输出 type=tool：'
                            . '优先 list_storyboard_shots 或 inspect_series_readiness；'
                            . 'params 里用 series_title（作品名）和 episode_number（集数）。拿到 TOOL_RESULT 后再 reply 汇报具体数字，禁止再空喊。',
                    ];
                    continue;
                }

                return ['worker' => $workerKey, 'reply' => $decision['text'], 'actions' => $actions];
            }

            $tool = $decision['name'];
            $params = $decision['params'];
            $messages[] = ['role' => 'assistant', 'content' => $raw];

            if (!in_array($tool, $persona['tools'], true)) {
                $messages[] = [
                    'role' => 'user',
                    'content' => "TOOL_ERROR: 工具 {$tool} 不在你的职能范围内。请改用你的可用工具，或直接用 reply 回答用户。",
                ];
                continue;
            }

            // 状态查询但没带作品名/id 时，从用户原话补 series_title / episode_number。
            if ($this->isProductionStatusQuestion($message)) {
                $params = $this->enrichLookupParamsFromMessage($params, $message, $trustedContext);
            }

            try {
                $result = $this->executeTool($app, $userId, $tool, $params);
            } catch (\Throwable $e) {
                $result = ['error' => mb_substr($e->getMessage(), 0, 300)];
            }

            $ok = !isset($result['error']);
            if ($ok && $this->isLookupTool($tool)) {
                $successfulLookups++;
            }
            $actions[] = [
                'tool' => $tool,
                'label' => $this->toolLabel($tool),
                'ok' => $ok,
            ];
            $messages[] = [
                'role' => 'user',
                'content' => 'TOOL_RESULT(' . $tool . '): ' . json_encode($result, JSON_UNESCAPED_UNICODE),
            ];
        }

        // 多轮仍未收敛：状态类问题由服务端直接查一次，避免再空喊。
        if ($this->isProductionStatusQuestion($message)) {
            $fallback = $this->fallbackStatusReply($app, $userId, $message, $trustedContext, $persona['tools']);
            if ($fallback !== null) {
                return [
                    'worker' => $workerKey,
                    'reply' => $fallback['reply'],
                    'actions' => array_merge($actions, $fallback['actions']),
                ];
            }
        }

        return [
            'worker' => $workerKey,
            'reply' => '这个问题我查了好几轮还没收敛，麻烦把问题拆小一点（比如指定某部剧本或某个任务）再问我一次。',
            'actions' => $actions,
        ];
    }

    // ── 人设与工具目录 ───────────────────────────────────────────────────────────

    private function personas(): array
    {
        return [
            'assistant' => [
                'key' => 'assistant',
                'name' => '制作助手',
                'duty' => '统一负责 AI 短剧生产协作：直接草拟与平台生产有关的宣传片文案、剧情梗概、剧本初稿、台词、分镜创意和资产提示词；理解当前页面中的作品与剧集上下文，查询作品、资产、分镜、图片、视频和工作流状态；运行只读制作体检；按明确目标执行生图、单镜头分镜修改、失败重试和排队任务取消；通过文本工具检查人物设定冲突，并说明单集图片核验面板可基于实际人物图片逐镜头检查。',
                'tools' => [
                    'get_status', 'list_series', 'list_episodes', 'list_assets', 'list_storyboard_shots',
                    'validate_storyboard_consistency', 'inspect_series_readiness', 'run_series_workflow', 'list_workflow_runs', 'list_agent_tasks',
                    'cancel_workflow_run', 'list_image_jobs', 'queue_missing_core_images', 'regenerate_asset_core_image',
                    'regenerate_storyboard_image', 'regenerate_storyboard_shot', 'retry_failed_image_jobs',
                    'cancel_queued_image_jobs', 'list_video_jobs', 'retry_failed_video_jobs', 'cancel_queued_video_jobs',
                ],
            ],
            'producer' => [
                'key' => 'producer',
                'name' => '制片助理',
                'duty' => '负责剧本与剧集的生产进度管理：查看剧本列表、启动剧本工作流、查看剧本工作流任务和 Agent 全流程任务的进展，定位失败原因，并可在用户确认后取消卡住或失败的工作流任务。',
                'tools' => ['get_status', 'list_series', 'run_series_workflow', 'list_workflow_runs', 'list_agent_tasks', 'cancel_workflow_run'],
            ],
            'asset' => [
                'key' => 'asset',
                'name' => '资产画师',
                'duty' => '负责资产库与参考图：查看人物/场景/道具资产及其参考图情况，查看生图任务队列，补齐缺失的核心参考图，重试失败的生图任务，取消排队中的生图任务。可以查询剧本列表来定位用户说的作品名，但不接管剧本拆解、剧集生产或工作流管理。',
                'tools' => ['get_status', 'list_series', 'list_assets', 'list_image_jobs', 'queue_missing_core_images', 'regenerate_asset_core_image', 'retry_failed_image_jobs', 'cancel_queued_image_jobs'],
            ],
            'video' => [
                'key' => 'video',
                'name' => '视频剪辑师',
                'duty' => '负责分镜视频生产：查看视频生成任务队列与失败原因，重试失败的视频任务，取消排队中的视频任务。',
                'tools' => ['get_status', 'list_video_jobs', 'retry_failed_video_jobs', 'cancel_queued_video_jobs'],
            ],
        ];
    }

    /** 工具说明（拼进 system prompt，也是白名单的文档）。 */
    private function toolCatalog(): array
    {
        return [
            'get_status' => ['desc' => '查看你自己当前的工作状态（队列/进行中/今日完成/今日失败 + 当前任务）', 'params' => '无'],
            'list_series' => ['desc' => '列出剧本及每部的剧集完成情况；也可按标题关键词查找指定剧本', 'params' => 'keyword?: 标题关键词; limit?: 数量上限(默认10)'],
            'list_episodes' => ['desc' => '列出指定作品下的剧集；可按集数或标题定位', 'params' => 'series_id?: 作品 id; series_title?: 作品标题; episode_number?: 集数; episode_title?: 剧集标题关键词; limit?: 默认20'],
            'list_storyboard_shots' => ['desc' => '查看指定剧集当前分镜版本的镜头列表、文本和图片状态', 'params' => 'episode_id?: 剧集 id; series_id?: 作品 id; series_title?: 作品标题; episode_number?: 集数; shot_index?: 镜头序号; limit?: 默认12'],
            'validate_storyboard_consistency' => ['desc' => '检查作品或单集当前分镜文本与人物资产描述/生图提示词中的发色、头发长度、发质、瞳色、年龄阶段、眼镜和胡须是否冲突，并返回修复建议', 'params' => 'series_id?: 作品 id; series_title?: 作品标题; episode_id?: 可选剧集 id; episode_number?: 可选集数'],
            'inspect_series_readiness' => ['desc' => '只读检查一部作品或单集的制作完整度：资产核心图、当前分镜、镜头描述、镜头图片/视频、失败任务及文本一致性；返回问题和下一步建议，不执行修复', 'params' => 'series_id?: 作品 id; series_title?: 作品标题; episode_id?: 可选剧集 id; episode_number?: 可选集数'],
            'run_series_workflow' => ['desc' => '启动已有剧本的剧本工作流，异步排队执行剧本拆解、剧集规划和资产提取', 'params' => 'series_id?: 剧本 id; keyword?: 剧本标题关键词; source_text?: 可选剧本正文; workflow_id?: 可选剧本工作流 id; episode_workflow_id?: 可选剧集工作流 id; episode_count?: 目标集数'],
            'list_workflow_runs' => ['desc' => '列出剧本工作流任务', 'params' => 'status?: queued|running|success|failed|cancelled; limit?: 默认10'],
            'list_agent_tasks' => ['desc' => '列出 Agent 全流程任务', 'params' => 'limit?: 默认10'],
            'cancel_workflow_run' => ['desc' => '取消一个排队/运行/失败状态的剧本工作流任务（已写入的剧集和资产不回滚）', 'params' => 'run_id: 任务 id（必填）'],
            'list_assets' => ['desc' => '列出资产及参考图情况', 'params' => 'series_id?: 限定剧本; keyword?: 资产名关键词; type?: character|scene|prop; missing_image_only?: true 只看缺图; limit?: 默认20'],
            'list_image_jobs' => ['desc' => '列出资产参考图生成任务', 'params' => 'series_id?: 作品 id; series_title?: 作品标题; status?: queued|running|waiting|success|failed|cancelled; limit?: 默认10'],
            'queue_missing_core_images' => ['desc' => '为指定剧本缺核心参考图的资产批量排队生图', 'params' => 'series_id: 剧本 id（必填）'],
            'regenerate_asset_core_image' => ['desc' => '强制重新生成某个资产的核心参考图；已有参考图也会排队重绘，生成成功后自动选中新图', 'params' => 'asset_id?: 资产 id; series_id?: 剧本 id; keyword?: 资产名关键词; prompt_addition?: 额外要求'],
            'regenerate_storyboard_image' => ['desc' => '为指定剧集的一个分镜镜头重新生成首帧图片；结果保存为候选版本，不会自动覆盖当前选中图片', 'params' => 'episode_id?: 剧集 id; series_id?: 作品 id; series_title?: 作品标题; episode_number?: 集数; shot_id?: 镜头 id; shot_index?: 镜头序号; node_id?: 可选图片节点 id'],
            'regenerate_storyboard_shot' => ['desc' => '按照用户明确要求只重写一个分镜镜头，并创建新的分镜修订版本；其他分镜保持不变', 'params' => 'episode_id?: 剧集 id; series_id?: 作品 id; series_title?: 作品标题; episode_number?: 集数; shot_index: 镜头序号; instruction: 修改要求; node_id?: 可选分镜节点 id'],
            'retry_failed_image_jobs' => ['desc' => '把失败的生图任务重新排队；用户指定“最新/最近 N 个”时必须传 limit=N，用户指定任务 id 时必须传 job_ids', 'params' => 'series_id?: 作品 id; series_title?: 作品标题; limit?: 只重试最新 N 个; job_ids?: 指定任务 id 数组'],
            'cancel_queued_image_jobs' => ['desc' => '取消排队中的生图任务；必须指定作品、最新 N 个或具体任务 id，禁止无范围取消', 'params' => 'series_id?: 作品 id; series_title?: 作品标题; limit?: 只取消最新 N 个; job_ids?: 指定任务 id 数组'],
            'list_video_jobs' => ['desc' => '列出视频生成任务', 'params' => 'status?: queued|blocked|running|success|failed|cancelled; episode_id?: 限定剧集; limit?: 默认10'],
            'retry_failed_video_jobs' => ['desc' => '把失败的视频任务重新排队', 'params' => 'episode_id?: 只重试该剧集的'],
            'cancel_queued_video_jobs' => ['desc' => '取消指定剧集排队/阻塞中的视频任务；必须限定剧集', 'params' => 'episode_id?: 剧集 id; series_id?: 作品 id; series_title?: 作品标题; episode_number?: 集数'],
        ];
    }

    private function toolLabel(string $tool): string
    {
        return match ($tool) {
            'get_status' => '查看工作状态',
            'list_series' => '查询剧本列表',
            'list_episodes' => '查询剧集列表',
            'list_storyboard_shots' => '查询当前分镜',
            'validate_storyboard_consistency' => '检查资产与分镜一致性',
            'inspect_series_readiness' => '运行制作体检',
            'run_series_workflow' => '启动剧本工作流',
            'list_workflow_runs' => '查询工作流任务',
            'list_agent_tasks' => '查询 Agent 任务',
            'cancel_workflow_run' => '取消工作流任务',
            'list_assets' => '查询资产库',
            'list_image_jobs' => '查询生图任务',
            'queue_missing_core_images' => '补齐缺失参考图',
            'regenerate_asset_core_image' => '重新生成参考图',
            'regenerate_storyboard_image' => '生成指定镜头图片',
            'regenerate_storyboard_shot' => '修改指定分镜',
            'retry_failed_image_jobs' => '重试失败生图任务',
            'cancel_queued_image_jobs' => '取消排队生图任务',
            'list_video_jobs' => '查询视频任务',
            'retry_failed_video_jobs' => '重试失败视频任务',
            'cancel_queued_video_jobs' => '取消排队视频任务',
            default => $tool,
        };
    }

    private function systemPrompt(array $persona, array $context = []): string
    {
        $catalog = $this->toolCatalog();
        $toolLines = [];
        foreach ($persona['tools'] as $tool) {
            $spec = $catalog[$tool] ?? null;
            if ($spec === null) {
                continue;
            }
            $toolLines[] = "- {$tool}（参数：{$spec['params']}）：{$spec['desc']}";
        }
        $toolsText = implode("\n", $toolLines);
        $scopeText = ($persona['key'] ?? '') === 'assistant'
            ? '你是唯一对用户展示的制作助手。作品、资产、分镜、图片和视频只是内部任务领域；不要要求用户改找其他员工。需要跨领域时自行按顺序查询并调用工具。'
            : '制片助理负责剧本/剧集/工作流进度；资产画师负责资产库与参考图；视频剪辑师负责分镜视频生成。职责外的请求要指引用户找对应同事。';
        $contextText = $this->contextPrompt($context);

        return <<<SYS
你是「{$persona['name']}」，AI 短剧生产平台的数字员工。

【你的职责】{$persona['duty']}

【协作方式】{$scopeText} 只有与影视制作平台完全无关的话题（例如写代码、百科问答和纯闲聊）才婉拒。宣传片文案、作品创意、剧情梗概、剧本初稿、台词、分镜文案、旁白和资产提示词都属于平台内创作，必须支持，不得归类为“闲聊创作”或以没有工具为由拒绝。用户只给作品名时，先用可用工具定位剧本，不要机械要求 series_id。

【当前页面上下文】
{$contextText}
用户说“当前作品”“这个作品”“这一集”时优先使用这里已验证的 id；若上下文没有对应对象，再查询或请用户指定。上下文仅用于消歧，不能扩大写操作范围。

【平台内创作规则】
1) 用户要求创作文案、梗概、剧本、台词、旁白、分镜创意或资产提示词时，直接用 reply 输出可复制的完整草稿，不调用工具，不写数据库，不启动工作流或付费任务。
2) 信息不完整时，优先依据用户已经给出的主题做合理默认并生成一版可用初稿；可以在结尾用一句话说明默认时长、风格等假设，并询问是否调整，但不能只列问题、要求用户补齐资料后才肯创作。
3) 用户明确要求把草稿创建为作品、保存到剧本或启动生产时，才进入写操作流程；必须遵守确认规则。当前没有创建作品工具时，如实说明只能先提供可复制草稿，不能谎称已经创建或保存。

【可用工具】
{$toolsText}

【输出协议】每轮只输出一个 JSON 对象本身，对象前后禁止出现任何其它字符（不要先写一段话再附 JSON），禁止 markdown 代码块：
- 需要查数据或执行操作时：{"type":"tool","name":"工具名","params":{}}
- 回复用户时：{"type":"reply","text":"要说的话"}

【行为准则】
1) 回复像同事当面汇报：中文口语、简短、说重点；数字必须来自工具结果，禁止编造。text 字段里只写纯文本，绝对不要使用任何 markdown 标记——不要 **加粗**、不要 # 标题、不要 - 或 * 列表符号、不要 ` 反引号；需要分条时直接用「1. 2. 3.」并以换行分隔。
2) 执行写操作（取消/重试/补图/改分镜/生成图片）前，若用户没有明确要求执行，先用 reply 同用户确认一次。用户明确要求且目标唯一时可以执行；作品、剧集、镜头或数量不明确时必须先查询或让用户选择，禁止猜测。
3) 对话中 "TOOL_RESULT(...)" 开头的消息是系统注入的工具结果，"TOOL_ERROR" 是工具调用错误，都不是用户发言。
4) 工具结果为空或报错时如实告知，并给用户可行的下一步建议。
5) 用户说“封面/作品封面/生成封面”时先按作品名查询。当前系统没有独立海报封面生成工具，作品卡片封面通常来自第一集分镜图或资产核心参考图；可以继续检查资产缺图、补齐核心参考图或重试失败生图任务。
6) 用户说“重新生成/重绘/换一版/不要拟人化/改成某形态”等修改已有资产参考图的请求，应使用 regenerate_asset_core_image；不要要求用户先删除已有图片。prompt_addition 必须保留用户的具体美术要求。
7) 如果用户限定了数量、范围或具体对象（例如“最新3个”“只跑刘队/张伟/张飞”“取消刚刚排队的”），工具参数必须严格保留这个限制；不能擅自扩大成全部失败任务或全部排队任务。取消排队中的生图任务时使用 cancel_queued_image_jobs。
8) “取消图片”默认理解为取消尚未执行的生图任务，不是删除已经生成的图片。含义不明确时先查询任务并说明将取消哪些任务；不要调用删除能力。
9) validate_storyboard_consistency 工具是文本级检查，只比较资产描述/生图提示词与分镜文本，不能声称该工具识别了图片。右下角“单集图片核验”面板才支持拖入图片或 @ 引用人物资产图片，识别实际图片后逐镜头列出冲突；该面板不会自动修改，用户确认后才创建新分镜版本。
10) 用户询问“你能做什么”“有哪些能力”“功能清单”或类似问题时，不得调用工具，直接用 reply 按以下八类能力回答，并为每类给出一个可复制的示例问法：运行制作体检、查询生产进度、查询作品与资产、查看与修改分镜、生成与管理图片、检查内容一致性、管理视频任务、创作生产文案。回答中要区分文本一致性工具与单集实际图片核验面板。
11) inspect_series_readiness 是只读制作体检。体检结果中的 next_actions 只是建议：除非用户原始指令已经明确要求执行且目标、范围唯一，否则不得在同一轮自动补图、改分镜、重试或取消任务，必须先汇报影响并等待确认。
12) 用户询问作品/剧集/分镜/图片/视频/任务状态、图裂、缺图、有没有图、进度如何时：第一轮必须输出 type=tool，禁止先用 reply 说“我先看一下/这就查/稍等”。正确顺序：先 list_series 或 list_storyboard_shots / inspect_series_readiness，再根据 TOOL_RESULT 用 reply 汇报。params 可用 series_title=作品名、episode_number=集数，不必先要 series_id。
13) 汇报分镜图片时必须区分：has_image=false 是缺图；image_accessible=false 或 image_status=broken 是库里有 URL 但文件不可读（常见裂图）；has_image=true 且 image_accessible=true 才算正常有图。用户说“图裂”时优先点名 broken 镜头，并说明可 regenerate_storyboard_image。
SYS;
    }

    private function resolvePageContext(int $userId, array $context): array
    {
        $trusted = [];
        $page = trim((string) ($context['page'] ?? ''));
        $allowedPages = ['dashboard', 'series', 'assets', 'workflow', 'quickCreate', 'scriptCreation'];
        if (in_array($page, $allowedPages, true)) {
            $trusted['page'] = $page;
        }

        $episode = null;
        $episodeId = (int) ($context['episode_id'] ?? 0);
        if ($episodeId > 0) {
            $candidate = Episode::where('id', $episodeId)->where('user_id', $userId)->find();
            if ($candidate instanceof Episode) {
                $episode = $candidate;
                $trusted['episode_id'] = (int) $candidate->getAttr('id');
                $trusted['episode_number'] = (int) $candidate->getAttr('number');
                $trusted['episode_title'] = (string) $candidate->getAttr('title');
            }
        }

        $seriesId = $episode instanceof Episode
            ? (int) $episode->getAttr('series_id')
            : (int) ($context['series_id'] ?? 0);
        if ($seriesId > 0) {
            $series = Series::where('id', $seriesId)->where('user_id', $userId)->find();
            if ($series instanceof Series) {
                $trusted['series_id'] = (int) $series->getAttr('id');
                $trusted['series_title'] = (string) $series->getAttr('title');
            } else {
                unset($trusted['episode_id'], $trusted['episode_number'], $trusted['episode_title']);
            }
        }

        $workflowRunId = (int) ($context['workflow_run_id'] ?? 0);
        if ($workflowRunId > 0) {
            $run = WorkflowRun::where('id', $workflowRunId)->where('user_id', $userId)->find();
            if ($run instanceof WorkflowRun) {
                $runSeriesId = (int) $run->getAttr('series_id');
                if (!isset($trusted['series_id']) || $runSeriesId === (int) $trusted['series_id']) {
                    $trusted['workflow_run_id'] = (int) $run->getAttr('id');
                    $trusted['workflow_status'] = (string) $run->getAttr('status');
                }
            }
        }

        return $trusted;
    }

    private function contextPrompt(array $context): string
    {
        if ($context === []) {
            return '没有可用的页面对象上下文。';
        }

        $pageLabels = [
            'dashboard' => '总览',
            'series' => '作品生产',
            'assets' => '资产管理',
            'workflow' => '流程编排',
            'quickCreate' => '灵感速创',
            'scriptCreation' => '剧本创作',
        ];
        $lines = [];
        $page = (string) ($context['page'] ?? '');
        if ($page !== '') {
            $lines[] = '当前页面：' . ($pageLabels[$page] ?? $page);
        }
        if ((int) ($context['series_id'] ?? 0) > 0) {
            $lines[] = '当前作品：' . (string) ($context['series_title'] ?? '')
                . '（series_id=' . (int) $context['series_id'] . '）';
        }
        if ((int) ($context['episode_id'] ?? 0) > 0) {
            $lines[] = '当前剧集：第' . (int) ($context['episode_number'] ?? 0) . '集 '
                . (string) ($context['episode_title'] ?? '')
                . '（episode_id=' . (int) $context['episode_id'] . '）';
        }
        if ((int) ($context['workflow_run_id'] ?? 0) > 0) {
            $lines[] = '当前工作流任务：run_id=' . (int) $context['workflow_run_id']
                . '，状态=' . (string) ($context['workflow_status'] ?? '');
        }

        return $lines !== [] ? implode("\n", $lines) : '没有可用的页面对象上下文。';
    }

    private function isCapabilityQuestion(string $message): bool
    {
        $text = mb_strtolower(trim($message));
        if ($text === '') {
            return false;
        }

        foreach (['你能做什么', '能做什么', '有哪些能力', '功能清单', '有什么功能', '可以做什么', '能帮我做什么'] as $phrase) {
            if (str_contains($text, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /** 用户是否在问作品/分镜/图片/任务状态（必须先 tool，禁止空喊）。 */
    private function isProductionStatusQuestion(string $message): bool
    {
        $text = trim($message);
        if ($text === '') {
            return false;
        }
        if ($this->isCapabilityQuestion($text)) {
            return false;
        }

        $needles = [
            '图裂', '裂图', '缺图', '没图', '没有图', '坏图', '图片状态', '分镜', '镜头',
            '状态', '进度', '有没有图', '检查', '看一下', '查一下', '体检',
            'storyboard', 'broken', 'missing image',
        ];
        foreach ($needles as $needle) {
            if (mb_stripos($text, $needle) !== false) {
                return true;
            }
        }

        // 「《xxx》第N集」类定位查询也视为状态问题
        if (preg_match('/第\s*\d+\s*集/u', $text) === 1) {
            return true;
        }

        return false;
    }

    /** 模型把「我先查」当最终回复的典型空话。 */
    private function isDeferredStatusReply(string $reply): bool
    {
        $text = trim($reply);
        if ($text === '') {
            return true;
        }

        // 过长且已含具体数字/镜头序号，多半已是实质汇报
        if (mb_strlen($text) > 80 && preg_match('/\d+/u', $text) === 1) {
            return false;
        }

        $phrases = [
            '我先看', '我这就', '这就查', '这就看', '先看一下', '查一下', '看一下',
            '稍等', '请稍等', '马上查', '立刻查', '正在查', '我来查', '让我查',
            '先帮你查', '先确认一下', '先定位',
        ];
        foreach ($phrases as $phrase) {
            if (mb_stripos($text, $phrase) !== false) {
                return true;
            }
        }

        // 很短且没有工具结果痕迹的“确认式”回复
        return mb_strlen($text) <= 40 && preg_match('/查|看|状态|分镜|图片/u', $text) === 1;
    }

    private function isLookupTool(string $tool): bool
    {
        return in_array($tool, [
            'get_status',
            'list_series',
            'list_episodes',
            'list_storyboard_shots',
            'inspect_series_readiness',
            'list_assets',
            'list_image_jobs',
            'list_video_jobs',
            'list_workflow_runs',
            'list_agent_tasks',
            'validate_storyboard_consistency',
        ], true);
    }

    /**
     * 从用户原话补齐 series_title / episode_number，避免模型只说作品名却不传参。
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function enrichLookupParamsFromMessage(array $params, string $message, array $context = []): array
    {
        if ((int) ($params['series_id'] ?? 0) <= 0 && (int) ($context['series_id'] ?? 0) > 0) {
            $params['series_id'] = (int) $context['series_id'];
        }
        if ((int) ($params['episode_id'] ?? 0) <= 0 && (int) ($context['episode_id'] ?? 0) > 0) {
            $params['episode_id'] = (int) $context['episode_id'];
        }

        $seriesTitle = trim((string) ($params['series_title'] ?? $params['series'] ?? $params['keyword'] ?? ''));
        if ($seriesTitle === '') {
            if (preg_match('/[《「]([^》」]{1,40})[》」]/u', $message, $m) === 1) {
                $seriesTitle = trim((string) $m[1]);
            } elseif (preg_match('/([\p{L}\p{N}_·\-]{2,30})\s*第\s*\d+\s*集/u', $message, $m) === 1) {
                $seriesTitle = trim((string) $m[1]);
            } elseif (trim((string) ($context['series_title'] ?? '')) !== '') {
                $seriesTitle = trim((string) $context['series_title']);
            }
            // 常见口语：「武松打虎 这一/这一集/有个图裂」
            if ($seriesTitle === '' && preg_match('/^([\p{L}\p{N}_·\-《》「」]{2,30})\s*(这一|这一集|第|有|的|里|图)/u', trim($message), $m) === 1) {
                $seriesTitle = trim((string) $m[1], " \t\n\r\0\x0B《》「」");
            }
            if ($seriesTitle !== '') {
                $params['series_title'] = $seriesTitle;
            }
        }

        if ((int) ($params['episode_number'] ?? $params['number'] ?? 0) <= 0) {
            if (preg_match('/第\s*(\d+)\s*集/u', $message, $m) === 1) {
                $params['episode_number'] = (int) $m[1];
            } elseif ((int) ($context['episode_number'] ?? 0) > 0) {
                $params['episode_number'] = (int) $context['episode_number'];
            } elseif (preg_match('/第\s*1\s*集|第一集|(?:^|[\s，,。；;])这一集?(?:[\s，,。；;]|$)/u', $message) === 1) {
                // 「这一 / 这一集」在口语里常指第 1 集
                $params['episode_number'] = 1;
            }
        }

        return $params;
    }

    /**
     * @return array{has_image:bool,image_accessible:bool,image_status:string,image_note:string}
     */
    private function inspectShotImageUrl(string $url): array
    {
        $url = trim($url);
        if ($url === '') {
            return [
                'has_image' => false,
                'image_accessible' => false,
                'image_status' => 'missing',
                'image_note' => '无图片 URL',
            ];
        }

        $localPath = $this->resolveLocalMediaPath($url);
        if ($localPath !== '') {
            if (is_file($localPath) && is_readable($localPath) && filesize($localPath) > 0) {
                return [
                    'has_image' => true,
                    'image_accessible' => true,
                    'image_status' => 'ok',
                    'image_note' => '本地文件可读',
                ];
            }

            return [
                'has_image' => true,
                'image_accessible' => false,
                'image_status' => 'broken',
                'image_note' => '本地路径不可读或文件为空（常见裂图）',
            ];
        }

        // 外链：只确认 URL 形态，不在对话路径做远程 HEAD，避免拖慢
        if (preg_match('#^https?://#i', $url) === 1) {
            return [
                'has_image' => true,
                'image_accessible' => true,
                'image_status' => 'ok',
                'image_note' => '外链 URL（未做远程探测）',
            ];
        }

        return [
            'has_image' => true,
            'image_accessible' => false,
            'image_status' => 'broken',
            'image_note' => '无法解析为本地文件或 http URL',
        ];
    }

    private function resolveLocalMediaPath(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('/^[a-zA-Z]:[\\\\\/]/', $url) === 1 && is_file($url)) {
            return $url;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = $url;
        }
        $path = str_replace('\\', '/', $path);
        $root = rtrim(app()->getRootPath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'public';

        if (str_starts_with($path, '/')) {
            $candidate = $root . str_replace('/', DIRECTORY_SEPARATOR, $path);
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        $candidate = $root . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
        return is_file($candidate) ? $candidate : '';
    }

    /**
     * 模型多轮空转时，服务端直接查分镜/体检并拼出口语汇报。
     *
     * @param array<string, mixed> $context
     * @param array<int, string> $allowedTools
     * @return array{reply:string,actions:array<int,array<string,mixed>>}|null
     */
    private function fallbackStatusReply(App $app, int $userId, string $message, array $context, array $allowedTools): ?array
    {
        unset($app);
        $params = $this->enrichLookupParamsFromMessage([], $message, $context);
        $actions = [];

        if (in_array('list_storyboard_shots', $allowedTools, true)
            && (
                (int) ($params['episode_id'] ?? 0) > 0
                || (int) ($params['episode_number'] ?? 0) > 0
                || preg_match('/分镜|镜头|图裂|裂图|图片/u', $message) === 1
            )
        ) {
            $result = $this->toolListStoryboardShots($userId, $params);
            $actions[] = [
                'tool' => 'list_storyboard_shots',
                'label' => $this->toolLabel('list_storyboard_shots'),
                'ok' => !isset($result['error']),
            ];
            if (!isset($result['error'])) {
                return [
                    'reply' => $this->formatStoryboardStatusReply($result),
                    'actions' => $actions,
                ];
            }
            // 找不到剧集时继续尝试作品体检
            if (!in_array('inspect_series_readiness', $allowedTools, true)) {
                return [
                    'reply' => '我查了，但没定位到分镜：' . (string) $result['error'],
                    'actions' => $actions,
                ];
            }
        }

        if (!in_array('inspect_series_readiness', $allowedTools, true)
            && !in_array('list_series', $allowedTools, true)
        ) {
            return null;
        }

        if (in_array('inspect_series_readiness', $allowedTools, true)
            && (
                (int) ($params['series_id'] ?? 0) > 0
                || trim((string) ($params['series_title'] ?? '')) !== ''
            )
        ) {
            $result = $this->toolInspectSeriesReadiness($userId, $params);
            $actions[] = [
                'tool' => 'inspect_series_readiness',
                'label' => $this->toolLabel('inspect_series_readiness'),
                'ok' => !isset($result['error']),
            ];
            if (!isset($result['error'])) {
                return [
                    'reply' => $this->formatReadinessReply($result),
                    'actions' => $actions,
                ];
            }

            return [
                'reply' => '我按作品名查了，但没找到可用结果：' . (string) $result['error'],
                'actions' => $actions,
            ];
        }

        if (in_array('list_series', $allowedTools, true)) {
            $keyword = trim((string) ($params['series_title'] ?? ''));
            $result = $this->toolListSeries($userId, $keyword !== '' ? ['keyword' => $keyword, 'limit' => 5] : ['limit' => 5]);
            $actions[] = [
                'tool' => 'list_series',
                'label' => $this->toolLabel('list_series'),
                'ok' => true,
            ];
            $titles = [];
            foreach (($result['series'] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $title = trim((string) ($row['title'] ?? ''));
                if ($title !== '') {
                    $titles[] = $title;
                }
            }
            if ($titles === []) {
                return [
                    'reply' => $keyword !== ''
                        ? "我按「{$keyword}」查作品列表，没有匹配结果。请确认作品名是否准确。"
                        : '我查了作品列表，当前账号下没有可用作品。',
                    'actions' => $actions,
                ];
            }

            return [
                'reply' => '我先定位到这些作品：' . implode('、', array_slice($titles, 0, 5))
                    . '。请再指定一部作品和第几集，我继续查分镜和图片状态。',
                'actions' => $actions,
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function formatStoryboardStatusReply(array $result): string
    {
        $seriesTitle = trim((string) ($result['series_title'] ?? ''));
        $title = trim((string) ($result['episode_title'] ?? ''));
        $number = (int) ($result['episode_number'] ?? 0);
        $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
        $total = (int) ($summary['total'] ?? count(is_array($result['shots'] ?? null) ? $result['shots'] : []));
        $withImage = (int) ($summary['with_image'] ?? 0);
        $missing = (int) ($summary['missing_image'] ?? 0);
        $broken = (int) ($summary['broken_image'] ?? 0);
        $accessible = (int) ($summary['accessible_image'] ?? 0);
        $withVideo = (int) ($summary['with_video'] ?? 0);

        $lines = [];
        $head = ($seriesTitle !== '' ? "《{$seriesTitle}》" : '')
            . '第' . ($number > 0 ? $number : '?') . '集'
            . ($title !== '' ? "「{$title}」" : '');
        $lines[] = $head . "当前分镜共 {$total} 个镜头。";
        $lines[] = "图片：有 URL {$withImage}，可访问 {$accessible}，缺图 {$missing}，裂图 {$broken}；视频：已有 {$withVideo}。";

        $brokenIndexes = [];
        $missingIndexes = [];
        foreach (is_array($result['shots'] ?? null) ? $result['shots'] : [] as $shot) {
            if (!is_array($shot)) {
                continue;
            }
            $idx = (int) ($shot['shot_index'] ?? 0);
            $status = (string) ($shot['image_status'] ?? '');
            if ($status === 'broken' && $idx > 0 && count($brokenIndexes) < 8) {
                $brokenIndexes[] = $idx;
            }
            if ($status === 'missing' && $idx > 0 && count($missingIndexes) < 8) {
                $missingIndexes[] = $idx;
            }
        }
        if ($brokenIndexes !== []) {
            $lines[] = '裂图镜头：' . implode('、', array_map(static fn (int $i): string => "第{$i}镜", $brokenIndexes))
                . (count($brokenIndexes) >= 8 ? ' 等' : '')
                . '。可以说“重绘第X镜图片”让我生成候选图。';
        }
        if ($missingIndexes !== []) {
            $lines[] = '缺图镜头：' . implode('、', array_map(static fn (int $i): string => "第{$i}镜", $missingIndexes))
                . (count($missingIndexes) >= 8 ? ' 等' : '') . '。';
        }
        if ($broken === 0 && $missing === 0) {
            $lines[] = '这集分镜图片看起来齐全可访问。';
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function formatReadinessReply(array $result): string
    {
        $scope = is_array($result['scope'] ?? null) ? $result['scope'] : [];
        $title = trim((string) ($scope['series_title'] ?? '该作品'));
        $shots = is_array($result['shots'] ?? null) ? $result['shots'] : [];
        $assets = is_array($result['assets'] ?? null) ? $result['assets'] : [];
        $status = (string) ($result['status'] ?? '');
        $lines = [];
        $lines[] = "《{$title}》制作体检结果：" . match ($status) {
            'ready' => '可继续推进',
            'attention' => '有需关注项',
            default => '还有阻塞项',
        } . '。';
        $lines[] = '资产缺核心图 '
            . (int) ($assets['missing_core_count'] ?? 0)
            . ' 个；镜头缺图 '
            . (int) ($shots['missing_image'] ?? 0)
            . '，裂图 '
            . (int) ($shots['broken_image'] ?? 0)
            . '，缺视频 '
            . (int) ($shots['missing_video'] ?? 0)
            . '。';

        $brokenNotes = [];
        $episodeItems = is_array($result['episodes'] ?? null) && is_array($result['episodes']['items'] ?? null)
            ? $result['episodes']['items']
            : [];
        foreach ($episodeItems as $ep) {
            if (!is_array($ep)) {
                continue;
            }
            $indexes = is_array($ep['broken_shot_indexes'] ?? null) ? $ep['broken_shot_indexes'] : [];
            if ($indexes === []) {
                continue;
            }
            $num = (int) ($ep['number'] ?? 0);
            $brokenNotes[] = '第' . ($num > 0 ? $num : '?') . '集镜头 '
                . implode('、', array_map(static fn ($i): string => '第' . (int) $i . '镜', array_slice($indexes, 0, 6)));
        }
        if ($brokenNotes !== []) {
            $lines[] = '裂图明细：' . implode('；', array_slice($brokenNotes, 0, 3)) . '。';
        }

        $blockers = is_array($result['blockers'] ?? null) ? $result['blockers'] : [];
        if ($blockers !== []) {
            $lines[] = '阻塞：' . implode('；', array_slice(array_map('strval', $blockers), 0, 3)) . '。';
        }

        return implode("\n", $lines);
    }

    private function capabilityReply(): string
    {
        return "我可以处理八类制作任务：\n"
            . "1. 运行制作体检：只读汇总资产缺图、分镜缺失、镜头图片/视频、失败任务和一致性问题。示例：检查当前作品是否可以继续生成。\n"
            . "2. 查询生产进度：查看当前生产、队列、失败任务和工作流状态。示例：现在有哪些任务正在生产？\n"
            . "3. 查询作品与资产：查看作品、剧集、人物、场景、道具及缺图情况。示例：查看《作品名》的剧集和缺图资产。\n"
            . "4. 查看与修改分镜：查看指定镜头，并按要求修改单个分镜。示例：把《作品名》第1集第3镜改成近景。\n"
            . "5. 生成与管理图片：生成或重画资产、镜头图片，重试或取消生图任务。示例：为《作品名》第1集第3镜生成一张图片。\n"
            . "6. 检查内容一致性：文本工具可对比分镜与人物资产描述/生图提示词；右下角“单集图片核验”可先选择作品和一集，再拖入主角图片或用 @ 引用人物资产图片，识别实际图片并逐镜头列出冲突。所有问题默认不修改，选择后还会展示差异并二次确认。示例：用主角图片检查《作品名》第1集分镜。\n"
            . "7. 管理视频任务：查询、重试或取消指定剧集的视频任务。示例：查询《作品名》第1集的视频任务。\n"
            . "8. 创作生产文案：直接草拟宣传片文案、剧情梗概、剧本初稿、台词、旁白、分镜创意和资产提示词；默认只在聊天中输出，不自动保存或启动任务。示例：写一版60秒的大草原旅游宣传片文案。";
    }

    // ── 工具执行（全部按 userId 隔离） ────────────────────────────────────────────

    private function executeTool(App $app, int $userId, string $tool, array $params): array
    {
        return match ($tool) {
            'get_status' => $this->toolGetStatus($userId, $params),
            'list_series' => $this->toolListSeries($userId, $params),
            'list_episodes' => $this->toolListEpisodes($userId, $params),
            'list_storyboard_shots' => $this->toolListStoryboardShots($userId, $params),
            'validate_storyboard_consistency' => $this->toolValidateStoryboardConsistency($userId, $params),
            'inspect_series_readiness' => $this->toolInspectSeriesReadiness($userId, $params),
            'run_series_workflow' => $this->toolRunSeriesWorkflow($app, $userId, $params),
            'list_workflow_runs' => $this->toolListWorkflowRuns($userId, $params),
            'list_agent_tasks' => $this->toolListAgentTasks($userId, $params),
            'cancel_workflow_run' => $this->toolCancelWorkflowRun($userId, $params),
            'list_assets' => $this->toolListAssets($userId, $params),
            'list_image_jobs' => $this->toolListImageJobs($userId, $params),
            'queue_missing_core_images' => $this->toolQueueMissingCoreImages($app, $userId, $params),
            'regenerate_asset_core_image' => $this->toolRegenerateAssetCoreImage($app, $userId, $params),
            'regenerate_storyboard_image' => $this->toolRegenerateStoryboardImage($app, $userId, $params),
            'regenerate_storyboard_shot' => $this->toolRegenerateStoryboardShot($app, $userId, $params),
            'retry_failed_image_jobs' => $this->toolRetryFailedImageJobs($userId, $params),
            'cancel_queued_image_jobs' => $this->toolCancelQueuedImageJobs($userId, $params),
            'list_video_jobs' => $this->toolListVideoJobs($userId, $params),
            'retry_failed_video_jobs' => $this->toolRetryFailedVideoJobs($userId, $params),
            'cancel_queued_video_jobs' => $this->toolCancelQueuedVideoJobs($userId, $params),
            default => ['error' => "未实现的工具：{$tool}"],
        };
    }

    private function toolGetStatus(int $userId, array $params): array
    {
        return ['workers' => WorkerCrewStats::all($userId)];
    }

    private function toolListSeries(int $userId, array $params): array
    {
        $limit = $this->clampLimit($params['limit'] ?? 10, 10, 20);
        $query = Series::where('user_id', $userId)->order('id', 'desc')->limit($limit);
        $keyword = trim((string) ($params['keyword'] ?? $params['title'] ?? ''));
        if ($keyword !== '') {
            $query->whereLike('title', '%' . $keyword . '%');
        }
        $rows = $query->select();

        $items = [];
        foreach ($rows as $series) {
            if (!$series instanceof Series) {
                continue;
            }
            $seriesId = (int) $series->getAttr('id');
            $items[] = [
                'series_id' => $seriesId,
                'title' => (string) $series->getAttr('title'),
                'episodes_total' => Episode::where('series_id', $seriesId)->where('user_id', $userId)->count(),
                'episodes_done' => Episode::where('series_id', $seriesId)->where('user_id', $userId)->where('status', 'done')->count(),
                'created' => (string) $series->getAttr('create_time'),
            ];
        }

        return ['series' => $items];
    }

    private function toolListEpisodes(int $userId, array $params): array
    {
        $seriesResult = $this->resolveSeriesForAction($userId, $params);
        if (isset($seriesResult['error'])) {
            return $seriesResult;
        }

        /** @var Series $series */
        $series = $seriesResult['series'];
        $limit = $this->clampLimit($params['limit'] ?? 20, 20, 50);
        $query = Episode::where('user_id', $userId)
            ->where('series_id', (int) $series->getAttr('id'))
            ->order(['number' => 'asc', 'id' => 'asc'])
            ->limit($limit);
        $episodeNumber = (int) ($params['episode_number'] ?? $params['number'] ?? 0);
        if ($episodeNumber > 0) {
            $query->where('number', $episodeNumber);
        }
        $episodeTitle = trim((string) ($params['episode_title'] ?? ''));
        if ($episodeTitle !== '') {
            $query->whereLike('title', '%' . $episodeTitle . '%');
        }

        $items = [];
        foreach ($query->select() as $episode) {
            if (!$episode instanceof Episode) {
                continue;
            }
            $revisionId = (int) ($episode->getAttr('current_storyboard_revision_id') ?? 0);
            $items[] = [
                'episode_id' => (int) $episode->getAttr('id'),
                'number' => (int) $episode->getAttr('number'),
                'title' => (string) $episode->getAttr('title'),
                'status' => (string) $episode->getAttr('status'),
                'storyboard_revision_id' => $revisionId ?: null,
                'shot_count' => $revisionId > 0
                    ? Shot::where('user_id', $userId)->where('episode_id', (int) $episode->getAttr('id'))->where('storyboard_revision_id', $revisionId)->count()
                    : 0,
            ];
        }

        return [
            'series_id' => (int) $series->getAttr('id'),
            'series_title' => (string) $series->getAttr('title'),
            'episodes' => $items,
        ];
    }

    private function toolListStoryboardShots(int $userId, array $params): array
    {
        $episodeResult = $this->resolveEpisodeForAction($userId, $params);
        if (isset($episodeResult['error'])) {
            return $episodeResult;
        }

        /** @var Episode $episode */
        $episode = $episodeResult['episode'];
        $revisionId = (int) ($episode->getAttr('current_storyboard_revision_id') ?? 0);
        if ($revisionId <= 0) {
            return ['error' => '该剧集还没有当前分镜版本'];
        }

        $limit = $this->clampLimit($params['limit'] ?? 12, 12, 30);
        $query = Shot::where('user_id', $userId)
            ->where('episode_id', (int) $episode->getAttr('id'))
            ->where('storyboard_revision_id', $revisionId)
            ->order(['index' => 'asc', 'id' => 'asc'])
            ->limit($limit);
        $shotIndex = (int) ($params['shot_index'] ?? $params['index'] ?? 0);
        if ($shotIndex > 0) {
            $query->where('index', $shotIndex);
        }

        $shots = [];
        $summary = [
            'total' => 0,
            'with_image' => 0,
            'missing_image' => 0,
            'broken_image' => 0,
            'accessible_image' => 0,
            'with_video' => 0,
        ];
        foreach ($query->select() as $shot) {
            if (!$shot instanceof Shot) {
                continue;
            }
            $imageMeta = $this->inspectShotImageUrl(trim((string) ($shot->getAttr('image_url') ?? '')));
            $hasVideo = trim((string) ($shot->getAttr('video_url') ?? '')) !== '';
            $summary['total']++;
            if ($imageMeta['has_image']) {
                $summary['with_image']++;
            } else {
                $summary['missing_image']++;
            }
            if ($imageMeta['image_status'] === 'broken') {
                $summary['broken_image']++;
            }
            if ($imageMeta['image_accessible']) {
                $summary['accessible_image']++;
            }
            if ($hasVideo) {
                $summary['with_video']++;
            }
            $shots[] = [
                'shot_id' => (int) $shot->getAttr('id'),
                'shot_index' => (int) $shot->getAttr('index'),
                'description' => mb_substr(trim((string) $shot->getAttr('desc')), 0, 500),
                'duration' => (string) $shot->getAttr('duration'),
                'status' => (string) $shot->getAttr('status'),
                'has_image' => $imageMeta['has_image'],
                'image_accessible' => $imageMeta['image_accessible'],
                'image_status' => $imageMeta['image_status'],
                'image_note' => $imageMeta['image_note'],
                'has_video' => $hasVideo,
            ];
        }

        $seriesId = (int) $episode->getAttr('series_id');
        $series = Series::where('id', $seriesId)->where('user_id', $userId)->find();

        return [
            'series_id' => $seriesId,
            'series_title' => $series instanceof Series ? (string) $series->getAttr('title') : '',
            'episode_id' => (int) $episode->getAttr('id'),
            'episode_number' => (int) $episode->getAttr('number'),
            'episode_title' => (string) $episode->getAttr('title'),
            'storyboard_revision_id' => $revisionId,
            'summary' => $summary,
            'shots' => $shots,
            'note' => 'image_status=missing 缺图；broken=库有 URL 但文件不可读（常见裂图）；ok=可访问。',
        ];
    }

    private function toolValidateStoryboardConsistency(int $userId, array $params): array
    {
        $episodeId = (int) ($params['episode_id'] ?? 0);
        $seriesId = (int) ($params['series_id'] ?? 0);

        if ($episodeId > 0) {
            $episode = Episode::where('id', $episodeId)->where('user_id', $userId)->find();
            if (!$episode instanceof Episode) {
                return ['error' => '剧集不存在'];
            }
            $seriesId = (int) $episode->getAttr('series_id');
        } else {
            $seriesResult = $this->resolveSeriesForAction($userId, $params);
            if (isset($seriesResult['error'])) {
                return $seriesResult;
            }
            /** @var Series $series */
            $series = $seriesResult['series'];
            $seriesId = (int) $series->getAttr('id');

            if ((int) ($params['episode_number'] ?? 0) > 0 || trim((string) ($params['episode_title'] ?? '')) !== '') {
                $episodeResult = $this->resolveEpisodeForAction($userId, $params + ['series_id' => $seriesId]);
                if (isset($episodeResult['error'])) {
                    return $episodeResult;
                }
                /** @var Episode $episode */
                $episode = $episodeResult['episode'];
                $episodeId = (int) $episode->getAttr('id');
            }
        }

        return (new StoryboardConsistencyService())->validate($userId, $seriesId, $episodeId);
    }

    private function toolInspectSeriesReadiness(int $userId, array $params): array
    {
        $episodeId = (int) ($params['episode_id'] ?? 0);
        $hasEpisodeSelector = $episodeId > 0
            || (int) ($params['episode_number'] ?? 0) > 0
            || trim((string) ($params['episode_title'] ?? '')) !== '';

        if ($hasEpisodeSelector) {
            $episodeResult = $this->resolveEpisodeForAction($userId, $params);
            if (isset($episodeResult['error'])) {
                return $episodeResult;
            }
            /** @var Episode $episode */
            $episode = $episodeResult['episode'];
            $episodeId = (int) $episode->getAttr('id');
            $seriesId = (int) $episode->getAttr('series_id');
            $series = Series::where('id', $seriesId)->where('user_id', $userId)->find();
            if (!$series instanceof Series) {
                return ['error' => '作品不存在'];
            }
        } else {
            $seriesResult = $this->resolveSeriesForAction($userId, $params);
            if (isset($seriesResult['error'])) {
                return $seriesResult;
            }
            /** @var Series $series */
            $series = $seriesResult['series'];
            $seriesId = (int) $series->getAttr('id');
        }

        $assetCounts = ['character' => 0, 'scene' => 0, 'prop' => 0];
        $missingCoreAssets = [];
        $missingCoreCount = 0;
        $assetRows = Asset::with(['images'])
            ->where('user_id', $userId)
            ->where('series_id', $seriesId)
            ->where('is_hidden', 0)
            ->order(['sort' => 'asc', 'id' => 'asc'])
            ->select();
        foreach ($assetRows as $asset) {
            if (!$asset instanceof Asset) {
                continue;
            }
            $type = (string) $asset->getAttr('type');
            if (array_key_exists($type, $assetCounts)) {
                $assetCounts[$type]++;
            }
            $hasCoreImage = false;
            foreach ($asset->images ?? [] as $image) {
                if ((string) $image->getAttr('view_type') === 'main'
                    && trim((string) $image->getAttr('url')) !== '') {
                    $hasCoreImage = true;
                    break;
                }
            }
            if (!$hasCoreImage) {
                $missingCoreCount++;
                if (count($missingCoreAssets) < 20) {
                    $missingCoreAssets[] = [
                        'asset_id' => (int) $asset->getAttr('id'),
                        'name' => (string) $asset->getAttr('name'),
                        'type' => $type,
                    ];
                }
            }
        }

        $episodeQuery = Episode::where('user_id', $userId)
            ->where('series_id', $seriesId)
            ->order(['number' => 'asc', 'id' => 'asc']);
        if ($episodeId > 0) {
            $episodeQuery->where('id', $episodeId);
        }

        $episodeSummaries = [];
        $episodeCount = 0;
        $missingStoryboardCount = 0;
        $shotCount = 0;
        $missingDescriptionCount = 0;
        $missingImageCount = 0;
        $brokenImageCount = 0;
        $missingVideoCount = 0;
        foreach ($episodeQuery->select() as $episode) {
            if (!$episode instanceof Episode) {
                continue;
            }
            $episodeCount++;
            $revisionId = (int) ($episode->getAttr('current_storyboard_revision_id') ?? 0);
            $summary = [
                'episode_id' => (int) $episode->getAttr('id'),
                'number' => (int) $episode->getAttr('number'),
                'title' => (string) $episode->getAttr('title'),
                'storyboard_revision_id' => $revisionId ?: null,
                'shot_count' => 0,
                'missing_description' => 0,
                'missing_image' => 0,
                'broken_image' => 0,
                'broken_shot_indexes' => [],
                'missing_video' => 0,
            ];
            if ($revisionId <= 0) {
                $missingStoryboardCount++;
                $episodeSummaries[] = $summary;
                continue;
            }

            $shots = Shot::where('user_id', $userId)
                ->where('episode_id', (int) $episode->getAttr('id'))
                ->where('storyboard_revision_id', $revisionId)
                ->order('index', 'asc')
                ->select();
            foreach ($shots as $shot) {
                if (!$shot instanceof Shot) {
                    continue;
                }
                $summary['shot_count']++;
                $shotCount++;
                if (trim((string) $shot->getAttr('desc')) === '') {
                    $summary['missing_description']++;
                    $missingDescriptionCount++;
                }
                $imageMeta = $this->inspectShotImageUrl(trim((string) ($shot->getAttr('image_url') ?? '')));
                if (!$imageMeta['has_image']) {
                    $summary['missing_image']++;
                    $missingImageCount++;
                } elseif ($imageMeta['image_status'] === 'broken') {
                    $summary['broken_image']++;
                    $brokenImageCount++;
                    if (count($summary['broken_shot_indexes']) < 12) {
                        $summary['broken_shot_indexes'][] = (int) $shot->getAttr('index');
                    }
                }
                if (trim((string) ($shot->getAttr('video_url') ?? '')) === '') {
                    $summary['missing_video']++;
                    $missingVideoCount++;
                }
            }
            $episodeSummaries[] = $summary;
        }

        $assetIds = WorkerActions::validAssetIdsForImageJobs($userId, $seriesId);
        $imageJobStatuses = $assetIds === []
            ? []
            : AssetImageJob::where('user_id', $userId)->whereIn('asset_id', $assetIds)->column('status');
        $videoJobQuery = VideoJob::where('user_id', $userId)->where('series_id', $seriesId);
        if ($episodeId > 0) {
            $videoJobQuery->where('episode_id', $episodeId);
        }
        $videoJobStatuses = $videoJobQuery->column('status');
        $workflowStatuses = WorkflowRun::where('user_id', $userId)
            ->where('series_id', $seriesId)
            ->column('status');
        $imageJobs = $this->statusCounts($imageJobStatuses, ['queued', 'running', 'waiting', 'success', 'failed', 'cancelled']);
        $videoJobs = $this->statusCounts($videoJobStatuses, ['queued', 'blocked', 'running', 'success', 'failed', 'cancelled']);
        $workflowRuns = $this->statusCounts($workflowStatuses, ['queued', 'running', 'success', 'failed', 'cancelled']);

        $consistency = (new StoryboardConsistencyService())->validate($userId, $seriesId, $episodeId);
        if (isset($consistency['error'])) {
            $consistency = [
                'issue_count' => 0,
                'error_count' => 0,
                'warning_count' => 0,
                'issues' => [],
                'note' => (string) $consistency['error'],
            ];
        }

        $blockers = [];
        $warnings = [];
        $notes = [];
        $nextActions = [];
        if ($episodeCount === 0) {
            $blockers[] = '检查范围内还没有剧集';
            $nextActions[] = ['action' => '先创建或生成剧集', 'requires_confirmation' => true];
        }
        if ($missingStoryboardCount > 0) {
            $blockers[] = "{$missingStoryboardCount} 集没有当前分镜版本";
            $nextActions[] = ['action' => '先运行对应剧集的分镜工作流', 'requires_confirmation' => true];
        }
        if ($missingDescriptionCount > 0) {
            $blockers[] = "{$missingDescriptionCount} 个镜头缺少分镜描述";
            $nextActions[] = ['action' => '补齐缺少描述的镜头', 'requires_confirmation' => true];
        }
        if ($missingCoreCount > 0) {
            $warnings[] = $missingCoreCount . ' 个资产缺少核心参考图（明细最多展示 20 个）';
            $nextActions[] = [
                'action' => '补齐缺失的资产核心参考图',
                'tool' => 'queue_missing_core_images',
                'requires_confirmation' => true,
                'scope' => ['series_id' => $seriesId],
            ];
        }
        if ((int) ($consistency['error_count'] ?? 0) > 0) {
            $blockers[] = (int) $consistency['error_count'] . ' 个明确的资产与分镜冲突';
            $nextActions[] = ['action' => '逐条核对冲突并选择修改分镜或建立特殊造型资产', 'requires_confirmation' => true];
        }
        if ((int) ($consistency['warning_count'] ?? 0) > 0) {
            $warnings[] = (int) $consistency['warning_count'] . ' 个可能随剧情变化的外观差异需要人工确认';
        }
        $failedJobs = ($imageJobs['failed'] ?? 0) + ($videoJobs['failed'] ?? 0) + ($workflowRuns['failed'] ?? 0);
        if ($failedJobs > 0) {
            $warnings[] = "共有 {$failedJobs} 个失败任务，重试前应先查看失败原因";
            $nextActions[] = ['action' => '查询失败任务详情后再决定是否重试', 'requires_confirmation' => false];
        }
        if ($missingImageCount > 0) {
            $notes[] = "{$missingImageCount} 个镜头尚无图片";
        }
        if ($brokenImageCount > 0) {
            $warnings[] = "{$brokenImageCount} 个镜头图片 URL 存在但文件不可读（常见裂图）";
            $nextActions[] = [
                'action' => '为裂图镜头重新生成首帧图片',
                'tool' => 'regenerate_storyboard_image',
                'requires_confirmation' => true,
            ];
        }
        if ($missingVideoCount > 0) {
            $notes[] = "{$missingVideoCount} 个镜头尚无视频";
        }

        $status = $blockers !== [] ? 'needs_fix' : ($warnings !== [] ? 'attention' : 'ready');

        return [
            'read_only' => true,
            'status' => $status,
            'scope' => [
                'series_id' => $seriesId,
                'series_title' => (string) $series->getAttr('title'),
                'episode_id' => $episodeId ?: null,
            ],
            'assets' => [
                'total' => array_sum($assetCounts),
                'by_type' => $assetCounts,
                'missing_core_count' => $missingCoreCount,
                'missing_core_assets' => $missingCoreAssets,
            ],
            'episodes' => [
                'checked' => $episodeCount,
                'missing_storyboard' => $missingStoryboardCount,
                'items' => $episodeSummaries,
            ],
            'shots' => [
                'checked' => $shotCount,
                'missing_description' => $missingDescriptionCount,
                'missing_image' => $missingImageCount,
                'broken_image' => $brokenImageCount,
                'missing_video' => $missingVideoCount,
            ],
            'jobs' => [
                'image' => $imageJobs,
                'video' => $videoJobs,
                'workflow' => $workflowRuns,
            ],
            'consistency' => [
                'mode' => 'text',
                'issue_count' => (int) ($consistency['issue_count'] ?? 0),
                'error_count' => (int) ($consistency['error_count'] ?? 0),
                'warning_count' => (int) ($consistency['warning_count'] ?? 0),
                'issues' => array_slice(is_array($consistency['issues'] ?? null) ? $consistency['issues'] : [], 0, 10),
                'limits' => (string) ($consistency['limits'] ?? '仅检查文本，不识别实际图片内容'),
            ],
            'blockers' => $blockers,
            'warnings' => $warnings,
            'notes' => $notes,
            'next_actions' => $nextActions,
            'safety' => '本工具只读，不会自动补图、修改分镜、重试或取消任务；任何写操作都需用户明确确认目标与范围。',
        ];
    }

    private function toolRunSeriesWorkflow(App $app, int $userId, array $params): array
    {
        $seriesResult = $this->resolveSeriesForAction($userId, $params);
        if (isset($seriesResult['error'])) {
            return $seriesResult;
        }

        /** @var Series $series */
        $series = $seriesResult['series'];
        $payload = [];
        foreach (['source_text', 'workflow_id', 'episode_workflow_id', 'episode_count'] as $field) {
            if (array_key_exists($field, $params) && $params[$field] !== '' && $params[$field] !== null) {
                $payload[$field] = $params[$field];
            }
        }

        $controller = new \app\controller\SeriesController($app);
        $result = $controller->queueAgentSeriesWorkflow($userId, (int) $series->getAttr('id'), $payload);
        $run = is_array($result['workflow_run'] ?? null) ? $result['workflow_run'] : [];

        return [
            'queued' => true,
            'series_id' => (int) $series->getAttr('id'),
            'title' => (string) $series->getAttr('title'),
            'run_id' => (int) ($run['id'] ?? 0),
            'status' => (string) ($run['status'] ?? 'queued'),
            'progress' => (int) ($run['progress'] ?? 0),
        ];
    }

    private function resolveSeriesForAction(int $userId, array $params): array
    {
        $seriesId = (int) ($params['series_id'] ?? $params['id'] ?? 0);
        if ($seriesId > 0) {
            $series = Series::where('id', $seriesId)->where('user_id', $userId)->find();
            return $series instanceof Series ? ['series' => $series] : ['error' => '剧本不存在'];
        }

        $keyword = trim((string) (
            $params['series_title']
            ?? $params['series']
            ?? $params['keyword']
            ?? $params['title']
            ?? $params['name']
            ?? ''
        ));
        if ($keyword === '') {
            return ['error' => '请提供 series_id 或剧本标题关键词'];
        }

        $rows = Series::where('user_id', $userId)
            ->whereLike('title', '%' . $keyword . '%')
            ->order('id', 'desc')
            ->limit(5)
            ->select();

        $matches = [];
        foreach ($rows as $series) {
            if (!$series instanceof Series) {
                continue;
            }
            $matches[] = $series;
        }

        if (count($matches) === 0) {
            return ['error' => "没有找到标题包含「{$keyword}」的剧本"];
        }

        $exactMatches = array_values(array_filter($matches, static function (Series $series) use ($keyword): bool {
            return trim((string) $series->getAttr('title')) === $keyword;
        }));
        if (count($exactMatches) === 1) {
            return ['series' => $exactMatches[0]];
        }
        if (count($matches) === 1) {
            return ['series' => $matches[0]];
        }

        return [
            'error' => '找到多个匹配剧本，请指定 series_id',
            'matches' => array_map(static fn (Series $series): array => [
                'series_id' => (int) $series->getAttr('id'),
                'title' => (string) $series->getAttr('title'),
            ], $matches),
        ];
    }

    private function resolveEpisodeForAction(int $userId, array $params): array
    {
        $episodeId = (int) ($params['episode_id'] ?? 0);
        if ($episodeId > 0) {
            $episode = Episode::where('id', $episodeId)->where('user_id', $userId)->find();
            return $episode instanceof Episode ? ['episode' => $episode] : ['error' => '剧集不存在'];
        }

        $seriesParams = [
            'series_id' => (int) ($params['series_id'] ?? 0),
            'series_title' => trim((string) ($params['series_title'] ?? $params['series'] ?? $params['keyword'] ?? '')),
        ];
        $seriesResult = $this->resolveSeriesForAction($userId, $seriesParams);
        if (isset($seriesResult['error'])) {
            return $seriesResult;
        }

        /** @var Series $series */
        $series = $seriesResult['series'];
        $query = Episode::where('user_id', $userId)
            ->where('series_id', (int) $series->getAttr('id'))
            ->order(['number' => 'asc', 'id' => 'asc'])
            ->limit(20);
        $episodeNumber = (int) ($params['episode_number'] ?? $params['number'] ?? 0);
        if ($episodeNumber > 0) {
            $query->where('number', $episodeNumber);
        }
        $episodeTitle = trim((string) ($params['episode_title'] ?? ''));
        if ($episodeTitle !== '') {
            $query->whereLike('title', '%' . $episodeTitle . '%');
        }

        $matches = [];
        foreach ($query->select() as $episode) {
            if ($episode instanceof Episode) {
                $matches[] = $episode;
            }
        }
        if ($matches === []) {
            return ['error' => '没有找到匹配的剧集'];
        }
        if (count($matches) === 1) {
            return ['episode' => $matches[0]];
        }

        return [
            'error' => '找到多个匹配剧集，请指定 episode_id 或集数',
            'series_id' => (int) $series->getAttr('id'),
            'series_title' => (string) $series->getAttr('title'),
            'matches' => array_map(static fn (Episode $episode): array => [
                'episode_id' => (int) $episode->getAttr('id'),
                'number' => (int) $episode->getAttr('number'),
                'title' => (string) $episode->getAttr('title'),
            ], $matches),
        ];
    }

    private function toolListWorkflowRuns(int $userId, array $params): array
    {
        $limit = $this->clampLimit($params['limit'] ?? 10, 10, 20);
        $query = WorkflowRun::where('user_id', $userId)->order('id', 'desc')->limit($limit);
        $status = trim((string) ($params['status'] ?? ''));
        if (in_array($status, ['queued', 'running', 'success', 'failed', 'cancelled'], true)) {
            $query->where('status', $status);
        }

        $items = [];
        foreach ($query->select() as $run) {
            if (!$run instanceof WorkflowRun) {
                continue;
            }
            $series = Series::where('id', (int) $run->getAttr('series_id'))->where('user_id', $userId)->find();
            $items[] = [
                'run_id' => (int) $run->getAttr('id'),
                'series_title' => $series instanceof Series ? (string) $series->getAttr('title') : '',
                'status' => (string) $run->getAttr('status'),
                'progress' => (int) $run->getAttr('progress'),
                'current_node' => (string) ($run->getAttr('current_node_label') ?? ''),
                'error' => mb_substr((string) ($run->getAttr('error_message') ?? ''), 0, 300),
                'created' => (string) $run->getAttr('create_time'),
            ];
        }

        return ['runs' => $items];
    }

    private function toolListAgentTasks(int $userId, array $params): array
    {
        $limit = $this->clampLimit($params['limit'] ?? 10, 10, 20);
        $rows = AgentTask::where('user_id', $userId)->order('id', 'desc')->limit($limit)->select();

        $items = [];
        foreach ($rows as $task) {
            if (!$task instanceof AgentTask) {
                continue;
            }
            $items[] = [
                'task_no' => (string) $task->getAttr('task_no'),
                'title' => (string) $task->getAttr('title'),
                'status' => (string) $task->getAttr('status'),
                'phase' => (string) $task->getAttr('phase'),
                'progress' => (int) $task->getAttr('progress'),
                'error' => mb_substr((string) ($task->getAttr('error_message') ?? ''), 0, 300),
            ];
        }

        return ['tasks' => $items];
    }

    private function toolCancelWorkflowRun(int $userId, array $params): array
    {
        $runId = (int) ($params['run_id'] ?? $params['id'] ?? 0);
        if ($runId <= 0) {
            return ['error' => 'run_id 必填'];
        }

        return Db::transaction(function () use ($userId, $runId): array {
            $run = WorkflowRun::where('id', $runId)->where('user_id', $userId)->lock(true)->find();
            if (!$run instanceof WorkflowRun) {
                return ['error' => '任务不存在'];
            }
            $status = (string) $run->getAttr('status');
            if (in_array($status, ['success', 'cancelled'], true)) {
                return ['error' => "任务当前状态为 {$status}，无需取消"];
            }

            WorkflowRunNode::where('run_id', $runId)
                ->whereIn('status', ['queued', 'running'])
                ->update([
                    'status' => 'skipped',
                    'error_message' => '任务已由数字员工取消',
                    'finished_at' => date('Y-m-d H:i:s'),
                ]);
            $run->save([
                'status' => 'cancelled',
                'current_node_label' => '',
                'error_message' => '任务已由数字员工取消',
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
            RedisCache::bumpVersion("workflow_run:{$runId}");
            RedisCache::bumpVersion('series');

            return ['cancelled' => true, 'run_id' => $runId, 'previous_status' => $status];
        });
    }

    private function toolListAssets(int $userId, array $params): array
    {
        $limit = $this->clampLimit($params['limit'] ?? 20, 20, 30);
        $query = Asset::with(['images'])->where('user_id', $userId)->order('id', 'desc')->limit($limit);
        $seriesId = (int) ($params['series_id'] ?? 0);
        if ($seriesId > 0) {
            $query->where('series_id', $seriesId);
        }
        $keyword = trim((string) ($params['keyword'] ?? $params['asset'] ?? $params['name'] ?? ''));
        if ($keyword !== '') {
            $query->whereLike('name', '%' . $keyword . '%');
        }
        $type = trim((string) ($params['type'] ?? ''));
        if (in_array($type, ['character', 'scene', 'prop'], true)) {
            $query->where('type', $type);
        }
        $missingOnly = filter_var($params['missing_image_only'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $items = [];
        foreach ($query->select() as $asset) {
            if (!$asset instanceof Asset) {
                continue;
            }
            $imageCount = count($asset->images ?? []);
            if ($missingOnly && $imageCount > 0) {
                continue;
            }
            $items[] = [
                'asset_id' => (int) $asset->getAttr('id'),
                'series_id' => (int) $asset->getAttr('series_id'),
                'name' => (string) $asset->getAttr('name'),
                'type' => (string) $asset->getAttr('type'),
                'image_count' => $imageCount,
            ];
        }

        return ['assets' => $items];
    }

    private function toolListImageJobs(int $userId, array $params): array
    {
        $limit = $this->clampLimit($params['limit'] ?? 10, 10, 20);
        $seriesId = (int) ($params['series_id'] ?? 0);
        if ($seriesId <= 0 && trim((string) ($params['series_title'] ?? $params['series'] ?? '')) !== '') {
            $seriesResult = $this->resolveSeriesForAction($userId, $params);
            if (isset($seriesResult['error'])) {
                return $seriesResult;
            }
            $seriesId = (int) $seriesResult['series']->getAttr('id');
        }
        $validAssetIds = WorkerActions::validAssetIdsForImageJobs($userId, $seriesId);
        if ($validAssetIds === []) {
            return ['jobs' => []];
        }

        $query = AssetImageJob::where('user_id', $userId)
            ->whereIn('asset_id', $validAssetIds)
            ->order('id', 'desc')
            ->limit($limit);
        $status = trim((string) ($params['status'] ?? ''));
        if (in_array($status, ['queued', 'running', 'waiting', 'success', 'failed', 'cancelled'], true)) {
            $query->where('status', $status);
        }

        $items = [];
        foreach ($query->select() as $job) {
            if (!$job instanceof AssetImageJob) {
                continue;
            }
            $assetId = (int) ($job->getAttr('asset_id') ?? 0);
            $asset = $assetId > 0 ? Asset::where('id', $assetId)->where('user_id', $userId)->find() : null;
            $items[] = [
                'job_id' => (int) $job->getAttr('id'),
                'asset' => $asset instanceof Asset ? (string) $asset->getAttr('name') : '',
                'status' => (string) $job->getAttr('status'),
                'error' => mb_substr((string) ($job->getAttr('error_message') ?? ''), 0, 200),
                'updated' => (string) $job->getAttr('update_time'),
            ];
        }

        return ['jobs' => $items];
    }

    private function toolQueueMissingCoreImages(App $app, int $userId, array $params): array
    {
        $seriesId = (int) ($params['series_id'] ?? 0);
        if ($seriesId <= 0) {
            return ['error' => 'series_id 必填，可先用 list_series 找到目标剧本'];
        }
        $series = Series::where('id', $seriesId)->where('user_id', $userId)->find();
        if (!$series instanceof Series) {
            return ['error' => '剧本不存在'];
        }

        $controller = new \app\controller\AssetController($app);
        $result = $controller->queueAgentCoreImageJobs($userId, $seriesId);
        RedisCache::bumpVersion('assets');

        return [
            'queued' => (int) ($result['created'] ?? 0),
            'skipped_with_image' => (int) ($result['skipped_with_core'] ?? 0),
            'skipped_already_queued' => (int) ($result['skipped_queued'] ?? 0),
        ];
    }

    private function toolRegenerateAssetCoreImage(App $app, int $userId, array $params): array
    {
        $assetResult = $this->resolveAssetForAction($userId, $params);
        if (isset($assetResult['error'])) {
            return $assetResult;
        }

        /** @var Asset $asset */
        $asset = $assetResult['asset'];
        $promptAddition = trim((string) ($params['prompt_addition'] ?? $params['instruction'] ?? ''));
        $controller = new \app\controller\AssetController($app);
        $result = $controller->queueAgentRegenerateCoreImageJob($userId, (int) $asset->getAttr('id'), $promptAddition);
        if (isset($result['error'])) {
            return $result;
        }
        RedisCache::bumpVersion('assets');

        $job = is_array($result['job'] ?? null) ? $result['job'] : [];
        return [
            'queued' => (int) ($result['queued'] ?? 0),
            'updated_existing_queue' => (bool) ($result['updated_existing_queue'] ?? false),
            'asset_id' => (int) $asset->getAttr('id'),
            'asset' => (string) $asset->getAttr('name'),
            'job_id' => (int) ($job['id'] ?? 0),
            'status' => (string) ($job['status'] ?? 'queued'),
        ];
    }

    private function resolveAssetForAction(int $userId, array $params): array
    {
        $assetId = (int) ($params['asset_id'] ?? $params['id'] ?? 0);
        if ($assetId > 0) {
            $asset = Asset::where('id', $assetId)->where('user_id', $userId)->find();
            return $asset instanceof Asset ? ['asset' => $asset] : ['error' => '资产不存在'];
        }

        $keyword = trim((string) ($params['keyword'] ?? $params['asset'] ?? $params['name'] ?? ''));
        if ($keyword === '') {
            return ['error' => '请提供 asset_id 或资产名关键词'];
        }

        $query = Asset::where('user_id', $userId)->whereLike('name', '%' . $keyword . '%');
        $seriesId = (int) ($params['series_id'] ?? 0);
        if ($seriesId > 0) {
            $query->where('series_id', $seriesId);
        }
        $rows = $query->order('id', 'desc')->limit(6)->select();

        $matches = [];
        foreach ($rows as $asset) {
            if ($asset instanceof Asset) {
                $matches[] = $asset;
            }
        }
        if ($matches === []) {
            return ['error' => "没有找到名称包含「{$keyword}」的资产"];
        }

        $exactMatches = array_values(array_filter($matches, static function (Asset $asset) use ($keyword): bool {
            return trim((string) $asset->getAttr('name')) === $keyword;
        }));
        if (count($exactMatches) === 1) {
            return ['asset' => $exactMatches[0]];
        }
        if (count($matches) === 1) {
            return ['asset' => $matches[0]];
        }

        return [
            'error' => '找到多个匹配资产，请指定 asset_id',
            'matches' => array_map(static fn (Asset $asset): array => [
                'asset_id' => (int) $asset->getAttr('id'),
                'series_id' => (int) $asset->getAttr('series_id'),
                'name' => (string) $asset->getAttr('name'),
                'type' => (string) $asset->getAttr('type'),
            ], $matches),
        ];
    }

    private function toolRegenerateStoryboardImage(App $app, int $userId, array $params): array
    {
        $episodeResult = $this->resolveEpisodeForAction($userId, $params);
        if (isset($episodeResult['error'])) {
            return $episodeResult;
        }
        /** @var Episode $episode */
        $episode = $episodeResult['episode'];
        $revisionId = (int) ($episode->getAttr('current_storyboard_revision_id') ?? 0);
        if ($revisionId <= 0) {
            return ['error' => '该剧集还没有当前分镜版本'];
        }

        $shotQuery = Shot::where('user_id', $userId)
            ->where('episode_id', (int) $episode->getAttr('id'))
            ->where('storyboard_revision_id', $revisionId);
        $shotId = (int) ($params['shot_id'] ?? 0);
        $shotIndex = (int) ($params['shot_index'] ?? $params['index'] ?? 0);
        if ($shotId > 0) {
            $shotQuery->where('id', $shotId);
        } elseif ($shotIndex > 0) {
            $shotQuery->where('index', $shotIndex);
        } else {
            return ['error' => '请提供 shot_id 或镜头序号 shot_index'];
        }
        $shot = $shotQuery->find();
        if (!$shot instanceof Shot) {
            return ['error' => '当前分镜版本中没有找到这个镜头'];
        }

        $nodeResult = $this->resolveEpisodeWorkflowNodeForAction(
            $userId,
            (int) $episode->getAttr('id'),
            'image',
            trim((string) ($params['node_id'] ?? '')),
        );
        if (isset($nodeResult['error'])) {
            return $nodeResult;
        }

        $controller = new \app\controller\SeriesController($app);
        $episodeData = $controller->rerunAgentEpisodeImageShot(
            $userId,
            (int) $episode->getAttr('id'),
            (string) $nodeResult['node_id'],
            (int) $shot->getAttr('id'),
        );

        $candidateVersionId = null;
        foreach (($episodeData['shots'] ?? []) as $item) {
            if (!is_array($item) || (int) ($item['id'] ?? 0) !== (int) $shot->getAttr('id')) {
                continue;
            }
            foreach (array_reverse(is_array($item['media_versions'] ?? null) ? $item['media_versions'] : []) as $version) {
                if (is_array($version) && (string) ($version['media_type'] ?? '') === 'image') {
                    $candidateVersionId = (int) ($version['id'] ?? 0) ?: null;
                    break 2;
                }
            }
        }

        return [
            'generated' => true,
            'series_id' => (int) $episode->getAttr('series_id'),
            'episode_id' => (int) $episode->getAttr('id'),
            'episode_number' => (int) $episode->getAttr('number'),
            'shot_id' => (int) $shot->getAttr('id'),
            'shot_index' => (int) $shot->getAttr('index'),
            'candidate_version_id' => $candidateVersionId,
            'auto_selected' => false,
            'note' => '新图片已保存为候选版本，未自动覆盖当前选中图片',
        ];
    }

    private function toolRegenerateStoryboardShot(App $app, int $userId, array $params): array
    {
        $episodeResult = $this->resolveEpisodeForAction($userId, $params);
        if (isset($episodeResult['error'])) {
            return $episodeResult;
        }
        /** @var Episode $episode */
        $episode = $episodeResult['episode'];

        $shotIndex = (int) ($params['shot_index'] ?? $params['index'] ?? 0);
        if ($shotIndex <= 0) {
            return ['error' => '镜头序号 shot_index 必填'];
        }
        $instruction = trim((string) ($params['instruction'] ?? $params['prompt'] ?? ''));
        if ($instruction === '') {
            return ['error' => '请提供明确的分镜修改要求 instruction'];
        }
        if (mb_strlen($instruction) > 2000) {
            return ['error' => '分镜修改要求不能超过 2000 字'];
        }

        $nodeResult = $this->resolveEpisodeWorkflowNodeForAction(
            $userId,
            (int) $episode->getAttr('id'),
            'storyboard',
            trim((string) ($params['node_id'] ?? '')),
        );
        if (isset($nodeResult['error'])) {
            return $nodeResult;
        }

        $controller = new \app\controller\SeriesController($app);
        $episodeData = $controller->regenerateAgentStoryboardShot(
            $userId,
            (int) $episode->getAttr('id'),
            (string) $nodeResult['node_id'],
            $shotIndex,
            $instruction,
            is_array($params['asset_refs'] ?? null) ? $params['asset_refs'] : [],
        );

        $shotSummary = null;
        foreach (($episodeData['shots'] ?? []) as $item) {
            if (is_array($item) && (int) ($item['index'] ?? 0) === $shotIndex) {
                $shotSummary = [
                    'shot_id' => (int) ($item['id'] ?? 0),
                    'shot_index' => $shotIndex,
                    'description' => mb_substr(trim((string) ($item['desc'] ?? '')), 0, 1200),
                ];
                break;
            }
        }

        return [
            'updated' => true,
            'series_id' => (int) $episode->getAttr('series_id'),
            'episode_id' => (int) $episode->getAttr('id'),
            'episode_number' => (int) $episode->getAttr('number'),
            'storyboard_revision_id' => (int) ($episodeData['current_storyboard_revision_id'] ?? 0),
            'shot' => $shotSummary,
            'impact' => '已创建新分镜版本；当前镜头视频和最终成片需重新生成，现有图片按当前版本策略保留',
        ];
    }

    private function resolveEpisodeWorkflowNodeForAction(
        int $userId,
        int $episodeId,
        string $purpose,
        string $requestedNodeId = '',
    ): array {
        $query = EpisodeWorkflowNodeState::where('user_id', $userId)->where('episode_id', $episodeId);
        if ($requestedNodeId !== '') {
            $node = $query->where('workflow_node_id', $requestedNodeId)->find();
            if (!$node instanceof EpisodeWorkflowNodeState) {
                return ['error' => '当前剧集中找不到指定工作流节点'];
            }
            if (!$this->episodeWorkflowNodeMatchesPurpose($node, $purpose)) {
                return ['error' => '指定节点类型与当前操作不匹配'];
            }
            return ['node_id' => (string) $node->getAttr('workflow_node_id'), 'label' => (string) $node->getAttr('label')];
        }

        $matches = [];
        foreach ($query->order(['sort' => 'asc', 'id' => 'asc'])->select() as $node) {
            if ($node instanceof EpisodeWorkflowNodeState && $this->episodeWorkflowNodeMatchesPurpose($node, $purpose)) {
                $matches[] = $node;
            }
        }
        if ($matches === []) {
            return ['error' => $purpose === 'image' ? '该剧集没有可用的图片节点' : '该剧集没有可用的分镜节点'];
        }
        if (count($matches) > 1) {
            return [
                'error' => '找到多个可用节点，请指定 node_id',
                'matches' => array_map(static fn (EpisodeWorkflowNodeState $node): array => [
                    'node_id' => (string) $node->getAttr('workflow_node_id'),
                    'label' => (string) $node->getAttr('label'),
                    'kind' => (string) $node->getAttr('kind'),
                ], $matches),
            ];
        }

        return [
            'node_id' => (string) $matches[0]->getAttr('workflow_node_id'),
            'label' => (string) $matches[0]->getAttr('label'),
        ];
    }

    private function episodeWorkflowNodeMatchesPurpose(EpisodeWorkflowNodeState $node, string $purpose): bool
    {
        $kind = strtolower(trim((string) $node->getAttr('kind')));
        if ($purpose === 'image') {
            return $kind === 'image';
        }
        if ($kind !== 'text') {
            return false;
        }
        $label = mb_strtolower(trim((string) $node->getAttr('label')));
        return str_contains($label, '分镜') || str_contains($label, 'storyboard');
    }

    private function toolRetryFailedImageJobs(int $userId, array $params): array
    {
        $seriesId = (int) ($params['series_id'] ?? 0);
        if ($seriesId <= 0 && trim((string) ($params['series_title'] ?? $params['series'] ?? '')) !== '') {
            $seriesResult = $this->resolveSeriesForAction($userId, $params);
            if (isset($seriesResult['error'])) {
                return $seriesResult;
            }
            $seriesId = (int) $seriesResult['series']->getAttr('id');
        }
        if ($seriesId > 0) {
            $hasAssets = Asset::where('series_id', $seriesId)->where('user_id', $userId)->count() > 0;
            if (!$hasAssets) {
                return ['retried' => 0, 'note' => '该剧本下没有资产'];
            }
        }

        $limit = $this->optionalLimit($params['limit'] ?? $params['latest'] ?? $params['recent'] ?? 0, 20);
        $jobIds = $this->normalizeIdList($params['job_ids'] ?? $params['ids'] ?? $params['job_id'] ?? []);

        return ['retried' => WorkerActions::retryFailedImageJobs($userId, $seriesId, $limit, $jobIds)];
    }

    private function toolCancelQueuedImageJobs(int $userId, array $params): array
    {
        $seriesId = (int) ($params['series_id'] ?? 0);
        if ($seriesId <= 0 && trim((string) ($params['series_title'] ?? $params['series'] ?? '')) !== '') {
            $seriesResult = $this->resolveSeriesForAction($userId, $params);
            if (isset($seriesResult['error'])) {
                return $seriesResult;
            }
            $seriesId = (int) $seriesResult['series']->getAttr('id');
        }
        if ($seriesId > 0) {
            $hasAssets = Asset::where('series_id', $seriesId)->where('user_id', $userId)->count() > 0;
            if (!$hasAssets) {
                return ['cancelled' => 0, 'note' => '该剧本下没有资产'];
            }
        }

        $limit = $this->optionalLimit($params['limit'] ?? $params['latest'] ?? $params['recent'] ?? 0, 20);
        $jobIds = $this->normalizeIdList($params['job_ids'] ?? $params['ids'] ?? $params['job_id'] ?? []);
        if ($seriesId <= 0 && $limit <= 0 && $jobIds === []) {
            return ['error' => '为避免误取消全部生图任务，请指定作品、最新 N 个任务或具体 job_id'];
        }

        return ['cancelled' => WorkerActions::cancelQueuedImageJobs($userId, $seriesId, $limit, $jobIds)];
    }

    private function toolListVideoJobs(int $userId, array $params): array
    {
        $limit = $this->clampLimit($params['limit'] ?? 10, 10, 20);
        $query = VideoJob::where('user_id', $userId)->order('id', 'desc')->limit($limit);
        $status = trim((string) ($params['status'] ?? ''));
        if (in_array($status, ['queued', 'blocked', 'running', 'success', 'failed', 'cancelled'], true)) {
            $query->where('status', $status);
        }
        $episodeId = (int) ($params['episode_id'] ?? 0);
        if ($episodeId > 0) {
            $query->where('episode_id', $episodeId);
        }

        $items = [];
        foreach ($query->select() as $job) {
            if (!$job instanceof VideoJob) {
                continue;
            }
            $episode = Episode::where('id', (int) $job->getAttr('episode_id'))->where('user_id', $userId)->find();
            $items[] = [
                'job_id' => (int) $job->getAttr('id'),
                'episode_id' => (int) $job->getAttr('episode_id'),
                'episode' => $episode instanceof Episode ? (string) $episode->getAttr('title') : '',
                'shot' => (int) ($job->getAttr('shot_index') ?? 0) . '/' . (int) ($job->getAttr('total_shots') ?? 0),
                'status' => (string) $job->getAttr('status'),
                'error' => mb_substr((string) ($job->getAttr('error_message') ?? ''), 0, 200),
                'updated' => (string) $job->getAttr('update_time'),
            ];
        }

        return ['jobs' => $items];
    }

    private function toolRetryFailedVideoJobs(int $userId, array $params): array
    {
        $episodeId = (int) ($params['episode_id'] ?? 0);

        return ['retried' => WorkerActions::retryFailedVideoJobs($userId, $episodeId)];
    }

    private function toolCancelQueuedVideoJobs(int $userId, array $params): array
    {
        $episodeId = (int) ($params['episode_id'] ?? 0);
        if ($episodeId <= 0 && (
            (int) ($params['series_id'] ?? 0) > 0
            || trim((string) ($params['series_title'] ?? $params['series'] ?? '')) !== ''
        )) {
            $episodeResult = $this->resolveEpisodeForAction($userId, $params);
            if (isset($episodeResult['error'])) {
                return $episodeResult;
            }
            $episodeId = (int) $episodeResult['episode']->getAttr('id');
        }
        if ($episodeId <= 0) {
            return ['error' => '为避免误取消全部视频任务，请指定 episode_id 或作品与集数'];
        }

        return ['cancelled' => WorkerActions::cancelQueuedVideoJobs($userId, $episodeId)];
    }

    // ── 对话基础设施 ─────────────────────────────────────────────────────────────

    private function resolveTextModel(int $userId): ?ModelConfig
    {
        return ModelConfigResolver::resolve('text', $userId);
    }

    private function sanitizeHistory(array $history): array
    {
        $clean = [];
        foreach ($history as $item) {
            if (!is_array($item)) {
                continue;
            }
            $role = (string) ($item['role'] ?? '');
            $content = trim((string) ($item['content'] ?? ''));
            if (!in_array($role, ['user', 'assistant'], true) || $content === '') {
                continue;
            }
            $clean[] = ['role' => $role, 'content' => mb_substr($content, 0, 4000)];
        }

        return array_slice($clean, -self::MAX_HISTORY);
    }

    /**
     * 解析模型输出：tool 调用 / reply。
     * 模型常见毛病：夹带散文、用 markdown、在 JSON 字符串里直接换行（非法 JSON）。
     * 这里逐级兜底，确保协议 JSON 绝不泄露到对话气泡：
     * 收集候选片段（整段 / 代码块 / 花括号片段）→ 每个先严格 decode、再"修复非法控制字符"后 decode
     * → 仍失败则按协议形状用正则硬抽 text → 最后才把整段当普通文本并清掉 markdown。
     */
    private function parseDecision(string $raw): array
    {
        $text = trim($raw);

        // 收集候选 JSON 片段（按优先级）
        $candidates = [$text];
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $text, $m)) {
            $candidates[] = trim($m[1]);
        }
        if (preg_match_all('/\{(?:[^{}]|(?R))*\}/s', $text, $matches)) {
            foreach ($matches[0] as $frag) {
                $candidates[] = $frag;
            }
        }

        foreach ($candidates as $candidate) {
            // 先严格解析；失败再修复 JSON 字符串里的字面换行/制表符等非法控制字符后重试
            $decision = $this->decodeDecision($candidate)
                ?? $this->decodeDecision($this->repairJsonControlChars($candidate));
            if ($decision !== null) {
                return $decision;
            }
        }

        // 协议形状但 JSON 实在修不好（如内部有未转义引号）：硬抽 text
        $loose = $this->extractReplyLoosely($text);
        if ($loose !== null) {
            return $loose;
        }

        // 没有任何协议 JSON：整段当普通回复，清掉 markdown
        return ['type' => 'reply', 'text' => $this->cleanReplyText($raw) ?: '（空回复）'];
    }

    /**
     * 修复 LLM 常见的非法 JSON：把字符串值内部的字面换行/回车/制表符转义掉，
     * 使 json_decode 能成功。按字节扫描即可（UTF-8 续字节不会与 ASCII 控制符/引号冲突）。
     */
    private function repairJsonControlChars(string $s): string
    {
        $out = '';
        $inString = false;
        $escaped = false;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $ch = $s[$i];
            if ($escaped) {
                $out .= $ch;
                $escaped = false;
                continue;
            }
            if ($ch === '\\') {
                $out .= $ch;
                $escaped = true;
                continue;
            }
            if ($ch === '"') {
                $inString = !$inString;
                $out .= $ch;
                continue;
            }
            if ($inString) {
                if ($ch === "\n") { $out .= '\\n'; continue; }
                if ($ch === "\r") { $out .= '\\r'; continue; }
                if ($ch === "\t") { $out .= '\\t'; continue; }
            }
            $out .= $ch;
        }

        return $out;
    }

    /**
     * 终极兜底：文本是 {"type":"reply","text":"..."} 的形状但 JSON 修不好时，
     * 直接正则抽出 text 值，反转义后清洗。只处理 reply（tool 需结构化参数，不强抽）。
     */
    private function extractReplyLoosely(string $text): ?array
    {
        if (!preg_match('/"type"\s*:\s*"reply"/', $text)) {
            return null;
        }
        if (!preg_match('/"text"\s*:\s*"([\s\S]*)"\s*\}?\s*$/', $text, $m)) {
            return null;
        }
        $clean = $this->cleanReplyText(stripcslashes($m[1]));

        return ['type' => 'reply', 'text' => $clean !== '' ? $clean : '（空回复）'];
    }

    /** 尝试把一段文本解析为协议对象；非协议返回 null。 */
    private function decodeDecision(string $text): ?array
    {
        $decoded = json_decode(trim($text), true);
        if (!is_array($decoded)) {
            return null;
        }

        $type = (string) ($decoded['type'] ?? '');
        if ($type === 'tool' && trim((string) ($decoded['name'] ?? '')) !== '') {
            return [
                'type' => 'tool',
                'name' => trim((string) $decoded['name']),
                'params' => is_array($decoded['params'] ?? null) ? $decoded['params'] : [],
            ];
        }
        if ($type === 'reply') {
            $replyText = $this->cleanReplyText((string) ($decoded['text'] ?? ''));
            return ['type' => 'reply', 'text' => $replyText !== '' ? $replyText : '（空回复）'];
        }

        return null;
    }

    /**
     * 把模型回复清洗成"像同事聊天"的纯文本：去掉 markdown 加粗/斜体/标题/代码标记，
     * 列表符号统一成「·」，避免气泡里出现 ** # ` 等噪声字符。
     */
    private function cleanReplyText(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        // 加粗/斜体：**x** __x__ *x* _x_
        $text = (string) preg_replace('/(\*\*|__)(.+?)\1/s', '$2', $text);
        $text = (string) preg_replace('/(?<!\w)([*_])(?=\S)(.+?)(?<=\S)\1(?!\w)/s', '$2', $text);
        // 行内代码 `x`
        $text = (string) preg_replace('/`([^`]*)`/', '$1', $text);
        // 行首标题 ###、引用 >、无序列表符号 - * +
        $text = (string) preg_replace('/^\s{0,3}#{1,6}\s*/m', '', $text);
        $text = (string) preg_replace('/^\s{0,3}>\s?/m', '', $text);
        $text = (string) preg_replace('/^(\s*)[-*+]\s+/m', '$1· ', $text);

        return trim($text);
    }

    /**
     * 调用 OpenAI 兼容 chat/completions（对话场景：低延迟参数 + 写入 ai_request_logs）。
     */
    private function completeChat(int $userId, ModelConfig $model, array $messages, string $workerKey): string
    {
        $endpoint = trim((string) $model->getAttr('endpoint'));
        if ($endpoint === '') {
            abort(422, '文本模型 endpoint 为空');
        }
        if (!str_contains($endpoint, '/chat/completions')) {
            $endpoint = rtrim($endpoint, '/') . '/chat/completions';
        }
        $modelId = trim((string) $model->getAttr('model_id'));
        if ($modelId === '') {
            abort(422, '文本模型 model_id 为空');
        }

        $payload = [
            'model' => $modelId,
            'messages' => $messages,
            'temperature' => 0.4,
            'max_tokens' => max(256, min(32768, (int) env('AI_TEXT_MAX_TOKENS', 8192))),
        ];

        CreditService::assertTextAffordable(
            $userId,
            CreditService::estimatePromptTokensFromMessages($messages),
            (int) $payload['max_tokens'],
            $modelId
        );

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: AI-Workflow/1.0',
        ];
        $apiKey = trim((string) $model->getAttr('api_key'));
        if ($apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        $startedAt = microtime(true);
        $ch = curl_init($endpoint);
        if ($ch === false) {
            abort(500, '初始化 AI 请求失败');
        }
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 180);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));

        $response = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = (string) curl_error($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $rawBody = is_string($response) ? $response : '';
        $decoded = json_decode($rawBody, true);
        $content = '';
        if (is_array($decoded)) {
            $value = $decoded['choices'][0]['message']['content'] ?? '';
            $content = is_string($value) ? $value : '';
        }

        $ok = $curlErrno === 0 && $httpStatus >= 200 && $httpStatus < 300 && $content !== '';
        $this->logAiRequest($userId, $model, $endpoint, $payload, $workerKey, [
            'http_status' => $httpStatus,
            'curl_errno' => $curlErrno,
            'curl_error' => $curlError,
            'ok' => $ok,
            'content' => $content,
            'raw_body' => $rawBody,
            'usage' => is_array($decoded) && is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [],
            'finish_reason' => is_array($decoded) ? (string) ($decoded['choices'][0]['finish_reason'] ?? '') : '',
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        if ($curlErrno !== 0) {
            abort(502, 'AI 请求失败：' . ($curlErrno === 28 ? '响应超时，请稍后再试' : $curlError));
        }
        if ($httpStatus < 200 || $httpStatus >= 300) {
            abort(502, 'AI 服务异常：HTTP ' . $httpStatus);
        }
        if ($content === '') {
            abort(502, 'AI 返回内容为空');
        }

        return $content;
    }

    private function logAiRequest(int $userId, ModelConfig $model, string $endpoint, array $payload, string $workerKey, array $meta): void
    {
        try {
            AiRequestLog::create([
                'user_id' => $userId,
                'source' => 'worker_agent',
                'model_config_id' => (int) $model->getAttr('id'),
                'llm_model' => (string) $model->getAttr('model_id'),
                'finish_reason' => mb_substr((string) ($meta['finish_reason'] ?? ''), 0, 32),
                'max_tokens' => (int) ($payload['max_tokens'] ?? 0),
                'usage_json' => $meta['usage'] ?? [],
                'endpoint' => mb_substr($endpoint, 0, 500),
                'context_json' => json_encode(['worker' => $workerKey], JSON_UNESCAPED_UNICODE),
                'request_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'http_status' => (int) ($meta['http_status'] ?? 0),
                'response_body' => mb_substr((string) ($meta['raw_body'] ?? ''), 0, 60000),
                'curl_errno' => (int) ($meta['curl_errno'] ?? 0),
                'curl_error' => mb_substr((string) ($meta['curl_error'] ?? ''), 0, 500),
                'request_ok' => ($meta['ok'] ?? false) ? 1 : 0,
                'error_message' => '',
                'duration_ms' => (int) ($meta['duration_ms'] ?? 0),
                'content_preview' => mb_substr((string) ($meta['content'] ?? ''), 0, 900),
                'assistant_content' => (string) ($meta['content'] ?? ''),
            ]);
        } catch (\Throwable) {
            // 日志失败不阻塞对话
        }
    }

    private function clampLimit(mixed $value, int $default, int $max): int
    {
        $limit = (int) $value;
        if ($limit <= 0) {
            $limit = $default;
        }

        return min($limit, $max);
    }

    private function optionalLimit(mixed $value, int $max): int
    {
        if (is_bool($value)) {
            return 0;
        }

        $limit = (int) $value;
        if ($limit <= 0) {
            return 0;
        }

        return min($limit, $max);
    }

    /** @param array<int, mixed> $statuses */
    private function statusCounts(array $statuses, array $allowed): array
    {
        $counts = array_fill_keys($allowed, 0);
        foreach ($statuses as $status) {
            $key = trim((string) $status);
            if (array_key_exists($key, $counts)) {
                $counts[$key]++;
            }
        }

        return $counts;
    }

    /** @param mixed $value */
    private function normalizeIdList(mixed $value): array
    {
        if (is_int($value) || is_string($value)) {
            $value = [$value];
        }
        if (!is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $id) {
            $intId = (int) $id;
            if ($intId > 0) {
                $ids[$intId] = $intId;
            }
        }

        return array_values($ids);
    }
}
