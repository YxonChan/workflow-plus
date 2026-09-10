<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\model\Asset;
use app\model\AiRequestLog;
use app\model\AssetImage;
use app\model\AssetImageJob;
use app\model\AssetImageVersion;
use app\model\AssetShare;
use app\model\SeriesShare;
use app\model\Episode;
use app\model\EpisodeWorkflowNodeState;
use app\model\ModelConfig;
use app\model\PromptTemplate;
use app\model\Series;
use app\model\Shot;
use app\model\ShotMediaVersion;
use app\model\StoryboardRevision;
use app\model\User;
use app\model\VideoJob;
use app\model\Workflow;
use app\model\WorkflowRun;
use app\model\WorkflowRunNode;
use app\support\CallSheetGenerator;
use app\support\AssetLookService;
use app\support\ToapisPrivateAvatarService;
use app\support\AssetVisibility;
use app\support\AssetExtractionRequestOptimizer;
use app\support\CharacterAssetClassifier;
use app\support\ImageGenerationOptions;
use app\support\ModelConfigResolver;
use app\support\MediaStorage;
use app\support\SupabaseStorage;
use app\support\NovelImportService;
use app\support\PromoVideoSegmentConfig;
use app\support\RedisCache;
use app\support\StoryboardRequestOptimizer;
use app\support\VideoAssetImageRefreshService;
use app\support\VideoVoiceAssetService;
use app\support\VideoReferencedAssetGate;
use app\support\VideoWorkflowProgress;
use app\support\WorkflowRuntime;
use think\facade\Db;
use think\facade\Log;

class SeriesController extends BaseController
{
    private const VIDEO_GENERATION_RESTRICTED_USER_IDS = [1, 2];

    /**
     * 上次 callChatCompletions 返回的 finish_reason。
     * 用于区分 length（被 max_tokens 截断）和 stop（模型自然结束）。
     */
    private string $lastChatFinishReason = '';

    /**
     * 上次 callChatCompletions 返回内容的字节长度，便于在错误信息中告知用户。
     */
    private int $lastChatContentBytes = 0;

    /**
     * 上次 safeParseJson 是否通过堆栈修复了截断的 JSON。
     */
    private bool $lastJsonWasRepaired = false;

    /**
     * 上次 insertAiRequestLog 写入的记录 id。
     */
    private int $lastAiRequestLogId = 0;

    /**
     * 上次 callChatCompletions 实际使用的 max_tokens。
     */
    private int $lastChatMaxTokens = 0;
    private array $workflowNameCache = [];

    /**
     * CLI worker 执行异步任务时没有登录态，用任务所属用户作为隔离上下文。
     */
    private int $runtimeUserId = 0;
    private ?AssetLookService $assetLookService = null;

    /**
     * 供 CLI worker（如灵感速创）复用生成链路时设置用户隔离上下文。
     */
    public function setRuntimeUserId(int $userId): void
    {
        $this->runtimeUserId = $userId > 0 ? $userId : 1;
    }

    /**
     * 读取上次 AI 请求日志 id，供外部调用方关联任务记录。
     */
    public function lastAiRequestLogId(): int
    {
        return $this->lastAiRequestLogId;
    }

    protected function initialize()
    {
        if (str_starts_with(trim((string) $this->request->pathinfo(), '/'), 'api/agent/')) {
            return;
        }

        parent::initialize();
    }

    /**
     * 节点 prompt 支持直接填写或引用词库。
     * 当前端仅保存了系统模板引用时，运行时通过 promptTemplateId 再解析出正文。
     */
    private function resolveNodePrompt(
        array $node,
        string $fallback = '',
        string $scope = 'all',
        ?array &$meta = null,
        string $override = '',
        int $overrideTemplateId = 0,
    ): string
    {
        $override = trim($override);
        if ($override !== '') {
            $meta = ['source' => 'override', 'template_id' => null, 'template_title' => '', 'is_system' => false];
            return $override;
        }

        if ($overrideTemplateId > 0) {
            $template = PromptTemplate::where('id', $overrideTemplateId)
                ->where('user_id', $this->effectiveUserId())
                ->find();
            if ($template instanceof PromptTemplate) {
                $templatePrompt = trim((string) $template->getAttr('prompt'));
                if ($templatePrompt !== '') {
                    $meta = [
                        'source' => (int) $template->getAttr('is_system') === 1 ? 'system_template' : 'user_template',
                        'template_id' => (int) $template->getAttr('id'),
                        'template_title' => (string) $template->getAttr('title'),
                        'is_system' => (int) $template->getAttr('is_system') === 1,
                    ];
                    return $templatePrompt;
                }
            }
        }

        $params = is_array($node['data']['params'] ?? null) ? $node['data']['params'] : [];
        $customPromptEnabledRaw = $params['customPromptEnabled'] ?? null;
        $customPromptEnabled = in_array($customPromptEnabledRaw, [true, 1, '1', 'true', 'on', 'yes'], true);
        $prompt = trim((string) ($params['prompt'] ?? ''));
        if ($customPromptEnabled && $prompt !== '') {
            $meta = ['source' => 'node_prompt', 'template_id' => null, 'template_title' => '', 'is_system' => false];
            return $prompt;
        }

        $templateId = (int) ($params['promptTemplateId'] ?? $params['prompt_template_id'] ?? 0);
        if ($templateId > 0) {
            $template = PromptTemplate::where('id', $templateId)
                ->where('user_id', $this->effectiveUserId())
                ->find();
            if ($template instanceof PromptTemplate) {
                $templatePrompt = trim((string) $template->getAttr('prompt'));
                if ($templatePrompt !== '') {
                    $meta = [
                        'source' => (int) $template->getAttr('is_system') === 1 ? 'system_template' : 'user_template',
                        'template_id' => (int) $template->getAttr('id'),
                        'template_title' => (string) $template->getAttr('title'),
                        'is_system' => (int) $template->getAttr('is_system') === 1,
                    ];
                    return $templatePrompt;
                }
            }
        }

        if ($prompt !== '') {
            $meta = ['source' => 'node_prompt', 'template_id' => null, 'template_title' => '', 'is_system' => false];
            return $prompt;
        }

        $label = trim((string) ($node['label'] ?? $node['data']['label'] ?? ''));
        $kind = trim((string) ($node['data']['kind'] ?? $node['kind'] ?? ''));
        if ($label !== '' && $kind !== '') {
            foreach ([$label, ''] as $nodeLabel) {
                $template = PromptTemplate::where('user_id', $this->effectiveUserId())
                    ->where('is_system', 1)
                    ->whereIn('scope', array_values(array_unique(['all', $scope])))
                    ->whereIn('node_kind', [$kind, 'all'])
                    ->where('node_label', $nodeLabel)
                    ->order('sort', 'asc')
                    ->order('id', 'asc')
                    ->find();
                if ($template instanceof PromptTemplate) {
                    $templatePrompt = trim((string) $template->getAttr('prompt'));
                    if ($templatePrompt !== '') {
                        $meta = [
                            'source' => 'system_template',
                            'template_id' => (int) $template->getAttr('id'),
                            'template_title' => (string) $template->getAttr('title'),
                            'is_system' => true,
                        ];
                        return $templatePrompt;
                    }
                }
            }
        }

        $meta = ['source' => $fallback !== '' ? 'fallback' : 'empty', 'template_id' => null, 'template_title' => '', 'is_system' => false];
        return trim($fallback);
    }

    /**
     * 查询剧本列表。
     * 返回所有剧本，并附带每个剧本下的剧集列表，供剧本管理页面左侧和中间栏展示。
     */
    public function index()
    {
        return successCode($this->doList($this->request->param()));
    }

    /**
     * 新建剧本。
     * 从 JSON 请求体读取剧本名称、简介和可选剧本工作流 id。
     * 如果请求体声明 run_workflow=true，则只创建异步任务并立即返回，不在 HTTP 请求中阻塞执行 AI。
     */
    public function save()
    {
        $payload = $this->request->param();

        if ($this->shouldRunWorkflowOnCreate($payload)) {
            return successCode($this->doCreateSeriesAndQueueWorkflow($payload), 'success', 201);
        }

        return successCode($this->doCreateSeries($payload), 'success', 201);
    }

    /**
     * Agent 编排器复用的内部入口。
     * Agent 对外状态独立，这里只负责创建内部剧本并排队剧本工作流。
     */
    public function createAgentSeriesAndQueueWorkflow(int $userId, array $payload): array
    {
        $this->runtimeUserId = $userId > 0 ? $userId : 1;
        return $this->doCreateSeriesAndQueueWorkflow($payload);
    }

    /**
     * 数字员工复用的内部入口：给已有剧本排队执行剧本工作流。
     */
    public function queueAgentSeriesWorkflow(int $userId, int $seriesId, array $payload = []): array
    {
        $this->runtimeUserId = $userId > 0 ? $userId : 1;
        $series = Series::where('id', $seriesId)->where('user_id', $this->runtimeUserId)->find();
        if (!$series instanceof Series) {
            abort(404, '剧本不存在');
        }

        $blockingRun = WorkflowRuntime::findBlockingSeriesRun($seriesId);
        if ($blockingRun instanceof WorkflowRun) {
            abort(423, '该剧本已有未完成的剧本工作流任务，请先查看进度或处理失败任务');
        }

        $runPayload = $payload;
        if (!isset($runPayload['workflow_id']) || (int) $runPayload['workflow_id'] <= 0) {
            $boundWorkflowId = (int) ($series->getAttr('series_workflow_id') ?? 0);
            if ($boundWorkflowId > 0) {
                $runPayload['workflow_id'] = $boundWorkflowId;
            } else {
                $defaultWorkflow = Workflow::where('user_id', $this->runtimeUserId)
                    ->where('scope', 'series')
                    ->order(['is_default' => 'desc', 'sort' => 'asc', 'id' => 'asc'])
                    ->find();
                if ($defaultWorkflow instanceof Workflow) {
                    $runPayload['workflow_id'] = (int) $defaultWorkflow->getAttr('id');
                }
            }
        }

        $storedSource = trim((string) ($series->getAttr('source_text') ?? ''));
        $sourceText = $this->preferFullSeriesSourceText(
            trim((string) ($runPayload['source_text'] ?? '')),
            $storedSource
        );
        if ($sourceText === '') {
            $description = trim((string) ($series->getAttr('description') ?? ''));
            $title = trim((string) ($series->getAttr('title') ?? ''));
            $sourceText = $description !== '' ? $description : $title;
        }
        $runPayload['source_text'] = $sourceText;

        $run = $this->createWorkflowRunForSeries($seriesId, $runPayload);
        return [
            'series_id' => $seriesId,
            'title' => (string) $series->getAttr('title'),
            'workflow_run' => $this->serializeWorkflowRun($run),
        ];
    }

    /**
     * 统一数字员工复用的剧集详情入口。
     */
    public function getAgentEpisodeDetail(int $userId, int $episodeId): array
    {
        $this->runtimeUserId = $userId > 0 ? $userId : 1;
        return $this->doGetEpisode($episodeId);
    }

    /**
     * 统一数字员工复用的单镜头分镜重生成入口。
     */
    public function regenerateAgentStoryboardShot(
        int $userId,
        int $episodeId,
        string $nodeId,
        int $shotIndex,
        string $instruction,
        array $assetRefs = [],
    ): array {
        $this->runtimeUserId = $userId > 0 ? $userId : 1;
        return $this->doRegenerateEpisodeStoryboardShot($episodeId, $nodeId, $shotIndex, $instruction, $assetRefs);
    }

    /**
     * 统一制作助手复用的已确认多镜头修订入口。
     * 一次只处理一集，所有改动写入同一个新 revision；调用方必须先完成预览与确认令牌校验。
     */
    public function applyAgentStoryboardRepairs(
        int $userId,
        int $episodeId,
        int $expectedRevisionId,
        array $previews,
    ): array {
        $this->runtimeUserId = $userId > 0 ? $userId : 1;
        $episode = $this->findEpisodeOrFail($episodeId);
        $seriesId = (int) $episode->getAttr('series_id');
        $this->assertSeriesWorkflowEditable($seriesId);
        $this->assertEpisodeAutoExecutionEditable($episode, '当前剧集正在自动执行，请先取消自动执行后再修改分镜');

        $changes = [];
        foreach (array_slice($previews, 0, 20) as $preview) {
            if (!is_array($preview)) {
                continue;
            }
            $shotId = (int) ($preview['shot_id'] ?? 0);
            $shotIndex = (int) ($preview['shot_index'] ?? 0);
            $before = trim((string) ($preview['before'] ?? ''));
            $after = trim((string) ($preview['after'] ?? ''));
            if ($shotId <= 0 || $shotIndex <= 0 || $before === '' || $after === '' || $before === $after) {
                continue;
            }
            if (mb_strlen($after) > 20000) {
                abort(422, "镜头{$shotIndex}修改后的文本过长");
            }
            $changes[$shotIndex] = [
                'shot_id' => $shotId,
                'shot_index' => $shotIndex,
                'before' => $before,
                'after' => $after,
                'issue_ids' => is_array($preview['issue_ids'] ?? null) ? $preview['issue_ids'] : [],
            ];
        }
        if ($changes === []) {
            abort(422, '没有可应用的镜头修改');
        }

        Db::transaction(function () use ($episodeId, $expectedRevisionId, $changes): void {
            $lockedEpisode = Episode::where('id', $episodeId)
                ->where('user_id', $this->runtimeUserId)
                ->lock(true)
                ->find();
            if (!$lockedEpisode instanceof Episode) {
                abort(404, '剧集不存在');
            }
            if ((int) ($lockedEpisode->getAttr('current_storyboard_revision_id') ?? 0) !== $expectedRevisionId) {
                abort(409, '分镜已经变化，确认令牌失效，请重新检查');
            }
            $oldRevision = StoryboardRevision::where('id', $expectedRevisionId)
                ->where('episode_id', $episodeId)
                ->find();
            if (!$oldRevision instanceof StoryboardRevision) {
                abort(409, '原分镜版本不存在，请重新检查');
            }

            foreach ($changes as $shotIndex => $change) {
                $shot = Shot::where('id', (int) $change['shot_id'])
                    ->where('user_id', $this->runtimeUserId)
                    ->where('episode_id', $episodeId)
                    ->where('storyboard_revision_id', $expectedRevisionId)
                    ->where('index', $shotIndex)
                    ->find();
                if (!$shot instanceof Shot || trim((string) $shot->getAttr('desc')) !== (string) $change['before']) {
                    abort(409, "镜头{$shotIndex}在预览后已经变化，请重新检查");
                }
            }

            StoryboardRevision::where('episode_id', $episodeId)
                ->where('status', 'current')
                ->update(['status' => 'archived']);
            $rawOutput = json_encode([
                'source' => 'worker_visual_review',
                'previous_revision_id' => $expectedRevisionId,
                'changes' => array_values($changes),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '';
            $revision = new StoryboardRevision();
            $revision->save([
                'user_id' => (int) $lockedEpisode->getAttr('user_id'),
                'series_id' => (int) $lockedEpisode->getAttr('series_id'),
                'episode_id' => $episodeId,
                'workflow_run_id' => (int) ($oldRevision->getAttr('workflow_run_id') ?? 0),
                'workflow_run_node_id' => (int) ($oldRevision->getAttr('workflow_run_node_id') ?? 0),
                'raw_output' => $rawOutput,
                'content_hash' => hash('sha256', $rawOutput),
                'shot_count' => (int) Shot::where('episode_id', $episodeId)->where('storyboard_revision_id', $expectedRevisionId)->count(),
                'status' => 'current',
            ]);
            $revisionId = (int) $revision->getAttr('id');
            $changedIndexes = array_keys($changes);
            $cloned = $this->cloneStoryboardRevisionContext($episodeId, $expectedRevisionId, $revisionId, $changedIndexes);
            foreach ($changes as $shotIndex => $change) {
                $shot = $cloned['shots'][$shotIndex] ?? null;
                if (!$shot instanceof Shot) {
                    abort(500, "克隆镜头{$shotIndex}失败");
                }
                $shot->save([
                    'desc' => (string) $change['after'],
                    'status' => 'pending',
                ]);
            }

            Episode::where('id', $episodeId)->update(['current_storyboard_revision_id' => $revisionId]);
            $this->purgeArchivedStoryboardArtifactsForEpisode($episodeId, $revisionId);
            $runId = (int) ($oldRevision->getAttr('workflow_run_id') ?? 0);
            $runNodeId = (int) ($oldRevision->getAttr('workflow_run_node_id') ?? 0);
            $runNode = $runNodeId > 0 ? WorkflowRunNode::where('id', $runNodeId)->where('run_id', $runId)->find() : null;
            if ($runNode instanceof WorkflowRunNode) {
                $this->invalidateEpisodeWorkflowNodesAfterStoryboardShotUpdates(
                    $runId,
                    (int) $runNode->getAttr('sort'),
                    $revisionId,
                    $changedIndexes,
                );
                $this->refreshEpisodeWorkflowRunProgress($runId);
            }
        });

        $this->clearSeriesCache();

        return $this->serializeEpisode(Episode::with(['shots'])->findOrFail($episodeId), true);
    }

    /**
     * 统一数字员工复用的单镜头图片候选生成入口。
     */
    public function rerunAgentEpisodeImageShot(int $userId, int $episodeId, string $nodeId, int $shotId): array
    {
        $this->runtimeUserId = $userId > 0 ? $userId : 1;
        $episode = Episode::where('id', $episodeId)->where('user_id', $this->runtimeUserId)->find();
        if (!$episode instanceof Episode) {
            abort(404, '剧集不存在');
        }

        return $this->doRerunEpisodeImageShot($episodeId, $nodeId, $shotId);
    }

    /**
     * 更新剧本。
     * 从 JSON 请求体读取 id，更新名称、简介和可选剧本工作流绑定。
     */
    public function update()
    {
        $payload = $this->request->param();
        $id = $this->requireId($payload, '剧本 id 不能为空');

        return successCode($this->doUpdateSeries($id, $payload));
    }

    /**
     * 删除剧本。
     * 从 JSON 请求体读取 id，删除指定剧本，同时删除它下面的剧集和镜头数据。
     */
    public function delete()
    {
        $payload = $this->request->param();
        $id = $this->requireId($payload, '剧本 id 不能为空');
        $this->doDeleteSeries($id);

        return successCode();
    }

    /**
     * 新建剧集。
     * 从 JSON 请求体读取 series_id 创建子剧集；工作流可稍后在右侧详情选择。
     */
    public function saveEpisode()
    {
        $payload = $this->request->param();
        $seriesId = $this->requireId($payload, '剧本 id 不能为空', 'series_id');

        return successCode($this->doCreateEpisode($seriesId, $payload), 'success', 201);
    }

    /**
     * 更新剧集配置。
     * 从 JSON 请求体读取 id，更新标题、序号、状态、剧情输入等；已绑定的 workflow_id 不允许被替换。
     */
    public function updateEpisode()
    {
        $payload = $this->request->param();
        $id = $this->requireId($payload, '剧集 id 不能为空');

        return successCode($this->doUpdateEpisode($id, $payload));
    }

    /**
     * 删除剧集。
     * 从 JSON 请求体读取 id，删除指定剧集，同时删除该剧集下的镜头数据。
     */
    public function deleteEpisode()
    {
        $payload = $this->request->param();
        $id = $this->requireId($payload, '剧集 id 不能为空');
        $this->doDeleteEpisode($id);

        return successCode();
    }

    /**
     * 查询剧集详情。
     * 从 JSON 请求体读取 id，返回剧集基础信息和 shots 明细。
     */
    public function readEpisode()
    {
        $payload = $this->request->param();
        $id = $this->requireId($payload, '剧集 id 不能为空');

        return successCode($this->doGetEpisode($id));
    }

    /**
     * 手动更新单个剧集工作流节点的产出内容。
     * 更新内容后，为了保持一致性，会自动清除该节点之后所有已执行节点的产出，并将状态重置为待执行。
     */
    public function updateEpisodeWorkflowNode()
    {
        $payload = $this->request->param();
        $episodeId = $this->requireId($payload, '剧集 id 不能为空');
        $nodeId = trim((string) ($payload['node_id'] ?? ''));
        if ($nodeId === '') {
            abort(422, '节点 id 不能为空');
        }

        $content = (string) ($payload['content'] ?? '');
        $storyboardShots = isset($payload['storyboard_shots']) && is_array($payload['storyboard_shots'])
            ? $payload['storyboard_shots']
            : [];
        $updateMode = trim((string) ($payload['update_mode'] ?? ''));
        $changedShotIndex = isset($payload['changed_shot_index']) ? (int) $payload['changed_shot_index'] : 0;

        return successCode($this->doUpdateEpisodeWorkflowNode($episodeId, $nodeId, $content, $storyboardShots, $updateMode, $changedShotIndex));
    }

    /**
     * 使用 AI 只重新生成一个分镜镜头，并复用单镜头编辑的失效范围。
     */
    public function regenerateEpisodeStoryboardShot()
    {
        $payload = $this->request->param();
        $episodeId = $this->requireId($payload, '剧集 id 不能为空');
        $nodeId = trim((string) ($payload['node_id'] ?? ''));
        if ($nodeId === '') {
            abort(422, '节点 id 不能为空');
        }

        $shotIndex = (int) ($payload['shot_index'] ?? 0);
        if ($shotIndex <= 0) {
            abort(422, '镜头序号不能为空');
        }
        $instruction = trim((string) ($payload['instruction'] ?? ''));
        if (mb_strlen($instruction) > 2000) {
            abort(422, '本次修改要求不能超过 2000 字');
        }
        $assetRefs = isset($payload['asset_refs']) && is_array($payload['asset_refs'])
            ? $payload['asset_refs']
            : [];

        return successCode($this->doRegenerateEpisodeStoryboardShot($episodeId, $nodeId, $shotIndex, $instruction, $assetRefs));
    }

    /**
     * 执行剧集节点内容更新。
     */
    private function doUpdateEpisodeWorkflowNode(
        int $episodeId,
        string $nodeId,
        string $content,
        array $storyboardShots = [],
        string $updateMode = '',
        int $changedShotIndex = 0,
    ): array
    {
        $episode = $this->findEpisodeOrFail($episodeId);
        $this->assertEpisodeAutoExecutionEditable($episode, '当前剧集正在自动执行，请先取消自动执行后再修改节点内容');
        [$run, $targetNode] = $this->resolveEpisodeRunNodeForAction($episode, $nodeId);

        if (!$targetNode instanceof WorkflowRunNode) {
            abort(404, '工作流节点记录不存在');
        }

        $label = (string) $targetNode->getAttr('label');
        $seriesId = (int) $episode->getAttr('series_id');
        $isStoryboardNode = $this->isStoryboardRevisionNode((string) $targetNode->getAttr('label'), (string) $targetNode->getAttr('kind'));
        $isSingleShotStoryboardUpdate = $isStoryboardNode && $updateMode === 'single_shot' && $changedShotIndex > 0;
        $normalizedStoryboardShots = $isStoryboardNode
            ? $this->normalizeStructuredStoryboardShots($storyboardShots, $seriesId)
            : [];
        $contentToSave = $normalizedStoryboardShots !== []
            ? $this->formatStructuredStoryboardShotsAsText($normalizedStoryboardShots)
                : ($this->shouldNormalizeStoryboardLookReferences($label)
                    ? $this->normalizeStoryboardLookReferences($content, $seriesId)
                    : $content);

        Db::transaction(function () use ($episode, $targetNode, $contentToSave, $run, $normalizedStoryboardShots, $isStoryboardNode, $seriesId, $isSingleShotStoryboardUpdate, $changedShotIndex) {
            // 更新目标节点
            $parsed = $this->safeParseJson($contentToSave);
            $output = isset($parsed['__raw']) ? ['text' => $contentToSave] : $parsed;
            if ($isStoryboardNode) {
                $output['text'] = $contentToSave;
                if ($normalizedStoryboardShots !== []) {
                    $output['shots'] = $normalizedStoryboardShots;
                    $output['asset_mentions'] = $this->assetMentionsFromStructuredStoryboardShots($normalizedStoryboardShots);
                } else {
                    $structuredShots = $this->structuredStoryboardShotsFromText($contentToSave, $seriesId);
                    if ($structuredShots !== []) {
                        $output['shots'] = $structuredShots;
                        $output['asset_mentions'] = $this->assetMentionsFromStructuredStoryboardShots($structuredShots);
                    }
                }
            }

            $previousRevisionId = $isStoryboardNode ? $this->currentStoryboardRevisionId($episode) : 0;
            $revision = $this->createStoryboardRevisionFromContent($episode, $run, $targetNode, $contentToSave, $output, [
                'preserve_media_from_revision_id' => $isSingleShotStoryboardUpdate ? $previousRevisionId : 0,
                'changed_shot_index' => $isSingleShotStoryboardUpdate ? $changedShotIndex : 0,
            ]);
            if ($revision instanceof StoryboardRevision) {
                $output['storyboard_revision_id'] = (int) $revision->getAttr('id');
            }
            
            $targetNode->save([
                'status' => 'success',
                'raw_output' => $contentToSave,
                'output_json' => $output,
                'error_message' => '',
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
            $this->syncEpisodeWorkflowStateForRunNode($targetNode, 'success', $output, $contentToSave, '');

            if ($isSingleShotStoryboardUpdate && $revision instanceof StoryboardRevision) {
                $this->invalidateEpisodeWorkflowNodesAfterSingleStoryboardShotUpdate(
                    (int) $run->getAttr('id'),
                    (int) $targetNode->getAttr('sort'),
                    (int) $revision->getAttr('id'),
                    $changedShotIndex,
                );
            } else {
                $this->invalidateEpisodeWorkflowNodesAfter((int) $run->getAttr('id'), (int) $targetNode->getAttr('sort'));
            }

            // 更新任务整体状态
            $this->refreshEpisodeWorkflowRunProgress((int) $run->getAttr('id'));
        });

        $this->clearSeriesCache();

        return $this->serializeEpisode(Episode::with(['shots'])->findOrFail($episodeId), true);
    }

    private function doRegenerateEpisodeStoryboardShot(
        int $episodeId,
        string $nodeId,
        int $shotIndex,
        string $instruction = '',
        array $requestedAssetRefs = [],
    ): array
    {
        $episode = $this->findEpisodeOrFail($episodeId);
        $seriesId = (int) $episode->getAttr('series_id');
        $this->assertSeriesWorkflowEditable($seriesId);
        $this->assertEpisodeAutoExecutionEditable($episode, '当前剧集正在自动执行，请先取消自动执行后再重新生成分镜');

        $workflowId = (int) ($episode->getAttr('workflow_id') ?? 0);
        if ($workflowId <= 0) {
            abort(422, '请先选择剧集工作流');
        }

        $plotInput = trim((string) ($episode->getAttr('plot_input') ?? ''));
        if ($plotInput === '') {
            abort(422, '请先填写剧情简介');
        }

        $workflow = $this->assertEpisodeWorkflow($workflowId);
        $graph = $workflow->getAttr('graph') ?: ['nodes' => [], 'edges' => []];
        if (!is_array($graph)) {
            $graph = ['nodes' => [], 'edges' => []];
        }
        $nodes = $this->topologicalOrder($graph);
        $target = $this->findWorkflowNodeForEpisodeAction($episode, $nodes, $nodeId);
        if ($target === null) {
            abort(404, '当前剧集绑定的工作流中找不到这个节点，请刷新页面后重试');
        }

        $nodeId = (string) ($target['id'] ?? $nodeId);
        $label = trim((string) ($target['label'] ?? $target['data']['label'] ?? $nodeId));
        $kind = trim((string) ($target['data']['kind'] ?? $target['kind'] ?? ''));
        if (!$this->isStoryboardRevisionNode($label, $kind)) {
            abort(422, '只有分镜处理节点支持单镜头 AI 重新生成');
        }

        $run = $this->ensureEpisodeWorkflowRun($episode, $workflow, $nodes);
        $runNode = $this->findWorkflowRunNode((int) $run->getAttr('id'), $target);
        if (!$runNode instanceof WorkflowRunNode) {
            abort(404, '当前分镜节点没有可用的运行记录，请先生成分镜');
        }
        if ((string) $runNode->getAttr('status') === 'running') {
            abort(409, '该分镜节点正在执行中，请等待当前任务完成后再操作');
        }

        $baseRevisionId = $this->currentStoryboardRevisionId($episode);
        if ($baseRevisionId <= 0) {
            abort(422, '需要先执行分镜处理节点，才能重新生成单个镜头');
        }

        $currentShots = $this->storyboardShotsFromRunNodeOutput($runNode, $seriesId);
        if ($currentShots === []) {
            abort(422, '当前分镜节点没有可重新生成的镜头内容');
        }

        // 只接受当前作品资产库中真实存在的引用，避免前端伪造 asset_id 或图片版本。
        $requestedRichNodes = $this->normalizeStoryboardRichNodes(
            array_map(static fn (mixed $ref): array => is_array($ref) ? ['type' => 'asset_ref'] + $ref : [], $requestedAssetRefs),
            $seriesId,
        );
        $requestedAssetRefs = $this->storyboardAssetRefsFromRichNodes($requestedRichNodes);

        $targetOffset = null;
        foreach ($currentShots as $offset => $shot) {
            if ((int) ($shot['index'] ?? 0) === $shotIndex) {
                $targetOffset = $offset;
                break;
            }
        }
        if ($targetOffset === null) {
            abort(422, "没有找到镜头 {$shotIndex}");
        }

        $promptMeta = [];
        $nodePrompt = $this->resolveNodePrompt($target, '', 'episode', $promptMeta);
        if ($nodePrompt === '') {
            abort(422, "节点【{$label}】prompt 为空");
        }

        $modelId = (int) ($target['data']['params']['modelId'] ?? 0);
        $model = $this->resolveTextModelByIdOrDefault($modelId);
        if (!$model instanceof ModelConfig) {
            abort(422, "节点【{$label}】未找到可用的文本模型，请联系管理员配置");
        }

        $episodeSeries = $episode->series;
        $context = array_merge([
            'series_id' => $seriesId,
            'episode_id' => (int) $episode->getAttr('id'),
            'episode_title' => (string) $episode->getAttr('title'),
            'episode_number' => (int) $episode->getAttr('number'),
            'plot_input' => $plotInput,
            'upstream_outputs' => $this->collectEpisodeUpstreamOutputs(
                (int) $run->getAttr('id'),
                $nodes,
                $nodeId,
                $plotInput,
                $seriesId,
            ),
            'asset_library' => $this->buildEpisodeAssetLibrary($seriesId),
            'requested_asset_refs' => $requestedAssetRefs,
            'target_shot' => $currentShots[$targetOffset],
            'previous_shot' => $targetOffset > 0 ? $currentShots[$targetOffset - 1] : null,
            'next_shot' => isset($currentShots[$targetOffset + 1]) ? $currentShots[$targetOffset + 1] : null,
            'user_instruction' => $instruction,
            'all_shots_readonly' => array_map(
                fn (array $shot): array => $this->summarizeStoryboardShotForRegeneration($shot),
                $currentShots,
            ),
            'prompt_source' => $promptMeta,
        ], $this->seriesRegionContext($episodeSeries instanceof Series ? $episodeSeries : null));

        $raw = $this->callChatCompletions($model, $this->buildStoryboardShotRegenerationMessages($label, $nodePrompt, $context), [
            'source' => 'storyboard_shot_regeneration',
            'series_id' => $seriesId,
            'episode_id' => (int) $episode->getAttr('id'),
            'workflow_id' => (int) $workflow->getAttr('id'),
            'workflow_run_id' => (int) $run->getAttr('id'),
            'workflow_run_node_id' => (int) $runNode->getAttr('id'),
            'node_label' => $label,
            'node_id' => $nodeId,
            'shot_index' => $shotIndex,
            'user_instruction' => $instruction,
            'requested_asset_refs' => $requestedAssetRefs,
        ]);

        if ($this->shouldNormalizeStoryboardLookReferences($label)) {
            $raw = $this->normalizeStoryboardLookReferences($raw, $seriesId);
        }

        if ($this->currentStoryboardRevisionIdForEpisodeId($episodeId) !== $baseRevisionId) {
            abort(409, '分镜内容已被其他操作更新，请刷新后重试');
        }

        $replacement = $this->normalizeRegeneratedStoryboardShot(
            $raw,
            $shotIndex,
            $seriesId,
            (string) ($currentShots[$targetOffset]['title'] ?? "镜头 {$shotIndex}"),
        );
        $replacement = $this->ensureRequestedStoryboardAssetRefs($replacement, $requestedAssetRefs, $seriesId);
        $currentShots[$targetOffset] = $replacement;

        $storyboardShots = $this->storyboardShotPayloadForUpdate($currentShots, $seriesId);
        if (count($storyboardShots) !== count($currentShots)) {
            abort(422, '分镜内容不完整，已阻止写入');
        }

        return $this->doUpdateEpisodeWorkflowNode(
            $episodeId,
            $nodeId,
            $this->formatStructuredStoryboardShotsAsText($storyboardShots),
            $storyboardShots,
            'single_shot',
            $shotIndex,
        );
    }

    /**
     * 用户明确选择的资产不能因模型漏写 @ 标记而丢失，否则后续图片/视频节点无法拿到引用。
     * 正常情况下模型会直接输出这些引用；这里只对缺失项补一行结构化引用，避免改写正文语义。
     */
    private function ensureRequestedStoryboardAssetRefs(array $shot, array $requestedAssetRefs, int $seriesId): array
    {
        if ($requestedAssetRefs === []) {
            return $shot;
        }

        $contentText = trim((string) ($shot['content_text'] ?? $shot['description'] ?? ''));
        $richNodes = $this->normalizeStoryboardRichNodes($shot['content_rich_json'] ?? [], $seriesId);
        if ($richNodes === []) {
            $richNodes = $this->storyboardRichNodesFromText($contentText, $seriesId);
        }

        $existingKeys = [];
        foreach ($this->storyboardAssetRefsFromRichNodes($richNodes) as $ref) {
            $existingKeys[$this->storyboardAssetRefKey(
                (int) ($ref['asset_id'] ?? 0),
                isset($ref['asset_image_id']) ? (int) $ref['asset_image_id'] : null,
                (string) ($ref['reference_role'] ?? 'view'),
                isset($ref['asset_image_version_id']) ? (int) $ref['asset_image_version_id'] : null,
            )] = true;
        }

        $missingLabels = [];
        foreach ($requestedAssetRefs as $ref) {
            if (!is_array($ref)) {
                continue;
            }
            $key = $this->storyboardAssetRefKey(
                (int) ($ref['asset_id'] ?? 0),
                isset($ref['asset_image_id']) ? (int) $ref['asset_image_id'] : null,
                (string) ($ref['reference_role'] ?? 'view'),
                isset($ref['asset_image_version_id']) ? (int) $ref['asset_image_version_id'] : null,
            );
            if (isset($existingKeys[$key])) {
                continue;
            }
            $label = trim((string) ($ref['label'] ?? ''));
            if ($label !== '') {
                $missingLabels[] = '@' . ltrim($label, '@');
            }
        }

        if ($missingLabels === []) {
            return $shot;
        }

        $contentText = trim($contentText . "\n引用资产：" . implode(' ', array_values(array_unique($missingLabels))));
        $richNodes = $this->storyboardRichNodesFromText($contentText, $seriesId);
        return [
            ...$shot,
            'content_text' => $contentText,
            'content_rich_json' => $richNodes,
            'asset_refs' => $this->storyboardAssetRefsFromRichNodes($richNodes),
            'description' => $contentText,
        ];
    }

    private function doRerunEpisodeVideoShot(int $episodeId, string $nodeId, int $jobId, string $prompt): array
    {
        $episode = Episode::with(['shots'])->findOrFail($episodeId);
        $this->assertVideoGenerationAllowedForUser($this->seriesOwnerId(null, (int) $episode->getAttr('user_id')));
        $this->assertEpisodeAutoExecutionEditable($episode, '当前剧集正在自动执行，请先取消自动执行后再重跑视频镜头');
        [$run, $runNode, $target, $orderedNodes] = $this->resolveEpisodeRunNodeForAction($episode, $nodeId, 'video');

        if ((string) $runNode->getAttr('kind') !== 'video') {
            abort(409, '当前运行记录不是视频节点，已阻止重生成以保护上游节点');
        }
        $storyboardSort = $this->lastStoryboardNodeSortBefore($orderedNodes, $nodeId);
        if ($storyboardSort > 0 && (int) $runNode->getAttr('sort') <= $storyboardSort) {
            abort(409, '当前视频节点运行记录顺序异常，已阻止重生成以保护上游分镜');
        }

        $targetJob = VideoJob::where('id', $jobId)
            ->where('user_id', $this->effectiveUserId())
            ->where('episode_id', $episodeId)
            ->where('workflow_run_node_id', (int) $runNode->getAttr('id'))
            ->find();
        if (!$targetJob instanceof VideoJob) {
            abort(404, '视频任务不存在或不属于当前节点');
        }
        $currentRevisionId = $this->currentStoryboardRevisionId($episode);
        if ($currentRevisionId <= 0 || (int) ($targetJob->getAttr('storyboard_revision_id') ?? 0) !== $currentRevisionId) {
            abort(422, '这个视频任务属于旧分镜，请先重新生成当前分镜的视频');
        }

        $nodeParams = is_array($target['data']['params'] ?? null) ? $target['data']['params'] : [];
        $model = $this->resolveVideoModelByIdOrDefault((int) ($nodeParams['modelId'] ?? 0));
        if (!$model instanceof ModelConfig) {
            abort(422, '当前视频节点没有可用的视频模型，请先检查节点配置');
        }
        $currentVideoOptions = $this->videoNodeRequestOptions($nodeParams);
        $currentChainShots = (bool) ($nodeParams['chainShots'] ?? true);
        $currentVideoStylePrompt = $this->videoStylePromptFromNodeParams($nodeParams);
        $shotData = $targetJob->getAttr('shot_data_json');
        $rerunShot = is_array($shotData) ? $shotData : [];
        $rerunShot['index'] = (int) ($rerunShot['index'] ?? $targetJob->getAttr('shot_index'));
        if (trim((string) ($rerunShot['description'] ?? '')) === '') {
            $rerunShot['description'] = (string) ($targetJob->getAttr('node_prompt') ?: '');
        }
        $frozenAssets = $targetJob->getAttr('assets_json');
        if (is_array($frozenAssets) && $frozenAssets !== []) {
            $rerunShot['assets'] = $frozenAssets;
        }
        $this->assertSeriesAssetsHaveCoreViewsForVideo(
            (int) $episode->getAttr('series_id'),
            [$rerunShot],
            trim((string) ($target['label'] ?? $target['data']['label'] ?? '视频生成'))
        );

        Db::transaction(function () use ($run, $runNode, $targetJob, $prompt, $model, $currentVideoOptions, $currentChainShots, $currentVideoStylePrompt): void {
            $chainShots = $currentChainShots;
            $targetIndex = (int) $targetJob->getAttr('shot_index');
            $jobs = $this->latestVideoJobsForRunNode((int) $runNode->getAttr('id'));
            $affectedJobIds = [];
            foreach ($jobs as $job) {
                if (!$job instanceof VideoJob) {
                    continue;
                }
                $index = (int) $job->getAttr('shot_index');
                if ((int) $job->getAttr('id') === (int) $targetJob->getAttr('id') || ($chainShots && $index >= $targetIndex)) {
                    $affectedJobIds[] = (int) $job->getAttr('id');
                }
            }
            if ($affectedJobIds === []) {
                $affectedJobIds[] = (int) $targetJob->getAttr('id');
            }

            foreach ($jobs as $job) {
                if (!$job instanceof VideoJob || !in_array((int) $job->getAttr('id'), $affectedJobIds, true)) {
                    continue;
                }
                $this->archiveVideoJobAsShotVersion($job);
                $isTarget = (int) $job->getAttr('id') === (int) $targetJob->getAttr('id');
                $context = $job->getAttr('request_context_json') ?: [];
                if (!is_array($context)) {
                    $context = [];
                }
                unset(
                    $context['final_prompt'],
                    $context['idempotency_key'],
                    $context['task_id'],
                    $context['provider_task_id'],
                    $context['result_endpoint'],
                );
                if ($isTarget) {
                    $context = $this->refreshVideoJobVoiceContext($job, $context);
                    $context['candidate_media'] = true;
                    $context['candidate_media_type'] = 'video';
                    $context['previous_video_url'] = (string) $job->getAttr('video_url');
                    $context['model_config_id'] = (int) $model->getAttr('id');
                    $context['video_options'] = $currentVideoOptions;
                    $context['video_style_prompt'] = $currentVideoStylePrompt;
                }

                $jobData = [
                    'node_prompt' => $isTarget ? $prompt : (string) $job->getAttr('node_prompt'),
                    'status' => $isTarget ? 'queued' : 'stale',
                    'ai_request_log_id' => null,
                    'request_context_json' => $context,
                    'error_message' => $isTarget ? '已按修改后的提示词重新排队' : '前序镜头开启了尾帧衔接，这段视频需要手动重新生成',
                    'started_at' => null,
                    'finished_at' => null,
                ];
                if ($isTarget) {
                    $jobData['model_config_id'] = (int) $model->getAttr('id');
                    $jobData['video_options_json'] = $currentVideoOptions;
                    $jobData['chain_shots'] = $chainShots;
                    $jobData['depends_on_job_id'] = $chainShots ? $job->getAttr('depends_on_job_id') : null;
                    $jobData['video_url'] = '';
                    $jobData['end_frame_url'] = '';
                    // 重排队时同步刷新资产参考图，避免仍携带替换前的卡脸旧图。
                    $frozenAssets = $job->getAttr('assets_json') ?: [];
                    if (!is_array($frozenAssets)) {
                        $frozenAssets = [];
                    }
                    $assetImageRefresh = $this->refreshVideoJobAssetImages(
                        $job,
                        $frozenAssets,
                        (string) $job->getAttr('source_image_url'),
                        (string) $job->getAttr('input_image_url'),
                    );
                    $jobData['assets_json'] = $assetImageRefresh['assets'];
                    $jobData['source_image_url'] = $assetImageRefresh['source_image_url'];
                    $jobData['input_image_url'] = $assetImageRefresh['input_image_url'];
                    $job->setAttr('assets_json', $assetImageRefresh['assets']);
                    $job->setAttr('source_image_url', $assetImageRefresh['source_image_url']);
                    $job->setAttr('input_image_url', $assetImageRefresh['input_image_url']);
                }
                $job->save($jobData);

                $shot = Shot::find((int) $job->getAttr('shot_id'));
                if ($shot instanceof Shot) {
                    $shotData = ['status' => 'pending'];
                    if ($isTarget) {
                        $shotData['status'] = 'generating';
                    }
                    $shot->save($shotData);
                }
            }

            // 单镜头重生成只会使最终输出失效，不能走整节点的下游失效逻辑，
            // 否则一旦运行节点语义映射异常，可能连带清空上游分镜状态。
            $this->invalidateEpisodeOutputNodesAfterVideoShotRerun(
                (int) $run->getAttr('id'),
                (int) $runNode->getAttr('sort'),
            );
            $this->refreshVideoWorkflowProgress((int) $runNode->getAttr('id'));
            Episode::where('id', (int) $targetJob->getAttr('episode_id'))->update(['status' => 'production']);
        });

        $this->clearSeriesCache();

        return $this->serializeEpisode(Episode::with(['shots'])->findOrFail($episodeId), true);
    }

    private function doRerunEpisodeImageShot(int $episodeId, string $nodeId, int $shotId): array
    {
        $episode = Episode::with(['shots'])->findOrFail($episodeId);
        $this->assertEpisodeAutoExecutionEditable($episode, '当前剧集正在自动执行，请先取消自动执行后再重跑图片镜头');
        [$run, $runNode] = $this->resolveEpisodeRunNodeForAction($episode, $nodeId, 'image');

        $shot = Shot::where('id', $shotId)
            ->where('episode_id', $episodeId)
            ->find();
        if (!$shot instanceof Shot) {
            abort(404, '镜头不存在或不属于当前剧集');
        }
        $currentRevisionId = $this->currentStoryboardRevisionId($episode);
        if ($currentRevisionId <= 0 || (int) ($shot->getAttr('storyboard_revision_id') ?? 0) !== $currentRevisionId) {
            abort(422, '这个镜头不属于当前分镜版本，请先重新执行分镜处理节点');
        }

        $input = $runNode->getAttr('input_json') ?: [];
        if (!is_array($input)) {
            $input = [];
        }
        $model = $this->resolveImageModelByIdOrDefault((int) ($input['model_config_id'] ?? 0));
        if (!$model instanceof ModelConfig) {
            abort(422, '未找到可用的图片模型，请联系管理员配置');
        }

        $label = (string) $runNode->getAttr('label') ?: '图片节点';
        $seriesId = (int) $episode->getAttr('series_id');
        $index = (int) $shot->getAttr('index');
        $description = trim((string) $shot->getAttr('desc'));
        if ($description === '') {
            abort(422, '镜头描述为空，无法重生成图片');
        }

        $assets = $this->matchAssetsForShot($seriesId, $description);
        $shotData = [
            'index' => $index,
            'description' => $description,
            'duration' => (string) $shot->getAttr('duration'),
        ];
        $nodePrompt = trim((string) (($input['node']['prompt'] ?? '') ?: '根据分镜内容生成首帧图片。'));
        $prompt = $this->buildFrameImagePrompt($episode, $label, $nodePrompt, $shotData, $assets);

        $shot->save(['status' => 'generating']);
        try {
            $imageUrl = $this->callWorkflowImageGeneration($model, $prompt, $assets, [
                'source' => 'episode_workflow_image_candidate',
                'series_id' => $seriesId,
                'episode_id' => $episodeId,
                'workflow_run_id' => (int) $run->getAttr('id'),
                'workflow_run_node_id' => (int) $runNode->getAttr('id'),
                'node_label' => $label,
                'node_id' => $nodeId,
                'shot_index' => $index,
                'asset_count' => count($assets),
            ]);
        } catch (\Throwable $e) {
            $shot->save(['status' => trim((string) $shot->getAttr('image_url')) !== '' ? 'done' : 'pending']);
            throw $e;
        }

        $this->createShotMediaVersion($shot, 'image', $imageUrl, [
            'workflow_run_node_id' => (int) $runNode->getAttr('id'),
            'model_config_id' => (int) $model->getAttr('id'),
            'prompt' => $prompt,
            'source' => 'rerun',
            'is_selected' => false,
            'ai_request_log_id' => $this->lastAiRequestLogId ?: null,
            'meta_json' => [
                'node_label' => $label,
                'node_id' => $nodeId,
                'shot_index' => $index,
            ],
        ]);
        $shot->save(['status' => 'done']);
        $this->clearSeriesCache();

        return $this->serializeEpisode(Episode::with(['shots'])->findOrFail($episodeId), true);
    }

    private function doSelectEpisodeMediaVersion(int $episodeId, int $versionId): array
    {
        $episode = $this->findEpisodeOrFail($episodeId);
        $this->assertEpisodeAutoExecutionEditable($episode, '当前剧集正在自动执行，暂时不能切换媒体版本');
        // 列表(attachShotMediaVersions)只按 shot_id(+revision) 返回版本，不校验 version.user_id /
        // version.episode_id。选用时若仍强制这两列与当前用户/剧集一致，会在字段漂移
        // （worker 写入 user_id=1、clone/归档未回写 episode_id 等）时出现：
        // UI 能看到 V2，点切换却报「媒体版本不存在或不属于当前剧集」。
        // 权威归属：镜头属于该剧集（剧集权限已由 findEpisodeOrFail 校验）。
        $version = ShotMediaVersion::where('id', $versionId)->find();
        if (!$version instanceof ShotMediaVersion) {
            abort(404, '媒体版本不存在或不属于当前剧集');
        }
        if ((bool) $version->getAttr('orphaned')) {
            abort(422, '未挂载历史成片不能选用为当前结果');
        }

        $shot = Shot::where('id', (int) $version->getAttr('shot_id'))
            ->where('episode_id', $episodeId)
            ->find();
        if (!$shot instanceof Shot) {
            abort(404, '媒体版本不存在或不属于当前剧集');
        }

        $heal = [];
        if ((int) $version->getAttr('episode_id') !== $episodeId) {
            $heal['episode_id'] = $episodeId;
        }
        $seriesId = (int) $episode->getAttr('series_id');
        if ($seriesId > 0 && (int) ($version->getAttr('series_id') ?? 0) !== $seriesId) {
            $heal['series_id'] = $seriesId;
        }
        $ownerId = (int) $episode->getAttr('user_id');
        if ($ownerId > 0 && (int) $version->getAttr('user_id') !== $ownerId) {
            $heal['user_id'] = $ownerId;
        }
        $shotRevisionId = (int) ($shot->getAttr('storyboard_revision_id') ?? 0);
        if ($shotRevisionId > 0 && (int) ($version->getAttr('storyboard_revision_id') ?? 0) <= 0) {
            $heal['storyboard_revision_id'] = $shotRevisionId;
        }
        if ($heal !== []) {
            $version->save($heal);
        }
        $currentRevisionId = $this->currentStoryboardRevisionIdForEpisodeId($episodeId);
        if ($currentRevisionId <= 0
            || (int) ($shot->getAttr('storyboard_revision_id') ?? 0) !== $currentRevisionId
            || (int) ($version->getAttr('storyboard_revision_id') ?? 0) !== $currentRevisionId
        ) {
            abort(422, '这个媒体版本属于旧分镜，不能作为当前结果选用');
        }

        $mediaType = (string) $version->getAttr('media_type');
        $url = trim((string) $version->getAttr('url'));
        if ($url === '') {
            abort(422, '媒体版本没有可用 URL');
        }
        if (!$this->isShotMediaVersionSelectable($shot, $version)) {
            abort(422, '这个媒体版本已随上游内容变更失效，请重新生成当前镜头');
        }

        Db::transaction(function () use ($version, $shot, $mediaType, $url): void {
            if ($mediaType === 'image') {
                ShotMediaVersion::where('shot_id', (int) $shot->getAttr('id'))
                    ->where('media_type', $mediaType)
                    ->update(['is_selected' => 0]);
                $version->save(['is_selected' => 1]);
                $shot->save([
                    'image_url' => $url,
                    'status' => 'done',
                ]);
            } elseif ($mediaType === 'video') {
                $this->selectShotVideoVersion($shot, $version);
                $this->markChainedVideoJobsAfterSelectedVersion($version, $shot);
            } else {
                abort(422, '不支持的媒体类型');
            }

            $runNodeId = (int) ($version->getAttr('workflow_run_node_id') ?? 0);
            $runNode = $runNodeId > 0 ? WorkflowRunNode::find($runNodeId) : null;
            if ($runNode instanceof WorkflowRunNode) {
                $this->invalidateEpisodeWorkflowNodesAfter((int) $runNode->getAttr('run_id'), (int) $runNode->getAttr('sort'));
                $this->refreshEpisodeWorkflowRunProgress((int) $runNode->getAttr('run_id'));
            }
        });

        $this->clearSeriesCache();
        return $this->serializeEpisode(Episode::with(['shots'])->findOrFail($episodeId), true);
    }

    private function isShotMediaVersionSelectable(Shot $shot, ShotMediaVersion $version): bool
    {
        if ((bool) $version->getAttr('orphaned')) {
            return false;
        }

        $shotRevisionId = (int) ($shot->getAttr('storyboard_revision_id') ?? 0);
        $versionRevisionId = (int) ($version->getAttr('storyboard_revision_id') ?? 0);
        $currentRevisionId = $this->currentStoryboardRevisionIdForEpisodeId((int) $shot->getAttr('episode_id'));

        return $shotRevisionId > 0
            && $shotRevisionId === $versionRevisionId
            && $shotRevisionId === $currentRevisionId;
    }

    /**
     * 执行或重新生成单个剧集工作流节点。
     */
    public function runEpisodeWorkflowNode()
    {
        $payload = $this->request->param();
        $id = $this->requireId($payload, '剧集 id 不能为空');
        $nodeId = trim((string) ($payload['node_id'] ?? ''));
        if ($nodeId === '') {
            abort(422, '节点 id 不能为空');
        }
        $promptOverride = trim((string) ($payload['prompt_override'] ?? ''));
        $options = [];
        $shotIndex = (int) ($payload['shot_index'] ?? 0);
        $promptTemplateId = (int) ($payload['prompt_template_id'] ?? 0);
        if ($shotIndex > 0) {
            $options['shot_index'] = $shotIndex;
        }
        if ($promptTemplateId > 0) {
            $options['prompt_template_id'] = $promptTemplateId;
        }

        return successCode($this->doRunEpisodeWorkflowNode($id, $nodeId, $promptOverride, $options));
    }

    /**
     * 异步执行单个剧集节点。
     * Web 请求只入队，真实生成交给 episode-node:worker，避免 PHP-FPM 长请求超时。
     */
    public function runEpisodeWorkflowNodeAsync()
    {
        $payload = $this->request->param();
        $id = $this->requireId($payload, '剧集 id 不能为空');
        $nodeId = trim((string) ($payload['node_id'] ?? ''));
        if ($nodeId === '') {
            abort(422, '节点 id 不能为空');
        }

        $promptOverride = array_key_exists('prompt_override', $payload) ? (string) $payload['prompt_override'] : '';
        $options = [
            'shot_index' => (int) ($payload['shot_index'] ?? 0),
            'prompt_template_id' => (int) ($payload['prompt_template_id'] ?? 0),
        ];

        return successCode($this->doQueueEpisodeWorkflowNode($id, $nodeId, $promptOverride, $options));
    }

    /**
     * 手动取消某个剧集工作流节点，让前端可以解锁并重新执行。
     */
    public function cancelEpisodeWorkflowNode()
    {
        $payload = $this->request->param();
        $id = $this->requireId($payload, '剧集 id 不能为空');
        $nodeId = trim((string) ($payload['node_id'] ?? ''));
        if ($nodeId === '') {
            abort(422, '节点 id 不能为空');
        }

        $episode = Episode::with(['shots'])->findOrFail($id);
        $this->assertEpisodeAutoExecutionEditable($episode, '当前剧集正在自动执行，请使用“取消本集自动执行”');
        [$run, $runNode] = $this->resolveEpisodeRunNodeForAction($episode, $nodeId);
        $cancelledVideoJobs = 0;

        Db::transaction(function () use ($runNode, &$cancelledVideoJobs): void {
            $runNodeId = (int) $runNode->getAttr('id');
            if ((string) $runNode->getAttr('kind') === 'video') {
                $cancelledVideoJobs = $this->cancelVideoJobsForRunNode($runNodeId, false);
            }

            $output = $runNode->getAttr('output_json') ?: [];
            if (!is_array($output)) {
                $output = [];
            }
            $output['status'] = 'cancelled';
            $output['cancelled_at'] = date('Y-m-d H:i:s');
            if ($cancelledVideoJobs > 0) {
                $output['cancelled_video_jobs'] = $cancelledVideoJobs;
            }

            $runNode->save([
                'status' => 'skipped',
                'output_json' => $output,
                'raw_output' => json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '',
                'error_message' => '节点已手动取消，可重新执行',
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
            $this->syncEpisodeWorkflowStateForRunNode(
                $runNode,
                'skipped',
                $output,
                json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '',
                '节点已手动取消，可重新执行',
            );
            RedisCache::bumpVersion('workflow_run:' . (int) $runNode->getAttr('run_id'));
        });

        $this->refreshEpisodeWorkflowRunProgress((int) $run->getAttr('id'));
        $this->clearSeriesCache();

        return successCode([
            'cancelled' => 1,
            'cancelled_video_jobs' => $cancelledVideoJobs,
            'episode' => $this->serializeEpisode(Episode::with(['shots'])->findOrFail($id), true),
        ]);
    }

    /**
     * 预览视频节点会发送给模型的提示词，不创建任务、不请求视频接口。
     */
    public function previewEpisodeVideoPrompts()
    {
        $payload = $this->request->param();
        $id = $this->requireId($payload, '剧集 id 不能为空');
        $nodeId = trim((string) ($payload['node_id'] ?? ''));
        if ($nodeId === '') {
            abort(422, '节点 id 不能为空');
        }

        return successCode($this->doPreviewEpisodeVideoPrompts($id, $nodeId));
    }

    /**
     * 仅初始化本集工作流节点，不自动执行（兼容旧「测试模式」接口；UI 已移除入口）。
     */
    public function prepareEpisodeWorkflow()
    {
        $payload = $this->request->param();
        $id = $this->requireId($payload, '剧集 id 不能为空');

        return successCode($this->doPrepareEpisodeWorkflow($id));
    }

    /**
     * 自动执行整集工作流（已停用）。
     * 保留路由避免旧客户端 404；请改为逐步执行各节点。
     */
    public function runEpisodeWorkflowAuto()
    {
        $payload = $this->request->param();
        $this->requireId($payload, '剧集 id 不能为空');
        abort(410, '整集一键自动执行已停用，请在流程节点中逐步执行。若仍有残留自动任务，请点「取消自动执行」。');
    }

    /**
     * 取消整集自动执行。
     * 已成功产物保留，只取消未完成的自动推进和后台视频任务。
     */
    public function cancelEpisodeWorkflowAuto()
    {
        $payload = $this->request->param();
        $id = $this->requireId($payload, '剧集 id 不能为空');

        return successCode($this->doCancelEpisodeWorkflowAuto($id));
    }

    /**
     * 修改单个镜头的视频提示词，并重新排队该视频任务。
     */
    public function rerunEpisodeVideoShot()
    {
        $payload = $this->request->param();
        $id = $this->requireId($payload, '剧集 id 不能为空');
        $nodeId = trim((string) ($payload['node_id'] ?? ''));
        $jobId = (int) ($payload['job_id'] ?? 0);
        $prompt = trim((string) ($payload['prompt'] ?? ''));
        if ($nodeId === '') {
            abort(422, '节点 id 不能为空');
        }
        if ($jobId <= 0) {
            abort(422, '视频任务 id 不能为空');
        }
        if ($prompt === '') {
            abort(422, '视频提示词不能为空');
        }

        return successCode($this->doRerunEpisodeVideoShot($id, $nodeId, $jobId, $prompt));
    }

    /**
     * 重新生成单个镜头首帧，生成结果先作为候选版本保存。
     */
    public function rerunEpisodeImageShot()
    {
        $payload = $this->request->param();
        $id = $this->requireId($payload, '剧集 id 不能为空');
        $nodeId = trim((string) ($payload['node_id'] ?? ''));
        $shotId = (int) ($payload['shot_id'] ?? 0);
        if ($nodeId === '') {
            abort(422, '节点 id 不能为空');
        }
        if ($shotId <= 0) {
            abort(422, '镜头 id 不能为空');
        }

        return successCode($this->doRerunEpisodeImageShot($id, $nodeId, $shotId));
    }

    /**
     * 选用某个图片/视频候选版本作为当前镜头结果。
     */
    public function selectEpisodeMediaVersion()
    {
        $payload = $this->request->param();
        $id = $this->requireId($payload, '剧集 id 不能为空');
        $versionId = (int) ($payload['version_id'] ?? 0);
        if ($versionId <= 0) {
            abort(422, '媒体版本 id 不能为空');
        }

        return successCode($this->doSelectEpisodeMediaVersion($id, $versionId));
    }

    /**
     * 取消某个剧集视频节点的后台视频任务。
     */
    public function cancelEpisodeVideoJobs()
    {
        $payload = $this->request->param();
        $id = $this->requireId($payload, '剧集 id 不能为空');
        $nodeId = trim((string) ($payload['node_id'] ?? ''));
        if ($nodeId === '') {
            abort(422, '节点 id 不能为空');
        }

        $episode = Episode::with(['shots'])->findOrFail($id);
        $this->assertEpisodeAutoExecutionEditable($episode, '当前剧集正在自动执行，请使用“取消本集自动执行”');
        [$run, $runNode] = $this->resolveEpisodeRunNodeForAction($episode, $nodeId, 'video');

        $cancelled = $this->cancelVideoJobsForRunNode((int) $runNode->getAttr('id'), true);
        $this->refreshEpisodeWorkflowRunProgress((int) $run->getAttr('id'));
        $this->clearSeriesCache();

        return successCode([
            'cancelled' => $cancelled,
            'episode' => $this->serializeEpisode(Episode::with(['shots'])->findOrFail($id), true),
        ]);
    }

    /**
     * 取消单个视频镜头任务（仅 queued/blocked/running；已成功镜头不动）。
     * 若开启镜头串联，会一并取消依赖本任务的下游未完成任务，避免永远卡在「等待上一段」。
     */
    public function cancelEpisodeVideoShot()
    {
        $payload = $this->request->param();
        $id = $this->requireId($payload, '剧集 id 不能为空');
        $nodeId = trim((string) ($payload['node_id'] ?? ''));
        $jobId = (int) ($payload['job_id'] ?? 0);
        if ($nodeId === '') {
            abort(422, '节点 id 不能为空');
        }
        if ($jobId <= 0) {
            abort(422, '视频任务 id 不能为空');
        }

        $episode = Episode::with(['shots'])->findOrFail($id);
        [$run, $runNode] = $this->resolveEpisodeRunNodeForAction($episode, $nodeId, 'video');

        $job = VideoJob::where('id', $jobId)
            ->where('episode_id', $id)
            ->where('workflow_run_node_id', (int) $runNode->getAttr('id'))
            ->find();
        if (!$job instanceof VideoJob) {
            abort(404, '视频任务不存在或不属于当前节点');
        }

        $status = (string) $job->getAttr('status');
        if (!in_array($status, ['queued', 'blocked', 'running'], true)) {
            abort(422, '只有排队中、等待中或生成中的镜头可以取消；已成功的镜头不会被取消。');
        }

        $cancelled = $this->cancelVideoJobAndDependents($job, '已取消本镜头排队（其他已完成镜头保留）');
        $this->refreshVideoWorkflowProgress((int) $runNode->getAttr('id'), '已取消本镜头排队');
        $this->refreshEpisodeWorkflowRunProgress((int) $run->getAttr('id'));
        $this->clearSeriesCache();

        return successCode([
            'cancelled' => $cancelled,
            'job_id' => $jobId,
            'shot_index' => (int) $job->getAttr('shot_index'),
            'episode' => $this->serializeEpisode(Episode::with(['shots'])->findOrFail($id), true),
        ]);
    }

    /**
     * 运行剧本工作流。
     * 从 JSON 请求体读取 id，按剧本工作流执行结构拆解、剧集规划、资产提取，并写入剧集和资产。
     */
    public function runSeriesWorkflow()
    {
        $payload = $this->request->param();
        $id = $this->requireId($payload, '剧本 id 不能为空');

        // 试验阶段默认异步排队，避免 HTTP 长时间阻塞；显式 sync=1 时仍走同步执行。
        $sync = $payload['sync'] ?? false;
        $forceSync = $sync === true || $sync === 1 || $sync === '1' || $sync === 'true';
        if (!$forceSync) {
            $this->assertSeriesWorkflowEditable($id);
            $series = $this->findSeriesOrFail($id);
            $blockingRun = WorkflowRuntime::findBlockingSeriesRun($id);
            if ($blockingRun instanceof WorkflowRun) {
                abort(423, '该剧本已有未完成的剧本工作流任务，请先查看进度或处理失败任务');
            }
            $runPayload = $payload;
            if (!isset($runPayload['workflow_id']) || (int) $runPayload['workflow_id'] <= 0) {
                $boundWorkflowId = (int) ($series->getAttr('series_workflow_id') ?? 0);
                if ($boundWorkflowId > 0) {
                    $runPayload['workflow_id'] = $boundWorkflowId;
                }
            }
            $storedSource = trim((string) ($series->getAttr('source_text') ?? ''));
            $sourceText = $this->preferFullSeriesSourceText(
                trim((string) ($runPayload['source_text'] ?? '')),
                $storedSource
            );
            if ($sourceText === '') {
                $description = trim((string) ($series->getAttr('description') ?? ''));
                $title = trim((string) ($series->getAttr('title') ?? ''));
                $sourceText = $description !== '' ? $description : $title;
            }
            $runPayload['source_text'] = $sourceText;
            $run = $this->createWorkflowRunForSeries($id, $runPayload);
            return successCode([
                'series_id' => $id,
                'workflow_run' => $this->serializeWorkflowRun($run),
                'queued' => true,
            ]);
        }

        return successCode($this->doRunSeriesWorkflow($id, $payload));
    }

    /**
     * 从请求体读取必填 id。
     * 所有接口参数统一走 JSON body，不再从 URL path 接收 id。
     */
    private function requireId(array $payload, string $message, string $field = 'id'): int
    {
        $id = (int) ($payload[$field] ?? 0);
        if ($id <= 0) {
            abort(422, $message);
        }

        return $id;
    }

    private function effectiveUserId(?Series $series = null): int
    {
        if ($this->authUser !== null) {
            return $this->currentUserId();
        }
        if ($this->runtimeUserId > 0) {
            return $this->runtimeUserId;
        }
        if ($series instanceof Series) {
            $userId = (int) $series->getAttr('user_id');
            if ($userId > 0) {
                return $userId;
            }
        }
        return 1;
    }

    private function seriesOwnerId(?Series $series = null, int $fallback = 0): int
    {
        if ($series instanceof Series) {
            $userId = (int) $series->getAttr('user_id');
            if ($userId > 0) {
                return $userId;
            }
        }
        return $fallback > 0 ? $fallback : $this->effectiveUserId($series);
    }

    private function isVideoGenerationRestrictedUserId(int $userId): bool
    {
        return in_array($userId, self::VIDEO_GENERATION_RESTRICTED_USER_IDS, true);
    }

    private function videoGenerationRestrictedMessage(): string
    {
        return '当前账号为演示/测试账号，已禁用视频生成；文本、图片和分镜节点仍可正常使用。';
    }

    private function assertVideoGenerationAllowedForUser(int $userId): void
    {
        if ($this->isVideoGenerationRestrictedUserId($userId)) {
            abort(403, $this->videoGenerationRestrictedMessage());
        }
    }

    /**
     * 视频生成前检查被引用资产是否具备可用参考。
     * 场景/道具仍要求核心视图；写实人物要求已入库的造型，不再因缺核心图挡片。
     *
     * @param array<int, array<string, mixed>> $readyShots
     */
    private function assertSeriesAssetsHaveCoreViewsForVideo(int $seriesId, array $readyShots, string $nodeLabel): void
    {
        $missing = $this->listAssetsMissingCoreViewsForVideo($seriesId, $readyShots);
        if ($missing === []) {
            return;
        }

        $names = [];
        foreach ($missing as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            $type = trim((string) ($row['type'] ?? ''));
            $reason = trim((string) ($row['reason'] ?? ''));
            $label = $name !== ''
                ? ($type !== '' ? "{$name}（{$type}）" : $name)
                : ('#' . (int) ($row['id'] ?? 0));
            $names[] = $reason !== '' ? "{$label}：{$reason}" : $label;
        }

        abort(
            422,
            "节点【{$nodeLabel}】无法生成视频：以下资产缺少可用参考，请先完成造型、真人检测或核心图后再试："
            . implode('、', $names)
        );
    }

    /**
     * 只检查当前视频/分镜实际引用的本剧资产。别人分享过来的资产可以选用，但不能挡住本剧出片。
     * 未解析出引用时不回退扫描整部作品资产库，避免新剧集未就绪资产挡住旧剧集出片。
     *
     * @param array<int, array<string, mixed>> $readyShots
     * @return array<int, array{id:int,name:string,type:string,reason?:string}>
     */
    private function listAssetsMissingCoreViewsForVideo(int $seriesId, array $readyShots): array
    {
        $referencedIds = [];
        $referencedLooks = [];
        foreach ($readyShots as $shotData) {
            if (!is_array($shotData)) {
                continue;
            }
            $compiled = $this->compileStoryboardShotForVideo(
                $seriesId,
                $shotData,
                (string) ($shotData['description'] ?? $shotData['content_text'] ?? '')
            );
            foreach (($compiled['assets'] ?? []) as $asset) {
                if (!is_array($asset)) {
                    continue;
                }
                $assetId = (int) ($asset['id'] ?? 0);
                $assetSeriesId = (int) ($asset['series_id'] ?? 0);
                if ($assetId > 0 && ($assetSeriesId === 0 || $assetSeriesId === $seriesId)) {
                    $referencedIds[$assetId] = true;
                    if (strtolower(trim((string) ($asset['reference_role'] ?? 'view'))) === 'look') {
                        $imageId = (int) ($asset['asset_image_id'] ?? 0);
                        $referencedLooks[] = [
                            'asset_id' => $assetId,
                            'asset_image_id' => $imageId,
                            'name' => (string) ($asset['name'] ?? ''),
                            'variant_name' => (string) ($asset['variant_name'] ?? ''),
                        ];
                    }
                }
            }
        }

        $idsToEnforce = VideoReferencedAssetGate::assetIdsToEnforce($referencedIds);
        if ($idsToEnforce === []) {
            return [];
        }

        $assets = Asset::with(['images'])
            ->where('series_id', $seriesId)
            ->whereIn('id', $idsToEnforce)
            ->order(['sort' => 'asc', 'id' => 'asc'])
            ->select();
        $visualStyle = $this->seriesVisualStyleForId($seriesId);

        $missing = [];
        foreach ($assets as $asset) {
            if (!$asset instanceof Asset) {
                continue;
            }
            $type = (string) $asset->getAttr('type');
            $assetId = (int) $asset->getAttr('id');
            if ($type === 'character' && $this->assetLookService()->needsLookToapisAvatar($visualStyle)) {
                $reason = $this->realisticCharacterVideoReadinessReason($asset, $referencedLooks);
                if ($reason === '') {
                    continue;
                }
                $missing[] = [
                    'id' => $assetId,
                    'name' => (string) $asset->getAttr('name'),
                    'type' => $type,
                    'reason' => $reason,
                ];
                continue;
            }
            if ($this->assetHasCompletedCoreView($asset)) {
                continue;
            }
            $missing[] = [
                'id' => $assetId,
                'name' => (string) $asset->getAttr('name'),
                'type' => $type,
                'reason' => '缺少核心视图',
            ];
        }

        return $missing;
    }

    private function assetHasCompletedCoreView(Asset $asset): bool
    {
        foreach ($asset->images as $image) {
            if (!$image instanceof \app\model\AssetImage) {
                continue;
            }
            if ((string) $image->getAttr('view_type') !== 'main') {
                continue;
            }
            if (trim((string) $image->getAttr('url')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array{asset_id:int,asset_image_id:int,name:string,variant_name:string}> $referencedLooks
     */
    private function realisticCharacterVideoReadinessReason(Asset $asset, array $referencedLooks): string
    {
        $assetId = (int) $asset->getAttr('id');
        $looksById = [];
        $hasLookUrl = false;
        $hasActiveLook = false;
        foreach ($asset->images as $image) {
            if (!$image instanceof AssetImage) {
                continue;
            }
            if (strtolower(trim((string) ($image->getAttr('reference_role') ?? 'view'))) !== 'look') {
                continue;
            }
            $looksById[(int) $image->getAttr('id')] = $image;
            if (trim((string) $image->getAttr('url')) === '') {
                continue;
            }
            $hasLookUrl = true;
            if (ToapisPrivateAvatarService::isLookAvatarActive(
                (string) ($image->getAttr('toapis_status') ?? ''),
                (string) ($image->getAttr('toapis_asset_url') ?? ''),
            )) {
                $hasActiveLook = true;
            }
        }

        $specific = [];
        foreach ($referencedLooks as $look) {
            if ((int) ($look['asset_id'] ?? 0) === $assetId) {
                $specific[] = $look;
            }
        }

        if ($specific !== []) {
            foreach ($specific as $look) {
                $imageId = (int) ($look['asset_image_id'] ?? 0);
                $label = $this->lookVideoGateLabel($asset, $look);
                if ($imageId <= 0) {
                    if ($hasActiveLook) {
                        continue;
                    }
                    return $hasLookUrl
                        ? "{$label}尚未完成真人检测，请到资产页点击「真人检测」"
                        : '缺少可用造型图';
                }
                $image = $looksById[$imageId] ?? null;
                if (!$image instanceof AssetImage || trim((string) $image->getAttr('url')) === '') {
                    return "{$label}缺少造型图，请先生成造型";
                }
                if (!ToapisPrivateAvatarService::isLookAvatarActive(
                    (string) ($image->getAttr('toapis_status') ?? ''),
                    (string) ($image->getAttr('toapis_asset_url') ?? ''),
                )) {
                    return "{$label}尚未完成真人检测，请到资产页点击「真人检测」";
                }
            }

            return '';
        }

        if ($hasActiveLook) {
            return '';
        }
        if ($hasLookUrl) {
            return '人物造型尚未完成真人检测，请到资产页对该造型点击「真人检测」';
        }

        return '缺少可用造型图';
    }

    /**
     * @param array{asset_id?:int,asset_image_id?:int,name?:string,variant_name?:string} $look
     */
    private function lookVideoGateLabel(Asset $asset, array $look): string
    {
        $character = trim((string) $asset->getAttr('name'));
        $variant = trim((string) ($look['variant_name'] ?? ''));
        if ($variant !== '') {
            return "{$character}（{$variant}）";
        }
        $name = trim((string) ($look['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        return $character !== '' ? "{$character}的人物造型" : '人物造型';
    }

    private function findSeriesOrFail(int $id): Series
    {
        $query = Series::where('id', $id);
        if ($this->authUser !== null) {
            $query->where('user_id', $this->currentUserId());
        }
        $series = $query->find();
        if (!$series instanceof Series) {
            abort(404, '剧本不存在');
        }
        return $series;
    }

    private function findEpisodeOrFail(int $id, bool $withShots = false): Episode
    {
        $episode = $withShots
            ? Episode::with(['shots'])->find($id)
            : Episode::find($id);
        if (!$episode instanceof Episode) {
            abort(404, '剧集不存在');
        }
        if ($this->authUser !== null) {
            $series = Series::where('id', (int) $episode->getAttr('series_id'))
                ->where('user_id', $this->currentUserId())
                ->find();
            if (!$series instanceof Series) {
                abort(404, '剧集不存在');
            }
        }
        return $episode;
    }

    /**
     * 执行剧本列表查询。
     */
    private function doList(array $query = []): array
    {
        $version = RedisCache::version('series');
        $userId = $this->effectiveUserId();
        $scope = in_array($query['scope'] ?? '', ['shared', 'all'], true) ? (string) $query['scope'] : 'mine';
        return RedisCache::remember("series:list:{$this->currentUserCacheSuffix()}:v{$version}:sc{$scope}", 120, function () use ($userId, $scope): array {
            $visibleIds = $this->visibleSeriesIds($userId, $scope);
            if ($visibleIds === []) {
                return [];
            }
            $builder = Series::with(['episodes'])
                ->order('id', 'desc')
                ->whereIn('id', $visibleIds);

            $seriesList = $builder->select();
            $ownerNames = [];
            if ($scope !== 'mine' && count($seriesList) > 0) {
                $ownerIds = array_values(array_unique(array_map(
                    static fn (Series $series): int => (int) $series->getAttr('user_id'),
                    $seriesList->all()
                )));
                $ownerNames = User::whereIn('id', $ownerIds)->column('display_name', 'id');
            }

            return $seriesList
                ->map(function (Series $series) use ($scope, $ownerNames): array {
                    $row = $this->serializeSeries($series);
                    if ($scope !== 'mine') {
                        $ownerId = (int) $series->getAttr('user_id');
                        $row['owner_user_id'] = $ownerId;
                        $row['owner_name'] = $ownerNames[$ownerId] ?? '';
                    }
                    return $row;
                })
                ->toArray();
        });
    }

    /**
     * 速创等只读场景需要按可见范围列作品：自己的作品、别人整部分享的作品、
     * 以及别人单独分享资产时该资产所属的作品。
     *
     * @return int[]
     */
    private function visibleSeriesIds(int $userId, string $scope): array
    {
        $ownIds = $scope === 'shared'
            ? []
            : array_map('intval', Series::where('user_id', $userId)->column('id'));

        if ($scope === 'mine') {
            return $ownIds;
        }

        $sharedBySeries = array_map('intval', Db::name('series_shares')
            ->whereIn('shared_with_user_id', [0, $userId])
            ->column('series_id'));

        $sharedByAsset = array_map('intval', Db::name('asset_shares')
            ->alias('sh')
            ->join('assets a', 'a.id = sh.asset_id')
            ->where('a.user_id', '<>', $userId)
            ->whereIn('sh.shared_with_user_id', [0, $userId])
            ->distinct(true)
            ->column('a.series_id'));

        $sharedIds = array_values(array_unique(array_filter(array_merge($sharedBySeries, $sharedByAsset))));
        if ($sharedIds !== []) {
            $sharedIds = array_map('intval', Series::whereIn('id', $sharedIds)
                ->where('user_id', '<>', $userId)
                ->column('id'));
        }
        if ($scope === 'shared') {
            return $sharedIds;
        }

        return array_values(array_unique(array_filter(array_merge($ownIds, $sharedIds))));
    }

    /**
     * 执行剧本创建。
     * 校验标题不能为空，并保存可选的剧本工作流绑定。
     */
    private function doCreateSeries(array $payload): array
    {
        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') {
            abort(422, '请输入剧本名称');
        }

        $visualStyle = $this->normalizeVisualStyle((string) ($payload['visual_style'] ?? 'realistic'));
        $description = trim((string) ($payload['description'] ?? ''));
        $sourceText = trim((string) ($payload['source_text'] ?? ''));
        // 兼容旧前端：若未单独传 source_text，但 description 超长，则视为正文。
        if ($sourceText === '' && mb_strlen($description) > 1000) {
            $sourceText = $description;
            $description = mb_substr($description, 0, 200);
        } elseif ($sourceText !== '' && $description === '') {
            $description = mb_substr(preg_replace('/\s+/u', ' ', $sourceText) ?? $sourceText, 0, 200);
        } else {
            $description = mb_substr($description, 0, 1000);
        }

        $series = new Series();
        $series->save([
            'user_id' => $this->effectiveUserId(),
            'title' => $title,
            'description' => $description,
            'source_text' => $sourceText !== '' ? $sourceText : null,
            'visual_style' => $visualStyle,
            'visual_style_variant' => $this->normalizeVisualStyleVariant($visualStyle, (string) ($payload['visual_style_variant'] ?? '')),
            'region' => $this->normalizeSeriesRegion((string) ($payload['region'] ?? 'china')),
            'series_workflow_id' => isset($payload['series_workflow_id']) && $payload['series_workflow_id'] !== ''
                ? (int) $payload['series_workflow_id']
                : null,
        ]);
        $this->clearSeriesCache();
        $this->writeAdminOperationLog([
            'action' => 'series.create',
            'target_type' => 'series',
            'target_id' => (int) $series->getAttr('id'),
            'target_name_snapshot' => $title,
            'series_id' => (int) $series->getAttr('id'),
            'result' => 'success',
            'before_json' => [],
            'after_json' => $series->toArray(),
            'meta_json' => [
                'route' => 'series.create',
            ],
        ]);

        return $series->toArray();
    }

    /**
     * 判断新建剧本时是否立即执行整剧工作流。
     * 前端工作流创建模式会传 run_workflow=true，避免再额外调用 run-workflow 接口。
     */
    private function shouldRunWorkflowOnCreate(array $payload): bool
    {
        $flag = $payload['run_workflow'] ?? false;
        return $flag === true || $flag === 1 || $flag === '1' || $flag === 'true';
    }

    /**
     * 新建剧本并创建异步工作流任务。
     * HTTP 请求只负责落库任务，后台 worker 再执行 AI 和写入剧集/资产。
     */
    private function doCreateSeriesAndQueueWorkflow(array $payload): array
    {
        return Db::transaction(function () use ($payload): array {
            $series = $this->doCreateSeries($payload);
            $seriesId = (int) ($series['id'] ?? 0);
            if ($seriesId <= 0) {
                abort(500, '剧本创建失败');
            }

            $run = $this->createWorkflowRunForSeries($seriesId, $payload);
            $series['workflow_run'] = $this->serializeWorkflowRun($run);

            return $series;
        });
    }

    /**
     * 创建剧本工作流任务。
     * 保存任务参数，并按当前 workflow graph 初始化节点状态，供前端展示进度。
     */
    private function createWorkflowRunForSeries(int $seriesId, array $payload): WorkflowRun
    {
        $series = $this->findSeriesOrFail($seriesId);
        $runPayload = $payload;
        if (!isset($runPayload['workflow_id']) && isset($payload['series_workflow_id'])) {
            $runPayload['workflow_id'] = $payload['series_workflow_id'];
        }

        $workflow = $this->resolveSeriesWorkflow($series, $runPayload);
        $storedSource = trim((string) ($series->getAttr('source_text') ?? ''));
        $sourceText = $this->preferFullSeriesSourceText(
            trim((string) ($runPayload['source_text'] ?? '')),
            $storedSource
        );
        $runPayload['source_text'] = $sourceText;
        $sourceFileToken = trim((string) ($runPayload['source_file_token'] ?? ''));
        if ($sourceText === '' && $sourceFileToken === '') {
            abort(422, '缺少剧本原文输入');
        }

        $episodeWorkflowId = $this->resolveEpisodeWorkflowIdForSeriesRun($runPayload);
        $targetEpisodeCount = $this->resolveUserTargetEpisodeCount($runPayload);

        $run = new WorkflowRun();
        $userId = $this->seriesOwnerId($series);
        $run->save([
            'user_id' => $userId,
            'series_id' => $seriesId,
            'workflow_id' => (int) $workflow->getAttr('id'),
            'episode_workflow_id' => $episodeWorkflowId,
            'status' => 'queued',
            'source_text' => $sourceText,
            'target_episode_count' => $targetEpisodeCount,
            'payload_json' => $runPayload,
            'result_json' => [],
            'progress' => 0,
            'current_node_label' => '',
            'error_message' => '',
        ]);
        WorkflowRuntime::rememberSeriesRun($run);
        $this->clearSeriesCache();

        $graph = $workflow->getAttr('graph') ?: ['nodes' => [], 'edges' => []];
        if (!is_array($graph)) {
            $graph = ['nodes' => [], 'edges' => []];
        }
        $orderedNodes = $this->topologicalOrder($graph);
        foreach ($orderedNodes as $index => $node) {
            if (!is_array($node)) {
                continue;
            }
            WorkflowRunNode::create([
                'user_id' => $userId,
                'run_id' => (int) $run->getAttr('id'),
                'workflow_node_id' => (string) ($node['id'] ?? ''),
                'label' => trim((string) ($node['label'] ?? $node['data']['label'] ?? '')),
                'kind' => trim((string) ($node['data']['kind'] ?? '')),
                'sort' => ($index + 1) * 10,
                'status' => 'queued',
                'depends_on_json' => [],
                'input_json' => [],
                'output_json' => [],
                'raw_output' => '',
                'error_message' => '',
                'duration_ms' => 0,
            ]);
        }

        return $run;
    }

    /**
     * 序列化异步工作流任务。
     * 返回给前端用于轮询展示状态。
     */
    private function serializeWorkflowRun(WorkflowRun $run): array
    {
        return [
            'id' => (int) $run->getAttr('id'),
            'series_id' => (int) $run->getAttr('series_id'),
            'workflow_id' => (int) $run->getAttr('workflow_id'),
            'episode_workflow_id' => $run->getAttr('episode_workflow_id') === null
                ? null
                : (int) $run->getAttr('episode_workflow_id'),
            'status' => (string) $run->getAttr('status'),
            'progress' => (int) $run->getAttr('progress'),
            'current_node_label' => (string) $run->getAttr('current_node_label'),
            'error_message' => (string) $run->getAttr('error_message'),
            'result_json' => $run->getAttr('result_json') ?: [],
            'create_time' => $run->getAttr('create_time'),
            'update_time' => $run->getAttr('update_time'),
        ];
    }

    /**
     * 执行剧本更新。
     * 更新基础信息和 series_workflow_id。
     */
    private function doUpdateSeries(int $id, array $payload): array
    {
        $this->assertSeriesWorkflowEditable($id);
        $series = $this->findSeriesOrFail($id);
        $before = $series->toArray();
        $visualStyle = array_key_exists('visual_style', $payload)
            ? $this->normalizeVisualStyle((string) $payload['visual_style'])
            : $this->normalizeVisualStyle((string) ($series->getAttr('visual_style') ?? 'realistic'));
        $series->save([
            'title' => trim($payload['title']),
            'description' => $payload['description'] ?? '',
            'visual_style' => $visualStyle,
            'visual_style_variant' => array_key_exists('visual_style_variant', $payload)
                ? $this->normalizeVisualStyleVariant($visualStyle, (string) $payload['visual_style_variant'])
                : $this->normalizeVisualStyleVariant($visualStyle, (string) ($series->getAttr('visual_style_variant') ?? '')),
            'region' => array_key_exists('region', $payload)
                ? $this->normalizeSeriesRegion((string) $payload['region'])
                : (string) ($series->getAttr('region') ?? 'china'),
            'series_workflow_id' => array_key_exists('series_workflow_id', $payload)
                ? (($payload['series_workflow_id'] === '' || $payload['series_workflow_id'] === null) ? null : (int) $payload['series_workflow_id'])
                : $series->getAttr('series_workflow_id'),
        ]);
        $this->clearSeriesCache();
        $this->writeAdminOperationLog([
            'action' => 'series.update',
            'target_type' => 'series',
            'target_id' => $id,
            'target_name_snapshot' => (string) $series->getAttr('title'),
            'series_id' => $id,
            'result' => 'success',
            'before_json' => $before,
            'after_json' => $series->toArray(),
            'meta_json' => [
                'route' => 'series.update',
            ],
        ]);

        return $series->toArray();
    }

    /**
     * 执行剧本删除事务。
     * 先删除异步工作流任务、资产任务和剧集镜头，再删除剧集，最后删除剧本。
     */
    private function doDeleteSeries(int $id): void
    {
        Db::transaction(function () use ($id) {
            $series = $this->findSeriesOrFail($id);
            $before = $series->toArray();
            $seriesName = (string) $series->getAttr('title');
            $ownerId = $this->seriesOwnerId($series);
            WorkflowRun::where('series_id', $id)->where('user_id', $ownerId)->delete();
            $assetIds = Asset::where('series_id', $id)->where('user_id', $ownerId)->column('id');
            if ($assetIds !== []) {
                $imageIds = AssetImage::whereIn('asset_id', $assetIds)->column('id');
                AssetImageJob::whereIn('asset_id', $assetIds)->delete();
                AssetImageVersion::whereIn('asset_id', $assetIds)->delete();
                if ($imageIds !== []) {
                    AssetImageJob::whereIn('asset_image_id', $imageIds)->delete();
                    AssetImageVersion::whereIn('asset_image_id', $imageIds)->delete();
                }
                AssetImage::whereIn('asset_id', $assetIds)->delete();
                AssetShare::whereIn('asset_id', $assetIds)->delete();
                Asset::destroy($assetIds);
            }
            SeriesShare::where('series_id', $id)->delete();
            foreach ($series->episodes as $episode) {
                EpisodeWorkflowNodeState::where('episode_id', (int) $episode->id)->delete();
                ShotMediaVersion::where('episode_id', (int) $episode->id)->delete();
                VideoJob::where('episode_id', (int) $episode->id)->delete();
                StoryboardRevision::where('episode_id', (int) $episode->id)->delete();
                Shot::where('episode_id', $episode->id)->delete();
                $episode->delete();
            }
            $series->delete();
            $this->writeAdminOperationLog([
                'action' => 'series.delete',
                'target_type' => 'series',
                'target_id' => $id,
                'target_name_snapshot' => $seriesName,
                'series_id' => $id,
                'result' => 'success',
                'before_json' => $before,
                'after_json' => [],
                'meta_json' => [
                    'route' => 'series.delete',
                ],
            ]);
        });
        WorkflowRuntime::clearSeriesRun($id);
        $this->clearSeriesCache();
        $this->clearAssetCache();
    }

    /**
     * 执行剧集创建。
     * 校验集号唯一和剧情简介，写入 episode 草稿记录。
     */
    private function doCreateEpisode(int $seriesId, array $payload): array
    {
        $this->assertSeriesWorkflowEditable($seriesId);
        $series = $this->findSeriesOrFail($seriesId);
        $workflowId = isset($payload['workflow_id']) && $payload['workflow_id'] !== ''
            ? (int) $payload['workflow_id']
            : null;
        if ($workflowId !== null) {
            $this->assertEpisodeWorkflow($workflowId);
        }

        $number = max(1, (int) ($payload['number'] ?? 1));
        $this->assertEpisodeNumberAvailable($seriesId, $number);

        $plotInput = trim((string) ($payload['plot_input'] ?? ''));
        if ($plotInput === '') {
            abort(422, '请输入剧情简介');
        }

        PromoVideoSegmentConfig::ensureSchema();
        $promoSegmentCount = null;
        try {
            $promoSegmentCount = PromoVideoSegmentConfig::optional($payload['promo_segment_count'] ?? null);
        } catch (\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        $episode = new Episode();
        $episode->save([
            'user_id' => $this->seriesOwnerId($series),
            'series_id' => $seriesId,
            'number' => $number,
            'title' => trim($payload['title'] ?? '未命名剧集'),
            'status' => 'draft',
            'workflow_id' => $workflowId,
            'plot_input' => $plotInput,
            'promo_segment_count' => $promoSegmentCount,
        ]);
        $this->clearSeriesCache();
        $this->writeAdminOperationLog([
            'action' => 'episode.create',
            'target_type' => 'episode',
            'target_id' => (int) $episode->getAttr('id'),
            'target_name_snapshot' => (string) $episode->getAttr('title'),
            'series_id' => $seriesId,
            'episode_id' => (int) $episode->getAttr('id'),
            'result' => 'success',
            'before_json' => [],
            'after_json' => $episode->toArray(),
            'meta_json' => [
                'route' => 'episodes.create',
            ],
        ]);

        return $this->serializeEpisode($episode);
    }

    /**
     * 执行剧集更新。
     * 处理基础字段更新；剧集开始执行前允许调整工作流，开始执行后锁定。
     */
    private function doUpdateEpisode(int $id, array $payload): array
    {
        $episode = $this->findEpisodeOrFail($id);
        $before = $episode->toArray();
        $this->assertSeriesWorkflowEditable((int) $episode->getAttr('series_id'));
        $this->assertEpisodeAutoExecutionEditable($episode, '当前剧集正在自动执行，请先取消自动执行后再修改');
        $data = [];
        $plotInputChanged = false;
        if (isset($payload['title'])) {
            $data['title'] = trim($payload['title']);
        }
        if (isset($payload['number'])) {
            $number = max(1, (int) $payload['number']);
            $this->assertEpisodeNumberAvailable((int) $episode->getAttr('series_id'), $number, $id);
            $data['number'] = $number;
        }
        if (isset($payload['status'])) {
            $data['status'] = $payload['status'];
        }
        if (array_key_exists('workflow_id', $payload)) {
            $nextWorkflowId = ($payload['workflow_id'] === '' || $payload['workflow_id'] === null)
                ? null
                : (int) $payload['workflow_id'];
            $currentWorkflowId = $episode->getAttr('workflow_id') === null
                ? null
                : (int) $episode->getAttr('workflow_id');

            if ($this->isEpisodeWorkflowLocked($episode) && $nextWorkflowId !== $currentWorkflowId) {
                abort(422, '剧集已开始执行，工作流不允许更换');
            }
            if ($nextWorkflowId !== null && $nextWorkflowId !== $currentWorkflowId) {
                $this->assertEpisodeWorkflow($nextWorkflowId);
                $data['workflow_id'] = $nextWorkflowId;
            }
        }
        if (isset($payload['plot_input'])) {
            $nextPlotInput = (string) $payload['plot_input'];
            $plotInputChanged = $nextPlotInput !== (string) ($episode->getAttr('plot_input') ?? '');
            $data['plot_input'] = $nextPlotInput;
        }

        Db::transaction(function () use ($episode, $data, $plotInputChanged): void {
            $episode->save($data);

            if (!$plotInputChanged) {
                return;
            }

            $run = $this->findLatestEpisodeWorkflowRun($episode);
            if (!$run instanceof WorkflowRun) {
                return;
            }

            $this->invalidateEpisodeWorkflowNodesAfter((int) $run->getAttr('id'), 0);
            $this->refreshEpisodeWorkflowRunProgress((int) $run->getAttr('id'));
        });
        $this->clearSeriesCache();
        $this->writeAdminOperationLog([
            'action' => 'episode.update',
            'target_type' => 'episode',
            'target_id' => $id,
            'target_name_snapshot' => (string) $episode->getAttr('title'),
            'series_id' => (int) $episode->getAttr('series_id'),
            'episode_id' => $id,
            'result' => 'success',
            'before_json' => $before,
            'after_json' => $this->findEpisodeOrFail($id, true)->toArray(),
            'meta_json' => [
                'route' => 'episodes.update',
            ],
        ]);

        return $this->serializeEpisode($this->findEpisodeOrFail($id, true), true);
    }

    /**
     * 同一个剧本下剧集序号不能重复。
     */
    private function assertEpisodeNumberAvailable(int $seriesId, int $number, ?int $ignoreId = null): void
    {
        $query = Episode::where('series_id', $seriesId)->where('number', $number);
        if ($ignoreId !== null) {
            $query->where('id', '<>', $ignoreId);
        }
        if ($query->count() > 0) {
            abort(422, "第 {$number} 集已存在，请换一个剧集序号");
        }
    }

    private function assertSeriesWorkflowEditable(int $seriesId): void
    {
        $run = WorkflowRuntime::findBlockingSeriesRun($seriesId);
        if (!$run instanceof WorkflowRun) {
            return;
        }

        $status = (string) $run->getAttr('status');
        $message = $status === 'failed'
            ? '剧本解析任务未完成，请先继续执行，或删除剧本后重新创建'
            : '剧本解析任务正在执行，完成后才能修改剧集或资产';
        abort(423, $message);
    }

    /**
     * 剧集进入执行态或已有分镜后，工作流只能展示不能更换。
     */
    private function isEpisodeWorkflowLocked(Episode $episode): bool
    {
        $status = (string) $episode->getAttr('status');
        if ($status !== '' && $status !== 'draft') {
            return true;
        }

        return Shot::where('episode_id', (int) $episode->getAttr('id'))->count() > 0;
    }

    /**
     * 校验剧集工作流。
     * 确保 workflow 存在且 scope 为 episode。
     */
    private function assertEpisodeWorkflow(int $workflowId): Workflow
    {
        $workflow = Workflow::where('id', $workflowId)
            ->where('user_id', $this->effectiveUserId())
            ->find();
        if (!$workflow instanceof Workflow) {
            abort(404, '剧集工作流不存在');
        }
        if ((string) $workflow->getAttr('scope') !== 'episode') {
            abort(422, '新建剧集只能选择“剧集”类型工作流');
        }
        $this->assertWorkflowHasNodes($workflow, '剧集');
        return $workflow;
    }

    /**
     * 校验工作流至少有一个节点。
     * 空工作流绑定后会在剧集页出现“还没有流程节点”的死胡同，必须在入口拦截。
     */
    private function assertWorkflowHasNodes(Workflow $workflow, string $scopeLabel): void
    {
        $graph = $workflow->getAttr('graph');
        $nodes = is_array($graph) && isset($graph['nodes']) && is_array($graph['nodes']) ? $graph['nodes'] : [];
        if (count($nodes) === 0) {
            abort(422, "当前{$scopeLabel}工作流「" . (string) $workflow->getAttr('name') . '」没有任何节点，请先到流程页完善后再使用');
        }
    }

    /**
     * 解析剧本工作流生成剧集时要绑定的剧集工作流。
     * 优先使用 episode_workflow_id，未传时使用默认剧集工作流。
     */
    private function resolveEpisodeWorkflowIdForSeriesRun(array $payload): ?int
    {
        $workflowId = isset($payload['episode_workflow_id']) && $payload['episode_workflow_id'] !== ''
            ? (int) $payload['episode_workflow_id']
            : 0;
        if ($workflowId > 0) {
            $this->assertEpisodeWorkflow($workflowId);
            return $workflowId;
        }

        $workflow = Workflow::where('user_id', $this->effectiveUserId())
            ->where('scope', 'episode')
            ->order(['is_default' => 'desc', 'sort' => 'asc', 'id' => 'asc'])
            ->find();
        return $workflow instanceof Workflow ? (int) $workflow->getAttr('id') : null;
    }

    /**
     * 执行剧集删除事务。
     * 删除前先清理 shots。
     */
    private function doDeleteEpisode(int $id): void
    {
        $episodeForLock = $this->findEpisodeOrFail($id);
        $before = $episodeForLock->toArray();
        $this->assertSeriesWorkflowEditable((int) $episodeForLock->getAttr('series_id'));
        $this->assertEpisodeAutoExecutionEditable($episodeForLock, '当前剧集正在自动执行，请先取消自动执行后再删除');
        Db::transaction(function () use ($id) {
            $episode = $this->findEpisodeOrFail($id);
            WorkflowRun::where('series_id', (int) $episode->getAttr('series_id'))
                ->where('user_id', $this->seriesOwnerId(null, (int) $episode->getAttr('user_id')))
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.scope')) = 'episode'")
                ->whereRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.episode_id')) AS UNSIGNED) = ?", [$id])
                ->delete();
            EpisodeWorkflowNodeState::where('episode_id', $id)->delete();
            ShotMediaVersion::where('episode_id', $id)->delete();
            VideoJob::where('episode_id', $id)->delete();
            StoryboardRevision::where('episode_id', $id)->delete();
            Shot::where('episode_id', $episode->id)->delete();
            $episode->delete();
        });
        $this->writeAdminOperationLog([
            'action' => 'episode.delete',
            'target_type' => 'episode',
            'target_id' => $id,
            'target_name_snapshot' => (string) $episodeForLock->getAttr('title'),
            'series_id' => (int) $episodeForLock->getAttr('series_id'),
            'episode_id' => $id,
            'result' => 'success',
            'before_json' => $before,
            'after_json' => [],
            'meta_json' => [
                'route' => 'episodes.delete',
            ],
        ]);
        $this->clearSeriesCache();
    }

    /**
     * 执行剧集详情查询。
     */
    private function doGetEpisode(int $id): array
    {
        return $this->serializeEpisode($this->findEpisodeOrFail($id, true), true);
    }

    /**
     * 初始化剧集工作流运行记录，供手动逐节点执行。
     */
    private function doPrepareEpisodeWorkflow(int $episodeId): array
    {
        $episode = $this->findEpisodeOrFail($episodeId);
        $this->assertSeriesWorkflowEditable((int) $episode->getAttr('series_id'));
        $this->assertEpisodeAutoExecutionEditable($episode, '当前剧集正在自动执行，请先取消自动执行后再初始化流程');
        $workflowId = (int) ($episode->getAttr('workflow_id') ?? 0);
        if ($workflowId <= 0) {
            abort(422, '请先选择剧集工作流');
        }
        $plotInput = trim((string) ($episode->getAttr('plot_input') ?? ''));
        if ($plotInput === '') {
            abort(422, '请先填写剧情简介');
        }

        $workflow = $this->assertEpisodeWorkflow($workflowId);
        $graph = $workflow->getAttr('graph') ?: ['nodes' => [], 'edges' => []];
        if (!is_array($graph)) {
            $graph = ['nodes' => [], 'edges' => []];
        }

        $nodes = $this->topologicalOrder($graph);
        $run = $this->ensureEpisodeWorkflowRun($episode, $workflow, $nodes);
        $this->ensureEpisodeWorkflowNodeStates($episode, $workflow, $nodes, $run);
        $run->save([
            'status' => 'idle',
            'source_text' => $plotInput,
            'current_node_label' => '',
            'error_message' => '',
            'finished_at' => null,
        ]);
        RedisCache::bumpVersion('workflow_run:' . (int) $run->getAttr('id'));

        if ((string) $episode->getAttr('status') === 'draft') {
            $episode->save(['status' => 'production']);
        }
        $this->clearSeriesCache();

        return $this->serializeEpisode(Episode::with(['shots'])->findOrFail($episodeId), true);
    }

    /**
     * @deprecated 整集一键自动执行已停用；保留方法签名供旧引用编译，调用即 410。
     */
    private function doRunEpisodeWorkflowAuto(int $episodeId, ?int $promoSegmentCount = null): array
    {
        unset($episodeId, $promoSegmentCount);
        abort(410, '整集一键自动执行已停用，请在流程节点中逐步执行。');
    }

    private function doCancelEpisodeWorkflowAuto(int $episodeId): array
    {
        $episode = $this->findEpisodeOrFail($episodeId);
        $this->assertSeriesWorkflowEditable((int) $episode->getAttr('series_id'));
        $run = $this->activeEpisodeAutoWorkflowRun($episode);
        if (!$run instanceof WorkflowRun) {
            return $this->serializeEpisode(Episode::with(['shots'])->findOrFail($episodeId), true);
        }

        Db::transaction(function () use ($run): void {
            $payload = $run->getAttr('payload_json') ?: [];
            if (!is_array($payload)) {
                $payload = [];
            }
            unset($payload['awaiting_node_id'], $payload['awaiting_kind']);
            $payload['execution_mode'] = 'auto';
            $payload['cancelled_at'] = date('Y-m-d H:i:s');

            $run->save([
                'status' => 'cancelled',
                'payload_json' => $payload,
                'current_node_label' => '',
                'error_message' => '已手动取消本集自动执行',
                'finished_at' => date('Y-m-d H:i:s'),
            ]);

            // 取消自动执行时清掉本 run 下所有未完成视频任务（含积分失败后仍卡在 queued/blocked 的孤儿任务）。
            $videoNodes = WorkflowRunNode::where('run_id', (int) $run->getAttr('id'))
                ->where('kind', 'video')
                ->select();
            foreach ($videoNodes as $videoNode) {
                if (!$videoNode instanceof WorkflowRunNode) {
                    continue;
                }
                $cancelled = $this->cancelVideoJobsForRunNode((int) $videoNode->getAttr('id'), false);
                $nodeStatus = (string) $videoNode->getAttr('status');
                if ($cancelled <= 0 && !in_array($nodeStatus, ['running', 'queued', 'waiting_async'], true)) {
                    continue;
                }
                $output = $videoNode->getAttr('output_json') ?: [];
                if (!is_array($output)) {
                    $output = [];
                }
                $output['status'] = 'cancelled';
                $output['cancelled_at'] = date('Y-m-d H:i:s');
                if ($cancelled > 0) {
                    $output['cancelled_video_jobs'] = $cancelled;
                }
                $videoNode->save([
                    'status' => 'skipped',
                    'output_json' => $output,
                    'raw_output' => json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '',
                    'error_message' => '本集自动执行已取消，可从当前进度继续',
                    'finished_at' => date('Y-m-d H:i:s'),
                ]);
                $this->syncEpisodeWorkflowStateForRunNode(
                    $videoNode,
                    'skipped',
                    $output,
                    json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '',
                    '本集自动执行已取消，可从当前进度继续',
                );
            }
        });

        RedisCache::bumpVersion('workflow_run:' . (int) $run->getAttr('id'));
        $this->clearSeriesCache();

        return $this->serializeEpisode(Episode::with(['shots'])->findOrFail($episodeId), true);
    }

    private function doQueueEpisodeWorkflowNode(int $episodeId, string $nodeId, string $promptOverride = '', array $options = []): array
    {
        $episode = $this->findEpisodeOrFail($episodeId);
        $this->assertSeriesWorkflowEditable((int) $episode->getAttr('series_id'));
        $this->assertEpisodeAutoExecutionEditable($episode, '当前剧集正在自动执行，请先取消自动执行后再手动操作节点');

        $workflowId = (int) ($episode->getAttr('workflow_id') ?? 0);
        if ($workflowId <= 0) {
            abort(422, '请先选择剧集工作流');
        }
        $plotInput = trim((string) ($episode->getAttr('plot_input') ?? ''));
        if ($plotInput === '') {
            abort(422, '请先填写剧情简介');
        }

        $workflow = $this->assertEpisodeWorkflow($workflowId);
        $graph = $workflow->getAttr('graph') ?: ['nodes' => [], 'edges' => []];
        if (!is_array($graph)) {
            $graph = ['nodes' => [], 'edges' => []];
        }
        $nodes = $this->topologicalOrder($graph);
        $target = $this->findWorkflowNodeForEpisodeAction($episode, $nodes, $nodeId);
        if ($target === null) {
            abort(404, '当前剧集绑定的工作流中找不到这个节点，请刷新页面后重试。');
        }
        $nodeId = (string) ($target['id'] ?? $nodeId);
        $label = (string) ($target['label'] ?? $target['data']['label'] ?? $nodeId);
        $kind = (string) ($target['data']['kind'] ?? $target['kind'] ?? 'node');
        $singleShotIndex = (int) ($options['shot_index'] ?? 0);
        if ($singleShotIndex > 0 && $kind !== 'video') {
            abort(422, '只有视频节点支持单独生成某个分镜');
        }

        $run = $this->ensureEpisodeWorkflowRun($episode, $workflow, $nodes);
        $payload = $run->getAttr('payload_json') ?: [];
        if (!is_array($payload)) {
            $payload = [];
        }

        $runNode = $this->findWorkflowRunNode((int) $run->getAttr('id'), $target);
        if (!$runNode instanceof WorkflowRunNode) {
            abort(500, '节点任务初始化失败');
        }
        $state = $this->findEpisodeWorkflowNodeState($episodeId, $nodeId);
        if ($state instanceof EpisodeWorkflowNodeState) {
            $this->reconcileEpisodeWorkflowNodeState($state);
            $state->refresh();
        }
        if ($state instanceof EpisodeWorkflowNodeState && (string) $state->getAttr('status') === 'running') {
            abort(409, '该节点正在执行中，请等待当前任务完成后再操作');
        }
        $run->refresh();
        if (
            (string) ($payload['execution_mode'] ?? '') === 'node'
            && in_array((string) $run->getAttr('status'), ['queued', 'running'], true)
        ) {
            abort(409, '当前剧集已有节点正在后台执行，请等待完成后再操作');
        }

        $payload['scope'] = 'episode';
        $payload['episode_id'] = $episodeId;
        $payload['workflow_id'] = $workflowId;
        $payload['execution_mode'] = 'node';
        PromoVideoSegmentConfig::ensureSchema();
        $storedPromoCount = PromoVideoSegmentConfig::optional($episode->getAttr('promo_segment_count') ?? null);
        if ($storedPromoCount !== null) {
            $payload['promo_segment_count'] = $storedPromoCount;
        }
        $payload['target_node_id'] = $nodeId;
        $payload['target_node_label'] = $label;
        $payload['prompt_override'] = $promptOverride;
        $payload['node_options'] = [
            'shot_index' => $singleShotIndex,
            'prompt_template_id' => (int) ($options['prompt_template_id'] ?? 0),
        ];

        $run->save([
            'status' => 'queued',
            'source_text' => $plotInput,
            'payload_json' => $payload,
            'current_node_label' => $label,
            'error_message' => '',
            'finished_at' => null,
        ]);
        $runNode->save([
            'status' => 'queued',
            'error_message' => '',
            'started_at' => null,
            'finished_at' => null,
        ]);
        $this->syncEpisodeWorkflowStateForRunNode(
            $runNode,
            'queued',
            is_array($runNode->getAttr('output_json')) ? $runNode->getAttr('output_json') : [],
            (string) $runNode->getAttr('raw_output'),
            '',
        );

        if ((string) $episode->getAttr('status') === 'draft') {
            $episode->save(['status' => 'production']);
        }
        RedisCache::bumpVersion('workflow_run:' . (int) $run->getAttr('id'));
        $this->clearSeriesCache();

        return $this->serializeEpisode(Episode::with(['shots'])->findOrFail($episodeId), true);
    }

    /**
     * 执行单个剧集节点并记录状态。
     */
    private function doRunEpisodeWorkflowNode(int $episodeId, string $nodeId, string $promptOverride = '', array $options = []): array
    {
        $episode = $this->findEpisodeOrFail($episodeId);
        $this->assertSeriesWorkflowEditable((int) $episode->getAttr('series_id'));
        $internalAuto = (bool) ($options['internal_auto'] ?? false);
        if (!$internalAuto) {
            $this->assertEpisodeAutoExecutionEditable($episode, '当前剧集正在自动执行，请先取消自动执行后再手动操作节点');
        }
        $workflowId = (int) ($episode->getAttr('workflow_id') ?? 0);
        if ($workflowId <= 0) {
            abort(422, '请先选择剧集工作流');
        }
        $plotInput = trim((string) ($episode->getAttr('plot_input') ?? ''));
        if ($plotInput === '') {
            abort(422, '请先填写剧情简介');
        }

        $workflow = $this->assertEpisodeWorkflow($workflowId);
        $graph = $workflow->getAttr('graph') ?: ['nodes' => [], 'edges' => []];
        if (!is_array($graph)) {
            $graph = ['nodes' => [], 'edges' => []];
        }

        $nodes = $this->topologicalOrder($graph);
        $target = $this->findWorkflowNodeForEpisodeAction($episode, $nodes, $nodeId);
        if ($target === null) {
            abort(404, '当前剧集绑定的工作流中找不到这个节点，请刷新页面后重试。');
        }
        $nodeId = (string) ($target['id'] ?? $nodeId);

        $run = $this->ensureEpisodeWorkflowRun($episode, $workflow, $nodes);
        $runNode = $this->findWorkflowRunNode((int) $run->getAttr('id'), $target);
        if (!$runNode instanceof WorkflowRunNode) {
            abort(500, '节点任务初始化失败');
        }
        $state = $this->findEpisodeWorkflowNodeState($episodeId, $nodeId);
        if (!$state instanceof EpisodeWorkflowNodeState) {
            abort(500, '当前节点状态初始化失败');
        }
        $this->reconcileEpisodeWorkflowNodeState($state);
        $state->refresh();
        $label = (string) ($target['label'] ?? $target['data']['label'] ?? $nodeId);
        $kind = (string) ($target['data']['kind'] ?? $target['kind'] ?? 'node');
        $singleShotIndex = (int) ($options['shot_index'] ?? 0);
        if ((string) $state->getAttr('status') === 'running') {
            abort(409, '该节点正在执行中，请等待当前任务完成后再操作');
        }
        if ($singleShotIndex > 0 && $kind !== 'video') {
            abort(422, '只有视频节点支持单独生成某个分镜');
        }

        if ($this->isStoryboardRevisionNode($label, $kind)) {
            $this->clearCurrentStoryboardRevisionForEpisodeId($episodeId);
        }
        $this->invalidateEpisodeWorkflowNodesAfter((int) $run->getAttr('id'), (int) $runNode->getAttr('sort'));

        $startedAt = microtime(true);
        $now = date('Y-m-d H:i:s');

        $run->save([
            'status' => 'running',
            'source_text' => $plotInput,
            'current_node_label' => $label,
            'started_at' => $run->getAttr('started_at') ?: $now,
            'finished_at' => null,
            'error_message' => '',
        ]);

        $runNode->save([
            'status' => 'running',
            'input_json' => [
                'episode_id' => $episodeId,
                'plot_input' => $plotInput,
                'node' => [
                    'id' => $nodeId,
                    'label' => $label,
                    'kind' => $kind,
                ],
            ],
            'output_json' => [],
            'raw_output' => '',
            'ai_request_log_id' => null,
            'request_payload_json' => null,
            'ai_meta_json' => null,
            'error_message' => '',
            'started_at' => $now,
            'finished_at' => null,
            'duration_ms' => 0,
        ]);
        $this->syncEpisodeWorkflowStateForRunNode($runNode, 'running', [], '', '');

        try {
            if ($kind === 'input') {
                $output = [
                    'episode_id' => $episodeId,
                    'workflow_node_id' => $nodeId,
                    'label' => $label,
                    'kind' => $kind,
                    'input_from' => 'episode.plot_input',
                    'text' => $plotInput,
                    'plot_input' => $plotInput,
                    'generated_at' => $now,
                ];
                $this->finishWorkflowRunNode($runNode, $output, $plotInput, true, $startedAt);
            } elseif ($kind === 'text') {
                $this->runEpisodeTextNode(
                    $episode,
                    $workflow,
                    $nodes,
                    $target,
                    $run,
                    $runNode,
                    $plotInput,
                    $startedAt,
                    $promptOverride,
                    (int) ($options['prompt_template_id'] ?? 0),
                );
            } elseif ($kind === 'image') {
                $this->runEpisodeImageNode($episode, $workflow, $nodes, $target, $run, $runNode, $plotInput, $startedAt);
            } elseif ($kind === 'video') {
                $this->runEpisodeVideoNode($episode, $workflow, $nodes, $target, $run, $runNode, $plotInput, $startedAt, $singleShotIndex);
            } elseif ($kind === 'output') {
                $this->runEpisodeOutputNode($episode, $workflow, $nodes, $target, $run, $runNode, $plotInput, $startedAt);
            } else {
                $output = [
                    'episode_id' => $episodeId,
                    'workflow_node_id' => $nodeId,
                    'label' => $label,
                    'kind' => $kind,
                    'input_from' => 'workflow_context',
                    'generated_at' => $now,
                    'note' => '该类型节点尚未接入真实生成任务。',
                ];
                $this->finishWorkflowRunNode($runNode, $output, "节点 {$label} 已执行", true, $startedAt);
            }
        } catch (\Throwable $e) {
            $this->finishWorkflowRunNode($runNode, null, '', false, $startedAt, mb_substr($e->getMessage(), 0, 2000));
            $this->refreshEpisodeWorkflowRunProgress((int) $run->getAttr('id'));
            throw $e;
        }

        $this->refreshEpisodeWorkflowRunProgress((int) $run->getAttr('id'));

        if ((string) $episode->getAttr('status') === 'draft') {
            $episode->save(['status' => 'production']);
        }

        $this->clearSeriesCache();

        return $this->serializeEpisode(Episode::with(['shots'])->findOrFail($episodeId), true);
    }

    private function doPreviewEpisodeVideoPrompts(int $episodeId, string $nodeId): array
    {
        $episode = $this->findEpisodeOrFail($episodeId);
        $this->assertSeriesWorkflowEditable((int) $episode->getAttr('series_id'));
        $workflowId = (int) ($episode->getAttr('workflow_id') ?? 0);
        if ($workflowId <= 0) {
            abort(422, '请先选择剧集工作流');
        }
        $plotInput = trim((string) ($episode->getAttr('plot_input') ?? ''));
        if ($plotInput === '') {
            abort(422, '请先填写剧情简介');
        }

        $workflow = $this->assertEpisodeWorkflow($workflowId);
        $graph = $workflow->getAttr('graph') ?: ['nodes' => [], 'edges' => []];
        if (!is_array($graph)) {
            $graph = ['nodes' => [], 'edges' => []];
        }
        $nodes = $this->topologicalOrder($graph);
        $target = $this->findWorkflowNodeForEpisodeAction($episode, $nodes, $nodeId);
        if ($target === null) {
            abort(404, '当前剧集绑定的工作流中找不到这个节点，请刷新页面后重试');
        }
        $nodeId = (string) ($target['id'] ?? $nodeId);
        $kind = (string) ($target['data']['kind'] ?? $target['kind'] ?? '');
        if ($kind !== 'video') {
            abort(422, '只有视频生成节点可以预览视频提示词');
        }

        $run = $this->ensureEpisodeWorkflowRun($episode, $workflow, $nodes);
        $previousOutput = $this->collectImmediatePreviousEpisodeOutput(
            (int) $run->getAttr('id'),
            $nodes,
            $nodeId,
            $plotInput,
            (int) $episode->getAttr('series_id'),
        );
        if ($previousOutput === []) {
            $previousLabel = $this->immediatePreviousEpisodeNodeLabel($nodes, $nodeId);
            abort(422, $previousLabel !== '' ? "需要先执行上游节点【{$previousLabel}】，才能预览视频提示词" : '需要先执行上游节点，才能预览视频提示词');
        }

        $shots = $this->extractVideoShotItems($previousOutput['output'] ?? []);
        if ($shots === []) {
            abort(422, '没有从上游节点读取到可生成视频的分镜内容');
        }

        $seriesId = (int) $episode->getAttr('series_id');
        $storyboardRevisionId = $this->ensureCurrentStoryboardRevisionForNodeInput($episode, $run, $nodes, $nodeId);
        if ($storyboardRevisionId <= 0) {
            abort(422, '需要先执行分镜处理节点，才能预览当前分镜的视频提示词');
        }
        $currentShotsByIndex = [];
        foreach ($this->currentEpisodeShots(Episode::find($episodeId) ?: $episode) as $shot) {
            if (is_array($shot)) {
                $currentShotsByIndex[(int) ($shot['index'] ?? 0)] = $shot;
            }
        }
        $nodeParams = is_array($target['data']['params'] ?? null) ? $target['data']['params'] : [];
        $nodePrompt = $this->defaultVideoNodePrompt();
        $videoStylePrompt = $this->videoStylePromptFromNodeParams($nodeParams);
        $readyShots = $this->filterEpisodeVideoShotsReady($shots);
        $chainShots = (bool) ($nodeParams['chainShots'] ?? true);
        $items = [];

        foreach ($readyShots as $offset => $shotData) {
            $index = (int) ($shotData['index'] ?? ($offset + 1));
            $index = $index > 0 ? $index : ($offset + 1);
            $description = trim((string) ($shotData['description'] ?? ''));
            $editorContentText = trim((string) ($shotData['content_text'] ?? $description));
            $imageUrl = trim((string) ($shotData['image_url'] ?? ''));
            if ($imageUrl === '') {
                $imageUrl = trim((string) ($currentShotsByIndex[$index]['image_url'] ?? ''));
            }
            $shotDuration = trim((string) ($shotData['duration'] ?? ''));
            $videoSeconds = $this->resolveWorkflowVideoDuration($shotDuration, $nodeParams);
            $shotDurationLabel = $videoSeconds > 0 ? (string) $videoSeconds : 'auto';
            $compiledShot = $this->compileStoryboardShotForVideo($seriesId, $shotData, $description);
            $compiledDescription = $compiledShot['description'];
            $shotData['description'] = $compiledDescription;
            $shotData['content_text'] = $editorContentText;
            $assets = $compiledShot['assets'];
            if ($imageUrl === '') {
                $imageUrl = $this->pickPrimaryVideoSourceImageUrl($assets);
            }
            $shotData['input_image_url'] = $imageUrl;
            $shotData['source_image_url'] = $imageUrl;
            $referenceMeta = $this->buildVideoReferenceImages($imageUrl, $assets);

            $items[] = [
                'index' => $index,
                'description' => $editorContentText,
                'compiled_prompt' => $this->buildVideoPrompt($episode, (string) ($target['label'] ?? '视频生成'), $nodePrompt, $shotData, $assets, $videoSeconds, $videoStylePrompt, $chainShots),
                'content_text' => $editorContentText,
                'content_rich_json' => isset($shotData['content_rich_json']) && is_array($shotData['content_rich_json']) ? $shotData['content_rich_json'] : [],
                'asset_refs' => isset($shotData['asset_refs']) && is_array($shotData['asset_refs']) ? $shotData['asset_refs'] : [],
                'duration' => $shotDurationLabel,
                'source_image_url' => $imageUrl,
                'input_image_url' => $imageUrl,
                'prompt' => $this->buildVideoPrompt($episode, (string) ($target['label'] ?? '视频生成'), $nodePrompt, $shotData, $assets, $videoSeconds, $videoStylePrompt, $chainShots),
                'reference_images' => array_map(
                    static fn (array $item, string $alias): array => [
                        'alias' => $alias,
                        'url' => (string) ($item['url'] ?? ''),
                        'name' => (string) ($item['name'] ?? ''),
                        'type' => (string) ($item['type'] ?? ''),
                    ],
                    $referenceMeta,
                    array_keys($referenceMeta),
                ),
                'assets' => array_map(
                    static fn (array $asset): array => [
                        'id' => $asset['id'] ?? null,
                        'name' => $asset['name'] ?? '',
                        'type' => $asset['type'] ?? '',
                        'image_url' => $asset['image_url'] ?? '',
                    ],
                    $assets,
                ),
            ];
        }

        if ($items === []) {
            abort(422, '没有可预览的视频镜头。请先生成首帧图片，或检查分镜是否有有效画面。');
        }

        return [
            'episode_id' => $episodeId,
            'storyboard_revision_id' => $storyboardRevisionId,
            'workflow_node_id' => $nodeId,
            'label' => (string) ($target['label'] ?? '视频生成'),
            'video_options' => $this->videoNodeRequestOptions($nodeParams),
            'video_style_prompt' => $videoStylePrompt,
            'video_style_source' => $videoStylePrompt === '' ? 'system_default' : 'custom',
            'chain_shots' => $chainShots,
            'shot_count' => count($items),
            'shots' => $items,
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * 执行剧集工作流中的文本节点。
     */
    private function runEpisodeTextNode(
        Episode $episode,
        Workflow $workflow,
        array $orderedNodes,
        array $target,
        WorkflowRun $run,
        WorkflowRunNode $runNode,
        string $plotInput,
        float $startedAt,
        string $promptOverride = '',
        int $promptTemplateOverrideId = 0,
    ): void {
        $label = trim((string) ($target['label'] ?? $target['data']['label'] ?? '文本节点'));
        if ($this->isCallSheetNode($label, $target)) {
            $this->runEpisodeCallSheetNode($episode, $workflow, $orderedNodes, $target, $run, $runNode, $plotInput, $startedAt, $promptOverride);
            return;
        }
        if ($this->isEpisodeAssetPrepNode($label)) {
            $this->runEpisodeAssetPrepNode($episode, $workflow, $orderedNodes, $target, $run, $runNode, $plotInput, $startedAt, $promptOverride);
            return;
        }

        $promptMeta = [];
        $prompt = $this->resolveNodePrompt($target, '', 'episode', $promptMeta, $promptOverride, $promptTemplateOverrideId);
        if ($prompt === '') {
            abort(422, "节点【{$label}】prompt 为空");
        }

        $modelId = (int) ($target['data']['params']['modelId'] ?? 0);
        $model = $this->resolveTextModelByIdOrDefault($modelId);
        if (!$model instanceof ModelConfig) {
            abort(422, "节点【{$label}】未找到可用的文本模型，请联系管理员配置");
        }

        $upstreamOutputs = $this->collectEpisodeUpstreamOutputs(
            (int) $run->getAttr('id'),
            $orderedNodes,
            (string) ($target['id'] ?? ''),
            $plotInput,
            (int) $episode->getAttr('series_id'),
        );
        $isStoryboardTextNode = str_contains($label, '分镜') && !$this->isShotAssetBindingNode($label);
        $storyboardMaxTokens = 0;
        if ($isStoryboardTextNode) {
            $upstreamOutputs = StoryboardRequestOptimizer::compactUpstreamOutputs($upstreamOutputs, $plotInput);
            $storyboardMaxTokens = StoryboardRequestOptimizer::maxTokens(
                is_array($target['data']['params'] ?? null) ? $target['data']['params'] : []
            );
        }
        $episodeSeries = $episode->series;
        $context = array_merge([
            'series_id' => (int) $episode->getAttr('series_id'),
            'episode_id' => (int) $episode->getAttr('id'),
            'episode_title' => (string) $episode->getAttr('title'),
            'episode_number' => (int) $episode->getAttr('number'),
            'plot_input' => $plotInput,
            'upstream_outputs' => $upstreamOutputs,
        ], $this->seriesRegionContext($episodeSeries instanceof Series ? $episodeSeries : null));
        if ($this->isPromoStoryboardNode($label)) {
            $promoSegmentCount = $this->resolveEpisodePromoSegmentCount($episode, $run);
            $context['promo_segment_count'] = $promoSegmentCount;
            $context['promo_total_seconds'] = PromoVideoSegmentConfig::totalSeconds($promoSegmentCount);
            $prompt = PromoVideoSegmentConfig::applyToInstruction($prompt, $promoSegmentCount);
        }
        if ($this->isShotAssetBindingNode($label)) {
            $context['asset_library'] = $this->buildEpisodeAssetLibrary((int) $episode->getAttr('series_id'));
        } elseif ($isStoryboardTextNode) {
            $context['asset_library'] = StoryboardRequestOptimizer::compactLibrary(
                $this->buildEpisodeAssetLibrary((int) $episode->getAttr('series_id'))
            );
        }

        $inputJson = [
            'episode_id' => (int) $episode->getAttr('id'),
            'plot_input' => $plotInput,
            'content_region' => $context['content_region'] ?? 'china',
            'model_config_id' => (int) $model->getAttr('id'),
            'upstream_output_keys' => array_keys($upstreamOutputs),
            'prompt_source' => $promptMeta,
            'prompt_override_used' => ($promptMeta['source'] ?? '') === 'override',
            'node' => [
                'id' => (string) ($target['id'] ?? ''),
                'label' => $label,
                'kind' => 'text',
            ],
        ];
        if ($storyboardMaxTokens > 0) {
            $inputJson['max_tokens'] = $storyboardMaxTokens;
        }
        if (isset($context['promo_segment_count'])) {
            $inputJson['promo_segment_count'] = $context['promo_segment_count'];
            $inputJson['promo_total_seconds'] = $context['promo_total_seconds'];
        }
        $runNode->save([
            'input_json' => $inputJson,
        ]);

        $requestContext = [
            'source' => 'episode_workflow',
            'series_id' => (int) $episode->getAttr('series_id'),
            'episode_id' => (int) $episode->getAttr('id'),
            'workflow_id' => (int) $workflow->getAttr('id'),
            'workflow_run_id' => (int) $run->getAttr('id'),
            'workflow_run_node_id' => (int) $runNode->getAttr('id'),
            'node_label' => $label,
            'node_id' => (string) ($target['id'] ?? ''),
        ];
        if ($storyboardMaxTokens > 0) {
            $requestContext['max_tokens'] = $storyboardMaxTokens;
        }
        $raw = $this->callChatCompletions(
            $model,
            $this->buildEpisodeNodeMessages($label, $prompt, $context),
            $requestContext,
        );

        if ($this->shouldNormalizeStoryboardLookReferences($label)) {
            $raw = $this->normalizeStoryboardLookReferences($raw, (int) $episode->getAttr('series_id'));
        }
        if ($this->isPromoStoryboardNode($label)) {
            $expectedCount = (int) ($context['promo_segment_count'] ?? PromoVideoSegmentConfig::DEFAULT);
            $actualCount = PromoVideoSegmentConfig::countStoryboardNodes($raw);
            if ($actualCount > $expectedCount) {
                $raw = PromoVideoSegmentConfig::trimStoryboardToCount($raw, $expectedCount);
                $actualCount = PromoVideoSegmentConfig::countStoryboardNodes($raw);
            }
            if ($actualCount !== $expectedCount) {
                abort(502, "宣传片分镜段数不符合要求：期望 {$expectedCount} 段，模型实际返回 {$actualCount} 段，请重新生成");
            }
        }

        $parsed = $this->safeParseJson($raw);
        if (isset($parsed['__raw'])) {
            $output = ['text' => $raw];
            // 分镜类纯文本产物：再让 AI 匹配文本中指代的资产（含简称/形态变体），
            // 供前端把对应词渲染成可点击的资产标签。匹配失败不影响节点结果。
            if (str_contains($label, '分镜')) {
                $mentions = $this->matchAssetMentionsWithAi($model, $raw, (int) $episode->getAttr('series_id'), [
                    'episode_id' => (int) $episode->getAttr('id'),
                    'workflow_id' => (int) $workflow->getAttr('id'),
                    'workflow_run_id' => (int) $run->getAttr('id'),
                    'workflow_run_node_id' => (int) $runNode->getAttr('id'),
                    'node_label' => $label,
                    'node_id' => (string) ($target['id'] ?? ''),
                ]);
                if ($mentions !== []) {
                    $output['asset_mentions'] = $mentions;
                }
                $structuredShots = $this->structuredStoryboardShotsFromText($raw, (int) $episode->getAttr('series_id'));
                if ($structuredShots !== []) {
                    $output['shots'] = $structuredShots;
                    if (!isset($output['asset_mentions']) || !is_array($output['asset_mentions'])) {
                        $output['asset_mentions'] = $this->assetMentionsFromStructuredStoryboardShots($structuredShots);
                    }
                }
            }
        } else {
            $output = $this->isShotAssetBindingNode($label)
                ? $this->hydrateShotAssetBindings($parsed, (int) $episode->getAttr('series_id'))
                : $parsed;
        }

        $revision = $this->createStoryboardRevisionFromContent($episode, $run, $runNode, $raw, $output);
        if ($revision instanceof StoryboardRevision) {
            $output['storyboard_revision_id'] = (int) $revision->getAttr('id');
        }

        $this->finishWorkflowRunNode($runNode, $output, $raw, true, $startedAt);
    }

    private function isShotAssetBindingNode(string $label): bool
    {
        return str_contains($label, '分镜绑定资产')
            || (str_contains($label, '分镜') && str_contains($label, '绑定') && str_contains($label, '资产'));
    }

    /**
     * 判断是否为剧集级「资产提取与生成」节点。
     * 注意与「分镜绑定资产」节点区分：绑定节点只引用已有资产，不创建资产。
     */
    private function isEpisodeAssetPrepNode(string $label): bool
    {
        if ($this->isShotAssetBindingNode($label)) {
            return false;
        }
        return str_contains($label, '资产提取')
            || (str_contains($label, '资产') && str_contains($label, '生成') && !str_contains($label, '帧') && !str_contains($label, '视频'));
    }

    /**
     * 执行剧集级「资产提取与合并」节点。
     * 资产来自本集扩写剧情，并与剧本级共享资产库增量合并；
     * 已有资产复用，新资产写入，缺少核心图的资产排队生成参考图。
     */
    private function runEpisodeAssetPrepNode(
        Episode $episode,
        Workflow $workflow,
        array $orderedNodes,
        array $target,
        WorkflowRun $run,
        WorkflowRunNode $runNode,
        string $plotInput,
        float $startedAt,
        string $promptOverride = '',
    ): void {
        $label = trim((string) ($target['label'] ?? $target['data']['label'] ?? '资产提取与生成'));
        $nodeId = (string) ($target['id'] ?? '');
        $seriesId = (int) $episode->getAttr('series_id');
        $series = $episode->series;
        $ownerId = $this->seriesOwnerId($series instanceof Series ? $series : null, (int) $episode->getAttr('user_id'));

        $assetLibrary = $this->buildEpisodeAssetExtractionLibrary($seriesId);
        $existingCount = count($assetLibrary);
        $promptMeta = ['source' => 'system_asset_extractor', 'template_id' => null, 'template_title' => '系统资产提取规则', 'is_system' => true];
        $modelId = (int) ($target['data']['params']['modelId'] ?? 0);
        $model = $this->resolveTextModelByIdOrDefault($modelId);
        if (!$model instanceof ModelConfig) {
            abort(422, "节点【{$label}】未找到可用的文本模型，请联系管理员配置");
        }

        $upstreamOutputs = $this->collectEpisodeUpstreamOutputs(
            (int) $run->getAttr('id'),
            $orderedNodes,
            $nodeId,
            $plotInput,
            $seriesId,
        );
        $effectivePlotInput = AssetExtractionRequestOptimizer::resolvePlot($plotInput, $upstreamOutputs);
        $assetMaxTokens = AssetExtractionRequestOptimizer::maxTokens(
            is_array($target['data']['params'] ?? null) ? $target['data']['params'] : []
        );
        $visualStyle = $this->normalizeVisualStyle((string) ($series instanceof Series ? $series->getAttr('visual_style') : 'realistic'));
        $context = array_merge([
            'series_id' => $seriesId,
            'episode_id' => (int) $episode->getAttr('id'),
            'episode_title' => (string) $episode->getAttr('title'),
            'episode_number' => (int) $episode->getAttr('number'),
            'plot_input' => $effectivePlotInput,
            'upstream_outputs' => [],
            'asset_library' => $assetLibrary,
            'visual_style' => $visualStyle,
        ], $this->seriesRegionContext($series instanceof Series ? $series : null));
        $prompt = \app\support\DefaultWorkflowGraphs::episodeAssetPrompt($visualStyle);

        $runNode->save([
            'input_json' => [
                'episode_id' => (int) $episode->getAttr('id'),
                'plot_input' => $effectivePlotInput,
                'content_region' => $context['content_region'] ?? 'china',
                'visual_style' => $visualStyle,
                'model_config_id' => (int) $model->getAttr('id'),
                'upstream_output_keys' => array_keys($upstreamOutputs),
                'existing_asset_count' => $existingCount,
                'max_tokens' => $assetMaxTokens,
                'prompt_source' => $promptMeta,
                'prompt_override_used' => ($promptMeta['source'] ?? '') === 'override',
                'node' => ['id' => $nodeId, 'label' => $label, 'kind' => 'text'],
            ],
        ]);

        $raw = $this->callChatCompletions($model, $this->buildEpisodeAssetPrepMessages($label, $prompt, $context), [
            'source' => 'episode_workflow',
            'series_id' => $seriesId,
            'episode_id' => (int) $episode->getAttr('id'),
            'workflow_id' => (int) $workflow->getAttr('id'),
            'workflow_run_id' => (int) $run->getAttr('id'),
            'workflow_run_node_id' => (int) $runNode->getAttr('id'),
            'node_label' => $label,
            'node_id' => $nodeId,
            'max_tokens' => $assetMaxTokens,
        ]);

        $parsed = $this->safeParseJson($raw);
        $assets = isset($parsed['__raw']) ? [] : $this->collectAssetsFromAny($parsed);
        $characterLooks = isset($parsed['__raw']) ? [] : $this->collectCharacterLooksFromAny($parsed);
        $charactersFromLooks = 0;
        $assets = $this->ensureCharactersFromLooks($assets, $characterLooks, $charactersFromLooks);
        if ($assets === []) {
            abort(422, "节点【{$label}】未能从剧情中解析出资产，请检查模型输出或重试");
        }
        $lookFallbackCount = 0;
        $characterLooks = $this->ensureEpisodeCharacterLooks(
            $assets,
            $characterLooks,
            (int) $episode->getAttr('number'),
            $lookFallbackCount,
            $visualStyle,
        );

        $writeStats = $this->upsertAssetsFromSeriesParse($seriesId, $assets, [
            'source' => 'episode_asset_prep',
            'episode_id' => (int) $episode->getAttr('id'),
            'episode_number' => (int) $episode->getAttr('number'),
            'episode_title' => (string) $episode->getAttr('title'),
        ]);
        $lookWriteStats = $this->upsertCharacterLooksFromSeriesParse($seriesId, $characterLooks, [
            'source' => 'episode_asset_prep',
            'episode_id' => (int) $episode->getAttr('id'),
            'episode_number' => (int) $episode->getAttr('number'),
            'episode_title' => (string) $episode->getAttr('title'),
        ]);

        $output = [
            'episode_id' => (int) $episode->getAttr('id'),
            'workflow_node_id' => $nodeId,
            'label' => $label,
            'kind' => 'text',
            'skipped' => false,
            'assets_written' => count($assets),
            'assets_extracted' => count($assets),
            'assets_created' => (int) ($writeStats['created'] ?? 0),
            'assets_updated' => (int) ($writeStats['updated'] ?? 0),
            'assets_reused_or_updated' => (int) ($writeStats['updated'] ?? 0),
            'character_looks_extracted' => count($characterLooks),
            'character_looks_created' => (int) ($lookWriteStats['created'] ?? 0),
            'character_looks_updated' => (int) ($lookWriteStats['updated'] ?? 0),
            'character_looks_added' => $lookFallbackCount,
            'characters_synthesized_from_looks' => $charactersFromLooks,
            'costume_props_added' => $lookFallbackCount,
            'existing_asset_count_before' => $existingCount,
            'stale_image_jobs_purged' => 0,
            'image_jobs_created' => 0,
            'image_jobs_skipped_with_core' => 0,
            'image_jobs_skipped_queued' => 0,
            'look_image_jobs_created' => 0,
            'look_image_jobs_skipped_with_image' => 0,
            'look_image_jobs_skipped_without_main' => 0,
            'look_image_jobs_skipped_queued' => 0,
            'assets' => array_map(static fn (array $a): array => [
                'name' => (string) ($a['name'] ?? ''),
                'type' => (string) ($a['type'] ?? ''),
            ], array_values(array_filter($assets, 'is_array'))),
            'character_looks' => array_values(array_filter(array_map(static function (array $look): array {
                return [
                    'character_name' => (string) ($look['character_name'] ?? ''),
                    'look_name' => (string) ($look['look_name'] ?? ''),
                ];
            }, array_values(array_filter($characterLooks, 'is_array'))), static fn (array $item): bool => $item['character_name'] !== '' && $item['look_name'] !== '')),
            'note' => $this->formatAssetExtractionNote($assets, $characterLooks, $writeStats, $lookWriteStats, $lookFallbackCount),
            'generated_at' => date('Y-m-d H:i:s'),
        ];

        $this->finishWorkflowRunNode($runNode, $output, $raw, true, $startedAt);
    }

    /**
     * 资产提取节点面向用户的摘要：按类型报数量，不回吐 JSON 字段名。
     *
     * @param array<int, mixed> $assets
     * @param array<int, mixed> $characterLooks
     * @param array<string, mixed> $writeStats
     * @param array<string, mixed> $lookWriteStats
     */
    private function formatAssetExtractionNote(
        array $assets,
        array $characterLooks,
        array $writeStats,
        array $lookWriteStats,
        int $lookFallbackCount,
    ): string {
        $characters = 0;
        $scenes = 0;
        $props = 0;
        $other = 0;
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $type = $this->normalizeAssetType((string) ($asset['type'] ?? ''));
            if ($type === 'character') {
                $characters++;
            } elseif ($type === 'scene') {
                $scenes++;
            } elseif ($type === 'prop') {
                $props++;
            } else {
                $other++;
            }
        }

        $lookCount = max(
            count($characterLooks),
            (int) ($lookWriteStats['created'] ?? 0) + (int) ($lookWriteStats['updated'] ?? 0),
            $lookFallbackCount,
        );

        $parts = [];
        if ($characters > 0) {
            $parts[] = "人物 {$characters} 个";
        }
        if ($scenes > 0) {
            $parts[] = "场景 {$scenes} 个";
        }
        if ($props > 0) {
            $parts[] = "道具 {$props} 个";
        }
        if ($other > 0) {
            $parts[] = "其他资产 {$other} 个";
        }
        if ($lookCount > 0) {
            $parts[] = "人物造型 {$lookCount} 套";
        }
        if ($parts === []) {
            $parts[] = '暂未识别到可整理资产';
        }

        $created = (int) ($writeStats['created'] ?? 0);
        $updated = (int) ($writeStats['updated'] ?? 0);
        $writeParts = [];
        if ($created > 0) {
            $writeParts[] = "新增 {$created}";
        }
        if ($updated > 0) {
            $writeParts[] = "更新 {$updated}";
        }

        $main = implode(' · ', $parts);
        $suffix = $writeParts !== [] ? '（' . implode(' · ', $writeParts) . '）' : '';

        return "本集资产已整理：{$main}{$suffix}。核心图与造型图请到资产页手动生成。";
    }

    private function isCallSheetNode(string $label, array $target): bool
    {
        $type = trim((string) ($target['data']['params']['type'] ?? ''));
        return $type === 'call_sheet'
            || str_contains($label, '通告单')
            || str_contains(strtolower($label), 'call sheet');
    }

    private function runEpisodeCallSheetNode(
        Episode $episode,
        Workflow $workflow,
        array $orderedNodes,
        array $target,
        WorkflowRun $run,
        WorkflowRunNode $runNode,
        string $plotInput,
        float $startedAt,
        string $promptOverride = '',
    ): void {
        $label = trim((string) ($target['label'] ?? $target['data']['label'] ?? '通告单生成'));
        $nodeId = (string) ($target['id'] ?? '');
        $promptMeta = [];
        $prompt = $this->resolveNodePrompt($target, '', 'episode', $promptMeta, $promptOverride);
        $modelId = (int) ($target['data']['params']['modelId'] ?? 0);
        $model = $this->resolveTextModelByIdOrDefault($modelId);
        if (!$model instanceof ModelConfig) {
            abort(422, "节点【{$label}】未找到可用文本模型，请联系管理员配置");
        }

        $upstreamOutputs = $this->collectEpisodeUpstreamOutputs(
            (int) $run->getAttr('id'),
            $orderedNodes,
            $nodeId,
            $plotInput,
            (int) $episode->getAttr('series_id'),
        );
        $previousOutput = $this->collectImmediatePreviousEpisodeOutput(
            (int) $run->getAttr('id'),
            $orderedNodes,
            $nodeId,
            $plotInput,
            (int) $episode->getAttr('series_id'),
        );
        $script = $this->resolveCallSheetSourceText($previousOutput, $upstreamOutputs, $plotInput);

        $runNode->save([
            'input_json' => [
                'episode_id' => (int) $episode->getAttr('id'),
                'plot_input' => $plotInput,
                'model_config_id' => (int) $model->getAttr('id'),
                'upstream_output_keys' => array_keys($upstreamOutputs),
                'input_chars' => mb_strlen($script),
                'prompt_source' => $promptMeta,
                'prompt_override_used' => ($promptMeta['source'] ?? '') === 'override',
                'node' => [
                    'id' => $nodeId,
                    'label' => $label,
                    'kind' => 'text',
                    'type' => 'call_sheet',
                ],
            ],
        ]);

        $generator = new CallSheetGenerator();
        $html = $generator->generateHtml($model, $script, [
            'source' => 'episode_workflow_call_sheet',
            'series_id' => (int) $episode->getAttr('series_id'),
            'episode_id' => (int) $episode->getAttr('id'),
            'workflow_id' => (int) $workflow->getAttr('id'),
            'node_label' => $label,
            'node_id' => $nodeId,
        ], $prompt);

        $output = [
            'episode_id' => (int) $episode->getAttr('id'),
            'workflow_node_id' => $nodeId,
            'label' => $label,
            'kind' => 'text',
            'type' => 'call_sheet',
            'format' => 'html',
            'html' => $html,
            'text' => $html,
            'generated_at' => date('Y-m-d H:i:s'),
        ];
        $this->finishWorkflowRunNode($runNode, $output, $html, true, $startedAt);
    }

    private function resolveCallSheetSourceText(array $previousOutput, array $upstreamOutputs, string $plotInput): string
    {
        $candidates = [];
        if (isset($previousOutput['output']) && is_array($previousOutput['output'])) {
            $candidates[] = $previousOutput['output'];
        }
        foreach (array_reverse($upstreamOutputs) as $output) {
            if (is_array($output)) {
                $candidates[] = $output;
            }
        }

        foreach ($candidates as $candidate) {
            foreach (['text', 'script', 'content', 'plot', 'summary', 'plot_input'] as $key) {
                $value = $candidate[$key] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }
            if ($candidate !== []) {
                $json = json_encode($candidate, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                if (is_string($json) && trim($json) !== '') {
                    return $json;
                }
            }
        }

        return $plotInput;
    }

    /**
     * 执行剧集工作流中的图片节点。
     * 读取上游分镜结果，为每个镜头生成首帧，并把图片地址写入 shots.image_url。
     */
    private function runEpisodeImageNode(
        Episode $episode,
        Workflow $workflow,
        array $orderedNodes,
        array $target,
        WorkflowRun $run,
        WorkflowRunNode $runNode,
        string $plotInput,
        float $startedAt,
    ): void {
        $label = trim((string) ($target['label'] ?? $target['data']['label'] ?? '图片节点'));
        $nodeId = (string) ($target['id'] ?? '');
        $nodePrompt = '根据上游分镜内容生成每个镜头的首帧图片；涉及人物、人物造型、场景、道具时优先参考资产库保持一致性。';

        $modelId = (int) ($target['data']['params']['modelId'] ?? 0);
        $model = $this->resolveImageModelByIdOrDefault($modelId);
        if (!$model instanceof ModelConfig) {
            abort(422, "节点【{$label}】未找到可用的图片模型，请联系管理员配置");
        }

        $upstreamOutputs = $this->collectEpisodeUpstreamOutputs(
            (int) $run->getAttr('id'),
            $orderedNodes,
            $nodeId,
            $plotInput,
            (int) $episode->getAttr('series_id'),
        );
        $previousOutput = $this->collectImmediatePreviousEpisodeOutput(
            (int) $run->getAttr('id'),
            $orderedNodes,
            $nodeId,
            $plotInput,
            (int) $episode->getAttr('series_id'),
        );
        if ($previousOutput === []) {
            $previousLabel = $this->immediatePreviousEpisodeNodeLabel($orderedNodes, $nodeId);
            abort(422, $previousLabel !== '' ? "节点【{$label}】需要先执行上游节点【{$previousLabel}】" : "节点【{$label}】需要先执行上游节点");
        }

        $shots = $this->extractShotItemsForImageNode([
            (string) ($previousOutput['label'] ?? '上游节点') => $previousOutput['output'] ?? [],
        ]);
        if ($shots === []) {
            abort(422, "节点【{$label}】没有从上游节点读取到分镜内容，请先执行分镜处理节点");
        }

        $episodeId = (int) $episode->getAttr('id');
        $seriesId = (int) $episode->getAttr('series_id');
        $storyboardRevisionId = $this->ensureCurrentStoryboardRevisionForNodeInput($episode, $run, $orderedNodes, $nodeId);
        if ($storyboardRevisionId <= 0) {
            abort(422, "节点【{$label}】需要先执行分镜处理节点，才能生成当前分镜的图片");
        }
        $this->deleteEpisodeShotsBeyond($episodeId, $storyboardRevisionId, count($shots));
        $generated = [];

        $runNode->save([
            'input_json' => [
                'episode_id' => $episodeId,
                'storyboard_revision_id' => $storyboardRevisionId,
                'plot_input' => $plotInput,
                'model_config_id' => (int) $model->getAttr('id'),
                'prompt_source' => ['source' => 'system_image_generator', 'is_system' => true],
                'upstream_output_keys' => array_keys($upstreamOutputs),
                'shot_count' => count($shots),
                'node' => [
                    'id' => $nodeId,
                    'label' => $label,
                    'kind' => 'image',
                ],
            ],
        ]);

        foreach ($shots as $offset => $shotData) {
            $index = (int) ($shotData['index'] ?? ($offset + 1));
            $index = $index > 0 ? $index : ($offset + 1);
            $description = trim((string) ($shotData['description'] ?? ''));
            if ($description === '') {
                continue;
            }
            $duration = trim((string) ($shotData['duration'] ?? ''));
            $shot = $this->upsertEpisodeShot($episodeId, $storyboardRevisionId, $index, $description, $duration, 'generating');
            $assets = $this->resolveStoryboardShotAssets($seriesId, $shotData, $description);
            $prompt = $this->buildFrameImagePrompt($episode, $label, $nodePrompt, $shotData, $assets);

            try {
                $imageUrl = $this->callWorkflowImageGeneration($model, $prompt, $assets, [
                    'source' => 'episode_workflow_image',
                    'series_id' => $seriesId,
                    'episode_id' => $episodeId,
                    'workflow_id' => (int) $workflow->getAttr('id'),
                    'node_label' => $label,
                    'node_id' => $nodeId,
                    'shot_index' => $index,
                    'asset_count' => count($assets),
                ]);
            } catch (\Throwable $e) {
                $shot->save(['status' => 'pending']);
                throw $e;
            }

            $shot->save([
                'status' => 'done',
                'image_url' => $imageUrl,
            ]);
            $this->createShotMediaVersion($shot, 'image', $imageUrl, [
                'workflow_run_node_id' => (int) $runNode->getAttr('id'),
                'storyboard_revision_id' => $storyboardRevisionId,
                'model_config_id' => (int) $model->getAttr('id'),
                'prompt' => $prompt,
                'source' => 'workflow',
                'is_selected' => true,
                'ai_request_log_id' => $this->lastAiRequestLogId ?: null,
                'meta_json' => [
                    'node_label' => $label,
                    'node_id' => $nodeId,
                    'shot_index' => $index,
                ],
            ]);

            $generated[] = [
                'shot_id' => (int) $shot->getAttr('id'),
                'storyboard_revision_id' => $storyboardRevisionId,
                'index' => $index,
                'title' => trim((string) ($shotData['title'] ?? "镜头 {$index}")),
                'description' => $description,
                'content_text' => trim((string) ($shotData['content_text'] ?? $description)),
                'content_rich_json' => isset($shotData['content_rich_json']) && is_array($shotData['content_rich_json']) ? $shotData['content_rich_json'] : [],
                'asset_refs' => isset($shotData['asset_refs']) && is_array($shotData['asset_refs']) ? $shotData['asset_refs'] : [],
                'duration' => $duration,
                'image_url' => $imageUrl,
                'assets' => array_map(
                    static fn (array $asset): array => [
                        'id' => $asset['id'],
                        'name' => $asset['name'],
                        'type' => $asset['type'],
                        'image_url' => $asset['image_url'],
                    ],
                    $assets
                ),
            ];
        }

        if ($generated === []) {
            abort(422, "节点【{$label}】没有可生成的有效分镜描述");
        }

        $output = [
            'episode_id' => $episodeId,
            'storyboard_revision_id' => $storyboardRevisionId,
            'workflow_node_id' => $nodeId,
            'label' => $label,
            'kind' => 'image',
            'shot_count' => count($generated),
            'shots' => $generated,
            'generated_at' => date('Y-m-d H:i:s'),
        ];
        $raw = json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $this->finishWorkflowRunNode($runNode, $output, is_string($raw) ? $raw : '', true, $startedAt);
    }

    /**
     * 执行剧集工作流中的视频节点。
     * 读取上游分镜结果，为每个镜头生成视频，并把视频地址写入 shots.video_url。
     */
    private function runEpisodeVideoNode(
        Episode $episode,
        Workflow $workflow,
        array $orderedNodes,
        array $target,
        WorkflowRun $run,
        WorkflowRunNode $runNode,
        string $plotInput,
        float $startedAt,
        int $onlyShotIndex = 0,
    ): void {
        $label = trim((string) ($target['label'] ?? $target['data']['label'] ?? '视频节点'));
        $nodeId = (string) ($target['id'] ?? '');
        $nodeParams = is_array($target['data']['params'] ?? null) ? $target['data']['params'] : [];
        $nodePrompt = $this->defaultVideoNodePrompt();
        $videoStylePrompt = $this->videoStylePromptFromNodeParams($nodeParams);
        $videoUserId = $this->seriesOwnerId(null, (int) ($episode->getAttr('user_id') ?: $run->getAttr('user_id')));
        $this->assertVideoGenerationAllowedForUser($videoUserId);

        $modelId = (int) ($nodeParams['modelId'] ?? 0);
        $model = $this->resolveVideoModelByIdOrDefault($modelId);
        if (!$model instanceof ModelConfig) {
            abort(422, "节点【{$label}】未找到可用的视频模型，请联系管理员配置");
        }
        if ($onlyShotIndex <= 0) {
            $this->cancelVideoJobsForRunNode((int) $runNode->getAttr('id'), false);
        }

        $upstreamOutputs = $this->collectEpisodeUpstreamOutputs(
            (int) $run->getAttr('id'),
            $orderedNodes,
            $nodeId,
            $plotInput,
            (int) $episode->getAttr('series_id'),
        );
        $previousOutput = $this->collectImmediatePreviousEpisodeOutput(
            (int) $run->getAttr('id'),
            $orderedNodes,
            $nodeId,
            $plotInput,
            (int) $episode->getAttr('series_id'),
        );
        if ($previousOutput === []) {
            $previousLabel = $this->immediatePreviousEpisodeNodeLabel($orderedNodes, $nodeId);
            abort(422, $previousLabel !== '' ? "节点【{$label}】需要先执行上游节点【{$previousLabel}】" : "节点【{$label}】需要先执行上游节点");
        }

        $shots = $this->extractVideoShotItems($previousOutput['output'] ?? []);
        if ($shots === []) {
            abort(422, "节点【{$label}】没有从上游节点读取到可生成视频的分镜内容");
        }

        $episodeId = (int) $episode->getAttr('id');
        $seriesId = (int) $episode->getAttr('series_id');
        $storyboardRevisionId = $this->ensureCurrentStoryboardRevisionForNodeInput($episode, $run, $orderedNodes, $nodeId);
        if ($storyboardRevisionId <= 0) {
            abort(422, "节点【{$label}】需要先执行分镜处理节点，才能生成当前分镜的视频");
        }
        $queued = [];
        $previousJobId = null;
        $singleShotMode = $onlyShotIndex > 0;
        $chainShots = (bool) ($target['data']['params']['chainShots'] ?? true);
        $readyShots = $this->filterEpisodeVideoShotsReady($shots);
        if ($singleShotMode) {
            $readyShots = array_values(array_filter(
                $readyShots,
                static fn (array $shotData): bool => (int) ($shotData['index'] ?? 0) === $onlyShotIndex
            ));
            if ($readyShots === []) {
                abort(422, "没有找到第 {$onlyShotIndex} 个可生成视频的分镜，请先确认分镜处理节点已生成该镜头");
            }
        }
        if ($chainShots && count($readyShots) > 1) {
            $this->assertFfmpegAvailable($label);
        }
        $this->assertSeriesAssetsHaveCoreViewsForVideo($seriesId, $readyShots, $label);

        $runNode->save([
            'input_json' => [
                'episode_id' => $episodeId,
                'storyboard_revision_id' => $storyboardRevisionId,
                'plot_input' => $plotInput,
                'model_config_id' => (int) $model->getAttr('id'),
                'prompt_source' => [
                    'source' => 'system_video_generator',
                    'is_system' => true,
                    'video_style_source' => $videoStylePrompt === '' ? 'system_default' : 'custom',
                ],
                'video_params' => $this->videoNodeRequestOptions($nodeParams),
                'video_style_prompt' => $videoStylePrompt,
                'upstream_output_keys' => array_keys($upstreamOutputs),
                'shot_count' => count($shots),
                'only_shot_index' => $singleShotMode ? $onlyShotIndex : null,
                'chain_shots' => $chainShots,
                'node' => [
                    'id' => $nodeId,
                    'label' => $label,
                    'kind' => 'video',
                ],
            ],
        ]);

        // 入队前先按整批镜头估算积分；不够则整批拒绝，避免中途 abort 留下 queued/blocked 僵尸任务。
        $videoOptionsForCredit = $this->videoNodeRequestOptions($nodeParams);
        $creditResolution = (string) ($videoOptionsForCredit['resolution'] ?? '480p');
        $creditModelId = trim((string) $model->getAttr('model_id'));
        $batchCreditSeconds = 0;
        foreach ($readyShots as $offset => $shotData) {
            $shotDuration = trim((string) ($shotData['duration'] ?? ''));
            $videoSeconds = $this->resolveWorkflowVideoDuration($shotDuration, $nodeParams);
            $batchCreditSeconds += $videoSeconds > 0 ? $videoSeconds : 15;
        }
        \app\support\CreditService::assertVideoAffordable(
            $videoUserId,
            $batchCreditSeconds,
            $creditResolution,
            $creditModelId
        );

        $totalReadyShots = $singleShotMode ? max(count($shots), count($readyShots)) : count($readyShots);
        $createdJobIds = [];
        try {
            foreach ($readyShots as $offset => $shotData) {
                $index = (int) ($shotData['index'] ?? ($offset + 1));
                $index = $index > 0 ? $index : ($offset + 1);
                $description = trim((string) ($shotData['description'] ?? ''));
                $editorContentText = trim((string) ($shotData['content_text'] ?? $description));
                $imageUrl = trim((string) ($shotData['image_url'] ?? ''));

                $shotDuration = trim((string) ($shotData['duration'] ?? ''));
                $videoSeconds = $this->resolveWorkflowVideoDuration($shotDuration, $nodeParams);
                $shotDurationLabel = $videoSeconds > 0 ? (string) $videoSeconds : 'auto';
                $shot = $this->upsertEpisodeShot($episodeId, $storyboardRevisionId, $index, $description, $shotDurationLabel, 'queued');
                $compiledShot = $this->compileStoryboardShotForVideo($seriesId, $shotData, $description);
                $compiledDescription = $compiledShot['description'];
                $shotData['description'] = $compiledDescription;
                $shotData['content_text'] = $editorContentText;
                $assets = $compiledShot['assets'];
                $voiceAssets = (new VideoVoiceAssetService())->resolveForVideoAssets($assets, $videoUserId);
                if ($imageUrl === '') {
                    $imageUrl = trim((string) $shot->getAttr('image_url'));
                }
                if ($imageUrl === '') {
                    $imageUrl = $this->pickPrimaryVideoSourceImageUrl($assets);
                }
                $inputImageUrl = $imageUrl;
                $previousEndFrameUrl = '';
                if ($singleShotMode && $chainShots && $index > 1) {
                    $previousEndFrameUrl = $this->latestSuccessfulVideoEndFrameForShot(
                        (int) $runNode->getAttr('id'),
                        $episodeId,
                        $storyboardRevisionId,
                        $index - 1,
                    );
                    if ($previousEndFrameUrl !== '') {
                        $inputImageUrl = $previousEndFrameUrl;
                    }
                }
                $shotData['input_image_url'] = $inputImageUrl;
                $shotData['source_image_url'] = $imageUrl;

                // 单镜再校验一次（余额可能在入队过程中被其他请求消耗）。
                $creditDuration = $videoSeconds > 0 ? $videoSeconds : 15;
                \app\support\CreditService::assertVideoAffordable(
                    $videoUserId,
                    $creditDuration,
                    $creditResolution,
                    $creditModelId
                );

                $job = VideoJob::create([
                    'user_id' => $videoUserId,
                    'series_id' => $seriesId,
                    'episode_id' => $episodeId,
                    'storyboard_revision_id' => $storyboardRevisionId,
                    'shot_id' => (int) $shot->getAttr('id'),
                    'workflow_id' => (int) $workflow->getAttr('id'),
                    'workflow_run_id' => (int) $run->getAttr('id'),
                    'workflow_run_node_id' => (int) $runNode->getAttr('id'),
                    'model_config_id' => (int) $model->getAttr('id'),
                    'node_id' => $nodeId,
                    'node_label' => $label,
                    'node_prompt' => $nodePrompt,
                    'shot_index' => $index,
                    'total_shots' => $totalReadyShots,
                    'duration' => $shotDurationLabel,
                    'source_image_url' => $imageUrl,
                    'input_image_url' => $inputImageUrl,
                    'previous_end_frame_url' => $previousEndFrameUrl,
                    'status' => (!$chainShots || $previousJobId === null) ? 'queued' : 'blocked',
                    'depends_on_job_id' => $chainShots ? $previousJobId : null,
                    'chain_shots' => $chainShots,
                    'shot_data_json' => $shotData,
                    'assets_json' => $assets,
                    'video_options_json' => $this->videoNodeRequestOptions($nodeParams),
                    'request_context_json' => [
                        'source' => 'episode_workflow_video',
                        'series_id' => $seriesId,
                        'episode_id' => $episodeId,
                        'storyboard_revision_id' => $storyboardRevisionId,
                        'workflow_id' => (int) $workflow->getAttr('id'),
                        'workflow_run_id' => (int) $run->getAttr('id'),
                        'workflow_run_node_id' => (int) $runNode->getAttr('id'),
                        'user_id' => $videoUserId,
                        'node_label' => $label,
                        'node_id' => $nodeId,
                        'shot_index' => $index,
                        'only_shot_index' => $singleShotMode ? $onlyShotIndex : null,
                        'video_style_prompt' => $videoStylePrompt,
                        'video_style_source' => $videoStylePrompt === '' ? 'system_default' : 'custom',
                        'image_url' => $inputImageUrl,
                        'source_image_url' => $imageUrl,
                        'previous_end_frame_url' => $previousEndFrameUrl,
                        'asset_count' => count($assets),
                        'voice_asset_count' => count($voiceAssets),
                        'voice_assets' => $voiceAssets,
                        'voice_assets_snapshot_at' => date('Y-m-d H:i:s'),
                        'video_options' => $this->videoNodeRequestOptions($nodeParams),
                    ],
                ]);
                $createdJobIds[] = (int) $job->getAttr('id');
                if ($chainShots) {
                    $previousJobId = (int) $job->getAttr('id');
                }

                $shot->save([
                    'status' => (!$chainShots || $offset === 0) ? 'queued' : 'blocked',
                    'image_url' => $shot->getAttr('image_url') ?: $imageUrl,
                    'video_url' => null,
                ]);

                $queued[] = [
                    'job_id' => (int) $job->getAttr('id'),
                    'shot_id' => (int) $shot->getAttr('id'),
                    'storyboard_revision_id' => $storyboardRevisionId,
                    'index' => $index,
                    'description' => $editorContentText,
                    'content_text' => $editorContentText,
                    'content_rich_json' => isset($shotData['content_rich_json']) && is_array($shotData['content_rich_json']) ? $shotData['content_rich_json'] : [],
                    'duration' => $shotDurationLabel,
                    'image_url' => $imageUrl,
                    'input_image_url' => $inputImageUrl,
                    'status' => (string) $job->getAttr('status'),
                    'asset_refs' => isset($shotData['asset_refs']) && is_array($shotData['asset_refs']) ? $shotData['asset_refs'] : [],
                    'assets' => array_map(
                        static fn (array $asset): array => [
                            'id' => $asset['id'] ?? null,
                            'name' => $asset['name'] ?? '',
                            'type' => $asset['type'] ?? '',
                            'image_url' => $asset['image_url'] ?? '',
                        ],
                        $assets
                    ),
                ];
            }
        } catch (\Throwable $e) {
            // 入队中途失败：清掉本批已创建的 queued/blocked，避免镜头永久卡在「排队中」。
            if ($createdJobIds !== []) {
                $this->failCreatedVideoJobsOnEnqueueError($createdJobIds, mb_substr($e->getMessage(), 0, 2000));
            }
            throw $e;
        }

        if ($queued === []) {
            abort(422, "节点【{$label}】没有可生成视频的有效镜头");
        }

        $output = [
            'episode_id' => $episodeId,
            'storyboard_revision_id' => $storyboardRevisionId,
            'workflow_node_id' => $nodeId,
            'label' => $label,
            'kind' => 'video',
            'async' => true,
            'status' => 'queued',
            'shot_count' => count($queued),
            'queued_count' => count($queued),
            'single_shot' => $singleShotMode,
            'only_shot_index' => $singleShotMode ? $onlyShotIndex : null,
            'shots' => $queued,
            'generated_at' => date('Y-m-d H:i:s'),
        ];
        $raw = json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $runNode->save([
            'status' => 'running',
            'output_json' => $output,
            'raw_output' => is_string($raw) ? $raw : '',
            'error_message' => '视频任务已提交后台队列，等待 video-job worker 生成完成。',
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
        RedisCache::bumpVersion('workflow_run:' . (int) $runNode->getAttr('run_id'));
    }

    private function runEpisodeOutputNode(
        Episode $episode,
        Workflow $workflow,
        array $orderedNodes,
        array $target,
        WorkflowRun $run,
        WorkflowRunNode $runNode,
        string $plotInput,
        float $startedAt,
    ): void {
        $label = trim((string) ($target['label'] ?? $target['data']['label'] ?? '输出视频'));
        $nodeId = (string) ($target['id'] ?? '');
        $episodeId = (int) $episode->getAttr('id');
        $seriesId = (int) $episode->getAttr('series_id');

        $upstreamOutputs = $this->collectEpisodeUpstreamOutputs(
            (int) $run->getAttr('id'),
            $orderedNodes,
            $nodeId,
            $plotInput,
            $seriesId,
        );
        $previousOutput = $this->collectImmediatePreviousEpisodeOutput(
            (int) $run->getAttr('id'),
            $orderedNodes,
            $nodeId,
            $plotInput,
            $seriesId,
        );

        $videoRunNodeId = $this->resolvePreviousVideoRunNodeId(
            (int) $run->getAttr('id'),
            $orderedNodes,
            $nodeId,
        );
        if ($videoRunNodeId <= 0) {
            $videoRunNodeId = $this->resolveUpstreamVideoRunNodeId($previousOutput, $upstreamOutputs);
        }
        if ($videoRunNodeId <= 0) {
            abort(422, "节点【{$label}】没有找到上游视频生成节点结果，请先执行视频生成节点");
        }

        $storyboardRevisionId = $this->currentStoryboardRevisionId($episode);
        if ($storyboardRevisionId <= 0) {
            abort(422, "节点【{$label}】需要先执行分镜处理节点");
        }

        // 按镜头收集，避免「最后一单镜重生」用 id 窗口把更早的镜1 success 漏掉。
        $mergePlan = $this->collectEpisodeVideosForMerge($episodeId, $storyboardRevisionId, $videoRunNodeId, $label);
        $videoUrls = $mergePlan['video_urls'];
        $sourceVideos = $mergePlan['source_videos'];

        $runNode->save([
            'input_json' => [
                'episode_id' => $episodeId,
                'storyboard_revision_id' => $storyboardRevisionId,
                'workflow_id' => (int) $workflow->getAttr('id'),
                'upstream_video_run_node_id' => $videoRunNodeId,
                'video_count' => count($videoUrls),
                'source_videos' => $sourceVideos,
                'node' => [
                    'id' => $nodeId,
                    'label' => $label,
                    'kind' => 'output',
                ],
            ],
        ]);

        $mergedUrl = $this->mergeAndUploadEpisodeVideos($videoUrls, $label, [
            'source' => 'merged_video',
            'user_id' => $this->seriesOwnerId(null, (int) ($episode->getAttr('user_id') ?: $run->getAttr('user_id'))),
            'series_id' => $seriesId,
            'episode_id' => $episodeId,
            'node_label' => $label,
            'workflow_run_id' => (int) $run->getAttr('id'),
            'workflow_run_node_id' => (int) $runNode->getAttr('id'),
        ]);
        $output = [
            'episode_id' => $episodeId,
            'storyboard_revision_id' => $storyboardRevisionId,
            'workflow_node_id' => $nodeId,
            'label' => $label,
            'kind' => 'output',
            'format' => 'mp4',
            'video_count' => count($videoUrls),
            'video_url' => $mergedUrl,
            'source_videos' => $sourceVideos,
            'generated_at' => date('Y-m-d H:i:s'),
        ];
        $raw = json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $this->finishWorkflowRunNode($runNode, $output, is_string($raw) ? $raw : '', true, $startedAt);
    }

    /**
     * 收集目标节点之前已完成的输出，输入节点默认使用剧情简介。
     */
    private function collectEpisodeUpstreamOutputs(int $runId, array $orderedNodes, string $targetNodeId, string $plotInput, int $seriesId = 0): array
    {
        $outputs = [];
        foreach ($orderedNodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $nodeId = (string) ($node['id'] ?? '');
            if ($nodeId === $targetNodeId) {
                break;
            }

            $label = trim((string) ($node['label'] ?? $node['data']['label'] ?? $nodeId));
            $kind = trim((string) ($node['data']['kind'] ?? $node['kind'] ?? ''));
            if ($kind === 'input') {
                $outputs[$label !== '' ? $label : '输入'] = ['text' => $plotInput, 'plot_input' => $plotInput];
                continue;
            }

            $runNode = $this->findWorkflowRunNode($runId, $node);
            if (!$runNode instanceof WorkflowRunNode || (string) $runNode->getAttr('status') !== 'success') {
                continue;
            }

            $output = $runNode->getAttr('output_json') ?: [];
            if (!is_array($output)) {
                $output = [];
            }
            $raw = trim((string) $runNode->getAttr('raw_output'));
            if ($seriesId > 0 && $this->shouldNormalizeStoryboardLookReferences($label)) {
                if ($raw !== '') {
                    $raw = $this->normalizeStoryboardLookReferences($raw, $seriesId);
                }
                if (isset($output['text']) && is_string($output['text'])) {
                    $output['text'] = $this->normalizeStoryboardLookReferences($output['text'], $seriesId);
                }
            }
            if ($raw !== '' && !isset($output['text'])) {
                $output['text'] = $raw;
            }
            $outputs[$label !== '' ? $label : $nodeId] = $output;
        }

        return $outputs;
    }

    /**
     * 读取目标节点拓扑顺序里的前一个节点输出。
     * 图片节点用它确保当前图片步骤严格吃上游分镜结果。
     */
    private function collectImmediatePreviousEpisodeOutput(int $runId, array $orderedNodes, string $targetNodeId, string $plotInput, int $seriesId = 0): array
    {
        $previous = null;
        foreach ($orderedNodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            if ((string) ($node['id'] ?? '') === $targetNodeId) {
                break;
            }
            $previous = $node;
        }

        if (!is_array($previous)) {
            return [];
        }

        $label = trim((string) ($previous['label'] ?? $previous['data']['label'] ?? '上游节点'));
        $kind = trim((string) ($previous['data']['kind'] ?? $previous['kind'] ?? ''));
        if ($kind === 'input') {
            return [
                'label' => $label !== '' ? $label : '输入',
                'output' => ['text' => $plotInput, 'plot_input' => $plotInput],
            ];
        }

        $runNode = $this->findWorkflowRunNode($runId, $previous);
        if (!$runNode instanceof WorkflowRunNode || (string) $runNode->getAttr('status') !== 'success') {
            return [];
        }

        $output = $runNode->getAttr('output_json') ?: [];
        if (!is_array($output)) {
            $output = [];
        }
        $raw = trim((string) $runNode->getAttr('raw_output'));
        if ($seriesId > 0 && $this->shouldNormalizeStoryboardLookReferences($label)) {
            if ($raw !== '') {
                $raw = $this->normalizeStoryboardLookReferences($raw, $seriesId);
            }
            if (isset($output['text']) && is_string($output['text'])) {
                $output['text'] = $this->normalizeStoryboardLookReferences($output['text'], $seriesId);
            }
        }
        if ($raw !== '' && !isset($output['text'])) {
            $output['text'] = $raw;
        }

        return [
            'label' => $label !== '' ? $label : (string) ($previous['id'] ?? '上游节点'),
            'output' => $output,
        ];
    }

    private function immediatePreviousEpisodeNodeLabel(array $orderedNodes, string $targetNodeId): string
    {
        $previous = null;
        foreach ($orderedNodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            if ((string) ($node['id'] ?? '') === $targetNodeId) {
                break;
            }
            $previous = $node;
        }

        if (!is_array($previous)) {
            return '';
        }

        return trim((string) ($previous['label'] ?? $previous['data']['label'] ?? $previous['id'] ?? ''));
    }

    /**
     * 序列化剧本，给每个剧集附带节点执行状态。
     */
    private function serializeSeries(Series $series): array
    {
        $data = $series->toArray();
        $sourceText = trim((string) ($data['source_text'] ?? ''));
        $data['has_source_text'] = $sourceText !== '';
        $data['source_text_length'] = mb_strlen($sourceText);
        // 列表接口保留完整正文供「运行剧本解析」；过大时仍返回，避免前端误用 description 摘要。
        $data['source_text'] = $sourceText !== '' ? $sourceText : null;
        $seriesWorkflowId = (int) ($series->getAttr('series_workflow_id') ?? 0);
        $data['series_workflow_name'] = $this->workflowName($seriesWorkflowId);
        $episodes = [];
        foreach ($series->episodes as $episode) {
            if ($episode instanceof Episode) {
                $episodes[] = $this->serializeEpisode($episode);
            }
        }
        $episodes = $this->attachEpisodeCovers($episodes);
        $data['episodes'] = $episodes;
        $data['cover_url'] = $this->resolveSeriesCoverUrl((int) $series->getAttr('id'), $episodes);
        $blockingRun = WorkflowRuntime::findBlockingSeriesRun((int) $series->getAttr('id'));
        $data['active_workflow_run'] = $blockingRun instanceof WorkflowRun
            ? $this->serializeWorkflowRun($blockingRun)
            : null;
        return $data;
    }

    /**
     * 批量给剧集补 cover_url（第一张已生成的分镜图）。
     * 列表接口不带 shots，这里用一条查询取每集首图，避免 N+1。
     */
    private function attachEpisodeCovers(array $episodes): array
    {
        $episodeIds = [];
        foreach ($episodes as $episode) {
            $id = (int) ($episode['id'] ?? 0);
            if ($id > 0) {
                $episodeIds[] = $id;
            }
        }
        if ($episodeIds === []) {
            return $episodes;
        }

        $revisionByEpisode = [];
        foreach ($episodes as $episode) {
            $episodeId = (int) ($episode['id'] ?? 0);
            $revisionId = (int) ($episode['current_storyboard_revision_id'] ?? 0);
            if ($episodeId > 0 && $revisionId > 0) {
                $revisionByEpisode[$episodeId] = $revisionId;
            }
        }
        if ($revisionByEpisode === []) {
            foreach ($episodes as &$episode) {
                $episode['cover_url'] = '';
            }
            unset($episode);
            return $episodes;
        }

        $coverByEpisode = [];
        $rows = Shot::whereIn('episode_id', array_values(array_unique($episodeIds)))
            ->whereIn('storyboard_revision_id', array_values(array_unique(array_values($revisionByEpisode))))
            ->where('image_url', '<>', '')
            ->field(['episode_id', 'image_url'])
            ->order(['episode_id' => 'asc', 'index' => 'asc', 'id' => 'asc'])
            ->select();
        foreach ($rows as $row) {
            $episodeId = (int) $row->getAttr('episode_id');
            if (!isset($coverByEpisode[$episodeId])) {
                $coverByEpisode[$episodeId] = (string) $row->getAttr('image_url');
            }
        }

        foreach ($episodes as &$episode) {
            $episode['cover_url'] = $coverByEpisode[(int) ($episode['id'] ?? 0)] ?? '';
        }
        unset($episode);

        return $episodes;
    }

    /**
     * 解析剧本封面：优先第一集已生成的分镜图，其次资产库核心视图。
     */
    private function resolveSeriesCoverUrl(int $seriesId, array $episodes): string
    {
        foreach ($episodes as $episode) {
            $cover = trim((string) ($episode['cover_url'] ?? ''));
            if ($cover !== '') {
                return $cover;
            }
        }

        $image = AssetImage::alias('ai')
            ->join('assets a', 'a.id = ai.asset_id')
            ->where('a.series_id', $seriesId)
            ->where('ai.url', '<>', '')
            ->field(['ai.url', 'ai.view_type'])
            ->orderRaw("CASE WHEN ai.view_type = 'main' THEN 0 ELSE 1 END, ai.id ASC")
            ->find();

        return $image instanceof AssetImage ? (string) $image->getAttr('url') : '';
    }

    /**
     * 序列化剧集。
     */
    private function serializeEpisode(Episode $episode, bool $withShots = false): array
    {
        $data = $episode->toArray();
        $workflowId = (int) ($episode->getAttr('workflow_id') ?? 0);
        $data['workflow_name'] = $this->workflowName($workflowId);
        if (!$withShots) {
            unset($data['shots']);
        } else {
            $data['shots'] = $this->attachShotMediaVersions($this->currentEpisodeShots($episode));
            $data['orphaned_media_versions'] = $this->serializeOrphanedMediaVersionsForEpisode($episode);
        }
        $data['workflow_state'] = $this->getEpisodeWorkflowState($episode);
        return $data;
    }

    private function workflowName(int $workflowId): string
    {
        if ($workflowId <= 0) {
            return '';
        }
        if (array_key_exists($workflowId, $this->workflowNameCache)) {
            return $this->workflowNameCache[$workflowId];
        }

        $name = (string) (Workflow::where('id', $workflowId)->value('name') ?: '');
        $this->workflowNameCache[$workflowId] = $name;
        return $name;
    }

    private function currentStoryboardRevisionId(Episode $episode): int
    {
        return (int) ($episode->getAttr('current_storyboard_revision_id') ?? 0);
    }

    private function currentStoryboardRevisionIdForEpisodeId(int $episodeId): int
    {
        if ($episodeId <= 0) {
            return 0;
        }

        return (int) (Episode::where('id', $episodeId)->value('current_storyboard_revision_id') ?: 0);
    }

    private function currentStoryboardRevisionIdForRunNode(int $runNodeId): int
    {
        if ($runNodeId <= 0) {
            return 0;
        }

        $runNode = WorkflowRunNode::find($runNodeId);
        if (!$runNode instanceof WorkflowRunNode) {
            return 0;
        }

        return $this->currentStoryboardRevisionIdForRunId((int) $runNode->getAttr('run_id'));
    }

    private function currentStoryboardRevisionIdForRunId(int $runId): int
    {
        $episodeId = $this->episodeIdForWorkflowRun($runId);
        return $episodeId > 0 ? $this->currentStoryboardRevisionIdForEpisodeId($episodeId) : 0;
    }

    private function episodeIdForWorkflowRun(int $runId): int
    {
        if ($runId <= 0) {
            return 0;
        }

        $run = WorkflowRun::find($runId);
        $payload = $run instanceof WorkflowRun ? ($run->getAttr('payload_json') ?: []) : [];
        return is_array($payload) ? (int) ($payload['episode_id'] ?? 0) : 0;
    }

    private function currentEpisodeShots(Episode $episode): array
    {
        $revisionId = $this->currentStoryboardRevisionId($episode);
        if ($revisionId <= 0) {
            return [];
        }

        $rows = Shot::where('episode_id', (int) $episode->getAttr('id'))
            ->where('storyboard_revision_id', $revisionId)
            ->order(['index' => 'asc', 'id' => 'asc'])
            ->select();

        $shots = [];
        foreach ($rows as $row) {
            if ($row instanceof Shot) {
                $shots[] = $row->toArray();
            }
        }

        return $shots;
    }

    private function attachShotMediaVersions(array $shots): array
    {
        $shotIds = [];
        $revisionIds = [];
        foreach ($shots as $shot) {
            if (is_array($shot) && (int) ($shot['id'] ?? 0) > 0) {
                $shotIds[] = (int) $shot['id'];
            }
            if (is_array($shot) && (int) ($shot['storyboard_revision_id'] ?? 0) > 0) {
                $revisionIds[] = (int) $shot['storyboard_revision_id'];
            }
        }
        if ($shotIds === []) {
            return $shots;
        }

        $currentRevisionId = $revisionIds !== [] ? (int) $revisionIds[0] : 0;
        $versionsQuery = ShotMediaVersion::whereIn('shot_id', array_values(array_unique($shotIds)))
            ->where('orphaned', 0);
        $revisionIds = array_values(array_unique($revisionIds));
        if ($revisionIds !== []) {
            // whereIn 不会匹配 NULL；生成时若未 stamp revision，第二版会从 UI 消失。
            $versionsQuery->where(function ($query) use ($revisionIds): void {
                $query->whereIn('storyboard_revision_id', $revisionIds)
                    ->whereNull('storyboard_revision_id', 'OR');
            });
        }
        $versions = $versionsQuery->order(['media_type' => 'asc', 'id' => 'asc'])
            ->select();

        $versionsByShot = [];
        foreach ($versions as $version) {
            if (!$version instanceof ShotMediaVersion) {
                continue;
            }
            $shotId = (int) $version->getAttr('shot_id');
            $versionsByShot[$shotId][] = $this->serializeShotMediaVersion($version, $currentRevisionId);
        }

        foreach ($shots as &$shot) {
            if (!is_array($shot)) {
                continue;
            }
            $shotId = (int) ($shot['id'] ?? 0);
            $shot['media_versions'] = $versionsByShot[$shotId] ?? [];
        }
        unset($shot);

        return $shots;
    }

    private function serializeOrphanedMediaVersionsForEpisode(Episode $episode): array
    {
        $episodeId = (int) $episode->getAttr('id');
        if ($episodeId <= 0) {
            return [];
        }

        $currentRevisionId = $this->currentStoryboardRevisionId($episode);
        $versions = ShotMediaVersion::where('episode_id', $episodeId)
            ->where('orphaned', 1)
            ->where('media_type', 'video')
            ->where('url', '<>', '')
            ->order(['id' => 'asc'])
            ->select();

        $rows = [];
        foreach ($versions as $version) {
            if ($version instanceof ShotMediaVersion) {
                $rows[] = $this->serializeShotMediaVersion($version, $currentRevisionId);
            }
        }

        return $rows;
    }

    private function serializeShotMediaVersion(ShotMediaVersion $version, ?int $currentRevisionId = null): array
    {
        $orphaned = (bool) $version->getAttr('orphaned');
        $url = trim((string) $version->getAttr('url'));
        $revisionId = (int) ($version->getAttr('storyboard_revision_id') ?? 0);
        $isSelectable = !$orphaned
            && $url !== ''
            && $currentRevisionId !== null
            && $currentRevisionId > 0
            && $revisionId === $currentRevisionId;

        return [
            'id' => (int) $version->getAttr('id'),
            'shot_id' => (int) $version->getAttr('shot_id'),
            'shot_key' => (string) ($version->getAttr('shot_key') ?? ''),
            'storyboard_revision_id' => $revisionId > 0 ? $revisionId : null,
            'media_type' => (string) $version->getAttr('media_type'),
            'url' => (string) $version->getAttr('url'),
            'poster_url' => (string) $version->getAttr('poster_url'),
            'end_frame_url' => (string) $version->getAttr('end_frame_url'),
            'prompt' => (string) $version->getAttr('prompt'),
            'source' => (string) $version->getAttr('source'),
            'is_selected' => (bool) $version->getAttr('is_selected'),
            'orphaned' => $orphaned,
            'is_selectable' => $isSelectable,
            'video_job_id' => (int) ($version->getAttr('video_job_id') ?? 0) ?: null,
            'parent_version_id' => (int) ($version->getAttr('parent_version_id') ?? 0) ?: null,
            'ai_request_log_id' => (int) ($version->getAttr('ai_request_log_id') ?? 0) ?: null,
            'meta_json' => $version->getAttr('meta_json') ?: [],
            'create_time' => $version->getAttr('create_time'),
        ];
    }

    /**
     * 返回当前剧集工作流的节点状态。
     */
    private function getEpisodeWorkflowState(Episode $episode): array
    {
        $workflowId = (int) ($episode->getAttr('workflow_id') ?? 0);
        if ($workflowId <= 0) {
            return [
                'run_id' => null,
                'status' => 'unbound',
                'auto_execution_run_id' => null,
                'auto_execution_status' => 'idle',
                'auto_execution_locked' => false,
                'nodes' => [],
            ];
        }

        $currentNodes = [];
        $workflow = Workflow::where('id', $workflowId)
            ->where('user_id', $this->seriesOwnerId(null, (int) $episode->getAttr('user_id')))
            ->find();
        if ($workflow instanceof Workflow) {
            $graph = $workflow->getAttr('graph') ?: ['nodes' => [], 'edges' => []];
            if (!is_array($graph)) {
                $graph = ['nodes' => [], 'edges' => []];
            }
            $currentNodes = $this->topologicalOrder($graph);
            if ($currentNodes !== []) {
                $this->ensureEpisodeWorkflowRun($episode, $workflow, $currentNodes);
            }
        }

        $run = $this->findLatestEpisodeWorkflowRun($episode);
        $autoRun = $this->latestEpisodeAutoWorkflowRun($episode);
        $states = $this->episodeWorkflowNodeStates((int) $episode->getAttr('id'));
        if ($states === []) {
            return [
                'run_id' => null,
                'status' => $autoRun instanceof WorkflowRun && (string) $autoRun->getAttr('status') === 'queued' ? 'queued' : 'idle',
                'auto_execution_run_id' => $autoRun instanceof WorkflowRun ? (int) $autoRun->getAttr('id') : null,
                'auto_execution_status' => $autoRun instanceof WorkflowRun ? $this->normalizeEpisodeAutoExecutionStatus((string) $autoRun->getAttr('status')) : 'idle',
                'auto_execution_locked' => $autoRun instanceof WorkflowRun && in_array((string) $autoRun->getAttr('status'), ['queued', 'running', 'waiting_async'], true),
                'nodes' => [],
            ];
        }

        $status = 'idle';
        $currentNodeLabel = '';
        $success = 0;
        $failed = 0;
        $running = 0;
        foreach ($states as $state) {
            if (!$state instanceof EpisodeWorkflowNodeState) {
                continue;
            }
            $nodeStatus = (string) $state->getAttr('status');
            if ($nodeStatus === 'success') {
                $success++;
            } elseif ($nodeStatus === 'failed') {
                $failed++;
            } elseif ($nodeStatus === 'running') {
                $running++;
                if ($currentNodeLabel === '') {
                    $currentNodeLabel = (string) $state->getAttr('label');
                }
            }
        }
        if ($running > 0) {
            $status = 'running';
        } elseif ($failed > 0) {
            $status = 'failed';
        } elseif ($run instanceof WorkflowRun && (string) $run->getAttr('status') === 'queued') {
            $status = 'queued';
            if ($currentNodeLabel === '') {
                $currentNodeLabel = (string) $run->getAttr('current_node_label');
            }
        } elseif ($run instanceof WorkflowRun && (string) $run->getAttr('status') === 'cancelled') {
            $status = 'cancelled';
        } elseif ($success > 0 && $success >= count($states)) {
            $status = 'success';
        }

        $progress = count($states) > 0 ? (int) floor(($success / count($states)) * 100) : 0;
        if ($run instanceof WorkflowRun && $status === 'success' && (string) $run->getAttr('status') !== 'success') {
            WorkflowRun::where('id', (int) $run->getAttr('id'))->update([
                'status' => 'success',
                'progress' => $progress,
                'current_node_label' => '',
                'error_message' => '',
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
        }

        return [
            'run_id' => $run instanceof WorkflowRun ? (int) $run->getAttr('id') : null,
            'status' => $status,
            'auto_execution_run_id' => $autoRun instanceof WorkflowRun ? (int) $autoRun->getAttr('id') : null,
            'auto_execution_status' => $autoRun instanceof WorkflowRun ? $this->normalizeEpisodeAutoExecutionStatus((string) $autoRun->getAttr('status')) : 'idle',
            'auto_execution_locked' => $autoRun instanceof WorkflowRun && in_array((string) $autoRun->getAttr('status'), ['queued', 'running', 'waiting_async'], true),
            'current_node_label' => $currentNodeLabel,
            'progress' => $progress,
            'nodes' => array_map(
                fn (EpisodeWorkflowNodeState $state): array => $this->serializeEpisodeWorkflowStateNode($state),
                $states
            ),
        ];
    }

    private function normalizeEpisodeAutoExecutionStatus(string $status): string
    {
        return match ($status) {
            'queued', 'running', 'waiting_async', 'success', 'failed', 'cancelled' => $status,
            default => 'idle',
        };
    }

    /**
     * @return array<int, EpisodeWorkflowNodeState>
     */
    private function episodeWorkflowNodeStates(int $episodeId): array
    {
        if ($episodeId <= 0) {
            return [];
        }

        $rows = EpisodeWorkflowNodeState::where('episode_id', $episodeId)
            ->order(['sort' => 'asc', 'id' => 'asc'])
            ->select();

        $states = [];
        foreach ($rows as $row) {
            if ($row instanceof EpisodeWorkflowNodeState) {
                $this->reconcileEpisodeWorkflowNodeState($row);
                $states[] = $row;
            }
        }

        return $states;
    }

    /**
     * 某些历史失败发生在状态同步修复之前，页面态可能还停留在 running。
     * 读取剧集详情时顺手做一次轻量自愈，避免用户刷新后仍一直看到“生成中”。
     */
    private function reconcileEpisodeWorkflowNodeState(EpisodeWorkflowNodeState $state): void
    {
        if ($this->shouldResetEpisodeWorkflowNodeStateReferences($state)) {
            $this->resetEpisodeWorkflowNodeState($state, true);
            return;
        }

        $runNodeId = (int) ($state->getAttr('workflow_run_node_id') ?? 0);
        if ($runNodeId <= 0) {
            return;
        }

        $stateStatus = (string) $state->getAttr('status');
        $isVideoState = (string) $state->getAttr('kind') === 'video';
        $shouldRecoverFailedVideo = $isVideoState
            && $stateStatus === 'failed'
            && str_contains((string) $state->getAttr('error_message'), '下载远端媒体失败');
        if ($stateStatus !== 'running' && !$shouldRecoverFailedVideo) {
            return;
        }

        $runNode = WorkflowRunNode::find($runNodeId);
        if (!$runNode instanceof WorkflowRunNode) {
            return;
        }

        $runNodeStatus = (string) $runNode->getAttr('status');
        if ($runNodeStatus === 'queued') {
            return;
        }
        if ($shouldRecoverFailedVideo) {
            $this->refreshVideoWorkflowProgress($runNodeId, (string) $runNode->getAttr('error_message'));
            $state->refresh();
            return;
        }
        if ($runNodeStatus === 'running') {
            if ($this->recoverInterruptedEpisodeWorkflowNodeState($state, $runNode)) {
                $state->refresh();
                return;
            }
            return;
        }

        if ((string) $state->getAttr('kind') === 'video') {
            $this->refreshVideoWorkflowProgress($runNodeId, (string) $runNode->getAttr('error_message'));
            $state->refresh();
            return;
        }

        $mappedStatus = $runNodeStatus === 'cancelled' ? 'skipped' : $runNodeStatus;
        $this->syncEpisodeWorkflowStateForRunNode(
            $runNode,
            $mappedStatus,
            is_array($runNode->getAttr('output_json')) ? $runNode->getAttr('output_json') : [],
            (string) $runNode->getAttr('raw_output'),
            (string) $runNode->getAttr('error_message'),
        );
        $state->refresh();
    }

    private function recoverInterruptedEpisodeWorkflowNodeState(EpisodeWorkflowNodeState $state, WorkflowRunNode $runNode): bool
    {
        $startedAt = (string) ($runNode->getAttr('started_at') ?? '');
        if ($startedAt === '') {
            return false;
        }

        $startedTs = strtotime($startedAt);
        if ($startedTs === false) {
            return false;
        }

        $runNodeId = (int) $runNode->getAttr('id');
        $episodeId = (int) ($state->getAttr('episode_id') ?? 0);
        $label = (string) ($runNode->getAttr('label') ?? $state->getAttr('label'));
        $kind = (string) ($runNode->getAttr('kind') ?? $state->getAttr('kind'));
        $output = $runNode->getAttr('output_json') ?: [];
        if (!is_array($output)) {
            $output = [];
        }
        $raw = (string) $runNode->getAttr('raw_output');

        if ($kind === 'video') {
            $this->refreshVideoWorkflowProgress($runNodeId);
            return true;
        }

        if ($kind === 'text' && $output !== [] && trim($raw) !== '' && (time() - $startedTs) >= 60) {
            $runNode->save([
                'status' => 'success',
                'error_message' => '',
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
            $this->syncEpisodeWorkflowStateForRunNode($runNode, 'success', $output, $raw, '');
            $this->refreshEpisodeWorkflowRunProgress((int) $runNode->getAttr('run_id'));

            return true;
        }

        $latestAiLog = AiRequestLog::where('workflow_run_node_id', $runNodeId)
            ->order('id', 'desc')
            ->find();
        $latestAiLogTs = null;
        if ($latestAiLog instanceof AiRequestLog) {
            $loggedAt = strtotime((string) ($latestAiLog->getAttr('create_time') ?? ''));
            if ($loggedAt !== false && $loggedAt >= $startedTs) {
                $latestAiLogTs = $loggedAt;
            }
        }

        if ($latestAiLogTs !== null) {
            if ((time() - $latestAiLogTs) < 120) {
                return false;
            }
        } elseif ((time() - $startedTs) < (15 * 60)) {
            return false;
        }

        if ($this->isStoryboardRevisionNode($label, $kind)) {
            $this->restoreLatestStoryboardRevisionForEpisodeNode($episodeId, $runNodeId);
        }

        $message = $latestAiLogTs !== null
            ? ($this->isStoryboardRevisionNode($label, $kind)
                ? '本次分镜生成在 AI 返回后未完成状态收口，已恢复最近一次成功分镜，请重新生成。'
                : '本次节点在 AI 返回后未完成状态收口，请重新生成。')
            : ($this->isStoryboardRevisionNode($label, $kind)
                ? '本次分镜重生成在完成前中断，已恢复最近一次成功分镜，请重新生成。'
                : '本次节点执行已中断，请重新生成。');

        $runNode->save([
            'status' => 'failed',
            'error_message' => $message,
            'finished_at' => date('Y-m-d H:i:s'),
        ]);
        $this->syncEpisodeWorkflowStateForRunNode(
            $runNode,
            'failed',
            $output,
            $raw,
            $message,
        );
        $this->refreshEpisodeWorkflowRunProgress((int) $runNode->getAttr('run_id'));

        return true;
    }

    private function restoreLatestStoryboardRevisionForEpisodeNode(int $episodeId, int $runNodeId): void
    {
        if ($episodeId <= 0 || $runNodeId <= 0) {
            return;
        }

        $currentRevisionId = $this->currentStoryboardRevisionIdForEpisodeId($episodeId);
        if ($currentRevisionId > 0) {
            return;
        }

        $revision = StoryboardRevision::where('episode_id', $episodeId)
            ->where('workflow_run_node_id', $runNodeId)
            ->order(['id' => 'desc'])
            ->find();
        if (!$revision instanceof StoryboardRevision) {
            return;
        }

        StoryboardRevision::where('episode_id', $episodeId)
            ->where('status', 'current')
            ->update(['status' => 'archived']);

        $revision->save(['status' => 'current']);
        Episode::where('id', $episodeId)->update(['current_storyboard_revision_id' => (int) $revision->getAttr('id')]);
    }

    private function findEpisodeWorkflowNodeState(int $episodeId, string $workflowNodeId): ?EpisodeWorkflowNodeState
    {
        if ($episodeId <= 0 || trim($workflowNodeId) === '') {
            return null;
        }

        $state = EpisodeWorkflowNodeState::where('episode_id', $episodeId)
            ->where('workflow_node_id', $workflowNodeId)
            ->find();

        return $state instanceof EpisodeWorkflowNodeState ? $state : null;
    }

    private function shouldResetEpisodeWorkflowNodeStateReferences(EpisodeWorkflowNodeState $state, ?int $expectedRunId = null, ?string $expectedWorkflowNodeId = null): bool
    {
        $stateRunId = (int) ($state->getAttr('workflow_run_id') ?? 0);
        $stateRunNodeId = (int) ($state->getAttr('workflow_run_node_id') ?? 0);
        $stateStatus = (string) ($state->getAttr('status') ?? '');
        $workflowNodeId = trim($expectedWorkflowNodeId !== null ? $expectedWorkflowNodeId : (string) ($state->getAttr('workflow_node_id') ?? ''));

        if ($expectedRunId !== null && $expectedRunId > 0 && $stateRunId > 0 && $stateRunId !== $expectedRunId) {
            return true;
        }
        if ($stateRunId > 0 && !WorkflowRun::where('id', $stateRunId)->find()) {
            return true;
        }
        if ($stateStatus === 'running' && $stateRunNodeId <= 0) {
            return true;
        }
        if ($stateRunNodeId <= 0) {
            return false;
        }

        $runNode = WorkflowRunNode::find($stateRunNodeId);
        if (!$runNode instanceof WorkflowRunNode) {
            return true;
        }
        if ($expectedRunId !== null && $expectedRunId > 0 && (int) $runNode->getAttr('run_id') !== $expectedRunId) {
            return true;
        }
        if ($stateRunId > 0 && (int) $runNode->getAttr('run_id') !== $stateRunId) {
            return true;
        }
        if ($workflowNodeId !== '' && (string) $runNode->getAttr('workflow_node_id') !== $workflowNodeId) {
            return true;
        }

        return false;
    }

    private function resetEpisodeWorkflowNodeState(EpisodeWorkflowNodeState $state, bool $clearError = false): void
    {
        $state->save([
            'workflow_run_id' => null,
            'workflow_run_node_id' => null,
            'status' => 'queued',
            'input_json' => [],
            'output_json' => [],
            'raw_output' => '',
            'upstream_snapshot_json' => [],
            'error_message' => $clearError ? '' : (string) ($state->getAttr('error_message') ?? ''),
            'started_at' => null,
            'finished_at' => null,
            'duration_ms' => 0,
        ]);
    }

    private function ensureEpisodeWorkflowNodeStates(Episode $episode, Workflow $workflow, array $nodes, ?WorkflowRun $run = null): void
    {
        $episodeId = (int) $episode->getAttr('id');
        if ($episodeId <= 0) {
            return;
        }

        $workflowNodeIds = [];
        foreach (array_values($nodes) as $index => $node) {
            if (!is_array($node)) {
                continue;
            }
            $workflowNodeId = trim((string) ($node['id'] ?? ''));
            if ($workflowNodeId === '') {
                continue;
            }
            $workflowNodeIds[] = $workflowNodeId;
            $state = $this->findEpisodeWorkflowNodeState($episodeId, $workflowNodeId);
            $data = [
                'user_id' => $this->seriesOwnerId(null, (int) $episode->getAttr('user_id')),
                'series_id' => (int) $episode->getAttr('series_id'),
                'episode_id' => $episodeId,
                'workflow_id' => (int) $workflow->getAttr('id'),
                'workflow_run_id' => $run instanceof WorkflowRun ? (int) $run->getAttr('id') : null,
                'workflow_node_id' => $workflowNodeId,
                'label' => (string) ($node['label'] ?? $node['data']['label'] ?? ''),
                'kind' => (string) ($node['data']['kind'] ?? $node['kind'] ?? ''),
                'sort' => $index + 1,
                'version_token' => implode(':', [
                    (int) $episode->getAttr('id'),
                    (int) $workflow->getAttr('id'),
                    $workflowNodeId,
                    $index + 1,
                ]),
            ];
            if ($state instanceof EpisodeWorkflowNodeState) {
                if ($this->shouldResetEpisodeWorkflowNodeStateReferences($state, $run instanceof WorkflowRun ? (int) $run->getAttr('id') : null, $workflowNodeId)) {
                    $state->save(array_merge($data, [
                        'workflow_run_node_id' => null,
                        'status' => 'queued',
                        'input_json' => [],
                        'output_json' => [],
                        'raw_output' => '',
                        'upstream_snapshot_json' => [],
                        'error_message' => '',
                        'started_at' => null,
                        'finished_at' => null,
                        'duration_ms' => 0,
                    ]));
                    continue;
                }
                $state->save($data);
                continue;
            }

            EpisodeWorkflowNodeState::create(array_merge($data, [
                'status' => 'queued',
                'input_json' => [],
                'output_json' => [],
                'raw_output' => '',
                'upstream_snapshot_json' => [],
                'error_message' => '',
                'started_at' => null,
                'finished_at' => null,
                'duration_ms' => 0,
            ]));
        }

        if ($workflowNodeIds !== []) {
            EpisodeWorkflowNodeState::where('episode_id', $episodeId)
                ->whereNotIn('workflow_node_id', array_values(array_unique($workflowNodeIds)))
                ->delete();
        }
    }

    /**
     * 查找某集最近一次节点执行记录。
     */
    private function findLatestEpisodeWorkflowRun(Episode $episode): ?WorkflowRun
    {
        $episodeId = (int) $episode->getAttr('id');
        $workflowId = (int) ($episode->getAttr('workflow_id') ?? 0);
        if ($episodeId <= 0 || $workflowId <= 0) {
            return null;
        }

        $run = WorkflowRun::with(['nodes'])
            ->where('series_id', (int) $episode->getAttr('series_id'))
            ->where('workflow_id', $workflowId)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.scope')) = 'episode'")
            ->whereRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.episode_id')) AS UNSIGNED) = ?", [$episodeId])
            ->order('id', 'desc')
            ->find();

        return $run instanceof WorkflowRun ? $run : null;
    }

    private function latestEpisodeAutoWorkflowRun(Episode $episode): ?WorkflowRun
    {
        $episodeId = (int) $episode->getAttr('id');
        $workflowId = (int) ($episode->getAttr('workflow_id') ?? 0);
        if ($episodeId <= 0 || $workflowId <= 0) {
            return null;
        }

        $run = WorkflowRun::with(['nodes'])
            ->where('series_id', (int) $episode->getAttr('series_id'))
            ->where('workflow_id', $workflowId)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.scope')) = 'episode'")
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.execution_mode')) = 'auto'")
            ->whereRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.episode_id')) AS UNSIGNED) = ?", [$episodeId])
            ->order('id', 'desc')
            ->find();

        return $run instanceof WorkflowRun ? $run : null;
    }

    private function activeEpisodeAutoWorkflowRun(Episode $episode): ?WorkflowRun
    {
        $run = $this->latestEpisodeAutoWorkflowRun($episode);
        if (!$run instanceof WorkflowRun) {
            return null;
        }

        return in_array((string) $run->getAttr('status'), ['queued', 'running', 'waiting_async'], true)
            ? $run
            : null;
    }

    private function assertEpisodeAutoExecutionEditable(Episode $episode, string $message): void
    {
        if ($this->activeEpisodeAutoWorkflowRun($episode) instanceof WorkflowRun) {
            abort(409, $message);
        }
    }

    /**
     * 找到自动执行下一步应继续的节点。
     * success 之前的节点视为已完成，其余状态都视为需要处理或人工介入。
     *
     * @param array<int, array<string, mixed>> $nodes
     */
    private function nextEpisodeAutoRunnableNode(Episode $episode, array $nodes): ?array
    {
        $stateMap = [];
        foreach ($this->episodeWorkflowNodeStates((int) $episode->getAttr('id')) as $state) {
            if ($state instanceof EpisodeWorkflowNodeState) {
                $stateMap[(string) $state->getAttr('workflow_node_id')] = (string) $state->getAttr('status');
            }
        }

        foreach ($nodes as $node) {
            $nodeId = (string) ($node['id'] ?? '');
            if ($nodeId === '') {
                continue;
            }
            $status = $stateMap[$nodeId] ?? 'queued';
            if ($status === 'success') {
                continue;
            }
            if ($status === 'running') {
                return null;
            }

            return $node;
        }

        return null;
    }

    private function markEpisodeAutoRunWaitingAsync(WorkflowRun $run, string $nodeId, string $kind): void
    {
        $payload = $run->getAttr('payload_json') ?: [];
        if (!is_array($payload)) {
            $payload = [];
        }
        $payload['execution_mode'] = 'auto';
        $payload['awaiting_node_id'] = $nodeId;
        $payload['awaiting_kind'] = $kind;
        $run->save([
            'status' => 'waiting_async',
            'payload_json' => $payload,
            'finished_at' => null,
            'error_message' => '',
        ]);
        RedisCache::bumpVersion('workflow_run:' . (int) $run->getAttr('id'));
    }

    /**
     * 前端有时会拿到旧运行记录里的 workflow_node_id。节点视觉上还存在，
     * 但流程图保存后 id 已变化，所以这里用同一剧集历史运行记录做语义映射。
     *
     * @param array<int, array<string, mixed>> $nodes
     */
    private function findWorkflowNodeForEpisodeAction(Episode $episode, array $nodes, string $nodeId): ?array
    {
        foreach ($nodes as $node) {
            if ((string) ($node['id'] ?? '') === $nodeId) {
                return $node;
            }
        }

        $staleNode = $this->findEpisodeRunNodeByWorkflowNodeId($episode, $nodeId);
        if ($staleNode instanceof WorkflowRunNode) {
            return $this->findWorkflowNodeByRunNodeSemantic($nodes, $staleNode);
        }

        return null;
    }

    private function resolveEpisodeRunNodeForAction(Episode $episode, string $nodeId, string $expectedKind = ''): array
    {
        $workflowId = (int) ($episode->getAttr('workflow_id') ?? 0);
        if ($workflowId <= 0) {
            abort(422, '请先选择剧集工作流');
        }

        $workflow = $this->assertEpisodeWorkflow($workflowId);
        $graph = $workflow->getAttr('graph') ?: ['nodes' => [], 'edges' => []];
        if (!is_array($graph)) {
            $graph = ['nodes' => [], 'edges' => []];
        }
        $nodes = $this->topologicalOrder($graph);
        $target = $this->findWorkflowNodeForEpisodeAction($episode, $nodes, $nodeId);
        if ($target === null) {
            abort(404, '当前剧集绑定的工作流中找不到这个节点，请刷新页面后重试');
        }

        $kind = (string) ($target['data']['kind'] ?? $target['kind'] ?? '');
        if ($expectedKind !== '' && $kind !== $expectedKind) {
            abort(404, '当前节点类型不匹配，请刷新页面后重试');
        }

        $run = $this->ensureEpisodeWorkflowRun($episode, $workflow, $nodes);
        $runNode = $this->findWorkflowRunNode((int) $run->getAttr('id'), $target);
        if (!$runNode instanceof WorkflowRunNode) {
            abort(404, '当前节点没有可用的运行记录，请刷新页面后重试');
        }

        return [$run, $runNode, $target, $nodes];
    }

    private function findEpisodeRunNodeByWorkflowNodeId(Episode $episode, string $workflowNodeId): ?WorkflowRunNode
    {
        $workflowNodeId = trim($workflowNodeId);
        $episodeId = (int) $episode->getAttr('id');
        $workflowId = (int) ($episode->getAttr('workflow_id') ?? 0);
        if ($workflowNodeId === '' || $episodeId <= 0 || $workflowId <= 0) {
            return null;
        }

        $rows = WorkflowRunNode::alias('n')
            ->join('workflow_runs r', 'r.id = n.run_id')
            ->where('r.series_id', (int) $episode->getAttr('series_id'))
            ->where('r.workflow_id', $workflowId)
            ->where('n.workflow_node_id', $workflowNodeId)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(r.payload_json, '$.scope')) = 'episode'")
            ->whereRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(r.payload_json, '$.episode_id')) AS UNSIGNED) = ?", [$episodeId])
            ->field('n.*')
            ->order('n.id', 'desc')
            ->select();

        $best = null;
        foreach ($rows as $row) {
            if (!$row instanceof WorkflowRunNode) {
                continue;
            }
            if (!$best instanceof WorkflowRunNode || $this->isBetterWorkflowRunNode($row, $best)) {
                $best = $row;
            }
        }

        return $best;
    }

    /**
     * 确保剧集工作流运行记录和节点记录存在。
     */
    private function ensureEpisodeWorkflowRun(Episode $episode, Workflow $workflow, array $nodes): WorkflowRun
    {
        $run = $this->findLatestEpisodeWorkflowRun($episode);
        if (!$run instanceof WorkflowRun) {
            $run = new WorkflowRun();
            $run->save([
                'user_id' => $this->seriesOwnerId(null, (int) $episode->getAttr('user_id')),
                'series_id' => (int) $episode->getAttr('series_id'),
                'workflow_id' => (int) $workflow->getAttr('id'),
                'episode_workflow_id' => null,
                'status' => 'idle',
                'source_text' => (string) ($episode->getAttr('plot_input') ?? ''),
                'payload_json' => $this->episodeWorkflowPayloadDefaults($episode, $workflow),
                'result_json' => [],
                'progress' => 0,
                'current_node_label' => '',
                'error_message' => '',
            ]);
        }

        foreach (array_values($nodes) as $index => $node) {
            $workflowNodeId = (string) ($node['id'] ?? '');
            if ($workflowNodeId === '') {
                continue;
            }
            $node['sort'] = $index + 1;
            $exists = WorkflowRunNode::where('run_id', (int) $run->getAttr('id'))
                ->where('workflow_node_id', $workflowNodeId)
                ->find();
            if ($exists instanceof WorkflowRunNode) {
                continue;
            }
            $semanticExisting = $this->findBestWorkflowRunNodeBySemantic(
                (int) $run->getAttr('id'),
                (string) ($node['label'] ?? $node['data']['label'] ?? ''),
                (string) ($node['data']['kind'] ?? $node['kind'] ?? ''),
                $index + 1,
            );
            if ($semanticExisting instanceof WorkflowRunNode) {
                $semanticExisting->save([
                    'workflow_node_id' => $workflowNodeId,
                    'label' => (string) ($node['label'] ?? $node['data']['label'] ?? ''),
                    'kind' => (string) ($node['data']['kind'] ?? $node['kind'] ?? ''),
                    'sort' => $index + 1,
                ]);
                continue;
            }
            WorkflowRunNode::create([
                'user_id' => $this->seriesOwnerId(null, (int) $run->getAttr('user_id')),
                'run_id' => (int) $run->getAttr('id'),
                'workflow_node_id' => $workflowNodeId,
                'label' => (string) ($node['label'] ?? $node['data']['label'] ?? ''),
                'kind' => (string) ($node['data']['kind'] ?? $node['kind'] ?? ''),
                'sort' => $index + 1,
                'status' => 'queued',
                'depends_on_json' => [],
                'input_json' => [],
                'output_json' => [],
                'raw_output' => '',
                'error_message' => '',
            ]);
        }

        $this->ensureEpisodeWorkflowNodeStates($episode, $workflow, $nodes, $run);

        return WorkflowRun::with(['nodes'])->findOrFail((int) $run->getAttr('id'));
    }

    /**
     * 刷新剧集节点运行进度。
     */
    private function refreshEpisodeWorkflowRunProgress(int $runId): void
    {
        $run = WorkflowRun::find($runId);
        $payload = $run instanceof WorkflowRun ? ($run->getAttr('payload_json') ?: []) : [];
        $episodeId = is_array($payload) ? (int) ($payload['episode_id'] ?? 0) : 0;
        $runStatus = $run instanceof WorkflowRun ? (string) $run->getAttr('status') : '';
        $nodes = $episodeId > 0
            ? $this->episodeWorkflowNodeStates($episodeId)
            : WorkflowRunNode::where('run_id', $runId)->select()->all();
        $total = count($nodes);
        $success = 0;
        $failed = 0;
        $running = 0;
        $lastError = '';
        $currentNodeLabel = '';
        foreach ($nodes as $node) {
            if (!$node instanceof WorkflowRunNode && !$node instanceof EpisodeWorkflowNodeState) {
                continue;
            }
            $status = (string) $node->getAttr('status');
            if ($status === 'success') {
                $success++;
            } elseif ($status === 'failed') {
                $failed++;
                if ($lastError === '') {
                    $lastError = (string) ($node->getAttr('error_message') ?? '');
                }
            } elseif ($status === 'running') {
                $running++;
                if ($currentNodeLabel === '') {
                    $currentNodeLabel = (string) ($node->getAttr('label') ?? '');
                }
            }
        }
        $isDone = $total > 0 && $success >= $total;
        $isFailed = $failed > 0;
        $nextStatus = $isDone ? 'success' : ($isFailed ? 'failed' : ($running > 0 ? 'running' : 'idle'));
        if ($runStatus === 'waiting_async' && !$isDone && !$isFailed) {
            $nextStatus = 'waiting_async';
        } elseif ($runStatus === 'cancelled' && !$isDone) {
            $nextStatus = 'cancelled';
        }
        WorkflowRun::where('id', $runId)->update([
            'status' => $nextStatus,
            'progress' => $total > 0 ? (int) floor(($success / $total) * 100) : 0,
            'finished_at' => ($isDone || $isFailed || $nextStatus === 'cancelled') ? date('Y-m-d H:i:s') : null,
            'current_node_label' => $currentNodeLabel,
            'error_message' => $nextStatus === 'cancelled'
                ? ((string) ($run?->getAttr('error_message') ?? '') ?: '已手动取消本集自动执行')
                : ($isFailed ? mb_substr($lastError, 0, 2000) : ''),
        ]);

        if ($episodeId > 0) {
            Episode::where('id', $episodeId)->update(['status' => $isDone ? 'done' : 'production']);
        }
    }

    /**
     * 序列化剧集节点状态。
     */
    private function serializeEpisodeWorkflowStateNode(EpisodeWorkflowNodeState $node): array
    {
        $output = $node->getAttr('output_json') ?: [];
        if (!is_array($output)) {
            $output = [];
        }
        $label = (string) $node->getAttr('label');
        $rawOutput = (string) $node->getAttr('raw_output');
        if ($this->shouldNormalizeStoryboardLookReferences($label)) {
            $seriesId = (int) $node->getAttr('series_id');
            if ($seriesId > 0) {
                $rawOutput = $this->normalizeStoryboardLookReferences($rawOutput, $seriesId);
                if (isset($output['text']) && is_string($output['text'])) {
                    $output['text'] = $this->normalizeStoryboardLookReferences($output['text'], $seriesId);
                }
            }
        }
        // 即使 episode state 为 stale（例如只改了镜1分镜），仍需回填当前 revision 上仍在跑的视频任务，
        // 否则克隆出的僵尸 running 永远无法从供应商拉回成功结果。
        if ((string) $node->getAttr('kind') === 'video') {
            $runNodeId = (int) ($node->getAttr('workflow_run_node_id') ?? 0);
            $runNode = $runNodeId > 0 ? WorkflowRunNode::find($runNodeId) : null;
            if ($runNode instanceof WorkflowRunNode) {
                $output = $this->freshVideoRunNodeOutput($runNode, $output);
            }
        }

        return [
            'id' => (int) $node->getAttr('id'),
            'workflow_node_id' => (string) $node->getAttr('workflow_node_id'),
            'label' => $label,
            'kind' => (string) $node->getAttr('kind'),
            'sort' => (int) $node->getAttr('sort'),
            'status' => (string) $node->getAttr('status'),
            'error_message' => (string) $node->getAttr('error_message'),
            'output_json' => $output,
            'raw_output' => $rawOutput,
            'duration_ms' => (int) $node->getAttr('duration_ms'),
            'started_at' => $node->getAttr('started_at'),
            'finished_at' => $node->getAttr('finished_at'),
        ];
    }

    private function syncEpisodeWorkflowStateForRunNode(
        WorkflowRunNode $runNode,
        ?string $statusOverride = null,
        ?array $outputOverride = null,
        ?string $rawOverride = null,
        ?string $errorOverride = null,
    ): void {
        $episodeId = $this->episodeIdForWorkflowRun((int) $runNode->getAttr('run_id'));
        if ($episodeId <= 0) {
            return;
        }

        $state = $this->findEpisodeWorkflowNodeState($episodeId, (string) $runNode->getAttr('workflow_node_id'));
        if (!$state instanceof EpisodeWorkflowNodeState) {
            return;
        }

        $status = $statusOverride ?? (string) $runNode->getAttr('status');
        $output = $outputOverride ?? ($runNode->getAttr('output_json') ?: []);
        if (!is_array($output)) {
            $output = [];
        }

        $state->save([
            'workflow_run_id' => (int) $runNode->getAttr('run_id'),
            'workflow_run_node_id' => (int) $runNode->getAttr('id'),
            'status' => $status,
            'input_json' => $runNode->getAttr('input_json') ?: [],
            'output_json' => $output,
            'raw_output' => $rawOverride ?? (string) $runNode->getAttr('raw_output'),
            'error_message' => $errorOverride ?? (string) $runNode->getAttr('error_message'),
            'started_at' => $runNode->getAttr('started_at'),
            'finished_at' => $runNode->getAttr('finished_at'),
            'duration_ms' => (int) $runNode->getAttr('duration_ms'),
        ]);
    }

    private function workflowRunSeriesId(int $runId): int
    {
        static $cache = [];
        if ($runId <= 0) {
            return 0;
        }
        if (array_key_exists($runId, $cache)) {
            return $cache[$runId];
        }

        $run = WorkflowRun::where('id', $runId)->find();
        $cache[$runId] = $run instanceof WorkflowRun ? (int) $run->getAttr('series_id') : 0;
        return $cache[$runId];
    }

    private function freshVideoRunNodeOutput(WorkflowRunNode $node, array $output): array
    {
        $storyboardRevisionId = $this->currentStoryboardRevisionIdForRunNode((int) $node->getAttr('id'));
        if ($storyboardRevisionId <= 0) {
            return in_array((string) $node->getAttr('status'), ['queued', 'skipped', 'cancelled'], true) && $output === []
                ? $output
                : [];
        }

        $nodeStatus = (string) $node->getAttr('status');
        $inFlightCount = (int) VideoJob::where('workflow_run_node_id', (int) $node->getAttr('id'))
            ->where('storyboard_revision_id', $storyboardRevisionId)
            ->whereIn('status', ['queued', 'blocked', 'running'])
            ->count();
        // 单镜改分镜后 run_node 常被置 queued + 空 output；若仍有 in-flight job，不能直接短路，否则无法回填。
        if (in_array($nodeStatus, ['queued', 'skipped', 'cancelled'], true) && $output === [] && $inFlightCount <= 0) {
            return $output;
        }

        $this->syncRunningVideoJobsForRunNode($node);
        $jobs = $this->latestVideoJobsForRunNode((int) $node->getAttr('id'), $storyboardRevisionId);
        if ($jobs === []) {
            return [];
        }

        $success = 0;
        $failed = 0;
        $shots = [];
        foreach ($jobs as $job) {
            $status = (string) $job->getAttr('status');
            if ($status === 'success') {
                $success++;
            } elseif ($status === 'failed') {
                $failed++;
            }
            $context = $job->getAttr('request_context_json') ?: [];
            if (!is_array($context)) {
                $context = [];
            }
            $shots[] = [
                'job_id' => (int) $job->getAttr('id'),
                'shot_id' => (int) $job->getAttr('shot_id'),
                'storyboard_revision_id' => (int) ($job->getAttr('storyboard_revision_id') ?? 0) ?: null,
                'index' => (int) $job->getAttr('shot_index'),
                'status' => $status,
                'chain_shots' => (bool) $job->getAttr('chain_shots'),
                'description' => trim((string) (($job->getAttr('shot_data_json') ?: [])['description'] ?? '')),
                'prompt' => (string) $job->getAttr('node_prompt'),
                'final_prompt' => (string) ($context['final_prompt'] ?? ''),
                'image_url' => (string) $job->getAttr('source_image_url'),
                'input_image_url' => (string) $job->getAttr('input_image_url'),
                'video_url' => (string) $job->getAttr('video_url'),
                'end_frame_url' => (string) $job->getAttr('end_frame_url'),
                'error_message' => (string) $job->getAttr('error_message'),
                'ai_request_log_id' => (int) ($job->getAttr('ai_request_log_id') ?? 0) ?: null,
            ];
        }

        $total = count($jobs);
        $output['async'] = true;
        $output['storyboard_revision_id'] = $storyboardRevisionId;
        $output['status'] = $failed > 0 ? 'failed' : ($success >= $total ? 'success' : 'running');
        $output['shot_count'] = $total;
        $output['finished_count'] = $success;
        $output['failed_count'] = $failed;
        $output['shots'] = $shots;

        return $output;
    }

    private function syncRunningVideoJobsForRunNode(WorkflowRunNode $node): void
    {
        $storyboardRevisionId = $this->currentStoryboardRevisionIdForRunNode((int) $node->getAttr('id'));
        if ($storyboardRevisionId <= 0) {
            return;
        }

        // queued 也可能带着 provider_task_id（改分镜 clone 后的迁移态），一并尝试回填。
        $jobs = VideoJob::where('workflow_run_node_id', (int) $node->getAttr('id'))
            ->where('storyboard_revision_id', $storyboardRevisionId)
            ->whereIn('status', ['running', 'queued'])
            ->select();

        foreach ($jobs as $job) {
            if (!$job instanceof VideoJob) {
                continue;
            }
            $this->syncRunningVideoJobFromProvider($job);
        }
    }

    private function syncRunningVideoJobFromProvider(VideoJob $job): void
    {
        $taskId = $this->extractVideoTaskIdFromJob($job);
        if ($taskId === '') {
            return;
        }

        $model = ModelConfigResolver::resolve('video', (int) ($job->getAttr('user_id') ?: 1), (int) $job->getAttr('model_config_id'));
        if (!$model instanceof ModelConfig) {
            return;
        }

        $apiKey = trim((string) $model->getAttr('api_key'));
        if ($apiKey === '') {
            return;
        }

        $endpoint = trim((string) $model->getAttr('endpoint'));
        $modelId = trim((string) $model->getAttr('model_id'));
        if ($endpoint === '' || $modelId === '') {
            return;
        }

        $modelOptions = $model->getAttr('options') ?: [];
        if (!is_array($modelOptions)) {
            $modelOptions = [];
        }
        $jobOptions = $job->getAttr('video_options_json') ?: [];
        if (!is_array($jobOptions)) {
            $jobOptions = [];
        }

        $mergedOptions = $this->mergeVideoOptions($modelOptions, $jobOptions);
        $resolvedEndpoint = $this->resolveVideoEndpoint($endpoint, $modelId, $mergedOptions);
        $options = $this->normalizeVideoRequestOptionsForModel(
            $resolvedEndpoint,
            $modelId,
            $mergedOptions,
        );
        $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey];
        $result = $this->fetchWorkflowVideoResultOnce($resolvedEndpoint, $headers, $taskId, $options);
        $videoUrl = trim((string) ($result['video_url'] ?? ''));
        if ($videoUrl === '') {
            $status = strtolower((string) ($result['status'] ?? ''));
            if (in_array($status, ['failed', 'error', 'expired', 'canceled', 'cancelled'], true)) {
                $shot = Shot::find((int) $job->getAttr('shot_id'));
                if ($shot instanceof Shot) {
                    $this->failQueuedVideoJob($job, $shot, '远端视频任务失败或已取消');
                }
            }
            return;
        }

        $this->completeVideoJobWithUrl($job, $model, $videoUrl, '', 0);
    }

    private function extractVideoTaskIdFromJob(VideoJob $job): string
    {
        $context = $job->getAttr('request_context_json') ?: [];
        if (is_array($context)) {
            foreach (['task_id', 'video_task_id', 'provider_task_id'] as $key) {
                $value = trim((string) ($context[$key] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        $message = (string) $job->getAttr('error_message');
        if (preg_match('/(tsk_[A-Za-z0-9_]+)/', $message, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/(cgt-\d{14}-[A-Za-z0-9_-]+)/', $message, $m) === 1) {
            return $m[1];
        }

        return '';
    }

    /**
     * 执行剧本级工作流主流程。
     * 读取 workflow graph、执行文本节点、提取剧集和资产、写入 episodes 和 assets。
     */
    private function doRunSeriesWorkflow(int $seriesId, array $payload = []): array
    {
        $this->assertSeriesWorkflowEditable($seriesId);
        $series = $this->findSeriesOrFail($seriesId);
        $workflow = $this->resolveSeriesWorkflow($series, $payload);

        $graph = $workflow->getAttr('graph') ?: ['nodes' => [], 'edges' => []];
        if (!is_array($graph)) {
            $graph = ['nodes' => [], 'edges' => []];
        }

        $sourceText = $this->resolveSourceText($series, $seriesId, $payload);
        $userTargetCount = $this->resolveUserTargetEpisodeCount($payload);

        $orderedNodes = $this->topologicalOrder($graph);
        $workflowId = (int) $workflow->getAttr('id');
        $pipeline = $this->executePipeline(
            $orderedNodes,
            $series,
            $sourceText,
            $userTargetCount,
            $seriesId,
            $workflowId,
        );

        $episodes = $this->extractEpisodesFromPipeline($pipeline);
        $assets = [];

        if ($episodes === []) {
            $fallback = $this->extractFromOutputNode($pipeline);
            if ($episodes === [] && isset($fallback['series_payload']) && is_array($fallback['series_payload'])) {
                $episodes = $this->normalizeEpisodeItems($this->findEpisodeList($fallback['series_payload']));
            }
        }

        $this->logPipelineTrace($seriesId, $workflowId, $pipeline, $episodes, $assets);

        if ($episodes === []) {
            abort(422, 'AI 未返回可用剧集结果，请检查“剧集规划”节点 prompt 后重试（已写入 runtime/log，便于排查 AI 实际返回）');
        }

        $normalized = $this->normalizeEpisodeCount($episodes, $userTargetCount);
        $episodeWorkflowId = $this->resolveEpisodeWorkflowIdForSeriesRun($payload);
        $this->persistSeriesWorkflowResult($seriesId, $normalized, $episodeWorkflowId, []);

        return [
            'series_id' => $seriesId,
            'workflow_id' => $workflowId,
            'episodes_written' => count($normalized),
            'assets_written' => 0,
            'preview' => [
                'episodes' => array_slice($normalized, 0, 3),
                'assets' => [],
                'trace' => array_map(
                    static fn (array $step): array => [
                        'label' => $step['label'],
                        'kind' => $step['kind'],
                        'ok' => $step['ok'],
                        'raw_excerpt' => mb_substr((string) ($step['raw'] ?? ''), 0, 600),
                        'output_keys' => is_array($step['output']) ? array_keys($step['output']) : [],
                    ],
                    $pipeline,
                ),
            ],
        ];
    }

    /**
     * 执行指定异步任务。
     * 供命令行 worker 调用；整集 auto 已停用，残留任务在此安全取消并退出。
     */
    public function runQueuedEpisodeWorkflowRun(int $runId): array
    {
        $run = WorkflowRun::find($runId);
        if (!$run instanceof WorkflowRun) {
            throw new \RuntimeException('剧集工作流任务不存在');
        }
        $payload = $run->getAttr('payload_json') ?: [];
        if (!is_array($payload) || (string) ($payload['scope'] ?? '') !== 'episode') {
            throw new \RuntimeException('当前任务不是剧集工作流任务');
        }
        if ((string) ($payload['execution_mode'] ?? '') !== 'auto') {
            throw new \RuntimeException('当前任务不是剧集自动执行任务');
        }

        $this->runtimeUserId = (int) ($run->getAttr('user_id') ?: 1);
        $episodeId = (int) ($payload['episode_id'] ?? 0);
        if ($episodeId <= 0) {
            $run->save([
                'status' => 'cancelled',
                'error_message' => '整集自动执行已停用（缺少 episode_id）',
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
            RedisCache::bumpVersion('workflow_run:' . $runId);
            throw new \RuntimeException('剧集自动执行缺少 episode_id');
        }

        // 功能已关停：不再推进节点，取消本 run 未完成视频后退出。
        return $this->retireResidualEpisodeAutoRun($run, $episodeId);
    }

    /**
     * 停用整集 auto 后，worker 捡到的残留 auto run 统一取消（保留已成功产物）。
     */
    private function retireResidualEpisodeAutoRun(WorkflowRun $run, int $episodeId): array
    {
        $runId = (int) $run->getAttr('id');
        if ((string) $run->getAttr('status') !== 'cancelled') {
            $payload = $run->getAttr('payload_json') ?: [];
            if (!is_array($payload)) {
                $payload = [];
            }
            unset($payload['awaiting_node_id'], $payload['awaiting_kind']);
            $payload['execution_mode'] = 'auto';
            $payload['cancelled_at'] = date('Y-m-d H:i:s');
            $payload['retired_reason'] = 'auto_run_disabled';

            $run->save([
                'status' => 'cancelled',
                'payload_json' => $payload,
                'current_node_label' => '',
                'error_message' => '整集一键自动执行已停用，残留自动任务已取消',
                'finished_at' => date('Y-m-d H:i:s'),
            ]);

            $videoNodes = WorkflowRunNode::where('run_id', $runId)
                ->where('kind', 'video')
                ->select();
            foreach ($videoNodes as $videoNode) {
                if (!$videoNode instanceof WorkflowRunNode) {
                    continue;
                }
                $cancelled = $this->cancelVideoJobsForRunNode((int) $videoNode->getAttr('id'), false);
                $nodeStatus = (string) $videoNode->getAttr('status');
                if ($cancelled <= 0 && !in_array($nodeStatus, ['running', 'queued', 'waiting_async'], true)) {
                    continue;
                }
                $output = $videoNode->getAttr('output_json') ?: [];
                if (!is_array($output)) {
                    $output = [];
                }
                $output['status'] = 'cancelled';
                $output['cancelled_at'] = date('Y-m-d H:i:s');
                if ($cancelled > 0) {
                    $output['cancelled_video_jobs'] = $cancelled;
                }
                $message = '整集自动执行已停用，残留任务已取消';
                $videoNode->save([
                    'status' => 'skipped',
                    'output_json' => $output,
                    'raw_output' => json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '',
                    'error_message' => $message,
                    'finished_at' => date('Y-m-d H:i:s'),
                ]);
                $this->syncEpisodeWorkflowStateForRunNode(
                    $videoNode,
                    'skipped',
                    $output,
                    json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '',
                    $message,
                );
            }
            RedisCache::bumpVersion('workflow_run:' . $runId);
            $this->clearSeriesCache();
        }

        $episode = Episode::with(['shots'])->find($episodeId);
        if (!$episode instanceof Episode) {
            return ['id' => $episodeId, 'workflow_state' => null];
        }

        return $this->serializeEpisode($episode, true);
    }

    /**
     * 执行单个手动剧集节点队列。
     * 供 episode-node:worker 调用；避免 Web 请求直接阻塞在 AI 长调用上。
     */
    public function runQueuedEpisodeNodeRun(int $runId): array
    {
        $run = WorkflowRun::find($runId);
        if (!$run instanceof WorkflowRun) {
            throw new \RuntimeException('剧集节点任务不存在');
        }
        $payload = $run->getAttr('payload_json') ?: [];
        if (!is_array($payload) || (string) ($payload['scope'] ?? '') !== 'episode') {
            throw new \RuntimeException('当前任务不是剧集节点任务');
        }
        if ((string) ($payload['execution_mode'] ?? '') !== 'node') {
            throw new \RuntimeException('当前任务不是手动节点异步任务');
        }

        $this->runtimeUserId = (int) ($run->getAttr('user_id') ?: 1);
        $episodeId = (int) ($payload['episode_id'] ?? 0);
        $nodeId = trim((string) ($payload['target_node_id'] ?? ''));
        if ($episodeId <= 0 || $nodeId === '') {
            throw new \RuntimeException('剧集节点任务缺少 episode_id 或 target_node_id');
        }

        $options = isset($payload['node_options']) && is_array($payload['node_options'])
            ? $payload['node_options']
            : [];
        $promptOverride = (string) ($payload['prompt_override'] ?? '');

        try {
            $result = $this->doRunEpisodeWorkflowNode($episodeId, $nodeId, $promptOverride, [
                'internal_async' => true,
                'shot_index' => (int) ($options['shot_index'] ?? 0),
                'prompt_template_id' => (int) ($options['prompt_template_id'] ?? 0),
            ]);

            $freshRun = WorkflowRun::find($runId);
            if ($freshRun instanceof WorkflowRun) {
                $freshPayload = $freshRun->getAttr('payload_json') ?: [];
                if (!is_array($freshPayload)) {
                    $freshPayload = [];
                }
                unset(
                    $freshPayload['execution_mode'],
                    $freshPayload['target_node_id'],
                    $freshPayload['target_node_label'],
                    $freshPayload['prompt_override'],
                    $freshPayload['node_options']
                );
                $freshRun->save([
                    'payload_json' => $freshPayload,
                    'current_node_label' => '',
                ]);
                RedisCache::bumpVersion('workflow_run:' . $runId);
            }

            return $result;
        } catch (\Throwable $e) {
            $freshRun = WorkflowRun::find($runId);
            if ($freshRun instanceof WorkflowRun) {
                $freshRun->save([
                    'status' => 'failed',
                    'error_message' => mb_substr($e->getMessage(), 0, 2000),
                    'finished_at' => date('Y-m-d H:i:s'),
                ]);
                RedisCache::bumpVersion('workflow_run:' . $runId);
            }
            throw $e;
        }
    }

    /**
     * 执行指定异步任务。
     * 供命令行 worker 调用；负责更新任务状态、节点状态和最终结果。
     */
    public function runQueuedWorkflowRun(int $runId): array
    {
        $run = WorkflowRun::find($runId);
        if (!$run instanceof WorkflowRun) {
            throw new \RuntimeException('工作流任务不存在');
        }
        $this->runtimeUserId = (int) ($run->getAttr('user_id') ?: 1);

        $seriesId = (int) $run->getAttr('series_id');
        $payload = is_array($run->getAttr('payload_json')) ? $run->getAttr('payload_json') : [];
        $payload['workflow_id'] = (int) $run->getAttr('workflow_id');
        $payload['episode_workflow_id'] = (int) ($run->getAttr('episode_workflow_id') ?? 0) ?: null;
        if (!isset($payload['source_text'])) {
            $payload['source_text'] = (string) $run->getAttr('source_text');
        }
        if ($run->getAttr('target_episode_count') !== null) {
            $payload['episode_count'] = (int) $run->getAttr('target_episode_count');
        }

        $run->save([
            'status' => 'running',
            'progress' => 1,
            'error_message' => '',
            'started_at' => date('Y-m-d H:i:s'),
        ]);
        RedisCache::bumpVersion("workflow_run:{$runId}");
        WorkflowRuntime::rememberSeriesRun($run);
        $this->clearSeriesCache();

        try {
            $result = $this->doRunSeriesWorkflowWithRun($seriesId, $payload, $run);
            $run->save([
                'status' => 'success',
                'progress' => 100,
                'current_node_label' => '',
                'result_json' => $result,
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
            RedisCache::bumpVersion("workflow_run:{$runId}");
            WorkflowRuntime::clearSeriesRun($seriesId, (int) $run->getAttr('id'));
            $this->clearSeriesCache();

            return $result;
        } catch (\Throwable $e) {
            $run->save([
                'status' => 'failed',
                'error_message' => mb_substr($e->getMessage(), 0, 2000),
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
            RedisCache::bumpVersion("workflow_run:{$runId}");
            WorkflowRuntime::rememberSeriesRun($run);
            $this->clearSeriesCache();
            throw $e;
        }
    }

    /**
     * 带任务进度记录的剧本工作流执行。
     * 逻辑与同步执行一致，但每个节点会写入 workflow_run_nodes 状态。
     */
    private function doRunSeriesWorkflowWithRun(int $seriesId, array $payload, WorkflowRun $run): array
    {
        $series = $this->findSeriesOrFail($seriesId);
        $workflow = $this->resolveSeriesWorkflow($series, $payload);

        $graph = $workflow->getAttr('graph') ?: ['nodes' => [], 'edges' => []];
        if (!is_array($graph)) {
            $graph = ['nodes' => [], 'edges' => []];
        }

        $sourceText = $this->resolveSourceText($series, $seriesId, $payload);
        $userTargetCount = $this->resolveUserTargetEpisodeCount($payload);
        $episodeWorkflowId = $this->resolveEpisodeWorkflowIdForSeriesRun($payload);
        $orderedNodes = $this->topologicalOrder($graph);
        $workflowId = (int) $workflow->getAttr('id');
        $pipeline = $this->executePipeline(
            $orderedNodes,
            $series,
            $sourceText,
            $userTargetCount,
            $seriesId,
            $workflowId,
            (int) $run->getAttr('id'),
            $episodeWorkflowId,
        );

        $episodes = $this->extractEpisodesFromPipeline($pipeline);
        $assets = [];

        if ($episodes === []) {
            $fallback = $this->extractFromOutputNode($pipeline);
            if ($episodes === [] && isset($fallback['series_payload']) && is_array($fallback['series_payload'])) {
                $episodes = $this->normalizeEpisodeItems($this->findEpisodeList($fallback['series_payload']));
            }
        }

        $this->logPipelineTrace($seriesId, $workflowId, $pipeline, $episodes, $assets);

        if ($episodes === []) {
            abort(422, 'AI 未返回可用剧集结果，请检查“剧集规划”节点 prompt 后重试（已写入 runtime/log，便于排查 AI 实际返回）');
        }

        $normalized = $this->normalizeEpisodeCount($episodes, $userTargetCount);
        $this->assertWorkflowRunStillActive((int) $run->getAttr('id'));
        $this->persistSeriesWorkflowResult($seriesId, $normalized, $episodeWorkflowId, []);

        return [
            'series_id' => $seriesId,
            'workflow_id' => $workflowId,
            'episodes_written' => count($normalized),
            'assets_written' => 0,
        ];
    }

    /**
     * 记录剧本工作流调试日志。
     * 把每个节点的原始输出、解析结果、剧集数量和资产数量写入 runtime log。
     */
    private function logPipelineTrace(int $seriesId, int $workflowId, array $pipeline, array $episodes, array $assets): void
    {
        try {
            $payload = [
                'series_id' => $seriesId,
                'workflow_id' => $workflowId,
                'episodes' => count($episodes),
                'assets' => count($assets),
                'pipeline' => array_map(static function (array $step): array {
                    return [
                        'label' => $step['label'],
                        'kind' => $step['kind'],
                        'ok' => $step['ok'],
                        'raw' => mb_substr((string) ($step['raw'] ?? ''), 0, 4000),
                        'output' => $step['output'],
                    ];
                }, $pipeline),
            ];
            Log::channel('default')->info('[SeriesPipeline] ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } catch (\Throwable $e) {
            // logging must never break the request
        }
    }

    /**
     * 删除剧本会同步删除任务；worker 在最终写库前用它阻止已删除任务继续落库。
     */
    private function assertWorkflowRunStillActive(int $runId): void
    {
        $run = WorkflowRun::find($runId);
        if (!$run instanceof WorkflowRun || (string) $run->getAttr('status') === 'cancelled') {
            throw new \RuntimeException('工作流任务已取消或删除');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pipeline core
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 解析并校验剧本工作流。
     * 优先使用请求体 workflow_id，否则使用剧本绑定的 series_workflow_id；scope 必须为 series。
     */
    private function resolveSeriesWorkflow(Series $series, array $payload): Workflow
    {
        $workflowId = isset($payload['workflow_id']) && $payload['workflow_id'] !== ''
            ? (int) $payload['workflow_id']
            : (int) ($series->getAttr('series_workflow_id') ?? 0);

        if ($workflowId <= 0) {
            abort(422, '请先绑定剧本工作流');
        }

        $workflow = Workflow::where('id', $workflowId)
            ->where('user_id', $this->seriesOwnerId($series))
            ->find();
        if (!$workflow instanceof Workflow) {
            abort(404, '剧本工作流不存在');
        }
        if ((string) $workflow->getAttr('scope') !== 'series') {
            abort(422, '当前工作流不是剧本类型');
        }
        $this->assertWorkflowHasNodes($workflow, '剧本');
        return $workflow;
    }

    /**
     * 解析剧本工作流输入正文。
     * 优先作品完整 source_text；请求体里的短摘要（常被 description 截断到 200 字）不能覆盖全文。
     */
    private function resolveSourceText(Series $series, int $seriesId, array $payload): string
    {
        $payloadText = trim((string) ($payload['source_text'] ?? ''));
        $storedText = trim((string) ($series->getAttr('source_text') ?? ''));
        $sourceText = $this->preferFullSeriesSourceText($payloadText, $storedText);

        if ($sourceText === '' || $this->isNovelImportPlaceholder($sourceText)) {
            $sourceFileToken = trim((string) ($payload['source_file_token'] ?? ''));
            if ($sourceFileToken !== '') {
                $payload['user_id'] = $this->effectiveUserId($series);
                return (new NovelImportService())->resolveTextFromPayload($payload);
            }
            $sourceText = $storedText;
        }
        if ($sourceText === '') {
            $firstEpisode = Episode::where('series_id', $seriesId)->order('number', 'asc')->find();
            $sourceText = trim((string) ($firstEpisode?->getAttr('plot_input') ?? ''));
        }
        if ($sourceText === '') {
            $sourceText = trim((string) ($series->getAttr('description') ?? ''));
        }
        if ($sourceText === '') {
            abort(422, '缺少剧本原文输入，请先在第一集剧情输入中粘贴小说正文');
        }
        return $sourceText;
    }

    /**
     * 当作品已存完整正文时，避免前端把 description 摘要当成 source_text 盖掉。
     */
    private function preferFullSeriesSourceText(string $payloadText, string $storedText): string
    {
        $payloadText = trim($payloadText);
        $storedText = trim($storedText);
        if ($storedText === '') {
            return $payloadText;
        }
        if ($payloadText === '' || $this->isNovelImportPlaceholder($payloadText)) {
            return $storedText;
        }
        if (mb_strlen($storedText) > mb_strlen($payloadText) + 50) {
            return $storedText;
        }
        return $payloadText;
    }

    private function isNovelImportPlaceholder(string $text): bool
    {
        return preg_match('/^\[已上传文件：.+，将在创建并排队后解析\]$/u', trim($text)) === 1;
    }

    /**
     * 解析用户指定的目标集数。
     * 返回 null 表示交给 AI 决定；传数字时限制在 1 到 100 集。
     */
    private function resolveUserTargetEpisodeCount(array $payload): ?int
    {
        if (!array_key_exists('episode_count', $payload)) {
            return null;
        }
        $raw = $payload['episode_count'];
        if ($raw === '' || $raw === null) {
            return null;
        }
        $n = (int) $raw;
        if ($n <= 0) {
            return null;
        }
        return min(100, max(1, $n));
    }

    /**
     * Run each text node in topological order. The output of each node is
     * exposed to downstream nodes via `upstream_outputs[label]` so the
     * "剧集规划" node literally sees the "结构拆解" JSON, etc.
     *
     * @param array<int, array<string, mixed>> $orderedNodes
     * @return array<int, array{label:string,kind:string,output:?array,raw:string,ok:bool}>
     */
    /**
     * 执行工作流文本节点。
     * 按拓扑顺序执行 input/text/output 节点，text 节点会调用 AI，并把上游 JSON 注入下游。
     */
    private function executePipeline(
        array $orderedNodes,
        Series $series,
        string $sourceText,
        ?int $userTargetCount,
        int $seriesId,
        int $workflowId,
        ?int $runId = null,
        ?int $episodeWorkflowId = null,
    ): array
    {
        $context = array_merge([
            'series_title' => (string) ($series->getAttr('title') ?? ''),
            'novel_text' => $sourceText,
            'target_episode_count' => $userTargetCount,
            'visual_style' => $this->normalizeVisualStyle((string) ($series->getAttr('visual_style') ?? 'realistic')),
            'upstream_outputs' => [],
        ], $this->seriesRegionContext($series));

        $trace = [];
        $totalNodes = max(1, count($orderedNodes));
        foreach ($orderedNodes as $nodeIndex => $node) {
            if (!is_array($node)) {
                continue;
            }
            $label = trim((string) ($node['label'] ?? $node['data']['label'] ?? ''));
            $kind  = trim((string) ($node['data']['kind'] ?? ''));
            $runNode = $runId !== null ? $this->findWorkflowRunNode($runId, $node) : null;
            $nodeStartedAt = microtime(true);

            if ($runNode instanceof WorkflowRunNode && $this->canReuseWorkflowRunNode($runNode, $kind)) {
                $cachedOutput = $this->workflowRunNodeOutput($runNode);
                $cachedRaw = (string) $runNode->getAttr('raw_output');
                if ($kind === 'text') {
                    $context['upstream_outputs'][$label] = $cachedOutput;
                    $this->persistSeriesWorkflowCheckpoint($seriesId, $label, $cachedOutput, $episodeWorkflowId);
                }
                if ($runId !== null) {
                    WorkflowRun::where('id', $runId)->update([
                        'current_node_label' => $label !== '' ? $label : $kind,
                        'progress' => max(1, min(99, (int) floor((($nodeIndex + 1) / $totalNodes) * 90))),
                    ]);
                    RedisCache::bumpVersion("workflow_run:{$runId}");
                }
                $trace[] = [
                    'label' => $label !== '' ? $label : ($kind !== '' ? $kind : '节点'),
                    'kind' => $kind,
                    'output' => $kind === 'input' ? null : $cachedOutput,
                    'raw' => $cachedRaw,
                    'ok' => true,
                    'reused' => true,
                ];
                continue;
            }

            if ($runId !== null) {
                WorkflowRun::where('id', $runId)->update([
                    'current_node_label' => $label !== '' ? $label : $kind,
                    'progress' => max(1, min(99, (int) floor(($nodeIndex / $totalNodes) * 90))),
                ]);
                RedisCache::bumpVersion("workflow_run:{$runId}");
            }
            if ($runNode instanceof WorkflowRunNode) {
                $runNode->save([
                    'status' => 'running',
                    'started_at' => date('Y-m-d H:i:s'),
                    'input_json' => [
                        'series_title' => $context['series_title'],
                        'target_episode_count' => $context['target_episode_count'],
                        'upstream_output_keys' => array_keys($context['upstream_outputs']),
                    ],
                    'error_message' => '',
                ]);
                RedisCache::bumpVersion("workflow_run:{$runId}");
            }

            if ($kind === 'input') {
                $this->finishWorkflowRunNode($runNode, null, '', true, $nodeStartedAt);
                $trace[] = [
                    'label' => $label !== '' ? $label : '输入',
                    'kind' => 'input',
                    'output' => null,
                    'raw' => '',
                    'ok' => true,
                ];
                continue;
            }

            if ($kind !== 'text') {
                // output / image / video / voice: pipeline merges programmatically.
                $this->finishWorkflowRunNode($runNode, null, '', true, $nodeStartedAt);
                $trace[] = [
                    'label' => $label !== '' ? $label : $kind,
                    'kind' => $kind,
                    'output' => null,
                    'raw' => '',
                    'ok' => true,
                ];
                continue;
            }

            $prompt = $this->resolveNodePrompt($node, '', 'series');
            if ($this->isLocalSeriesScriptParseNode($label, $node)) {
                $parsed = $this->parseSeriesScriptLocally($sourceText, $series);
                if ($parsed['episodes'] === []) {
                    $error = '本地剧本解析未识别到分集标记，请检查文本中是否包含独立成行的 EPISODE 1 / Episode 1 / 第1集';
                    $this->finishWorkflowRunNode($runNode, null, '', false, $nodeStartedAt, $error);
                    abort(422, $error);
                }
                $raw = json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                $raw = is_string($raw) ? $raw : '';
                $context['upstream_outputs'][$label] = $parsed;
                $this->finishWorkflowRunNode($runNode, $parsed, $raw, true, $nodeStartedAt);
                $this->persistSeriesWorkflowCheckpoint($seriesId, $label, $parsed, $episodeWorkflowId);
                $trace[] = [
                    'label' => $label,
                    'kind' => 'text',
                    'output' => $parsed,
                    'raw' => $raw,
                    'ok' => true,
                    'engine' => 'local',
                ];
                continue;
            }

            if ($prompt === '') {
                $this->finishWorkflowRunNode($runNode, null, '', false, $nodeStartedAt, '节点 prompt 为空');
                $trace[] = [
                    'label' => $label,
                    'kind' => $kind,
                    'output' => null,
                    'raw' => '',
                    'ok' => false,
                ];
                continue;
            }

            $modelId = (int) ($node['data']['params']['modelId'] ?? 0);
            $model = $this->resolveTextModelByIdOrDefault($modelId);
            if (!$model instanceof ModelConfig) {
                $this->finishWorkflowRunNode($runNode, null, '', false, $nodeStartedAt, '未找到可用文本模型');
                abort(422, "节点【{$label}】未找到可用的文本模型，请联系管理员配置");
            }

            if ($this->isCallSheetNode($label, $node)) {
                $generator = new CallSheetGenerator();
                $raw = $generator->generateHtml($model, $this->buildSeriesCallSheetSourceText($sourceText, $context), [
                    'source' => 'series_workflow_call_sheet',
                    'series_id' => $seriesId,
                    'workflow_id' => $workflowId,
                    'node_label' => $label,
                    'node_id' => (string) ($node['id'] ?? ''),
                ], $prompt);
                $parsed = [
                    'series_id' => $seriesId,
                    'workflow_node_id' => (string) ($node['id'] ?? ''),
                    'label' => $label,
                    'kind' => 'text',
                    'type' => 'call_sheet',
                    'format' => 'html',
                    'html' => $raw,
                    'text' => $raw,
                    'generated_at' => date('Y-m-d H:i:s'),
                ];
                $context['upstream_outputs'][$label] = $parsed;
                $this->finishWorkflowRunNode($runNode, $parsed, $raw, true, $nodeStartedAt);
                $trace[] = [
                    'label' => $label,
                    'kind' => 'text',
                    'output' => $parsed,
                    'raw' => $raw,
                    'ok' => true,
                ];
                continue;
            }

            $messages = $this->buildNodeMessages($label, $prompt, $context);
            $raw = $this->callChatCompletions($model, $messages, [
                'source' => 'series_workflow',
                'series_id' => $seriesId,
                'workflow_id' => $workflowId,
                'workflow_run_id' => $runId,
                'workflow_run_node_id' => $runNode instanceof WorkflowRunNode ? (int) $runNode->getAttr('id') : null,
                'node_label' => $label,
                'node_id' => (string) ($node['id'] ?? ''),
            ]);
            $parsed = $this->safeParseJson($raw);
            $requiresJson = $this->nodeRequiresJson($label, $prompt);
            $finishReason = $this->lastChatFinishReason;
            $wasRepaired = $this->lastJsonWasRepaired;
            if (isset($parsed['__raw']) && !$requiresJson) {
                $parsed = ['text' => $raw];
            }
            if (isset($parsed['__raw']) && $requiresJson) {
                $repairContext = [
                    'source' => 'series_workflow_json_repair',
                    'series_id' => $seriesId,
                    'workflow_id' => $workflowId,
                    'workflow_run_id' => $runId,
                    'workflow_run_node_id' => $runNode instanceof WorkflowRunNode ? (int) $runNode->getAttr('id') : null,
                    'node_label' => $label,
                    'node_id' => (string) ($node['id'] ?? ''),
                    'origin_ai_request_log_id' => $this->lastAiRequestLogId,
                    'origin_finish_reason' => $finishReason,
                ];
                $repairRaw = $this->repairRequiredJsonWithModel($model, $label, $raw, $repairContext);
                if ($repairRaw !== '') {
                    $repairParsed = $this->safeParseJson($repairRaw);
                    if (!isset($repairParsed['__raw'])) {
                        $raw = $repairRaw;
                        $parsed = $repairParsed;
                        $finishReason = $this->lastChatFinishReason;
                        $wasRepaired = $this->lastJsonWasRepaired;
                    }
                }
            }
            $nodeDebug = $this->buildWorkflowNodeAiDebug($model, $messages, $raw, isset($parsed['__raw']));
            if (isset($parsed['__raw']) && $requiresJson) {
                $reasonHint = $finishReason === 'length'
                    ? '模型输出已达 max_tokens 上限被截断'
                    : ($finishReason !== '' && $finishReason !== 'stop'
                        ? "模型返回 finish_reason={$finishReason}"
                        : '模型输出不是完整合法 JSON，可能包含未转义引号、尾随文本或内容截断');
                $bytesHint = $this->lastChatContentBytes > 0
                    ? "（本次返回约 {$this->lastChatContentBytes} 字节）"
                    : '';
                $logHint = $this->lastAiRequestLogId > 0
                    ? "（ai_request_logs.id={$this->lastAiRequestLogId}，workflow_run_nodes 含 request_payload_json / ai_meta_json）"
                    : '';
                $error = '节点【' . ($label !== '' ? $label : '文本节点') . '】未返回完整合法 JSON：'
                    . $reasonHint . $bytesHint . $logHint
                    . '。请到“模型配置”页提高该文本模型的 options.max_tokens（当前后端上限 65536），'
                    . '或拆短该节点 prompt / 输出内容。';
                $this->finishWorkflowRunNode($runNode, null, $raw, false, $nodeStartedAt, $error, $nodeDebug);
                abort(502, $error);
            }

            $context['upstream_outputs'][$label] = $parsed;
            $this->finishWorkflowRunNode($runNode, $parsed, $raw, !isset($parsed['__raw']), $nodeStartedAt, '', $nodeDebug);
            if (!isset($parsed['__raw'])) {
                if ($wasRepaired) {
                    Log::warning('[SeriesWorkflow] JSON 输出被截断，已通过堆栈修复恢复', [
                        'series_id' => $seriesId,
                        'workflow_id' => $workflowId,
                        'node_label' => $label,
                        'finish_reason' => $finishReason,
                    ]);
                }
                $this->persistSeriesWorkflowCheckpoint($seriesId, $label, $parsed, $episodeWorkflowId);
            }

            $trace[] = [
                'label' => $label,
                'kind' => 'text',
                'output' => $parsed,
                'raw' => $raw,
                'ok' => !isset($parsed['__raw']),
            ];
        }

        $seriesPayload = $context['upstream_outputs']['剧集规划']
            ?? $context['upstream_outputs']['分集规划']
            ?? $context['upstream_outputs']['提取剧集']
            ?? $context['upstream_outputs']['小说剧本拆解']
            ?? [];
        $assetsPayload = $context['upstream_outputs']['资产提取']
            ?? $context['upstream_outputs']['小说剧本拆解']
            ?? [];

        $trace[] = [
            'label' => '写入结果',
            'kind' => 'output',
            'output' => [
                'series_payload' => $seriesPayload,
                'assets_payload' => $assetsPayload,
            ],
            'raw' => '',
            'ok' => true,
        ];

        return $trace;
    }

    /**
     * 判断文本节点是否使用本地剧本拆解。
     */
    private function isLocalSeriesScriptParseNode(string $label, array $node): bool
    {
        $params = isset($node['data']['params']) && is_array($node['data']['params'])
            ? $node['data']['params']
            : [];
        $mode = strtolower(trim((string) ($params['executionMode'] ?? $params['engine'] ?? 'ai')));
        if (!in_array($mode, ['local', 'local_parse', 'local_script_parse'], true)) {
            return false;
        }

        return str_contains($label, '结构拆解')
            || str_contains($label, '剧本拆解')
            || str_contains($label, '提取剧集')
            || str_contains($label, '剧集规划')
            || str_contains($label, '分集规划');
    }

    /**
     * 本地解析已经按 EPISODE 分好的英文/中文剧本。
     * 输出结构保持和 AI 节点一致，后续资产提取仍可读取 upstream_outputs。
     */
    private function parseSeriesScriptLocally(string $sourceText, Series $series): array
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $sourceText));
        if ($text === '') {
            return ['episodes' => [], 'episode_outline' => []];
        }
        // 剧本创作/Markdown 导出常见「# 第一集」「**第1集**」；先去掉行首标题符号再识别。
        $text = preg_replace('/^[ \t]*#{1,6}[ \t]+/mu', '', $text) ?? $text;
        $text = preg_replace('/^[ \t]*\*\*[ \t]*/mu', '', $text) ?? $text;
        $text = preg_replace('/[ \t]*\*\*[ \t]*$/mu', '', $text) ?? $text;

        $matches = [];
        // 同时识别 EPISODE 1 / Episode 1: Title，以及剧本创作常用的「第1集 / 第 1 集 / 第一集：标题」。
        preg_match_all(
            '/^[ \t]*(?:(?:EPISODE|Episode|episode)[ \t]*([0-9一二三四五六七八九十百]+)|第[ \t]*([0-9一二三四五六七八九十百]+)[ \t]*集)(?:[ \t]*[：:\-—–|｜][ \t]*|[ \t]+)?([^\r\n]*?)[ \t]*$/mu',
            $text,
            $matches,
            PREG_OFFSET_CAPTURE
        );
        if ($matches[0] === []) {
            return ['episodes' => [], 'episode_outline' => []];
        }

        $seriesTitle = $this->extractLocalScriptTitle($text, (string) ($series->getAttr('title') ?? ''));
        $episodes = [];
        $outline = [];
        $count = count($matches[0]);
        for ($i = 0; $i < $count; $i++) {
            $marker = (string) $matches[0][$i][0];
            $markerStart = (int) $matches[0][$i][1];
            $bodyStart = $markerStart + strlen($marker);
            $bodyEnd = $i + 1 < $count ? (int) $matches[0][$i + 1][1] : strlen($text);
            $body = trim(substr($text, $bodyStart, max(0, $bodyEnd - $bodyStart)));
            $numberRaw = trim((string) (($matches[1][$i][0] ?? '') !== '' ? $matches[1][$i][0] : ($matches[2][$i][0] ?? '')));
            $number = $this->parseEpisodeNumber($numberRaw);
            if ($number <= 0) {
                $number = $i + 1;
            }
            $title = trim((string) ($matches[3][$i][0] ?? ''));
            $title = trim($title, "\"' \t\n\r\0\x0B");
            if ($title === '') {
                $title = $this->extractEpisodeTitleFromBody($body);
            }
            if ($title === '') {
                $title = $this->normalizeSeriesRegion((string) ($series->getAttr('region') ?? 'china')) === 'western'
                    ? 'Episode ' . $number
                    : '第' . $number . '集';
            }

            $episodes[] = [
                'number' => $number,
                'title' => $title,
                'plot_input' => $body,
            ];
            $outline[] = [
                'episode_number' => $number,
                'title' => $title,
                'brief_summary' => mb_substr($body, 0, 500),
                'plot_input' => $body,
            ];
        }

        return [
            'title' => $seriesTitle,
            'type' => 'script',
            'parse_engine' => 'local',
            'episode_count' => count($episodes),
            'episodes' => $episodes,
            'episode_outline' => $outline,
            'assets' => [
                'characters' => [],
                'locations' => [],
                'props' => [],
            ],
            'warnings' => [],
        ];
    }

    private function extractLocalScriptTitle(string $text, string $fallback): string
    {
        $lines = preg_split('/\n/u', $text) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^(?:written\s+by|by\s+|episode\s+\d+|第\s*[0-9一二三四五六七八九十百]+\s*集)/iu', $line)) {
                continue;
            }
            return mb_substr($line, 0, 200);
        }
        return trim($fallback) !== '' ? trim($fallback) : 'Untitled Script';
    }

    private function extractEpisodeTitleFromBody(string $body): string
    {
        $lines = preg_split('/\n/u', $body) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^(?:FADE IN:?|CUT TO BLACK\.?|INT\.|EXT\.|TITLE CARD:)/iu', $line)) {
                continue;
            }
            if (mb_strlen($line) <= 80 && !preg_match('/[.!?。！？]$/u', $line)) {
                return trim($line, "\"' ");
            }
            break;
        }
        return '';
    }

    private function parseEpisodeNumber(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return 0;
        }
        if (preg_match('/^\d+$/', $raw)) {
            return (int) $raw;
        }

        $map = ['一' => 1, '二' => 2, '三' => 3, '四' => 4, '五' => 5, '六' => 6, '七' => 7, '八' => 8, '九' => 9];
        if ($raw === '十') {
            return 10;
        }
        if (str_contains($raw, '十')) {
            [$left, $right] = array_pad(explode('十', $raw, 2), 2, '');
            $tens = $left === '' ? 1 : ($map[$left] ?? 0);
            $ones = $right === '' ? 0 : ($map[$right] ?? 0);
            return $tens * 10 + $ones;
        }
        return $map[$raw] ?? 0;
    }

    private function buildSeriesCallSheetSourceText(string $sourceText, array $context): string
    {
        $payload = [
            'series_title' => $context['series_title'] ?? '',
            'target_episode_count' => $context['target_episode_count'] ?? null,
            'source_script' => $sourceText,
            'upstream_outputs' => $context['upstream_outputs'] ?? [],
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return is_string($json) && trim($json) !== '' ? $json : $sourceText;
    }

    private function canReuseWorkflowRunNode(WorkflowRunNode $runNode, string $kind): bool
    {
        if ((string) $runNode->getAttr('status') !== 'success') {
            return false;
        }
        if ($kind === 'input') {
            return true;
        }
        if ($kind !== 'text') {
            return true;
        }

        $output = $this->workflowRunNodeOutput($runNode);
        return $output !== [] || trim((string) $runNode->getAttr('raw_output')) !== '';
    }

    private function workflowRunNodeOutput(WorkflowRunNode $runNode): array
    {
        $output = $runNode->getAttr('output_json');
        if (is_array($output)) {
            return $output;
        }

        $raw = trim((string) $runNode->getAttr('raw_output'));
        if ($raw === '') {
            return [];
        }

        return $this->safeParseJson($raw);
    }

    private function nodeRequiresJson(string $label, string $prompt): bool
    {
        foreach (['资产绑定', '绑定资产'] as $needle) {
            if (str_contains($label, $needle)) {
                return true;
            }
        }

        if ($this->promptExplicitlyForbidsJson($prompt)) {
            return false;
        }

        foreach (['剧集', '分集', '资产', '拆解', '规划'] as $needle) {
            if (str_contains($label, $needle)) {
                return true;
            }
        }

        $text = $prompt;
        foreach (['JSON', 'json', '严格 JSON', '合法 JSON', '"assets"', '"episodes"', 'assets":', 'episodes":'] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }
        return false;
    }

    private function promptExplicitlyForbidsJson(string $prompt): bool
    {
        $text = trim($prompt);
        if ($text === '') {
            return false;
        }

        return (bool) preg_match('/(?:不要|不(?:要|得|可|允许)|禁止|严禁)\s*(?:输出|返回|使用)?\s*(?:严格\s*)?json/iu', $text)
            || (bool) preg_match('/自然语言[^。；;\n]{0,40}(?:不要|不(?:要|得|可|允许)|禁止|严禁)\s*(?:输出|返回|使用)?\s*(?:严格\s*)?json/iu', $text);
    }

    /**
     * 查找当前执行节点对应的任务节点记录。
     */
    private function findWorkflowRunNode(int $runId, array $node): ?WorkflowRunNode
    {
        $workflowNodeId = (string) ($node['id'] ?? '');
        if ($workflowNodeId === '') {
            return null;
        }

        $runNode = WorkflowRunNode::where('run_id', $runId)
            ->where('workflow_node_id', $workflowNodeId)
            ->find();

        $semanticNode = $this->findBestWorkflowRunNodeBySemantic(
            $runId,
            (string) ($node['label'] ?? $node['data']['label'] ?? ''),
            (string) ($node['data']['kind'] ?? $node['kind'] ?? ''),
            (int) ($node['sort'] ?? 0),
        );
        if (!$semanticNode instanceof WorkflowRunNode) {
            $semanticNode = $this->findBestWorkflowRunNodeBySemantic(
                $runId,
                (string) ($node['label'] ?? $node['data']['label'] ?? ''),
                (string) ($node['data']['kind'] ?? $node['kind'] ?? ''),
                $this->workflowNodeSortInRun($runId, $workflowNodeId),
            );
        }

        if ($runNode instanceof WorkflowRunNode && $semanticNode instanceof WorkflowRunNode) {
            return $this->isBetterWorkflowRunNode($semanticNode, $runNode) ? $semanticNode : $runNode;
        }

        return $runNode instanceof WorkflowRunNode ? $runNode : $semanticNode;
    }

    /**
     * 完成当前任务节点状态。
     * 保存解析输出、原始输出、耗时和错误信息。
     */
    private function finishWorkflowRunNode(
        ?WorkflowRunNode $runNode,
        ?array $output,
        string $raw,
        bool $ok,
        float $startedAt,
        string $errorMessage = '',
        array $debugFields = [],
    ): void {
        if (!$runNode instanceof WorkflowRunNode) {
            return;
        }
        $freshRunNode = WorkflowRunNode::find((int) $runNode->getAttr('id'));
        if (
            $freshRunNode instanceof WorkflowRunNode
            && in_array((string) $freshRunNode->getAttr('status'), ['skipped', 'cancelled'], true)
        ) {
            return;
        }

        $data = [
            'status' => $ok ? 'success' : 'failed',
            'output_json' => $output ?? [],
            'raw_output' => $raw,
            'error_message' => $errorMessage,
            'finished_at' => date('Y-m-d H:i:s'),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
        if ($debugFields !== []) {
            $data = array_merge($data, $debugFields);
        }

        $runNode->save($data);
        $this->syncEpisodeWorkflowStateForRunNode(
            $runNode,
            $ok ? 'success' : 'failed',
            $output ?? [],
            $raw,
            $errorMessage,
        );
        RedisCache::bumpVersion('workflow_run:' . (int) $runNode->getAttr('run_id'));
    }

    /**
     * 构造工作流节点 AI 调试快照，写入 workflow_run_nodes.request_payload_json / ai_meta_json。
     */
    private function buildWorkflowNodeAiDebug(
        ModelConfig $model,
        array $messages,
        string $raw,
        bool $parseFailed,
    ): array {
        $meta = [
            'finish_reason' => $this->lastChatFinishReason,
            'max_tokens' => $this->lastChatMaxTokens,
            'content_bytes' => $this->lastChatContentBytes,
            'json_repaired' => $this->lastJsonWasRepaired,
            'parse_failed' => $parseFailed,
            'model_config_id' => (int) $model->getAttr('id'),
            'llm_model' => (string) $model->getAttr('model_id'),
            'logged_at' => date('Y-m-d H:i:s'),
        ];
        if ($parseFailed) {
            $meta['json_last_error'] = json_last_error_msg();
        }
        if ($raw !== '') {
            $meta['raw_bytes'] = strlen($raw);
            $meta['raw_head'] = mb_substr($raw, 0, 2000);
            $meta['raw_tail'] = mb_substr($raw, -2000);
        }

        $fields = [
            'request_payload_json' => [
                'model_config_id' => (int) $model->getAttr('id'),
                'llm_model' => (string) $model->getAttr('model_id'),
                'max_tokens' => $this->lastChatMaxTokens,
                'messages' => $this->summarizeMessagesForLog($messages),
            ],
            'ai_meta_json' => $meta,
        ];
        if ($this->lastAiRequestLogId > 0) {
            $fields['ai_request_log_id'] = $this->lastAiRequestLogId;
        }

        return $fields;
    }

    /**
     * 日志用 messages 摘要：超长 user 内容保留头尾，避免撑爆 JSON 字段。
     *
     * @param array<int, array{role:string, content:string}> $messages
     * @return array<int, array<string, mixed>>
     */
    private function summarizeMessagesForLog(array $messages): array
    {
        $out = [];
        foreach ($messages as $msg) {
            if (!is_array($msg)) {
                continue;
            }
            $role = (string) ($msg['role'] ?? '');
            $content = (string) ($msg['content'] ?? '');
            $bytes = strlen($content);
            if ($bytes > 12000) {
                $out[] = [
                    'role' => $role,
                    '_truncated' => true,
                    'bytes' => $bytes,
                    'head' => mb_substr($content, 0, 4000),
                    'tail' => mb_substr($content, -4000),
                ];
                continue;
            }
            $out[] = ['role' => $role, 'content' => $content];
        }

        return $out;
    }

    /**
     * 从合成输出节点提取数据。
     * 当剧集或资产在原始 text 节点中没解析到时，使用写入结果节点作为兜底。
     */
    private function extractFromOutputNode(array $pipeline): array
    {
        foreach (array_reverse($pipeline) as $step) {
            if (($step['kind'] ?? '') !== 'output' || !is_array($step['output'] ?? null)) {
                continue;
            }
            return $step['output'];
        }
        return [];
    }

    /**
     * 构造 AI 节点请求消息。
     * 把剧本正文、目标集数、上游输出和当前节点 prompt 打包给模型。
     */
    private function buildNodeMessages(string $label, string $nodePrompt, array $context): array
    {
        $requiresJson = $this->nodeRequiresJson($label, $nodePrompt);
        $outputRule = $requiresJson
            ? '1) 输出必须是可被 json_decode 解析的合法 JSON；不要 Markdown、不要解释文字、不要尾随说明；字符串内部如需引号必须转义或改用中文书名号/单引号。'
            : '1) 如果 node_instruction 没有要求 JSON，可以输出自然语言；不要输出与任务无关的解释、寒暄或过程说明。';
        $system = <<<SYS
你是 AI 短剧生产工作流中的【{$label}】节点。请严格按 node_instruction 执行：
{$outputRule}
2) 如果 node_instruction 指定了 JSON schema，请遵循该 schema。
3) 你可以参考 upstream_outputs 中上游节点已经产出的 JSON。
4) target_episode_count 为 null 表示由你根据剧本长度自行决定集数；为数字表示请尽量接近该集数。
5) 机器消费型 JSON 节点优先输出“短字段、稳定字段、必要信息”，不要把完整剧本正文塞进 JSON 字段。
SYS;

        $region = (string) ($context['content_region'] ?? 'china');

        if ($requiresJson && (str_contains($label, '剧集') || str_contains($label, '分集'))) {
            $plotLengthRule = $region === 'western'
                ? 'plot should be a concise English episode synopsis, about 80-180 words'
                : 'plot（字符串，本集剧情摘要，150–400 字）';
            $system .= <<<SYS

6) 本节点【禁止】输出自然语言分集正文、剧本段落、「第01集｜」标题行、分镜、视频节点、时长行或 Markdown；只能输出一个 JSON 对象。
7) 顶层必须包含 episodes 数组；每项至少含 number（整数）、title（字符串）、plot_input（{$plotLengthRule}）、hook（字符串）。
8) 示例结构：{"episodes":[{"number":1,"title":"...","plot_input":"...","hook":"..."}]}
SYS;
        }

        $seriesVisualStyle = $this->normalizeVisualStyle((string) ($context['visual_style'] ?? 'realistic'));
        if ($requiresJson && str_contains($label, '资产')) {
            $system .= <<<SYS

6) 资产分类必须严格区分：
   - character：人物，只放角色；必须根据全文上下文提取年龄、性别、气质、固定外貌、体型、基础服装色系。character 的 image_prompt 不要写手持道具、剧情道具、场景光线或外套造型；道具必须单独输出为 prop。
   - scene：场景，只放地点/空间/环境，例如公寓厨房、客厅、卧室、楼道、楼梯间、医院、办公室、街道、车辆内部。
   - prop：物品，只放可被角色拿取或作为关键道具的实体物件，例如信封、离婚协议、医疗报告。
7) 只提取“资产库需要长期复用的实体”。不要提取镜头、动作、表情瞬间、眼泪滑落、特写、慢动作、转场、黑屏字幕、VFX、情绪氛围、镜头语言；这些属于分镜/后期说明，不属于资产。
8) 每个资产必须输出 name、type、description、image_prompt、tags。description 不能为空；image_prompt 要能直接用于生成参考图。
9) scene 的 image_prompt 尽量简短：地点 + 时间/光线 + 2-4 个关键陈设即可；多视角参考板由生图时自动拼接，不要在 image_prompt 里写分镜或多机位说明。
10) 如果原文没有明确外貌，允许从年龄、身份、动作、关系和情绪中做保守视觉推断，但不要编造重大剧情设定。
11) 输出前自检：任何 location/scene 不得出现在 props；任何 prop 不得是地点、房间、室内空间或环境。
12) 分类例子：
   - 公寓厨房/Kitchen => type=scene
   - 公寓客厅/Living Room => type=scene
   - 公寓楼道/Stairwell/Hallway => type=scene
   - 离婚协议书、诊断报告、信封、戒指、锅具 => type=prop
13) 排除例子（不要输出）：眼泪滑落、信封放置、诊断报告特写、泪水晕墨、回忆闪现、慢镜头、黑屏字幕、镜头推进、情绪沉淀。
14) 输出 JSON 建议使用 { "assets": [ ... ] }，每条资产的 type 只能是 character、scene、prop 三者之一。
15) scene/prop 的 description 与 image_prompt 必须是可拍摄的具体视觉内容（地点结构、陈设、物件外形与材质）；禁止“用于展示”“场景落地”“剧情片段画面”“功能演示”“样例场景”等营销或元描述。
16) scene/prop 的 image_prompt 禁止人物、人脸、背影、手部；scene 必须按无人空场景写。
17) image_prompt 必须服从作品 visual_style={$seriesVisualStyle}，且风格互斥：realistic 禁止动漫/卡通/赛璐璐措辞；anime 禁止写实/真人/摄影与纯3D CGI；3d 禁止2D动漫平涂与手机实拍措辞。
SYS;
        }

        $hasUpstreamOutputs = ($context['upstream_outputs'] ?? []) !== [];
        $regionRule = (string) ($context['content_region_rule'] ?? '');
        if ($regionRule !== '') {
            $system .= "\n\n内容地区与语言约束（优先级高于 node_instruction 中的语言示例和原文语言）：{$regionRule}";
        }

        $userPayload = [
            'series_title' => $context['series_title'],
            'content_region' => $context['content_region'] ?? 'china',
            'visual_style' => $seriesVisualStyle,
            'target_episode_count' => $context['target_episode_count'],
            'upstream_outputs' => $context['upstream_outputs'],
            'node_instruction' => $nodePrompt,
        ];
        if (!$hasUpstreamOutputs) {
            $userPayload['novel_text'] = $context['novel_text'];
        } else {
            $userPayload['novel_text_summary'] = '完整小说正文已由上游节点拆解。当前节点请优先基于 upstream_outputs 中的结构化 JSON 继续处理，不要要求重新读取全文。';
        }

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => json_encode($userPayload, JSON_UNESCAPED_UNICODE)],
        ];
    }

    /**
     * 构造剧集级文本节点请求消息。
     * 文本节点允许返回自然语言；若 prompt 要求 JSON，则按 prompt 执行。
     */
    private function buildEpisodeNodeMessages(string $label, string $nodePrompt, array $context): array
    {
        $isStoryboardNode = str_contains($label, '分镜');
        $isShotAssetBindingNode = $this->isShotAssetBindingNode($label);
        $isStoryboardTextNode = $isStoryboardNode && !$isShotAssetBindingNode;
        $nodePromptDisablesAssetPrefixes = $this->nodePromptDisablesAssetMentionPrefixes($nodePrompt);
        $system = <<<SYS
你是 AI 短剧剧集工作流中的【{$label}】节点。请严格按 node_instruction 执行：
1) 当前处理的是单集剧情，不要生成其他剧集内容。
2) plot_input 是本集剧情概要；upstream_outputs 是之前已完成节点的输出。
3) 如果 node_instruction 明确要求 JSON，请只输出合法 JSON，不要 Markdown 包裹符。
4) 如果 node_instruction 没有要求 JSON，可以直接输出可用于下一节点的完整文本。
5) 不要输出与任务无关的解释、寒暄或过程说明。
SYS;

        if ($isStoryboardNode) {
            if ($isShotAssetBindingNode) {
                $system .= <<<SYS

6) 当前节点是“分镜绑定资产”节点。你必须只基于 upstream_outputs 中已有分镜和 asset_library 中真实存在的资产进行绑定。
7) 你必须返回真实存在的 asset_id；如果命中的是人物造型版本，还必须同时返回对应的 asset_image_id 与 reference_role="look"。严禁编造资产、URL、人物、场景或道具；URL 由后端代码根据 asset_id / asset_image_id 补全。
8) 输出必须是合法 JSON，不要 Markdown，不要解释文字。
SYS;
            } else {
                $system .= <<<SYS

6) 当前节点是给用户阅读的分镜文本节点，必须输出自然语言长分镜，不要输出 JSON、数组、字段对象、Markdown 表格或代码块。
7) 请使用“【视频节点01｜15s｜一句话概括】”或“【视频节点01｜一句话概括】”作为每段标题；必须按剧情顺序输出 视频节点01、视频节点02、视频节点03……。
8) 必须覆盖完整上游剧情，从开端、冲突、关键转折到结尾钩子都要拆进去；不要只输出第一场、第一地点或第一个冲突点。视频节点数量以 node_instruction 明确写出的数量、范围或“通常 N-M 个节点”为准；只有 node_instruction 没写数量时，才默认至少 3 个、推荐 6-10 个。
9) 不要把“镜头数量”误判成“视频节点数量”。“视频节点01、视频节点02……”是片段数量；每个节点内部的“镜头1、镜头2、cut”是该片段内部镜头。
10) 视频节点内部镜头结构以 node_instruction 为准：如果 node_instruction 要求 2-5 个镜头、cut 或“镜头1 / 镜头2”，必须按该结构输出；只有 node_instruction 没写多镜头结构时，才默认每段是一条连续镜头。
11) 每段都要像可直接投喂视频模型的高密度影视摄影脚本，明确写出主体、场景、动作、镜头、声音、光色和最后定焦画面；不要只写一句话摘要。
SYS;

                if ($nodePromptDisablesAssetPrefixes) {
                    $system .= <<<SYS

12) node_instruction 明确要求正文人物使用真实姓名且不加 @ 前缀时，正文中的人物、场景和道具可不加 @；不要因此把“@资产名”强行写入正文。
13) 如 node_instruction 的输出格式没有“引用资产：”行，优先遵守 node_instruction 的输出格式；如需要写引用资产行，引用资产项仍建议使用 asset_library 里的真实名称并加 @，方便后端识别。
14) 不要编造 asset_library 里不存在的人物造型名；若正文不用 @，也必须保持人物、场景、道具名称与 asset_library 可对应。
SYS;
                } else {
                    $system .= <<<SYS

12) 每段标题下必须写一行“引用资产：”，只列该视频节点真实出现的 asset_library 资产；每个引用都必须加 @，例如：引用资产：@林逸 @林逸·第1集常服 @圣辉魔法学院魔力测试广场 @《龙虎归元图》。
13) 严禁输出“图片1：资产名 / 图片2：资产名 / Image 1: name”这类格式；引用资产行和正文都只能写 @真实资产名。英文名若含空格，只在完整名字开头加一个 @，例如 @Jude Parker、@Jude Parker·Episode 1 Casual Travel Wear；禁止拆成 @Jude @Parker 或给姓名后续单词再加 @。
14) 正文里出现对应人物、人物造型、场景或道具时，必须保留 @ 前缀，方便用户编辑时直接看到引用关系。
15) 如果 asset_library 里存在人物造型候选，并且这个镜头明确要使用某套服装/造型，必须在用户可见文案里直接写出完整 @人物名·造型名；不要只写 @人物名，也不要把造型名藏到备注里。
16) 同一视频节点内，只要某角色已经使用了 @人物名·造型名，后续所有指代该角色的动作、站位、表情、对白都继续写完整 @人物名·造型名，不要简写回人物本名。
17) 人物造型只有在分镜正文中出现完整“人物名·造型名”时才会命中对应造型图；如果正文只写人物名，后端会回退到人物本体图。
18) 不要编造 asset_library 里不存在的人物造型名；若没有明确造型需求，可以只写 @人物本名。
19) 每个 15s 视频节点内部镜头时间码必须连续覆盖 0-15s，例如 0-5s、5-9s、9-14s、14-15s；禁止 0-1s/1-2s/2-3s 这种碎片切镜后再跳到 14-15s。
SYS;
                }
            }
        }

        $region = (string) ($context['content_region'] ?? 'china');
        if ($isStoryboardTextNode) {
            $system .= "\n\n分镜地区与输出语言约束（最高优先级，默认跟随作品 content_region；仅当 node_instruction 用中文明确要求输出中文/英文时才覆盖）："
                . $this->storyboardRegionLanguageRule($region, $nodePrompt);
        }

        if (str_contains($label, '资产')) {
            $system .= <<<SYS

6) 当前资产节点必须基于本集剧情扩写结果做“增量资产合并”，不要因为 asset_library 已有资产就停止提取。
7) 如果 asset_library 中已有同一人物、场景或道具，请沿用已有 name，并在输出里写 match_name；如果本集出现新实体，才输出新 name。
8) character 只放人物基础形态：年龄、性别、脸部、发型、体型、固定外貌、气质；image_prompt 使用白底棚拍、无手持物、非裸露基础衣裤或贴身打底服。
9) character 禁止写剧情服装、外套、盔甲、武器、手机、包、文件、场景光线、动作姿势；服装信息必须输出到 character_looks，不再作为独立 prop。
10) prop 只包含非服装类关键道具、装备、手持物和剧情物件；scene 只放地点/空间/环境，不混入人物动作和一次性事件。
11) 只有明确是人类或类人可穿搭角色的 character，才必须输出至少 1 个 character_looks；look_name 建议用“第N集常服/制服/礼服”等。如果剧情没明说服装，请根据时代、身份、职业/阶层、场景保守推断，并在 tags 加 inferred_look。
12) 动物、宠物、怪物、坐骑、非人类生物仍然可以归类为 character，但默认不要强行输出 character_looks；除非剧情明确要求其具备稳定可穿戴造型，并在 tags 标记 humanoid 或 anthropomorphic。
13) character_looks 的 image_prompt（全风格统一）：输出左右分栏造型板。左栏正、侧、背三个无头全身（头从衣领处切除，只看服装结构）；右栏同一人物放大特写（头部五官完整）。禁止红笔涂脸；禁止只出一张带头正面全身。白底棚拍，保持角色五官发型体型稳定，重点变化在服装与整体造型。写实造型的人像入库由系统在展示图完成后自动处理，不要写进 image_prompt。
14) 服装必须是可公开外穿的完整套装，至少包含上衣、裤装/裙装、鞋履，可包含帽子/发饰/腰带/外套；禁止只输出内衣、内裤、贴身打底服或裸露身体。
15) 输出 JSON 建议使用 { "assets": [ ... ], "character_looks": [ ... ] }；assets 中 type 只能是 character、scene、prop。
16) 硬性：每个 character_looks 的 character_name / match_character 必须在 assets 里有一条同名 type=character。禁止只输出造型、漏掉人物本体。
17) scene/prop 的 description 与 image_prompt 必须写具体可拍摄内容；禁止“用于展示”“场景落地”“剧情片段画面”等营销或元描述。
18) scene/prop 的 image_prompt 禁止人物、人脸、背影、手部；scene 按无人空场景写；image_prompt 服从本作品 visual_style 且风格互斥（写实禁动漫措辞，动漫禁写实/3D CGI，3D 禁2D平涂）。
SYS;
        }

        $regionRule = (string) ($context['content_region_rule'] ?? '');
        if ($regionRule !== '') {
            if ($isStoryboardTextNode) {
                $system .= "\n\n内容地区与语言约束（最高优先级，高于 node_instruction 中的英文模板、English Prompt 示例和原文语言；视频节点数量与镜头结构仍可按 node_instruction）：{$regionRule}";
            } else {
                $system .= "\n\n内容地区与语言约束（最高优先级，高于 node_instruction 中的语言要求、英文示例、Prompt 字样和原文语言）：{$regionRule}";
            }
        }

        $userPayload = [
            'series_id' => $context['series_id'],
            'episode_id' => $context['episode_id'],
            'episode_title' => $context['episode_title'],
            'episode_number' => $context['episode_number'],
            'plot_input' => $context['plot_input'],
            'content_region' => $context['content_region'] ?? 'china',
            'upstream_outputs' => $context['upstream_outputs'],
            'node_instruction' => $nodePrompt,
        ];
        if (isset($context['promo_segment_count'])) {
            $userPayload['promo_segment_count'] = (int) $context['promo_segment_count'];
            $userPayload['promo_total_seconds'] = (int) ($context['promo_total_seconds'] ?? 0);
        }
        if (isset($context['asset_library']) && is_array($context['asset_library'])) {
            $userPayload['asset_library'] = $context['asset_library'];
        }

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => json_encode($userPayload, JSON_UNESCAPED_UNICODE)],
        ];
    }

    /**
     * 资产提取使用专用精简请求：只携带一份有效剧情和无媒体 URL 的资产索引。
     */
    private function buildEpisodeAssetPrepMessages(string $label, string $nodePrompt, array $context): array
    {
        $visualStyle = $this->normalizeVisualStyle((string) ($context['visual_style'] ?? 'realistic'));
        $lookRule = $this->characterLookImagePromptRuleForStyle($visualStyle);
        $scenePropStyleRule = match ($visualStyle) {
            'anime' => 'scene/prop 的 image_prompt 必须写动漫手绘关键词，禁止写实/真人/摄影与纯3D CGI；scene/prop 禁止人物与人脸。',
            '3d' => 'scene/prop 的 image_prompt 必须写三维渲染关键词，禁止2D动漫平涂与手机实拍措辞；scene/prop 禁止人物与人脸。',
            default => 'scene/prop 的 image_prompt 必须写写实摄影关键词，禁止动漫/卡通/赛璐璐措辞；scene/prop 禁止人物与人脸，scene 必须无人空场景。',
        };
        $system = "你是 AI 短剧剧集工作流中的【{$label}】节点。只处理当前单集，严格按 node_instruction 提取并合并可长期复用的人物、场景、道具和人物造型。asset_library 是已有资产精简索引；命中已有实体时必须沿用其 name。只输出合法 JSON，不要 Markdown、解释或过程说明。"
            . "\n\n本作品 visual_style={$visualStyle}。character_looks.image_prompt 必须遵守：{$lookRule}"
            . " scene/prop 的 description/image_prompt 必须是具体地点或物件视觉内容，禁止营销/元描述（用于展示、场景落地、剧情片段画面等）。{$scenePropStyleRule}";
        $regionRule = trim((string) ($context['content_region_rule'] ?? ''));
        if ($regionRule !== '') {
            $system .= "\n\n内容地区与语言约束（最高优先级）：{$regionRule}";
        }

        $payload = [
            'episode_title' => (string) ($context['episode_title'] ?? ''),
            'episode_number' => (int) ($context['episode_number'] ?? 0),
            'plot_input' => (string) ($context['plot_input'] ?? ''),
            'content_region' => (string) ($context['content_region'] ?? 'china'),
            'visual_style' => $visualStyle,
            'node_instruction' => $nodePrompt,
            'asset_library' => is_array($context['asset_library'] ?? null) ? $context['asset_library'] : [],
        ];

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => json_encode($payload, JSON_UNESCAPED_UNICODE)],
        ];
    }

    /**
     * 构造单个分镜镜头 AI 重写消息。
     * 这里只允许模型返回一个 replacement shot，写库时只替换对应 index。
     */
    private function buildStoryboardShotRegenerationMessages(string $label, string $nodePrompt, array $context): array
    {
        $targetShot = is_array($context['target_shot'] ?? null) ? $context['target_shot'] : [];
        $targetIndex = (int) ($targetShot['index'] ?? 0);
        $assetRule = $this->nodePromptDisablesAssetMentionPrefixes($nodePrompt)
            ? '正文里可以按 node_instruction 使用真实资产名称；不要编造 asset_library 里不存在的人物、场景、道具或造型。'
            : '正文里出现 asset_library 中的人物、场景、道具或人物造型时，必须使用精确的 @资产名；不要编造 asset_library 里不存在的资产。';
        $region = (string) ($context['content_region'] ?? 'china');
        $languageRule = $this->storyboardRegionLanguageRule($region, $nodePrompt);
        $userInstruction = trim((string) ($context['user_instruction'] ?? ''));
        $instructionRule = $userInstruction !== ''
            ? "9) 本次用户补充修改要求必须优先落实，但不得违反以上只改当前镜头、固定 index、只引用真实资产的约束。"
            : "9) 如果没有用户补充修改要求，请在保持上下文连贯的前提下优化当前镜头。";
        $requestedAssetRefs = is_array($context['requested_asset_refs'] ?? null) ? $context['requested_asset_refs'] : [];
        $requestedAssetRule = $requestedAssetRefs !== []
            ? '10) 用户在本次对话中明确选择了 requested_asset_refs；必须在当前镜头正文中实际使用这些资产，并使用对应的精确 @资产名，不得静默忽略。'
            : '10) requested_asset_refs 为空时，不需要额外强制新增资产引用。';
        $system = <<<SYS
你是 AI 短剧剧集工作流中的【{$label}】节点，现在只允许重新生成一个分镜镜头。
必须遵守：
1) 只输出一个合法 JSON 对象，不要 Markdown，不要解释文字，不要代码块。
2) JSON 格式必须是 {"index":{$targetIndex},"title":"...","content_text":"..."}。
3) index 必须等于 {$targetIndex}；不要输出 shots 数组，不要输出其他镜头。
4) 只能重写 target_shot；previous_shot、next_shot、all_shots_readonly 只能作为连贯性参考，严禁改写、重排、合并或删除其他镜头。
5) title 是当前镜头的一句话标题；content_text 是完整替换正文，不要包含“【视频节点】”标题包装。
6) 内容必须可直接用于后续图片/视频生成：明确主体、场景、动作、镜头、声音、光色和最后定焦画面。
7) {$assetRule}
8) 分镜地区与输出语言约束：{$languageRule}
{$instructionRule}
{$requestedAssetRule}
SYS;

        $userPayload = [
            'series_id' => $context['series_id'] ?? null,
            'episode_id' => $context['episode_id'] ?? null,
            'episode_title' => $context['episode_title'] ?? '',
            'episode_number' => $context['episode_number'] ?? null,
            'plot_input' => $context['plot_input'] ?? '',
            'content_region' => $context['content_region'] ?? 'china',
            'node_instruction' => $nodePrompt,
            'prompt_source' => $context['prompt_source'] ?? [],
            'user_instruction' => $userInstruction,
            'upstream_outputs' => $context['upstream_outputs'] ?? [],
            'asset_library' => $context['asset_library'] ?? [],
            'requested_asset_refs' => $requestedAssetRefs,
            'target_shot' => $targetShot,
            'previous_shot' => $context['previous_shot'] ?? null,
            'next_shot' => $context['next_shot'] ?? null,
            'all_shots_readonly' => $context['all_shots_readonly'] ?? [],
        ];

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => json_encode($userPayload, JSON_UNESCAPED_UNICODE)],
        ];
    }

    /**
     * 分镜输出语言以作品 content_region 为准。
     * 仅当 node_instruction 用中文明确写了「输出中文/输出英文」等指令时才覆盖；
     * 不把英文默认模板里的 “Output English...” 当成用户显式语言选择。
     */
    private function storyboardRegionLanguageRule(string $region, string $nodePrompt): string
    {
        $region = $this->normalizeSeriesRegion($region);
        $language = $this->nodePromptOutputLanguage($nodePrompt);

        $contextRule = $region === 'western'
            ? '欧美地区语境：使用西方式人名、欧美都市/庄园/医院/荒原等语境，关系与对白符合欧美短剧习惯，避免中国特有地名、制度与文化梗。人物与资产设定保持 Western / European or American casting and facial features。'
            : '中国地区语境：使用中文姓名、中国本土场景与生活方式，关系与对白符合中国短剧习惯，避免明显欧美文化设定。';

        if ($language === 'chinese') {
            return $contextRule . '用户已在 node_instruction 明确要求中文输出：分镜标题、镜头描述、定焦画面、旁白和人物台词必须使用中文；asset_library 中已有的英文资产名称可原样保留。';
        }

        if ($language === 'english') {
            return $contextRule . '用户已在 node_instruction 明确要求英文输出：分镜标题、镜头描述、定焦画面、旁白和人物台词必须使用英文；资产名称按 asset_library 原文保留。';
        }

        return $region === 'western'
            ? $contextRule . '输出语言必须使用英文：分镜标题、镜头描述、定焦画面、旁白和人物台词全部写英文。即使 node_instruction 是中文模板或含中文示例，也不得改成中文输出。'
            : $contextRule . '输出语言必须使用中文：分镜标题、镜头描述、定焦画面、旁白和人物台词全部写中文。即使 node_instruction 是英文模板、含 English Prompt 或英文示例，也必须改写为中文输出，不得输出整段英文分镜。';
    }

    private function nodePromptOutputLanguage(string $nodePrompt): string
    {
        $prompt = trim($nodePrompt);

        // 只认用户显式中文指令，避免默认英文模板里的 “Output English...” 覆盖作品地区设置。
        if (
            str_contains($prompt, '输出中文')
            || str_contains($prompt, '中文自然语言')
            || str_contains($prompt, '必须使用中文')
            || str_contains($prompt, '中文描述')
            || str_contains($prompt, '中文分镜')
            || preg_match('/\bmust\s+(?:be\s+)?(?:written\s+)?in\s+chinese\b/i', $prompt) === 1
        ) {
            return 'chinese';
        }

        if (
            str_contains($prompt, '输出英文')
            || str_contains($prompt, '必须使用英文')
            || str_contains($prompt, '英文分镜')
            || preg_match('/\bmust\s+(?:be\s+)?(?:written\s+)?in\s+english\b/i', $prompt) === 1
        ) {
            return 'english';
        }

        return '';
    }

    private function nodePromptDisablesAssetMentionPrefixes(string $nodePrompt): bool
    {
        return str_contains($nodePrompt, '不加 @')
            || str_contains($nodePrompt, '不加@')
            || str_contains($nodePrompt, '不要 @')
            || str_contains($nodePrompt, '不要@')
            || str_contains($nodePrompt, '不用 @')
            || str_contains($nodePrompt, '不用@');
    }

    /**
     * 解析文本模型配置。
     * 优先使用节点指定 modelId，未找到时使用排序最靠前的文本模型。
     */
    private function resolveTextModelByIdOrDefault(int $modelId): ?ModelConfig
    {
        $model = ModelConfigResolver::resolve('text', $this->effectiveUserId(), $modelId);
        if ($modelId > 0 && !$model instanceof ModelConfig) {
            return ModelConfigResolver::resolve('text', $this->effectiveUserId(), 0);
        }
        return $model;
    }

    /**
     * 解析图片模型配置。
     * 使用全局默认图片模型（当前为腾讯云点播 OG/image2）。
     */
    private function resolveImageModelByIdOrDefault(int $modelId): ?ModelConfig
    {
        return ModelConfigResolver::resolve('image', $this->effectiveUserId(), 0);
    }

    /**
     * 解析视频模型配置。
     * 优先使用节点指定 modelId，未找到时使用默认视频模型。
     */
    private function resolveVideoModelByIdOrDefault(int $modelId): ?ModelConfig
    {
        $model = ModelConfigResolver::resolve('video', $this->effectiveUserId(), $modelId);
        if ($modelId > 0 && !$model instanceof ModelConfig) {
            return ModelConfigResolver::resolve('video', $this->effectiveUserId(), 0);
        }
        return $model;
    }

    /**
     * 从上游节点输出中提取分镜列表。
     * 兼容 JSON shots/scenes/frames，也兼容普通编号文本。
     */
    private function extractShotItemsForImageNode(array $upstreamOutputs): array
    {
        foreach (array_reverse($upstreamOutputs) as $output) {
            $shots = $this->normalizeShotItems($output);
            if ($shots !== []) {
                return $shots;
            }

            if (is_array($output)) {
                foreach (['text', 'content', 'result', 'script', 'raw'] as $key) {
                    if (isset($output[$key]) && is_string($output[$key])) {
                        $shots = $this->parseShotText($output[$key]);
                        if ($shots !== []) {
                            return $shots;
                        }
                    }
                }
            }
        }

        return [];
    }

    /**
     * 从图片节点输出中提取可用于图生视频的镜头。
     */
    private function extractVideoShotItems(array $upstreamOutput): array
    {
        $shots = [];
        $items = [];
        if (isset($upstreamOutput['shots']) && is_array($upstreamOutput['shots'])) {
            $items = $upstreamOutput['shots'];
        } elseif ($this->isListArray($upstreamOutput)) {
            $items = $upstreamOutput;
        }

        foreach ($items as $idx => $item) {
            if (!is_array($item)) {
                continue;
            }
            $imageUrl = trim((string) ($item['image_url'] ?? $item['url'] ?? ''));
            $referenceImages = isset($item['reference_images']) && is_array($item['reference_images'])
                ? $item['reference_images']
                : [];
            if ($imageUrl === '') {
                $imageUrl = $this->firstReferenceImageUrl($referenceImages);
            }
            $description = trim((string) (
                $item['description']
                ?? $item['desc']
                ?? $item['visual']
                ?? $item['prompt']
                ?? ''
            ));
            if ($description === '') {
                continue;
            }
            $shots[] = [
                'index' => (int) ($item['index'] ?? $item['number'] ?? ($idx + 1)),
                'description' => $description,
                'duration' => trim((string) ($item['duration'] ?? $item['time'] ?? '')),
                'image_url' => $imageUrl,
                'transition' => trim((string) ($item['transition'] ?? '')),
                'reference_images' => $referenceImages,
                'assets' => isset($item['assets']) && is_array($item['assets'])
                    ? $item['assets']
                    : $this->assetsFromReferenceImages($referenceImages),
            ];
        }

        if ($shots === []) {
            foreach ($this->normalizeShotItems($upstreamOutput) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $description = trim((string) ($item['description'] ?? ''));
                if ($description === '') {
                    continue;
                }
                $shots[] = [
                    'index' => (int) ($item['index'] ?? (count($shots) + 1)),
                    'description' => $description,
                    'duration' => trim((string) ($item['duration'] ?? '')),
                    'image_url' => '',
                    'transition' => '',
                    'reference_images' => [],
                    'assets' => [],
                ];
            }
        }

        if ($shots === []) {
            foreach (['text', 'content', 'result', 'script', 'raw'] as $key) {
                if (!isset($upstreamOutput[$key]) || !is_string($upstreamOutput[$key])) {
                    continue;
                }
                foreach ($this->parseShotText($upstreamOutput[$key]) as $item) {
                    $description = trim((string) ($item['description'] ?? ''));
                    if ($description === '') {
                        continue;
                    }
                    $shots[] = [
                        'index' => (int) ($item['index'] ?? (count($shots) + 1)),
                        'description' => $description,
                        'duration' => trim((string) ($item['duration'] ?? '')),
                        'image_url' => '',
                        'transition' => '',
                        'reference_images' => [],
                        'assets' => [],
                    ];
                }
                if ($shots !== []) {
                    break;
                }
            }
        }

        return $shots;
    }

    private function firstReferenceImageUrl(array $referenceImages): string
    {
        foreach ($this->videoReferenceAliases() as $key) {
            $url = trim((string) ($referenceImages[$key]['url'] ?? ''));
            if ($url !== '') {
                return $url;
            }
        }
        foreach (($referenceImages['props'] ?? []) as $prop) {
            if (!is_array($prop)) {
                continue;
            }
            $url = trim((string) ($prop['url'] ?? ''));
            if ($url !== '') {
                return $url;
            }
        }
        return '';
    }

    private function assetsFromReferenceImages(array $referenceImages): array
    {
        $assets = [];
        foreach ($this->videoReferenceAliases() as $alias) {
            $asset = $referenceImages[$alias] ?? null;
            if (is_array($asset)) {
                $asset['reference_alias'] = $alias;
                $asset['image_url'] = trim((string) ($asset['image_url'] ?? $asset['url'] ?? ''));
                $assets[] = $asset;
            }
        }
        foreach (($referenceImages['props'] ?? []) as $prop) {
            if (!is_array($prop)) {
                continue;
            }
            $prop['reference_alias'] = 'prop';
            $prop['image_url'] = trim((string) ($prop['image_url'] ?? $prop['url'] ?? ''));
            $assets[] = $prop;
        }
        return $assets;
    }

    /**
     * 规范化各种 JSON 分镜结构。
     */
    private function normalizeShotItems(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        foreach (['shots', 'shot_list', 'storyboard', 'frames', 'scenes', 'segments'] as $key) {
            if (isset($value[$key]) && is_array($value[$key])) {
                return $this->normalizeShotItems($value[$key]);
            }
        }

        if (!$this->isListArray($value)) {
            return [];
        }

        $shots = [];
        foreach ($value as $idx => $item) {
            if (is_string($item)) {
                $desc = trim($item);
                if ($desc !== '') {
                    $shots[] = ['index' => count($shots) + 1, 'description' => $desc, 'duration' => ''];
                }
                continue;
            }
            if (!is_array($item)) {
                continue;
            }

            $desc = trim((string) (
                $item['description']
                ?? $item['desc']
                ?? $item['text']
                ?? $item['visual']
                ?? $item['visual_description']
                ?? $item['image_prompt']
                ?? $item['prompt']
                ?? $item['content']
                ?? $item['shot']
                ?? $item['scene']
                ?? ''
            ));
            if ($desc === '') {
                $parts = [];
                foreach (['action', 'dialogue', 'camera', 'location', 'emotion'] as $field) {
                    if (isset($item[$field]) && trim((string) $item[$field]) !== '') {
                        $parts[] = trim((string) $item[$field]);
                    }
                }
                $desc = implode('；', $parts);
            }
            if ($desc === '') {
                continue;
            }

            $shots[] = [
                'index' => (int) ($item['index'] ?? $item['number'] ?? $item['shot_index'] ?? ($idx + 1)),
                'title' => trim((string) ($item['title'] ?? '')),
                'description' => $desc,
                'content_text' => trim((string) ($item['content_text'] ?? $desc)),
                'content_rich_json' => isset($item['content_rich_json']) && is_array($item['content_rich_json']) ? $item['content_rich_json'] : [],
                'asset_refs' => isset($item['asset_refs']) && is_array($item['asset_refs']) ? $item['asset_refs'] : [],
                'duration' => trim((string) ($item['duration'] ?? $item['time'] ?? '')),
            ];
        }

        return $shots;
    }

    private function isListArray(array $value): bool
    {
        if ($value === []) {
            return true;
        }
        return array_keys($value) === range(0, count($value) - 1);
    }

    private function shouldNormalizeStoryboardLookReferences(string $label): bool
    {
        return str_contains($label, '分镜') && !$this->isShotAssetBindingNode($label);
    }

    /**
     * 同一分镜里一旦明确使用「人物名·造型名」，后续人物本名统一改成完整造型引用。
     * 这一步是对模型输出的兜底，避免视频阶段同时命中人物本体图和人物造型图。
     * 同时清理模型常见的「图片N：资产名 / Image N: name」错误引用格式。
     */
    private function normalizeStoryboardLookReferences(string $text, int $seriesId): string
    {
        if (trim($text) === '') {
            return $text;
        }

        $text = $this->normalizeStoryboardAssetReferenceFormat($text);

        if ($seriesId <= 0) {
            return $this->dedupeStoryboardAssetReferenceLines($text);
        }

        $labels = $this->storyboardAssetReferenceLabels($seriesId);
        if ($labels !== []) {
            $text = $this->collapseSplitStoryboardAssetMentions($text, $labels);
            $text = $this->rewriteStoryboardAssetReferenceLinesWithLibrary($text, $labels);
        }

        $looks = $this->storyboardLookReferenceCandidates($seriesId);
        if ($looks === []) {
            return $this->dedupeStoryboardAssetReferenceLines($text);
        }

        $parts = preg_split(
            '/(?=^\s*【\s*(?:Video\s+Node|视频节点)\s*\d{1,3}\s*｜)/imu',
            $text,
            -1,
            PREG_SPLIT_NO_EMPTY,
        );
        if (!is_array($parts) || count($parts) <= 1) {
            return $this->normalizeStoryboardLookReferenceBlock($text, $looks);
        }

        return implode('', array_map(
            fn (string $part): string => $this->normalizeStoryboardLookReferenceBlock($part, $looks),
            $parts,
        ));
    }

    /**
     * 将模型误写的「图片1：导演·第1集常服 / Image 2: Schedule」统一为「@导演·第1集常服」。
     * 引用资产行与正文一并处理；无真实资产名的裸「图片N」直接删除。
     */
    private function normalizeStoryboardAssetReferenceFormat(string $text): string
    {
        if (trim($text) === '') {
            return $text;
        }

        // 引用资产行：图片1：xxx、图片2：yyy → @xxx @yyy
        $text = preg_replace_callback(
            '/^([ \t]*(?:引用资产|Referenced\s+assets)[：:]\s*)(.+)$/imu',
            function (array $matches): string {
                $prefix = (string) ($matches[1] ?? '');
                $body = (string) ($matches[2] ?? '');
                $body = preg_replace(
                    '/(?:图片|Image)\s*(\d+)\s*[：:]\s*/iu',
                    '',
                    $body,
                ) ?? $body;
                $body = preg_replace('/(?:图片|Image)\s*\d+/iu', '', $body) ?? $body;
                $body = $this->glueStoryboardReferenceLineBody($body);
                if ($body === '') {
                    return rtrim($prefix);
                }

                return $prefix . $body;
            },
            $text,
        ) ?? $text;

        // 正文/任意位置：图片3：财务报表 → @财务报表
        $text = preg_replace(
            '/(?:图片|Image)\s*\d+\s*[：:]\s*([^\s,，、;；。：:（）()【】\[\]]+)/iu',
            '@$1',
            $text,
        ) ?? $text;

        // 残留裸编号：图片1 / Image 2（无资产名）删除
        $text = preg_replace('/(?:图片|Image)\s*\d+/iu', '', $text) ?? $text;

        return $text;
    }

    /**
     * 英文名带空格时，模型常写成 @Jude Parker，旧逻辑会把后续词再加 @，拆成 @Jude @Parker。
     * 没有 @ 的词粘到上一个 @ 引用上。
     */
    private function glueStoryboardReferenceLineBody(string $body): string
    {
        $body = preg_replace('/[，,;；、|｜]+/u', ' ', $body) ?? $body;
        $body = preg_replace('/\s+/u', ' ', trim($body)) ?? trim($body);
        if ($body === '') {
            return '';
        }

        $tokens = preg_split('/\s+/u', $body) ?: [];
        $hasAt = false;
        foreach ($tokens as $token) {
            if (str_starts_with(trim((string) $token), '@')) {
                $hasAt = true;
                break;
            }
        }
        if (!$hasAt) {
            $names = [];
            $seenBare = [];
            foreach ($tokens as $token) {
                $token = trim((string) $token, " \t\n\r\0\x0B,，、;；。");
                if ($token === '') {
                    continue;
                }
                $mention = '@' . ltrim($token, '@');
                if (isset($seenBare[$mention])) {
                    continue;
                }
                $seenBare[$mention] = true;
                $names[] = $mention;
            }
            return implode(' ', $names);
        }

        $mentions = [];
        $current = '';
        foreach ($tokens as $token) {
            $token = trim((string) $token, " \t\n\r\0\x0B,，、;；。");
            if ($token === '') {
                continue;
            }
            if (str_starts_with($token, '@')) {
                if ($current !== '') {
                    $mentions[] = $current;
                }
                $current = '@' . ltrim($token, '@');
                continue;
            }
            if ($current !== '') {
                $current .= ' ' . $token;
                continue;
            }
            $current = '@' . ltrim($token, '@');
        }
        if ($current !== '') {
            $mentions[] = $current;
        }

        $mentions = array_map(
            static fn (string $mention): string => preg_replace('/(\S+)\s+\1·/u', '$1·', $mention) ?? $mention,
            $mentions,
        );

        $seen = [];
        $unique = [];
        foreach ($mentions as $mention) {
            if ($mention === '' || isset($seen[$mention])) {
                continue;
            }
            $seen[$mention] = true;
            $unique[] = $mention;
        }

        return implode(' ', $unique);
    }

    /**
     * @return array<int, string>
     */
    private function storyboardAssetReferenceLabels(int $seriesId): array
    {
        $labels = [];
        foreach ($this->buildEpisodeAssetLibrary($seriesId) as $asset) {
            foreach (['name', 'character_name'] as $field) {
                $label = trim((string) ($asset[$field] ?? ''));
                if ($label !== '') {
                    $labels[$label] = $label;
                }
            }
        }

        $values = array_values($labels);
        usort($values, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $values;
    }

    /**
     * 把 @Jude @Parker·Episode @1 @Casual 拼回资产库里的完整英文名。
     *
     * @param array<int, string> $labels
     */
    private function collapseSplitStoryboardAssetMentions(string $text, array $labels): string
    {
        foreach ($labels as $label) {
            $words = preg_split('/\s+/u', $label) ?: [];
            if (count($words) < 2) {
                continue;
            }
            $parts = array_map(static fn (string $word): string => preg_quote($word, '/'), $words);
            $pattern = '/@?' . implode('(?:\s+@?)', $parts) . '/u';
            $text = preg_replace($pattern, '@' . $label, $text) ?? $text;
        }

        return $text;
    }

    /**
     * @param array<int, string> $labels
     */
    private function rewriteStoryboardAssetReferenceLinesWithLibrary(string $text, array $labels): string
    {
        return preg_replace_callback(
            '/^([ \t]*(?:引用资产|Referenced\s+assets)[：:]\s*)(.*)$/imu',
            function (array $matches) use ($labels): string {
                $prefix = (string) ($matches[1] ?? '');
                $body = $this->mergeAdjacentStoryboardMentions(
                    $this->glueStoryboardReferenceLineBody((string) ($matches[2] ?? '')),
                    $labels,
                );
                return $body === '' ? rtrim($prefix) : $prefix . $body;
            },
            $text,
        ) ?? $text;
    }

    /**
     * @param array<int, string> $labels
     */
    private function mergeAdjacentStoryboardMentions(string $body, array $labels): string
    {
        if ($body === '' || $labels === []) {
            return $body;
        }

        preg_match_all('/@[^@]+/u', $body, $matches);
        $parts = [];
        foreach ($matches[0] ?? [] as $part) {
            $part = trim((string) $part);
            if ($part !== '') {
                $parts[] = $part;
            }
        }
        if ($parts === []) {
            return $body;
        }

        $labelSet = [];
        foreach ($labels as $label) {
            $labelSet[mb_strtolower(trim($label))] = trim($label);
        }

        $merged = [];
        $i = 0;
        $count = count($parts);
        while ($i < $count) {
            $best = $parts[$i];
            $consumed = 1;
            $acc = ltrim($parts[$i], '@');
            for ($j = $i + 1; $j < $count; $j++) {
                $next = ltrim($parts[$j], '@');
                $joined = $acc . ' ' . $next;
                $collapsed = preg_replace('/(\S+)\s+\1·/u', '$1·', $joined) ?? $joined;
                $hit = $labelSet[mb_strtolower(trim($collapsed))]
                    ?? $labelSet[mb_strtolower(trim($joined))]
                    ?? null;
                if ($hit === null) {
                    break;
                }
                $best = '@' . $hit;
                $acc = $hit;
                $consumed = $j - $i + 1;
            }
            $single = $labelSet[mb_strtolower(ltrim($best, '@'))] ?? null;
            if (is_string($single) && $single !== '') {
                $best = '@' . $single;
            }
            $merged[] = $best;
            $i += $consumed;
        }

        return implode(' ', $merged);
    }

    /**
     * @return array<int, array{look_name: string, character_name: string}>
     */
    private function storyboardLookReferenceCandidates(int $seriesId): array
    {
        $items = [];
        foreach ($this->buildEpisodeAssetLibrary($seriesId) as $asset) {
            if (($asset['reference_role'] ?? '') !== 'look') {
                continue;
            }
            $lookName = trim((string) ($asset['name'] ?? ''));
            $characterName = trim((string) ($asset['character_name'] ?? ''));
            if ($lookName === '' || $characterName === '' || $lookName === $characterName) {
                continue;
            }
            $items[] = [
                'look_name' => $lookName,
                'character_name' => $characterName,
            ];
        }

        usort($items, static function (array $a, array $b): int {
            $characterLength = mb_strlen($b['character_name']) <=> mb_strlen($a['character_name']);
            if ($characterLength !== 0) {
                return $characterLength;
            }
            return mb_strlen($b['look_name']) <=> mb_strlen($a['look_name']);
        });

        return $items;
    }

    /**
     * @param array<int, array{look_name: string, character_name: string}> $looks
     */
    private function normalizeStoryboardLookReferenceBlock(string $block, array $looks): string
    {
        $activeByCharacter = [];
        foreach ($looks as $look) {
            $lookName = $look['look_name'];
            $position = mb_strpos($block, $lookName);
            if ($position === false) {
                continue;
            }
            $key = mb_strtolower($look['character_name']);
            if (!isset($activeByCharacter[$key]) || $position < $activeByCharacter[$key]['position']) {
                $activeByCharacter[$key] = [
                    'look_name' => $lookName,
                    'character_name' => $look['character_name'],
                    'position' => $position,
                ];
            }
        }

        if ($activeByCharacter === []) {
            return $block;
        }

        $activeLooks = array_values($activeByCharacter);
        usort($activeLooks, static function (array $a, array $b): int {
            return mb_strlen($b['look_name']) <=> mb_strlen($a['look_name']);
        });

        $working = $block;
        $placeholders = [];
        foreach ($activeLooks as $look) {
            $pattern = '/@?' . preg_quote($look['look_name'], '/') . '/u';
            $working = preg_replace_callback($pattern, function () use (&$placeholders, $look): string {
                $placeholder = '__CODEX_LOOK_REF_' . count($placeholders) . '__';
                $placeholders[$placeholder] = '@' . $look['look_name'];
                return $placeholder;
            }, $working) ?? $working;
        }

        usort($activeLooks, static function (array $a, array $b): int {
            return mb_strlen($b['character_name']) <=> mb_strlen($a['character_name']);
        });

        foreach ($activeLooks as $look) {
            $characterPattern = '/(?<![@·A-Za-z0-9_])@?' . preg_quote($look['character_name'], '/') . '(?![·A-Za-z0-9_])/u';
            $working = preg_replace($characterPattern, '@' . $look['look_name'], $working) ?? $working;
        }

        foreach ($placeholders as $placeholder => $replacement) {
            $working = str_replace($placeholder, $replacement, $working);
        }

        return $this->dedupeStoryboardAssetReferenceLines($working);
    }

    private function dedupeStoryboardAssetReferenceLines(string $text): string
    {
        return preg_replace_callback(
            '/^([ \t]*(?:引用资产|Referenced\s+assets)[：:]\s*)(.*)$/imu',
            static function (array $matches): string {
                $prefix = (string) ($matches[1] ?? '');
                $body = (string) ($matches[2] ?? '');
                preg_match_all('/@[^@\n]+/u', $body, $tokenMatches);
                $tokens = array_values(array_filter(array_map(
                    static fn (string $token): string => trim($token),
                    $tokenMatches[0] ?? [],
                )));
                if ($tokens === []) {
                    // 无 @ 时仍尽量把空格分隔名补成 @名
                    $parts = preg_split('/[\s,，、;；]+/u', trim($body)) ?: [];
                    $fallback = [];
                    $seenFallback = [];
                    foreach ($parts as $part) {
                        $part = trim((string) $part);
                        if ($part === '') {
                            continue;
                        }
                        $token = str_starts_with($part, '@') ? $part : '@' . $part;
                        if (isset($seenFallback[$token])) {
                            continue;
                        }
                        $seenFallback[$token] = true;
                        $fallback[] = $token;
                    }
                    if ($fallback === []) {
                        return rtrim($prefix);
                    }
                    return $prefix . implode(' ', $fallback);
                }

                $seen = [];
                $deduped = [];
                foreach ($tokens as $token) {
                    if (isset($seen[$token])) {
                        continue;
                    }
                    $seen[$token] = true;
                    $deduped[] = $token;
                }

                return $prefix . implode(' ', $deduped);
            },
            $text,
        ) ?? $text;
    }

    private function storyboardShotsFromRunNodeOutput(WorkflowRunNode $runNode, int $seriesId): array
    {
        $output = $runNode->getAttr('output_json') ?: [];
        if (is_array($output)) {
            $rawShots = isset($output['shots']) && is_array($output['shots']) ? $output['shots'] : [];
            $shots = $this->normalizeStructuredStoryboardShots($rawShots, $seriesId);
            if ($shots !== []) {
                return $shots;
            }

            foreach (['text', 'content', 'result', 'script', 'raw'] as $key) {
                if (isset($output[$key]) && is_string($output[$key])) {
                    $shots = $this->structuredStoryboardShotsFromText($output[$key], $seriesId);
                    if ($shots !== []) {
                        return $shots;
                    }
                }
            }
        }

        $raw = trim((string) $runNode->getAttr('raw_output'));
        return $raw !== '' ? $this->structuredStoryboardShotsFromText($raw, $seriesId) : [];
    }

    private function summarizeStoryboardShotForRegeneration(array $shot): array
    {
        return [
            'index' => (int) ($shot['index'] ?? 0),
            'title' => (string) ($shot['title'] ?? ''),
            'content_text' => mb_substr(trim((string) ($shot['content_text'] ?? $shot['description'] ?? '')), 0, 1200),
        ];
    }

    private function storyboardShotPayloadForUpdate(array $shots, int $seriesId): array
    {
        $payload = [];
        foreach ($shots as $offset => $shot) {
            if (!is_array($shot)) {
                continue;
            }
            $index = (int) ($shot['index'] ?? ($offset + 1));
            if ($index <= 0) {
                $index = $offset + 1;
            }
            $title = trim((string) ($shot['title'] ?? "镜头 {$index}"));
            $contentText = trim((string) ($shot['content_text'] ?? $shot['description'] ?? ''));
            if ($contentText === '') {
                continue;
            }
            $richNodes = $this->normalizeStoryboardRichNodes($shot['content_rich_json'] ?? [], $seriesId);
            if ($richNodes === []) {
                $richNodes = $this->storyboardRichNodesFromText($contentText, $seriesId);
            }
            $payload[] = [
                'index' => $index,
                'title' => $this->cleanRegeneratedStoryboardShotTitle($title, $index),
                'content_text' => $contentText,
                'content_rich_json' => $richNodes,
                'asset_refs' => $this->storyboardAssetRefsFromRichNodes($richNodes),
                'description' => $contentText,
            ];
        }

        usort($payload, static fn (array $a, array $b): int => (int) $a['index'] <=> (int) $b['index']);
        return $payload;
    }

    private function normalizeRegeneratedStoryboardShot(string $raw, int $shotIndex, int $seriesId, string $fallbackTitle): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            abort(502, 'AI 未返回可用分镜内容');
        }

        $parsed = $this->safeParseJson($raw);
        if (!isset($parsed['__raw'])) {
            $candidate = $parsed;
            if (isset($parsed['shot']) && is_array($parsed['shot'])) {
                $candidate = $parsed['shot'];
            } elseif (isset($parsed['shots']) && is_array($parsed['shots'])) {
                $shotItems = array_values(array_filter($parsed['shots'], static fn ($item): bool => is_array($item)));
                if (count($shotItems) !== 1) {
                    abort(422, 'AI 返回了多个镜头，已阻止写入');
                }
                $candidate = $shotItems[0];
            }

            if (!is_array($candidate)) {
                abort(422, 'AI 返回格式无效，已阻止写入');
            }

            $returnedIndex = (int) ($candidate['index'] ?? $candidate['shot_index'] ?? $candidate['number'] ?? 0);
            if ($returnedIndex > 0 && $returnedIndex !== $shotIndex) {
                abort(422, 'AI 返回的镜头序号不匹配，已阻止写入');
            }

            $richNodes = $this->normalizeStoryboardRichNodes($candidate['content_rich_json'] ?? [], $seriesId);
            $contentText = $richNodes !== []
                ? $this->storyboardRichNodesToText($richNodes)
                : trim((string) (
                    $candidate['content_text']
                    ?? $candidate['content']
                    ?? $candidate['text']
                    ?? $candidate['description']
                    ?? $candidate['body']
                    ?? ''
                ));
            if ($contentText === '') {
                abort(422, 'AI 返回的镜头正文为空，已阻止写入');
            }
            if ($richNodes === []) {
                $richNodes = $this->storyboardRichNodesFromText($contentText, $seriesId);
            }

            $title = trim((string) ($candidate['title'] ?? $candidate['summary'] ?? $fallbackTitle));
            return [
                'index' => $shotIndex,
                'title' => $this->cleanRegeneratedStoryboardShotTitle($title, $shotIndex),
                'content_text' => $contentText,
                'content_rich_json' => $richNodes,
                'asset_refs' => $this->storyboardAssetRefsFromRichNodes($richNodes),
                'description' => $contentText,
            ];
        }

        $text = $this->stripMarkdownCodeFence($raw);
        if (preg_match('/^\s*[\{\[]/u', $text) === 1) {
            abort(422, 'AI 返回 JSON 格式无效，已阻止写入');
        }
        $shots = $this->structuredStoryboardShotsFromText($text, $seriesId);
        if (count($shots) > 1) {
            abort(422, 'AI 返回了多个镜头，已阻止写入');
        }
        if (count($shots) === 1) {
            $shot = $shots[0];
            $returnedIndex = (int) ($shot['index'] ?? 0);
            if ($returnedIndex > 0 && $returnedIndex !== $shotIndex) {
                abort(422, 'AI 返回的镜头序号不匹配，已阻止写入');
            }
            $shot['index'] = $shotIndex;
            $shot['title'] = $this->cleanRegeneratedStoryboardShotTitle((string) ($shot['title'] ?? $fallbackTitle), $shotIndex);
            return $shot;
        }

        if ($text === '') {
            abort(422, 'AI 返回的镜头正文为空，已阻止写入');
        }
        $richNodes = $this->storyboardRichNodesFromText($text, $seriesId);
        return [
            'index' => $shotIndex,
            'title' => $this->cleanRegeneratedStoryboardShotTitle($fallbackTitle, $shotIndex),
            'content_text' => $text,
            'content_rich_json' => $richNodes,
            'asset_refs' => $this->storyboardAssetRefsFromRichNodes($richNodes),
            'description' => $text,
        ];
    }

    private function cleanRegeneratedStoryboardShotTitle(string $title, int $index): string
    {
        $title = trim((string) preg_replace('/^【\s*(?:视频节点|Video\s+Node)\s*\d{1,3}\s*｜?/iu', '', $title));
        $title = trim((string) preg_replace('/】$/u', '', $title));
        return $title !== '' ? $title : "镜头 {$index}";
    }

    private function stripMarkdownCodeFence(string $text): string
    {
        $text = trim($text);
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```[a-zA-Z]*\s*/', '', $text) ?? $text;
            $text = preg_replace('/\s*```$/', '', $text) ?? $text;
        }
        return trim($text);
    }

    private function normalizeStructuredStoryboardShots(array $shots, int $seriesId): array
    {
        $normalized = [];
        foreach ($shots as $offset => $shot) {
            if (!is_array($shot)) {
                continue;
            }
            $index = (int) ($shot['index'] ?? ($offset + 1));
            if ($index <= 0) {
                $index = $offset + 1;
            }
            $title = trim((string) ($shot['title'] ?? ''));
            $title = $title !== '' ? $title : "镜头 {$index}";
            $richNodes = $this->normalizeStoryboardRichNodes($shot['content_rich_json'] ?? [], $seriesId);
            $contentText = $richNodes !== []
                ? $this->storyboardRichNodesToText($richNodes)
                : trim((string) ($shot['content_text'] ?? ''));
            if ($contentText === '') {
                continue;
            }
            $item = [
                'index' => $index,
                'title' => $title,
                'content_text' => $contentText,
                'content_rich_json' => $richNodes !== [] ? $richNodes : $this->storyboardRichNodesFromText($contentText, $seriesId),
                'asset_refs' => $this->storyboardAssetRefsFromRichNodes($richNodes !== [] ? $richNodes : $this->storyboardRichNodesFromText($contentText, $seriesId)),
                'description' => $contentText,
            ];
            $shotKey = trim((string) ($shot['shot_key'] ?? ''));
            if ($shotKey !== '') {
                $item['shot_key'] = $this->ensureShotKeyValue($shotKey);
            }
            $normalized[] = $item;
        }

        usort($normalized, static fn (array $a, array $b): int => (int) $a['index'] <=> (int) $b['index']);
        return $normalized;
    }

    private function normalizeStoryboardRichNodes(mixed $nodes, int $seriesId): array
    {
        if (!is_array($nodes)) {
            return [];
        }

        $library = $this->episodeAssetLibraryByRefKey($seriesId);
        $normalized = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $type = trim((string) ($node['type'] ?? ''));
            if ($type === 'text') {
                $text = (string) ($node['text'] ?? '');
                if ($text !== '') {
                    $normalized[] = ['type' => 'text', 'text' => $text];
                }
                continue;
            }
            if ($type !== 'asset_ref') {
                continue;
            }

            $assetId = (int) ($node['asset_id'] ?? 0);
            $assetImageId = isset($node['asset_image_id']) && $node['asset_image_id'] !== null ? (int) $node['asset_image_id'] : null;
            $assetImageVersionId = isset($node['asset_image_version_id']) && $node['asset_image_version_id'] !== null ? (int) $node['asset_image_version_id'] : null;
            $referenceRole = strtolower(trim((string) ($node['reference_role'] ?? 'view'))) === 'look' ? 'look' : 'view';
            $key = $this->storyboardAssetRefKey($assetId, $assetImageId, $referenceRole, $assetImageVersionId);
            $matched = $library[$key] ?? null;
            if (!is_array($matched)) {
                continue;
            }
            $normalized[] = [
                'type' => 'asset_ref',
                'asset_id' => $assetId,
                'asset_image_id' => $assetImageId,
                'asset_image_version_id' => $assetImageVersionId,
                'reference_role' => $referenceRole,
                'label' => (string) ($matched['name'] ?? ($node['label'] ?? '')),
                'asset_type' => $referenceRole === 'look' ? 'look' : (string) ($matched['type'] ?? ($node['asset_type'] ?? 'prop')),
            ];
        }

        return $this->mergeAdjacentStoryboardTextNodes($normalized);
    }

    private function storyboardRichNodesToText(array $nodes): string
    {
        $chunks = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            if (($node['type'] ?? '') === 'asset_ref') {
                $label = trim((string) ($node['label'] ?? ''));
                if ($label !== '') {
                    $chunks[] = '@' . ltrim($label, '@');
                }
                continue;
            }
            $chunks[] = (string) ($node['text'] ?? '');
        }

        return implode('', $chunks);
    }

    private function storyboardAssetRefsFromRichNodes(array $nodes): array
    {
        $refs = [];
        $seen = [];
        foreach ($nodes as $node) {
            if (!is_array($node) || ($node['type'] ?? '') !== 'asset_ref') {
                continue;
            }
            $assetId = (int) ($node['asset_id'] ?? 0);
            if ($assetId <= 0) {
                continue;
            }
            $assetImageId = isset($node['asset_image_id']) && $node['asset_image_id'] !== null ? (int) $node['asset_image_id'] : null;
            $assetImageVersionId = isset($node['asset_image_version_id']) && $node['asset_image_version_id'] !== null ? (int) $node['asset_image_version_id'] : null;
            $referenceRole = strtolower(trim((string) ($node['reference_role'] ?? 'view'))) === 'look' ? 'look' : 'view';
            $key = $this->storyboardAssetRefKey($assetId, $assetImageId, $referenceRole, $assetImageVersionId);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $refs[] = [
                'type' => 'asset_ref',
                'asset_id' => $assetId,
                'asset_image_id' => $assetImageId,
                'asset_image_version_id' => $assetImageVersionId,
                'reference_role' => $referenceRole,
                'label' => trim((string) ($node['label'] ?? '')),
                'asset_type' => (string) ($node['asset_type'] ?? ($referenceRole === 'look' ? 'look' : 'prop')),
            ];
        }

        return $refs;
    }

    private function mergeAdjacentStoryboardTextNodes(array $nodes): array
    {
        $merged = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            if (($node['type'] ?? '') === 'text') {
                $text = (string) ($node['text'] ?? '');
                if ($text === '') {
                    continue;
                }
                $lastIndex = count($merged) - 1;
                if ($lastIndex >= 0 && ($merged[$lastIndex]['type'] ?? '') === 'text') {
                    $merged[$lastIndex]['text'] .= $text;
                } else {
                    $merged[] = ['type' => 'text', 'text' => $text];
                }
                continue;
            }
            $merged[] = $node;
        }

        return $merged;
    }

    private function formatStructuredStoryboardShotsAsText(array $shots): string
    {
        $parts = [];
        foreach ($shots as $shot) {
            if (!is_array($shot)) {
                continue;
            }
            $index = (int) ($shot['index'] ?? (count($parts) + 1));
            $title = trim((string) ($shot['title'] ?? "镜头 {$index}"));
            $body = trim((string) ($shot['content_text'] ?? $shot['description'] ?? ''));
            if ($body === '') {
                continue;
            }
            $parts[] = sprintf(
                "【视频节点%s｜%s】\n%s",
                str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                $title,
                $body
            );
        }

        return implode("\n\n", $parts);
    }

    private function structuredStoryboardShotsFromText(string $text, int $seriesId): array
    {
        $shots = [];
        foreach ($this->parseShotText($text) as $shot) {
            $description = trim((string) ($shot['description'] ?? ''));
            $title = trim((string) preg_replace('/\R[\s\S]*$/u', '', $description));
            $body = trim((string) preg_replace('/^[^\r\n]*\R?/u', '', $description));
            if ($body === '') {
                $body = $description;
            }
            $richNodes = $this->storyboardRichNodesFromText($body, $seriesId);
            $shots[] = [
                'index' => (int) ($shot['index'] ?? (count($shots) + 1)),
                'title' => $title !== '' ? $title : "镜头 " . ((int) ($shot['index'] ?? (count($shots) + 1))),
                'content_text' => $body,
                'content_rich_json' => $richNodes,
                'asset_refs' => $this->storyboardAssetRefsFromRichNodes($richNodes),
                'description' => $body,
            ];
        }

        return $shots;
    }

    private function storyboardRichNodesFromText(string $text, int $seriesId): array
    {
        $source = (string) $text;
        if ($source === '') {
            return [];
        }

        $ranges = $this->storyboardExplicitReferenceRanges($source, $seriesId);
        if ($ranges === []) {
            return [['type' => 'text', 'text' => $source]];
        }

        $nodes = [];
        $cursor = 0;
        foreach ($ranges as $range) {
            if (($range['start'] ?? 0) > $cursor) {
                $nodes[] = ['type' => 'text', 'text' => mb_substr($source, $cursor, (int) $range['start'] - $cursor)];
            }
            $nodes[] = [
                'type' => 'asset_ref',
                'asset_id' => (int) ($range['asset_id'] ?? 0),
                'asset_image_id' => $range['asset_image_id'] ?? null,
                'asset_image_version_id' => $range['asset_image_version_id'] ?? null,
                'reference_role' => (string) ($range['reference_role'] ?? 'view'),
                'label' => (string) ($range['label'] ?? ''),
                'asset_type' => (string) ($range['asset_type'] ?? 'prop'),
            ];
            $cursor = (int) ($range['end'] ?? $cursor);
        }
        if ($cursor < mb_strlen($source)) {
            $nodes[] = ['type' => 'text', 'text' => mb_substr($source, $cursor)];
        }

        return $this->mergeAdjacentStoryboardTextNodes($nodes);
    }

    private function storyboardExplicitReferenceRanges(string $text, int $seriesId): array
    {
        $library = $this->buildEpisodeAssetLibrary($seriesId);
        usort($library, static fn (array $a, array $b): int => mb_strlen((string) ($b['name'] ?? '')) <=> mb_strlen((string) ($a['name'] ?? '')));

        $ranges = [];
        foreach ($library as $asset) {
            $label = trim((string) ($asset['name'] ?? ''));
            if ($label === '') {
                continue;
            }
            $token = '@' . $label;
            $cursor = 0;
            while (($start = mb_strpos($text, $token, $cursor)) !== false) {
                $end = $start + mb_strlen($token);
                $overlaps = false;
                foreach ($ranges as $range) {
                    if ($start < (int) $range['end'] && $end > (int) $range['start']) {
                        $overlaps = true;
                        break;
                    }
                }
                if (!$overlaps) {
                    $ranges[] = [
                        'start' => $start,
                        'end' => $end,
                        'label' => $label,
                        'asset_id' => (int) ($asset['asset_id'] ?? $asset['id'] ?? 0),
                        'asset_image_id' => isset($asset['asset_image_id']) ? (int) $asset['asset_image_id'] : null,
                        'asset_image_version_id' => isset($asset['asset_image_version_id']) ? (int) $asset['asset_image_version_id'] : null,
                        'reference_role' => (string) ($asset['reference_role'] ?? 'view'),
                        'asset_type' => ((string) ($asset['reference_role'] ?? 'view')) === 'look' ? 'look' : (string) ($asset['type'] ?? 'prop'),
                    ];
                }
                $cursor = $start + 1;
            }
        }

        usort($ranges, static fn (array $a, array $b): int => (int) $a['start'] <=> (int) $b['start']);
        return $ranges;
    }

    private function assetMentionsFromStructuredStoryboardShots(array $shots): array
    {
        $mentions = [];
        foreach ($shots as $shot) {
            if (!is_array($shot)) {
                continue;
            }
            foreach (($shot['asset_refs'] ?? []) as $ref) {
                if (!is_array($ref) || (int) ($ref['asset_id'] ?? 0) <= 0) {
                    continue;
                }
                $mentions[] = [
                    'term' => '@' . ltrim((string) ($ref['label'] ?? ''), '@'),
                    'asset_id' => (int) ($ref['asset_id'] ?? 0),
                    'asset_image_id' => isset($ref['asset_image_id']) && $ref['asset_image_id'] !== null ? (int) $ref['asset_image_id'] : null,
                    'asset_image_version_id' => isset($ref['asset_image_version_id']) && $ref['asset_image_version_id'] !== null ? (int) $ref['asset_image_version_id'] : null,
                    'reference_role' => (string) ($ref['reference_role'] ?? 'view'),
                ];
            }
        }

        return $mentions;
    }

    private function episodeAssetLibraryByRefKey(int $seriesId): array
    {
        $mapped = [];
        foreach ($this->buildEpisodeAssetLibrary($seriesId) as $asset) {
            $assetId = (int) ($asset['asset_id'] ?? $asset['id'] ?? 0);
            if ($assetId <= 0) {
                continue;
            }
            $assetImageId = isset($asset['asset_image_id']) && $asset['asset_image_id'] !== null ? (int) $asset['asset_image_id'] : null;
            $referenceRole = strtolower(trim((string) ($asset['reference_role'] ?? 'view'))) === 'look' ? 'look' : 'view';
            $mapped[$this->storyboardAssetRefKey($assetId, $assetImageId, $referenceRole, isset($asset['asset_image_version_id']) && $asset['asset_image_version_id'] !== null ? (int) $asset['asset_image_version_id'] : null)] = $asset;
        }

        return $mapped;
    }

    private function storyboardAssetRefKey(int $assetId, ?int $assetImageId, string $referenceRole, ?int $assetImageVersionId = null): string
    {
        return implode(':', [$assetId, $assetImageId ?: 0, $assetImageVersionId ?: 0, $referenceRole === 'look' ? 'look' : 'view']);
    }

    /**
     * 从普通文本中按“镜头 1 / 1. / 1、”等编号提取分镜。
     */
    private function parseShotText(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $videoNodePattern = '/(?:^|\R)\s*【\s*(?:Video\s+Node|视频节点)\s*(\d{1,3})\s*｜\s*(?:(\d+(?:\.\d+)?\s*(?:s|秒))\s*｜\s*)?([^】]+)\s*】\s*(.*?)(?=\R\s*(?:---\s*)?\R?\s*【\s*(?:Video\s+Node|视频节点)\s*\d{1,3}\s*｜|\z)/isu';
        preg_match_all($videoNodePattern, "\n" . $text, $videoNodeMatches, PREG_SET_ORDER);
        $shots = [];
        foreach ($videoNodeMatches as $match) {
            $body = trim((string) ($match[4] ?? ''));
            $title = trim((string) ($match[3] ?? ''));
            $desc = trim($title . ($body !== '' ? "\n" . $body : ''));
            if ($desc === '') {
                continue;
            }
            $duration = trim((string) ($match[2] ?? ''));
            $shots[] = [
                'index' => (int) ($match[1] ?? (count($shots) + 1)),
                'description' => $desc,
                'duration' => $duration !== '' ? $duration : $this->extractDurationFromText($desc),
            ];
        }

        if ($shots !== []) {
            return $shots;
        }

        $matches = [];
        $pattern = '/(?:^|\R)\s*(?:镜头|分镜|shot)?\s*(\d{1,2})\s*[\.\、:：\)）-]\s*(.+?)(?=\R\s*(?:镜头|分镜|shot)?\s*\d{1,2}\s*[\.\、:：\)）-]|\z)/isu';
        preg_match_all($pattern, "\n" . $text, $matches, PREG_SET_ORDER);

        $shots = [];
        foreach ($matches as $match) {
            $desc = trim((string) ($match[2] ?? ''));
            if ($desc === '') {
                continue;
            }
            $shots[] = [
                'index' => (int) ($match[1] ?? (count($shots) + 1)),
                'description' => $desc,
                'duration' => $this->extractDurationFromText($desc),
            ];
        }

        if ($shots !== []) {
            return $shots;
        }

        $lines = preg_split('/\R+/', $text) ?: [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '' || mb_strlen($line) < 6) {
                continue;
            }
            $shots[] = [
                'index' => count($shots) + 1,
                'description' => preg_replace('/^\s*[-*]\s*/u', '', $line) ?? $line,
                'duration' => $this->extractDurationFromText($line),
            ];
        }

        return $shots;
    }

    private function isStoryboardRevisionNode(string $label, string $kind = ''): bool
    {
        return ($kind === '' || $kind === 'text')
            && str_contains($label, '分镜处理')
            && !$this->isShotAssetBindingNode($label);
    }

    private function isPromoStoryboardNode(string $label): bool
    {
        return str_contains($label, '宣传片') && str_contains($label, '分镜处理');
    }

    /**
     * @return array<string, mixed>
     */
    private function episodeWorkflowPayloadDefaults(Episode $episode, Workflow $workflow): array
    {
        $payload = [
            'scope' => 'episode',
            'episode_id' => (int) $episode->getAttr('id'),
            'workflow_id' => (int) $workflow->getAttr('id'),
        ];
        $promoSegmentCount = PromoVideoSegmentConfig::optional($episode->getAttr('promo_segment_count') ?? null);
        if ($promoSegmentCount !== null) {
            $payload['promo_segment_count'] = $promoSegmentCount;
        }

        return $payload;
    }

    private function resolveEpisodePromoSegmentCount(Episode $episode, WorkflowRun $run): int
    {
        PromoVideoSegmentConfig::ensureSchema();
        $runPayload = $run->getAttr('payload_json') ?: [];
        if (!is_array($runPayload)) {
            $runPayload = [];
        }

        return PromoVideoSegmentConfig::resolve(
            PromoVideoSegmentConfig::optional($episode->getAttr('promo_segment_count') ?? null),
            $runPayload,
        );
    }

    private function clearCurrentStoryboardRevisionForEpisodeId(int $episodeId): void
    {
        if ($episodeId <= 0) {
            return;
        }

        $revisionId = $this->currentStoryboardRevisionIdForEpisodeId($episodeId);
        if ($revisionId > 0) {
            StoryboardRevision::where('id', $revisionId)->update(['status' => 'archived']);
        }
        Episode::where('id', $episodeId)->update(['current_storyboard_revision_id' => null]);
    }

    private function clearCurrentStoryboardRevisionForRunId(int $runId): void
    {
        $episodeId = $this->episodeIdForWorkflowRun($runId);
        if ($episodeId > 0) {
            $this->clearCurrentStoryboardRevisionForEpisodeId($episodeId);
        }
    }

    private function createStoryboardRevisionFromContent(
        Episode $episode,
        WorkflowRun $run,
        WorkflowRunNode $runNode,
        string $rawOutput,
        array $output,
        array $options = [],
    ): ?StoryboardRevision {
        $label = (string) $runNode->getAttr('label');
        $kind = (string) $runNode->getAttr('kind');
        if (!$this->isStoryboardRevisionNode($label, $kind)) {
            return null;
        }

        $source = $output;
        if ($rawOutput !== '' && !isset($source['text'])) {
            $source['text'] = $rawOutput;
        }
        $shots = $this->extractShotItemsForImageNode([$label !== '' ? $label : '分镜处理' => $source]);
        if ($shots === [] && $rawOutput !== '') {
            $shots = $this->parseShotText($rawOutput);
        }
        if ($shots === []) {
            return null;
        }

        $episodeId = (int) $episode->getAttr('id');
        $seriesId = (int) $episode->getAttr('series_id');
        $previousRevisionId = $this->currentStoryboardRevisionId($episode);
        $preserveRevisionId = (int) ($options['preserve_media_from_revision_id'] ?? 0);
        $changedShotIndex = (int) ($options['changed_shot_index'] ?? 0);
        $isSingleShotUpdate = $preserveRevisionId > 0 && $changedShotIndex > 0;

        $shotKeyByIndex = [];
        if (isset($output['shots']) && is_array($output['shots'])) {
            foreach ($output['shots'] as $offset => $shotMeta) {
                if (!is_array($shotMeta)) {
                    continue;
                }
                $metaIndex = (int) ($shotMeta['index'] ?? ($offset + 1));
                $metaIndex = $metaIndex > 0 ? $metaIndex : ($offset + 1);
                $metaKey = trim((string) ($shotMeta['shot_key'] ?? ''));
                if ($metaKey !== '') {
                    $shotKeyByIndex[$metaIndex] = $this->ensureShotKeyValue($metaKey);
                }
            }
        }

        StoryboardRevision::where('episode_id', $episodeId)
            ->where('status', 'current')
            ->update(['status' => 'archived']);

        $revision = new StoryboardRevision();
        $revision->save([
            'user_id' => $this->seriesOwnerId(null, (int) $episode->getAttr('user_id')),
            'series_id' => $seriesId,
            'episode_id' => $episodeId,
            'workflow_run_id' => (int) $run->getAttr('id'),
            'workflow_run_node_id' => (int) $runNode->getAttr('id'),
            'raw_output' => $rawOutput,
            'content_hash' => hash('sha256', $rawOutput),
            'shot_count' => count($shots),
            'status' => 'current',
        ]);

        $revisionId = (int) $revision->getAttr('id');
        $clonedContext = $isSingleShotUpdate
            ? $this->cloneStoryboardRevisionContext($episodeId, $preserveRevisionId, $revisionId, $changedShotIndex)
            : ['shots' => []];

        foreach ($shots as $offset => $shotData) {
            $index = (int) ($shotData['index'] ?? ($offset + 1));
            $index = $index > 0 ? $index : ($offset + 1);
            $description = trim((string) ($shotData['description'] ?? ''));
            if ($description === '') {
                continue;
            }
            $duration = trim((string) ($shotData['duration'] ?? ''));
            $shotKey = (string) ($shotKeyByIndex[$index] ?? trim((string) ($shotData['shot_key'] ?? '')));
            $clonedShot = $clonedContext['shots'][$index] ?? null;
            if ($clonedShot instanceof Shot) {
                $status = $index === $changedShotIndex ? 'pending' : (string) ($clonedShot->getAttr('status') ?? 'pending');
                $clonedShot->save([
                    'desc' => $description,
                    'duration' => $duration,
                    'status' => $status,
                ]);
                if ($index === $changedShotIndex) {
                    $this->markShotVideoJobStale((int) $runNode->getAttr('id'), $clonedShot, '分镜内容已修改，这段视频需要重新生成');
                }
                continue;
            }

            $this->upsertEpisodeShot($episodeId, $revisionId, $index, $description, $duration, 'pending', $shotKey);
        }

        if (!$isSingleShotUpdate && $previousRevisionId > 0 && $previousRevisionId !== $revisionId) {
            $this->migrateStoryboardMediaByShotKey($episodeId, $previousRevisionId, $revisionId);
        }

        Episode::where('id', $episodeId)->update(['current_storyboard_revision_id' => $revisionId]);
        $episode->setAttr('current_storyboard_revision_id', $revisionId);
        $this->purgeArchivedStoryboardArtifactsForEpisode($episodeId, $revisionId);

        return $revision;
    }

    /**
     * 单镜头改分镜时，先把旧 revision 的 shot / media / video job 复制到新 revision，
     * 然后仅把目标镜头视频打回待重生，其他镜头保持现状。
     *
     * @return array{shots: array<int, Shot>}
     */
    private function cloneStoryboardRevisionContext(int $episodeId, int $fromRevisionId, int $toRevisionId, int|array $changedShotIndexes): array
    {
        if ($episodeId <= 0 || $fromRevisionId <= 0 || $toRevisionId <= 0) {
            return ['shots' => []];
        }

        $oldShots = Shot::where('episode_id', $episodeId)
            ->where('storyboard_revision_id', $fromRevisionId)
            ->order(['index' => 'asc', 'id' => 'asc'])
            ->select();
        if ($oldShots->isEmpty()) {
            return ['shots' => []];
        }

        $changedIndexSet = $this->normalizeChangedShotIndexes($changedShotIndexes);
        $newShotsByIndex = [];
        $shotIdMap = [];
        foreach ($oldShots as $oldShot) {
            if (!$oldShot instanceof Shot) {
                continue;
            }

            $index = (int) $oldShot->getAttr('index');
            $isChangedShot = isset($changedIndexSet[$index]);
            $newShot = new Shot();
            $newShot->save([
                'user_id' => (int) $oldShot->getAttr('user_id'),
                'episode_id' => $episodeId,
                'storyboard_revision_id' => $toRevisionId,
                'shot_key' => $this->ensureShotKeyValue((string) ($oldShot->getAttr('shot_key') ?? '')),
                'index' => $index,
                'desc' => (string) $oldShot->getAttr('desc'),
                'duration' => (string) $oldShot->getAttr('duration'),
                'status' => $isChangedShot ? 'pending' : (string) ($oldShot->getAttr('status') ?? 'pending'),
                'image_url' => (string) ($oldShot->getAttr('image_url') ?? ''),
                'video_url' => $isChangedShot ? '' : (string) ($oldShot->getAttr('video_url') ?? ''),
                'video_end_frame_url' => $isChangedShot ? '' : (string) ($oldShot->getAttr('video_end_frame_url') ?? ''),
            ]);
            $newShotsByIndex[$index] = $newShot;
            $shotIdMap[(int) $oldShot->getAttr('id')] = (int) $newShot->getAttr('id');
        }

        $jobMap = $this->cloneStoryboardVideoJobsToRevision($episodeId, $fromRevisionId, $toRevisionId, $shotIdMap, $changedIndexSet);
        $this->cloneStoryboardMediaVersionsToRevision($episodeId, $fromRevisionId, $toRevisionId, $shotIdMap, $jobMap, $changedIndexSet);

        return ['shots' => $newShotsByIndex];
    }

    /**
     * 复制旧 revision 的视频任务到新 revision，保证未变更镜头仍能保留 prompt、状态与版本关联。
     *
     * @param array<int, int> $shotIdMap
     * @return array<int, int>
     */
    private function cloneStoryboardVideoJobsToRevision(
        int $episodeId,
        int $fromRevisionId,
        int $toRevisionId,
        array $shotIdMap,
        int|array $changedShotIndexes,
    ): array {
        if ($shotIdMap === []) {
            return [];
        }

        $changedIndexSet = $this->normalizeChangedShotIndexes($changedShotIndexes);
        $oldJobs = VideoJob::where('episode_id', $episodeId)
            ->where('storyboard_revision_id', $fromRevisionId)
            ->order(['id' => 'asc'])
            ->select();
        $jobMap = [];
        foreach ($oldJobs as $oldJob) {
            if (!$oldJob instanceof VideoJob) {
                continue;
            }

            $oldShotId = (int) $oldJob->getAttr('shot_id');
            if (!isset($shotIdMap[$oldShotId])) {
                continue;
            }

            $index = (int) $oldJob->getAttr('shot_index');
            $isChangedShot = isset($changedIndexSet[$index]);
            $oldStatus = (string) $oldJob->getAttr('status');
            $isInFlight = in_array($oldStatus, ['queued', 'blocked', 'running'], true);
            $data = $oldJob->toArray();
            unset($data['id'], $data['create_time'], $data['update_time']);
            $data['shot_id'] = $shotIdMap[$oldShotId];
            $data['storyboard_revision_id'] = $toRevisionId;
            // 禁止把 running 原样克隆到新 revision：旧 job 会被 purge，worker 仍持有旧 id，
            // 新行会变成无人认领的僵尸「生成中」。in-flight 改为 queued/blocked，保留 provider_task_id 供回填。
            if ($isChangedShot) {
                $data['status'] = 'stale';
                $data['video_url'] = '';
                $data['end_frame_url'] = '';
                $data['error_message'] = '分镜内容已修改，这段视频需要重新生成';
                $data['started_at'] = null;
                $data['finished_at'] = null;
            } elseif ($isInFlight) {
                $data['status'] = $oldStatus === 'blocked' ? 'blocked' : 'queued';
                $data['video_url'] = '';
                $data['end_frame_url'] = '';
                $data['error_message'] = $oldStatus === 'running'
                    ? '分镜修订迁移中：保留供应商任务，等待回填或继续排队'
                    : (string) $oldJob->getAttr('error_message');
                $data['started_at'] = null;
                $data['finished_at'] = null;
            } else {
                $data['status'] = $oldStatus;
                $data['video_url'] = (string) $oldJob->getAttr('video_url');
                $data['end_frame_url'] = (string) $oldJob->getAttr('end_frame_url');
                $data['error_message'] = (string) $oldJob->getAttr('error_message');
                $data['started_at'] = $oldJob->getAttr('started_at');
                $data['finished_at'] = $oldJob->getAttr('finished_at');
            }

            $requestContext = $oldJob->getAttr('request_context_json') ?: [];
            if (!is_array($requestContext)) {
                $requestContext = [];
            }
            if ($isChangedShot) {
                unset($requestContext['final_prompt']);
            }
            $requestContext['migrated_from_video_job_id'] = (int) $oldJob->getAttr('id');
            $data['request_context_json'] = $requestContext;

            $newJob = new VideoJob();
            $newJob->save($data);
            $jobMap[(int) $oldJob->getAttr('id')] = (int) $newJob->getAttr('id');
        }

        // 血缘依赖指向旧 job id，必须重映射到新 revision，否则链式永远 blocked。
        foreach ($jobMap as $newJobId) {
            $newJob = VideoJob::find((int) $newJobId);
            if (!$newJob instanceof VideoJob) {
                continue;
            }
            $dependsOn = (int) ($newJob->getAttr('depends_on_job_id') ?? 0);
            if ($dependsOn > 0 && isset($jobMap[$dependsOn])) {
                $newJob->save(['depends_on_job_id' => (int) $jobMap[$dependsOn]]);
            }
        }

        return $jobMap;
    }

    /**
     * 把旧 revision 的媒体版本复制到新 revision。
     * 未变更镜头保留图片/视频历史；变更镜头保留历史视频但强制 is_selected=0（当前结果待生成）。
     *
     * @param array<int, int> $shotIdMap
     * @param array<int, int> $jobMap
     */
    private function cloneStoryboardMediaVersionsToRevision(
        int $episodeId,
        int $fromRevisionId,
        int $toRevisionId,
        array $shotIdMap,
        array $jobMap,
        int|array $changedShotIndexes,
    ): void {
        if ($shotIdMap === []) {
            return;
        }

        $changedIndexSet = $this->normalizeChangedShotIndexes($changedShotIndexes);
        $newShotKeyById = [];
        if ($shotIdMap !== []) {
            $newShots = Shot::whereIn('id', array_values($shotIdMap))->select();
            foreach ($newShots as $newShot) {
                if ($newShot instanceof Shot) {
                    $newShotKeyById[(int) $newShot->getAttr('id')] = (string) ($newShot->getAttr('shot_key') ?? '');
                }
            }
        }

        $oldVersions = ShotMediaVersion::where('episode_id', $episodeId)
            ->where('storyboard_revision_id', $fromRevisionId)
            ->where('orphaned', 0)
            ->order(['id' => 'asc'])
            ->select();
        $versionMap = [];
        $parentRemap = [];
        foreach ($oldVersions as $oldVersion) {
            if (!$oldVersion instanceof ShotMediaVersion) {
                continue;
            }

            $url = trim((string) ($oldVersion->getAttr('url') ?? ''));
            if ($url === '') {
                continue;
            }

            $oldShotId = (int) $oldVersion->getAttr('shot_id');
            if (!isset($shotIdMap[$oldShotId])) {
                continue;
            }

            $shotIndex = (int) (Shot::where('id', $oldShotId)->value('index') ?: 0);
            $isChangedShot = isset($changedIndexSet[$shotIndex]);
            $mediaType = (string) $oldVersion->getAttr('media_type');
            $newShotId = $shotIdMap[$oldShotId];

            $data = $oldVersion->toArray();
            unset($data['id'], $data['create_time'], $data['update_time']);
            $data['shot_id'] = $newShotId;
            $data['storyboard_revision_id'] = $toRevisionId;
            $data['shot_key'] = $this->ensureShotKeyValue(
                (string) ($newShotKeyById[$newShotId] ?? $oldVersion->getAttr('shot_key') ?? '')
            );
            $data['orphaned'] = 0;
            $data['video_job_id'] = $mediaType === 'video'
                ? ($jobMap[(int) ($oldVersion->getAttr('video_job_id') ?? 0)] ?? null)
                : null;
            $data['parent_version_id'] = null;
            if ($isChangedShot && $mediaType === 'video') {
                $data['is_selected'] = 0;
            }

            $newVersion = new ShotMediaVersion();
            $newVersion->save($data);
            $oldVersionId = (int) $oldVersion->getAttr('id');
            $newVersionId = (int) $newVersion->getAttr('id');
            $versionMap[$oldVersionId] = $newVersionId;
            $parentRemap[$newVersionId] = (int) ($oldVersion->getAttr('parent_version_id') ?? 0);
        }

        foreach ($parentRemap as $newVersionId => $oldParentId) {
            if ($oldParentId <= 0 || !isset($versionMap[$oldParentId])) {
                continue;
            }
            ShotMediaVersion::where('id', $newVersionId)->update(['parent_version_id' => $versionMap[$oldParentId]]);
        }
    }

    /** @return array<int, true> */
    private function normalizeChangedShotIndexes(int|array $indexes): array
    {
        $values = is_array($indexes) ? $indexes : [$indexes];
        $set = [];
        foreach ($values as $index) {
            $index = (int) $index;
            if ($index > 0) {
                $set[$index] = true;
            }
        }

        return $set;
    }

    private function generateShotKey(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function ensureShotKeyValue(?string $key): string
    {
        $key = strtolower(trim((string) $key));
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $key) === 1) {
            return $key;
        }

        return $this->generateShotKey();
    }

    private function normalizeShotDescForCompare(string $desc): string
    {
        $desc = trim($desc);
        $collapsed = preg_replace('/\s+/u', ' ', $desc);

        return is_string($collapsed) ? $collapsed : $desc;
    }

    /**
     * 整版分镜按 shot_key 迁移媒体；精确匹配，不做 index 兜底。
     */
    private function migrateStoryboardMediaByShotKey(int $episodeId, int $previousRevisionId, int $newRevisionId): void
    {
        if ($episodeId <= 0 || $previousRevisionId <= 0 || $newRevisionId <= 0 || $previousRevisionId === $newRevisionId) {
            return;
        }

        $prevShots = Shot::where('episode_id', $episodeId)
            ->where('storyboard_revision_id', $previousRevisionId)
            ->select();
        $newShots = Shot::where('episode_id', $episodeId)
            ->where('storyboard_revision_id', $newRevisionId)
            ->select();

        $prevByKey = [];
        foreach ($prevShots as $shot) {
            if (!$shot instanceof Shot) {
                continue;
            }
            $key = trim((string) ($shot->getAttr('shot_key') ?? ''));
            if ($key !== '') {
                $prevByKey[$key] = $shot;
            }
        }

        $newByKey = [];
        foreach ($newShots as $shot) {
            if (!$shot instanceof Shot) {
                continue;
            }
            $key = trim((string) ($shot->getAttr('shot_key') ?? ''));
            if ($key !== '') {
                $newByKey[$key] = $shot;
            }
        }

        foreach ($newByKey as $key => $newShot) {
            $prevShot = $prevByKey[$key] ?? null;
            if (!$prevShot instanceof Shot) {
                continue;
            }

            $descChanged = $this->normalizeShotDescForCompare((string) ($prevShot->getAttr('desc') ?? ''))
                !== $this->normalizeShotDescForCompare((string) ($newShot->getAttr('desc') ?? ''));

            if ($descChanged) {
                $newShot->save([
                    'image_url' => (string) ($prevShot->getAttr('image_url') ?? ''),
                    'video_url' => '',
                    'video_end_frame_url' => '',
                    'status' => 'pending',
                ]);
            } else {
                $newShot->save([
                    'image_url' => (string) ($prevShot->getAttr('image_url') ?? ''),
                    'video_url' => (string) ($prevShot->getAttr('video_url') ?? ''),
                    'video_end_frame_url' => (string) ($prevShot->getAttr('video_end_frame_url') ?? ''),
                    'status' => (string) ($prevShot->getAttr('status') ?? 'pending'),
                ]);
            }

            $this->cloneShotMediaVersionsByShotKey(
                $episodeId,
                $previousRevisionId,
                $newRevisionId,
                $prevShot,
                $newShot,
                $key,
                $descChanged,
            );
        }

        foreach ($prevByKey as $key => $prevShot) {
            if (isset($newByKey[$key])) {
                continue;
            }
            $oldVersions = ShotMediaVersion::where('episode_id', $episodeId)
                ->where('storyboard_revision_id', $previousRevisionId)
                ->where('shot_id', (int) $prevShot->getAttr('id'))
                ->where('orphaned', 0)
                ->where('url', '<>', '')
                ->order(['id' => 'asc'])
                ->select();
            foreach ($oldVersions as $oldVersion) {
                if ($oldVersion instanceof ShotMediaVersion) {
                    $this->copyMediaVersionAsOrphan($oldVersion, $episodeId, $newRevisionId, $key);
                }
            }
        }
    }

    private function cloneShotMediaVersionsByShotKey(
        int $episodeId,
        int $previousRevisionId,
        int $newRevisionId,
        Shot $prevShot,
        Shot $newShot,
        string $shotKey,
        bool $descChanged,
    ): void {
        $oldVersions = ShotMediaVersion::where('episode_id', $episodeId)
            ->where('storyboard_revision_id', $previousRevisionId)
            ->where('shot_id', (int) $prevShot->getAttr('id'))
            ->where('orphaned', 0)
            ->order(['id' => 'asc'])
            ->select();

        $versionMap = [];
        $parentRemap = [];
        $newShotId = (int) $newShot->getAttr('id');
        $ensuredKey = $this->ensureShotKeyValue($shotKey);

        foreach ($oldVersions as $oldVersion) {
            if (!$oldVersion instanceof ShotMediaVersion) {
                continue;
            }
            $url = trim((string) ($oldVersion->getAttr('url') ?? ''));
            if ($url === '') {
                continue;
            }

            $mediaType = (string) $oldVersion->getAttr('media_type');
            $data = $oldVersion->toArray();
            unset($data['id'], $data['create_time'], $data['update_time']);
            $data['shot_id'] = $newShotId;
            $data['storyboard_revision_id'] = $newRevisionId;
            $data['shot_key'] = $ensuredKey;
            $data['orphaned'] = 0;
            $data['video_job_id'] = null;
            $data['parent_version_id'] = null;
            if ($descChanged && $mediaType === 'video') {
                $data['is_selected'] = 0;
            }

            $newVersion = new ShotMediaVersion();
            $newVersion->save($data);
            $oldVersionId = (int) $oldVersion->getAttr('id');
            $newVersionId = (int) $newVersion->getAttr('id');
            $versionMap[$oldVersionId] = $newVersionId;
            $parentRemap[$newVersionId] = (int) ($oldVersion->getAttr('parent_version_id') ?? 0);
        }

        foreach ($parentRemap as $newVersionId => $oldParentId) {
            if ($oldParentId <= 0 || !isset($versionMap[$oldParentId])) {
                continue;
            }
            ShotMediaVersion::where('id', $newVersionId)->update(['parent_version_id' => $versionMap[$oldParentId]]);
        }
    }

    private function copyMediaVersionAsOrphan(
        ShotMediaVersion $oldVersion,
        int $episodeId,
        int $currentRevisionId,
        string $shotKey = '',
    ): ?ShotMediaVersion {
        $url = trim((string) ($oldVersion->getAttr('url') ?? ''));
        if ($url === '') {
            return null;
        }

        $mediaType = (string) $oldVersion->getAttr('media_type');
        $ensuredKey = $this->ensureShotKeyValue(
            $shotKey !== '' ? $shotKey : (string) ($oldVersion->getAttr('shot_key') ?? '')
        );

        $exists = ShotMediaVersion::where('episode_id', $episodeId)
            ->where('storyboard_revision_id', $currentRevisionId)
            ->where('orphaned', 1)
            ->where('media_type', $mediaType)
            ->where('shot_key', $ensuredKey)
            ->where('url', $url)
            ->find();
        if ($exists instanceof ShotMediaVersion) {
            return $exists;
        }

        $data = $oldVersion->toArray();
        unset($data['id'], $data['create_time'], $data['update_time']);
        $data['episode_id'] = $episodeId;
        $data['shot_id'] = 0;
        $data['storyboard_revision_id'] = $currentRevisionId;
        $data['shot_key'] = $ensuredKey;
        $data['orphaned'] = 1;
        $data['is_selected'] = 0;
        $data['video_job_id'] = null;
        $data['parent_version_id'] = null;

        $newVersion = new ShotMediaVersion();
        $newVersion->save($data);

        return $newVersion;
    }

    /**
     * 删除归档 revision 前，把仍有 URL 且 shot_key 不在当前分镜的媒体救到未挂载库。
     *
     * @param list<int> $archivedRevisionIds
     */
    private function rescueOrphanMediaBeforePurge(int $episodeId, int $currentRevisionId, array $archivedRevisionIds): void
    {
        if ($episodeId <= 0 || $currentRevisionId <= 0 || $archivedRevisionIds === []) {
            return;
        }

        $currentKeys = Shot::where('episode_id', $episodeId)
            ->where('storyboard_revision_id', $currentRevisionId)
            ->column('shot_key');
        $currentKeySet = [];
        foreach (is_array($currentKeys) ? $currentKeys : [] as $key) {
            $key = trim((string) $key);
            if ($key !== '') {
                $currentKeySet[$key] = true;
            }
        }

        $candidates = ShotMediaVersion::where('episode_id', $episodeId)
            ->whereIn('storyboard_revision_id', $archivedRevisionIds)
            ->where('orphaned', 0)
            ->where('url', '<>', '')
            ->order(['id' => 'asc'])
            ->select();

        foreach ($candidates as $version) {
            if (!$version instanceof ShotMediaVersion) {
                continue;
            }
            $key = trim((string) ($version->getAttr('shot_key') ?? ''));
            if ($key === '') {
                $shotId = (int) ($version->getAttr('shot_id') ?? 0);
                if ($shotId > 0) {
                    $key = trim((string) (Shot::where('id', $shotId)->value('shot_key') ?: ''));
                }
            }
            if ($key === '' || isset($currentKeySet[$key])) {
                continue;
            }
            $this->copyMediaVersionAsOrphan($version, $episodeId, $currentRevisionId, $key);
        }
    }

    private function purgeArchivedStoryboardArtifactsForEpisode(int $episodeId, int $currentRevisionId): void
    {
        if ($episodeId <= 0 || $currentRevisionId <= 0) {
            return;
        }

        $archivedRevisionIds = StoryboardRevision::where('episode_id', $episodeId)
            ->where('id', '<>', $currentRevisionId)
            ->column('id');
        $archivedRevisionIds = array_values(array_unique(array_map('intval', is_array($archivedRevisionIds) ? $archivedRevisionIds : [])));
        if ($archivedRevisionIds === []) {
            return;
        }

        $this->rescueOrphanMediaBeforePurge($episodeId, $currentRevisionId, $archivedRevisionIds);

        $archivedShotIds = Shot::where('episode_id', $episodeId)
            ->whereIn('storyboard_revision_id', $archivedRevisionIds)
            ->column('id');
        $archivedShotIds = array_values(array_unique(array_map('intval', is_array($archivedShotIds) ? $archivedShotIds : [])));

        VideoJob::where('episode_id', $episodeId)
            ->whereIn('storyboard_revision_id', $archivedRevisionIds)
            ->delete();

        // 不删挂在 current revision 上的 orphaned 未挂载成片
        ShotMediaVersion::where('episode_id', $episodeId)
            ->whereIn('storyboard_revision_id', $archivedRevisionIds)
            ->whereRaw('NOT (orphaned = 1 AND storyboard_revision_id = ?)', [$currentRevisionId])
            ->delete();

        if ($archivedShotIds !== []) {
            ShotMediaVersion::where('episode_id', $episodeId)
                ->whereIn('shot_id', $archivedShotIds)
                ->whereRaw('NOT (orphaned = 1 AND storyboard_revision_id = ?)', [$currentRevisionId])
                ->delete();

            Shot::whereIn('id', $archivedShotIds)->delete();
        }

        StoryboardRevision::where('episode_id', $episodeId)
            ->whereIn('id', $archivedRevisionIds)
            ->delete();
    }

    private function ensureCurrentStoryboardRevisionForNodeInput(
        Episode $episode,
        WorkflowRun $run,
        array $orderedNodes,
        string $targetNodeId,
    ): int {
        $episode = Episode::find((int) $episode->getAttr('id')) ?: $episode;
        $revisionId = $this->currentStoryboardRevisionId($episode);
        if ($revisionId > 0) {
            return $revisionId;
        }

        $storyboardNode = null;
        foreach ($orderedNodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            if ((string) ($node['id'] ?? '') === $targetNodeId) {
                break;
            }
            $label = trim((string) ($node['label'] ?? $node['data']['label'] ?? ''));
            $kind = trim((string) ($node['data']['kind'] ?? $node['kind'] ?? ''));
            if ($this->isStoryboardRevisionNode($label, $kind)) {
                $storyboardNode = $node;
            }
        }

        if (!is_array($storyboardNode)) {
            return 0;
        }

        $runNode = $this->findWorkflowRunNode((int) $run->getAttr('id'), $storyboardNode);
        if (!$runNode instanceof WorkflowRunNode || (string) $runNode->getAttr('status') !== 'success') {
            return 0;
        }

        $output = $runNode->getAttr('output_json') ?: [];
        if (!is_array($output)) {
            $output = [];
        }
        $revision = $this->createStoryboardRevisionFromContent(
            $episode,
            $run,
            $runNode,
            (string) $runNode->getAttr('raw_output'),
            $output,
        );

        return $revision instanceof StoryboardRevision ? (int) $revision->getAttr('id') : 0;
    }

    private function extractDurationFromText(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        // Prefer total span from timeline cuts (0-5s / 5-10s / 10-15s → 15s),
        // instead of picking the first cut length (which used to force many jobs to 5s).
        $maxEnd = 0.0;
        if (preg_match_all('/(\d+(?:\.\d+)?)\s*[-‑–—]\s*(\d+(?:\.\d+)?)\s*(?:秒|s)?/iu', $text, $ranges, PREG_SET_ORDER)) {
            foreach ($ranges as $range) {
                $end = (float) ($range[2] ?? 0);
                if ($end > $maxEnd) {
                    $maxEnd = $end;
                }
            }
        }
        if ($maxEnd >= 4 && $maxEnd <= 15) {
            $label = rtrim(rtrim(sprintf('%.2f', $maxEnd), '0'), '.');

            return $label . 's';
        }

        // Header style: 【视频节点01｜15s｜...】 or leading "15秒"
        $head = implode("\n", array_slice(preg_split('/\R/u', $text) ?: [], 0, 3));
        if (preg_match('/(?:^|[｜|（(])\s*(\d+(?:\.\d+)?)\s*(秒|s)\b/iu', $head, $matches) === 1) {
            return trim((string) $matches[1] . (string) $matches[2]);
        }

        if (preg_match('/(\d+(?:\.\d+)?\s*(?:秒|s))\b/iu', $text, $matches) === 1) {
            return trim((string) $matches[1]);
        }

        return '';
    }

    private function upsertEpisodeShot(
        int $episodeId,
        int $storyboardRevisionId,
        int $index,
        string $description,
        string $duration,
        string $status,
        string $shotKey = '',
    ): Shot {
        $shot = Shot::where('episode_id', $episodeId)
            ->where('storyboard_revision_id', $storyboardRevisionId)
            ->where('index', $index)
            ->find();
        if ($shot instanceof Shot) {
            $update = [
                'desc' => $description,
                'duration' => $duration,
                'status' => $status,
            ];
            $existingKey = trim((string) ($shot->getAttr('shot_key') ?? ''));
            if ($existingKey === '') {
                $update['shot_key'] = $this->ensureShotKeyValue($shotKey);
            }
            // 已有非空 shot_key 视为稳定身份，即使传入不同值也不覆盖
            $shot->save($update);
            return $shot;
        }

        $shot = new Shot();
        $shot->save([
            'user_id' => $this->effectiveUserId(),
            'episode_id' => $episodeId,
            'storyboard_revision_id' => $storyboardRevisionId,
            'shot_key' => $this->ensureShotKeyValue($shotKey),
            'index' => $index,
            'desc' => $description,
            'duration' => $duration,
            'status' => $status,
            'image_url' => null,
            'video_url' => null,
        ]);
        return $shot;
    }

    private function deleteEpisodeShotsBeyond(int $episodeId, int $storyboardRevisionId, int $maxIndex): void
    {
        if ($episodeId <= 0 || $storyboardRevisionId <= 0 || $maxIndex <= 0) {
            return;
        }
        Shot::where('episode_id', $episodeId)
            ->where('storyboard_revision_id', $storyboardRevisionId)
            ->where('index', '>', $maxIndex)
            ->delete();
    }

    private function createShotMediaVersion(Shot $shot, string $mediaType, string $url, array $extra = []): ?ShotMediaVersion
    {
        $url = trim($url);
        if ($url === '' || !in_array($mediaType, ['image', 'video'], true)) {
            return null;
        }

        $shotId = (int) $shot->getAttr('id');
        $storyboardRevisionId = (int) ($extra['storyboard_revision_id'] ?? 0) ?: (int) ($shot->getAttr('storyboard_revision_id') ?? 0);
        $isSelected = (bool) ($extra['is_selected'] ?? false);
        if ($isSelected) {
            ShotMediaVersion::where('shot_id', $shotId)
                ->where('media_type', $mediaType)
                ->update(['is_selected' => 0]);
        }

        $videoJobId = (int) ($extra['video_job_id'] ?? 0);
        $source = trim((string) ($extra['source'] ?? 'generated'));
        if ($mediaType === 'video' && $videoJobId > 0 && $source !== 'archive') {
            $existingByJob = ShotMediaVersion::where('shot_id', $shotId)
                ->where('media_type', 'video')
                ->where('video_job_id', $videoJobId)
                ->where('source', '<>', 'archive')
                ->order(['id' => 'desc'])
                ->find();
            if ($existingByJob instanceof ShotMediaVersion) {
                $update = [];
                if (trim((string) $existingByJob->getAttr('url')) !== $url) {
                    $update['url'] = $url;
                }
                foreach (['workflow_run_node_id', 'parent_version_id', 'model_config_id', 'ai_request_log_id', 'storyboard_revision_id'] as $key) {
                    $nextValue = (int) ($extra[$key] ?? 0);
                    if ($key === 'storyboard_revision_id' && $nextValue <= 0) {
                        $nextValue = $storyboardRevisionId;
                    }
                    if ($nextValue > 0 && (int) ($existingByJob->getAttr($key) ?? 0) !== $nextValue) {
                        $update[$key] = $nextValue;
                    }
                }
                foreach (['poster_url', 'end_frame_url', 'prompt', 'source'] as $key) {
                    $nextValue = trim((string) ($extra[$key] ?? ''));
                    if ($nextValue !== '' && (string) $existingByJob->getAttr($key) !== $nextValue) {
                        $update[$key] = $nextValue;
                    }
                }
                if ($isSelected && !(bool) $existingByJob->getAttr('is_selected')) {
                    $update['is_selected'] = 1;
                }
                if (is_array($extra['meta_json'] ?? null) && ($extra['meta_json'] ?? []) !== []) {
                    $update['meta_json'] = $extra['meta_json'];
                }
                if ($update !== []) {
                    $existingByJob->save($update);
                }
                return $existingByJob;
            }
        }

        $exists = ShotMediaVersion::where('shot_id', $shotId)
            ->where('media_type', $mediaType)
            ->where('url', $url)
            ->find();
        if ($exists instanceof ShotMediaVersion) {
            $update = [];
            foreach (['workflow_run_node_id', 'video_job_id', 'parent_version_id', 'model_config_id', 'ai_request_log_id', 'storyboard_revision_id'] as $key) {
                $nextValue = (int) ($extra[$key] ?? 0);
                if ($key === 'storyboard_revision_id' && $nextValue <= 0) {
                    $nextValue = $storyboardRevisionId;
                }
                if ($nextValue > 0 && (int) ($exists->getAttr($key) ?? 0) <= 0) {
                    $update[$key] = $nextValue;
                }
            }
            if ($isSelected && !(bool) $exists->getAttr('is_selected')) {
                $update['is_selected'] = 1;
            }
            if ($update !== []) {
                $exists->save($update);
            }
            return $exists;
        }

        $version = new ShotMediaVersion();
        $version->save([
            'user_id' => $this->effectiveUserId(),
            'series_id' => (int) ($extra['series_id'] ?? 0) ?: (int) (Episode::where('id', (int) $shot->getAttr('episode_id'))->value('series_id') ?: 0),
            'episode_id' => (int) $shot->getAttr('episode_id'),
            'storyboard_revision_id' => $storyboardRevisionId > 0 ? $storyboardRevisionId : null,
            'shot_id' => $shotId,
            'shot_key' => $this->ensureShotKeyValue((string) ($shot->getAttr('shot_key') ?? '')),
            'workflow_run_node_id' => (int) ($extra['workflow_run_node_id'] ?? 0) ?: null,
            'video_job_id' => (int) ($extra['video_job_id'] ?? 0) ?: null,
            'parent_version_id' => (int) ($extra['parent_version_id'] ?? 0) ?: null,
            'model_config_id' => (int) ($extra['model_config_id'] ?? 0),
            'media_type' => $mediaType,
            'url' => $url,
            'poster_url' => (string) ($extra['poster_url'] ?? ''),
            'end_frame_url' => (string) ($extra['end_frame_url'] ?? ''),
            'prompt' => (string) ($extra['prompt'] ?? ''),
            'source' => $source,
            'is_selected' => $isSelected ? 1 : 0,
            'orphaned' => 0,
            'ai_request_log_id' => (int) ($extra['ai_request_log_id'] ?? 0) ?: null,
            'meta_json' => is_array($extra['meta_json'] ?? null) ? $extra['meta_json'] : [],
        ]);

        return $version;
    }

    private function archiveVideoJobAsShotVersion(VideoJob $job): ?ShotMediaVersion
    {
        $videoUrl = trim((string) $job->getAttr('video_url'));
        if ($videoUrl === '') {
            return null;
        }

        $shot = Shot::find((int) $job->getAttr('shot_id'));
        if (!$shot instanceof Shot) {
            return null;
        }

        $inputImageUrl = trim((string) $job->getAttr('input_image_url'));
        if ($inputImageUrl === '') {
            $inputImageUrl = trim((string) $job->getAttr('source_image_url'));
        }

        return $this->createShotMediaVersion($shot, 'video', $videoUrl, [
            'series_id' => (int) $job->getAttr('series_id'),
            'workflow_run_node_id' => (int) $job->getAttr('workflow_run_node_id'),
            'storyboard_revision_id' => (int) ($job->getAttr('storyboard_revision_id') ?? 0),
            'video_job_id' => (int) $job->getAttr('id'),
            'model_config_id' => (int) $job->getAttr('model_config_id'),
            'poster_url' => (string) $job->getAttr('source_image_url'),
            'end_frame_url' => (string) $job->getAttr('end_frame_url'),
            'prompt' => (string) $job->getAttr('node_prompt'),
            'source' => 'archive',
            'is_selected' => trim((string) $shot->getAttr('video_url')) === $videoUrl,
            'ai_request_log_id' => (int) ($job->getAttr('ai_request_log_id') ?? 0) ?: null,
            'meta_json' => [
                'node_label' => (string) $job->getAttr('node_label'),
                'node_id' => (string) $job->getAttr('node_id'),
                'shot_index' => (int) $job->getAttr('shot_index'),
                'input_image_url' => $inputImageUrl,
                'archived_from_job' => true,
            ],
        ]);
    }

    private function selectShotVideoVersion(Shot $shot, ShotMediaVersion $version): void
    {
        ShotMediaVersion::where('shot_id', (int) $shot->getAttr('id'))
            ->where('media_type', 'video')
            ->update(['is_selected' => 0]);

        $version->save(['is_selected' => 1]);
        $this->syncVideoJobFromSelectedVersion($version);
        $shot->save([
            'video_url' => (string) $version->getAttr('url'),
            'video_end_frame_url' => (string) $version->getAttr('end_frame_url'),
            'status' => 'done',
        ]);
    }

    private function syncVideoJobFromSelectedVersion(ShotMediaVersion $version): void
    {
        $videoJobId = (int) ($version->getAttr('video_job_id') ?? 0);
        if ($videoJobId <= 0) {
            return;
        }

        $job = VideoJob::find($videoJobId);
        if (!$job instanceof VideoJob) {
            return;
        }

        $inputImageUrl = $this->inputFrameForVideoVersion($version);
        $data = [
            'status' => 'success',
            'video_url' => (string) $version->getAttr('url'),
            'end_frame_url' => (string) $version->getAttr('end_frame_url'),
            'error_message' => '',
            'finished_at' => $job->getAttr('finished_at') ?: date('Y-m-d H:i:s'),
        ];
        if ($inputImageUrl !== '') {
            $data['input_image_url'] = $inputImageUrl;
        }

        $job->save($data);
    }

    private function inputFrameForVideoVersion(ShotMediaVersion $version): string
    {
        $meta = $version->getAttr('meta_json') ?: [];
        return is_array($meta) ? trim((string) ($meta['input_image_url'] ?? '')) : '';
    }

    /**
     * 切换某镜头视频版本后，沿「血缘父子链」精确联动后续镜头：
     * - 后续镜头存在 parent_version_id 指向当前选中版本的子版本 → 自动选回那一批，并继续往下游联动；
     * - 找不到匹配子版本 → 把该段及其下游标记为待手动重生（stale），并断开链路。
     * 仅对开启「尾帧衔接」的视频生效；未衔接时各镜头独立，切换不影响其它镜头。
     */
    private function markChainedVideoJobsAfterSelectedVersion(ShotMediaVersion $version, Shot $shot): void
    {
        $videoJobId = (int) ($version->getAttr('video_job_id') ?? 0);
        if ($videoJobId <= 0) {
            return;
        }

        $job = VideoJob::find($videoJobId);
        if (!$job instanceof VideoJob || !(bool) $job->getAttr('chain_shots')) {
            return;
        }

        $runNodeId = (int) $job->getAttr('workflow_run_node_id');
        $episodeId = (int) $job->getAttr('episode_id');
        $storyboardRevisionId = (int) ($job->getAttr('storyboard_revision_id') ?? 0);
        $targetIndex = (int) $job->getAttr('shot_index');
        if ($runNodeId <= 0 || $episodeId <= 0 || $storyboardRevisionId <= 0 || $targetIndex <= 0) {
            return;
        }

        $currentVersion = $version;
        $chainBroken = false;
        for ($nextIndex = $targetIndex + 1; ; $nextIndex++) {
            $nextShot = Shot::where('episode_id', $episodeId)
                ->where('storyboard_revision_id', $storyboardRevisionId)
                ->where('index', $nextIndex)
                ->find();
            if (!$nextShot instanceof Shot) {
                break;
            }

            // 链路已断开后，下游镜头都无法确定输入帧，统一标记待手动重生。
            $matched = null;
            if (!$chainBroken) {
                $matched = $this->findChainedChildVideoVersion($currentVersion, $nextShot);
            }

            if ($matched instanceof ShotMediaVersion) {
                $this->selectShotVideoVersion($nextShot, $matched);
                $currentVersion = $matched;
                continue;
            }

            $this->markShotVideoJobStale($runNodeId, $nextShot);
            $chainBroken = true;
        }

        $this->refreshVideoWorkflowProgress($runNodeId);
    }

    private function findChainedChildVideoVersion(ShotMediaVersion $parentVersion, Shot $nextShot): ?ShotMediaVersion
    {
        $parentVersionId = (int) $parentVersion->getAttr('id');
        $nextShotId = (int) $nextShot->getAttr('id');
        $storyboardRevisionId = (int) ($parentVersion->getAttr('storyboard_revision_id') ?? 0);
        if ($parentVersionId <= 0 || $nextShotId <= 0) {
            return null;
        }

        $matched = ShotMediaVersion::where('shot_id', $nextShotId)
            ->where('storyboard_revision_id', $storyboardRevisionId)
            ->where('media_type', 'video')
            ->where('parent_version_id', $parentVersionId)
            ->order('id', 'desc')
            ->find();
        if ($matched instanceof ShotMediaVersion) {
            return $matched;
        }

        $parentEndFrame = trim((string) $parentVersion->getAttr('end_frame_url'));
        if ($parentEndFrame !== '') {
            $matched = $this->findOrphanChildVideoVersionByInputFrame($nextShotId, $storyboardRevisionId, $parentEndFrame);
            if ($matched instanceof ShotMediaVersion) {
                $matched->save(['parent_version_id' => $parentVersionId]);
                return $matched;
            }
        }

        return null;
    }

    private function findOrphanChildVideoVersionByInputFrame(int $shotId, int $storyboardRevisionId, string $inputFrameUrl): ?ShotMediaVersion
    {
        $versions = ShotMediaVersion::where('shot_id', $shotId)
            ->where('storyboard_revision_id', $storyboardRevisionId)
            ->where('media_type', 'video')
            ->whereNull('parent_version_id')
            ->order('id', 'desc')
            ->select();

        foreach ($versions as $version) {
            if (!$version instanceof ShotMediaVersion) {
                continue;
            }
            $meta = $version->getAttr('meta_json') ?: [];
            $input = is_array($meta) ? trim((string) ($meta['input_image_url'] ?? '')) : '';
            if ($input !== '' && $input === $inputFrameUrl) {
                return $version;
            }
        }

        return null;
    }

    /**
     * 把某镜头在指定节点下最新的视频任务标记为 stale（待手动重生），并把镜头状态回退为 pending。
     */
    private function markShotVideoJobStale(
        int $runNodeId,
        Shot $shot,
        string $message = '前序镜头切换了视频版本，这段视频需要手动重新生成',
    ): void
    {
        $nextJob = VideoJob::where('workflow_run_node_id', $runNodeId)
            ->where('shot_id', (int) $shot->getAttr('id'))
            ->order('id', 'desc')
            ->find();
        if ($nextJob instanceof VideoJob) {
            $nextJob->save([
                'status' => 'stale',
                'video_url' => '',
                'end_frame_url' => '',
                'error_message' => $message,
                'started_at' => null,
                'finished_at' => null,
            ]);
        }
        ShotMediaVersion::where('shot_id', (int) $shot->getAttr('id'))
            ->where('media_type', 'video')
            ->update(['is_selected' => 0]);
        $shot->save([
            'status' => 'pending',
            'video_url' => '',
            'video_end_frame_url' => '',
        ]);
    }

    /**
     * 根据镜头描述匹配剧本资产，并附带可作为 form-data 上传的本地参考图路径。
     */
    private function matchAssetsForShot(int $seriesId, string $shotText): array
    {
        $matched = [];
        foreach ($this->buildEpisodeAssetLibrary($seriesId) as $asset) {
            $assetSeriesId = (int) ($asset['series_id'] ?? 0);
            $imageUrl = trim((string) ($asset['main_image_url'] ?? ''));
            if ($assetSeriesId > 0 && $assetSeriesId !== $seriesId && $imageUrl === '') {
                continue;
            }
            if (!$this->assetReferenceMatchesShot($asset, $shotText)) {
                continue;
            }
            $matched[] = $this->finalizeVideoAssetReferenceFields([
                'id' => (int) ($asset['asset_id'] ?? $asset['id'] ?? 0),
                'asset_image_id' => isset($asset['asset_image_id']) ? (int) $asset['asset_image_id'] : null,
                'asset_image_version_id' => isset($asset['asset_image_version_id']) ? (int) $asset['asset_image_version_id'] : null,
                'series_id' => (int) ($asset['series_id'] ?? $seriesId),
                'name' => (string) ($asset['name'] ?? ''),
                'type' => (string) ($asset['type'] ?? ''),
                'reference_role' => (string) ($asset['reference_role'] ?? 'view'),
                'reference_key' => (string) ($asset['reference_key'] ?? ''),
                'variant_name' => (string) ($asset['variant_name'] ?? ''),
                'character_name' => (string) ($asset['character_name'] ?? ''),
                'description' => (string) ($asset['description'] ?? ''),
                'image_prompt' => (string) ($asset['image_prompt'] ?? ''),
                'tags' => isset($asset['tags']) && is_array($asset['tags']) ? $asset['tags'] : [],
                'image_url' => (string) ($asset['main_image_url'] ?? ''),
                'display_image_url' => (string) ($asset['main_image_url'] ?? ''),
                'toapis_asset_url' => (string) ($asset['toapis_asset_url'] ?? ''),
                'toapis_status' => (string) ($asset['toapis_status'] ?? ''),
            ], $seriesId);
            if (count($matched) >= 8) {
                break;
            }
        }

        return $matched;
    }

    private function resolveStoryboardShotAssets(int $seriesId, array $shotData, string $fallbackText = ''): array
    {
        $assetRefs = isset($shotData['asset_refs']) && is_array($shotData['asset_refs']) ? $shotData['asset_refs'] : [];
        if ($assetRefs !== []) {
            $resolved = $this->storyboardAssetsFromRefs($seriesId, $assetRefs);
            if ($resolved !== []) {
                return $resolved;
            }
        }

        return $this->normalizeVideoShotAssets($shotData['assets'] ?? [], $seriesId, $fallbackText);
    }

    private function storyboardAssetsFromRefs(int $seriesId, array $assetRefs): array
    {
        $library = $this->episodeAssetLibraryByRefKey($seriesId);
        $assets = [];
        $seen = [];
        foreach ($assetRefs as $ref) {
            if (!is_array($ref)) {
                continue;
            }
            $assetId = (int) ($ref['asset_id'] ?? 0);
            if ($assetId <= 0) {
                continue;
            }
            $assetImageId = isset($ref['asset_image_id']) && $ref['asset_image_id'] !== null ? (int) $ref['asset_image_id'] : null;
            $referenceRole = strtolower(trim((string) ($ref['reference_role'] ?? 'view'))) === 'look' ? 'look' : 'view';
            $key = $this->storyboardAssetRefKey($assetId, $assetImageId, $referenceRole, isset($ref['asset_image_version_id']) && $ref['asset_image_version_id'] !== null ? (int) $ref['asset_image_version_id'] : null);
            if (isset($seen[$key]) || !isset($library[$key]) || !is_array($library[$key])) {
                continue;
            }
            $seen[$key] = true;
            $asset = $library[$key];
            $assets[] = $this->finalizeVideoAssetReferenceFields([
                'id' => (int) ($asset['asset_id'] ?? $asset['id'] ?? 0),
                'asset_image_id' => isset($asset['asset_image_id']) && $asset['asset_image_id'] !== null ? (int) $asset['asset_image_id'] : null,
                'asset_image_version_id' => isset($asset['asset_image_version_id']) && $asset['asset_image_version_id'] !== null ? (int) $asset['asset_image_version_id'] : null,
                'series_id' => (int) ($asset['series_id'] ?? $seriesId),
                'name' => (string) ($asset['name'] ?? ''),
                'type' => (string) ($asset['type'] ?? ''),
                'reference_role' => (string) ($asset['reference_role'] ?? 'view'),
                'reference_key' => (string) ($asset['reference_key'] ?? ''),
                'variant_name' => (string) ($asset['variant_name'] ?? ''),
                'character_name' => (string) ($asset['character_name'] ?? ''),
                'description' => (string) ($asset['description'] ?? ''),
                'image_prompt' => (string) ($asset['image_prompt'] ?? ''),
                'tags' => isset($asset['tags']) && is_array($asset['tags']) ? $asset['tags'] : [],
                'image_url' => (string) ($asset['main_image_url'] ?? ''),
                'display_image_url' => (string) ($asset['main_image_url'] ?? ''),
                'toapis_asset_url' => (string) ($asset['toapis_asset_url'] ?? ''),
                'toapis_status' => (string) ($asset['toapis_status'] ?? ''),
            ], $seriesId);
            if (count($assets) >= 8) {
                break;
            }
        }

        return $assets;
    }

    /**
     * 视频参考资产字段收口：写实造型仅在人像入库 active 时使用 asset://。
     *
     * @param array<string, mixed> $asset
     * @return array<string, mixed>
     */
    private function finalizeVideoAssetReferenceFields(array $asset, int $seriesId = 0): array
    {
        $displayUrl = trim((string) ($asset['display_image_url'] ?? $asset['image_url'] ?? $asset['main_image_url'] ?? ''));
        $toapisAssetUrl = trim((string) ($asset['toapis_asset_url'] ?? ''));
        $toapisStatus = strtolower(trim((string) ($asset['toapis_status'] ?? '')));
        $referenceRole = strtolower(trim((string) ($asset['reference_role'] ?? 'view')));
        $type = strtolower(trim((string) ($asset['type'] ?? '')));
        $usedToapis = false;
        $imageUrl = $displayUrl;

        if ($type === 'character' && $referenceRole === 'look') {
            $visualStyle = $this->seriesVisualStyleForId(
                $seriesId > 0 ? $seriesId : (int) ($asset['series_id'] ?? 0)
            );
            $resolved = $this->assetLookService()->resolveVideoLookImageUrl(
                $displayUrl,
                $toapisAssetUrl,
                $visualStyle,
                $toapisStatus,
            );
            $imageUrl = (string) ($resolved['url'] ?? $displayUrl);
            $usedToapis = (bool) ($resolved['used_toapis'] ?? false);
            $toapisStatus = (string) ($resolved['status'] ?? $toapisStatus);
        }

        $asset['display_image_url'] = $displayUrl;
        $asset['toapis_asset_url'] = $toapisAssetUrl;
        $asset['toapis_status'] = $toapisStatus;
        $asset['image_url'] = $imageUrl;
        $asset['used_toapis'] = $usedToapis;
        $asset['local_path'] = ToapisPrivateAvatarService::isAssetUri($imageUrl)
            ? ''
            : $this->resolveLocalPublicPath($imageUrl);

        return $asset;
    }

    private function seriesVisualStyleForId(int $seriesId): string
    {
        static $cache = [];
        if ($seriesId <= 0) {
            return 'realistic';
        }
        if (isset($cache[$seriesId])) {
            return $cache[$seriesId];
        }
        $series = Series::where('id', $seriesId)->find();
        $style = $series instanceof Series
            ? $this->normalizeVisualStyle((string) ($series->getAttr('visual_style') ?? 'realistic'))
            : 'realistic';
        $cache[$seriesId] = $style;

        return $style;
    }

    private function assetReferenceMatchesShot(array $asset, string $shotText): bool
    {
        $needleText = mb_strtolower($shotText);
        $name = trim((string) ($asset['name'] ?? ''));
        $referenceRole = strtolower(trim((string) ($asset['reference_role'] ?? 'view')));
        if ($referenceRole === 'look') {
            return $name !== '' && mb_stripos($shotText, $name) !== false;
        }
        if ($name !== '' && mb_stripos($shotText, $name) !== false) {
            return true;
        }

        $tokens = preg_split('/[\s,，、;；:：\/\\\\|（）()\[\]【】《》"“”]+/u', $name) ?: [];
        foreach ($tokens as $token) {
            $token = trim((string) $token);
            if (mb_strlen($token) >= 2 && mb_stripos($shotText, $token) !== false) {
                return true;
            }
        }

        $tags = $asset['tags'] ?? [];
        if (is_array($tags)) {
            foreach ($tags as $tag) {
                $tag = trim((string) $tag);
                if (mb_strlen($tag) >= 2 && mb_stripos($needleText, mb_strtolower($tag)) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    private function pickAssetReferenceImageUrl(Asset $asset): string
    {
        $fallback = '';
        foreach ($asset->images as $image) {
            if (!$image instanceof \app\model\AssetImage) {
                continue;
            }
            $url = trim((string) $image->getAttr('url'));
            if ($url === '') {
                continue;
            }
            if ($fallback === '') {
                $fallback = $url;
            }
            if ((string) $image->getAttr('view_type') === 'main') {
                return $url;
            }
        }
        return $fallback;
    }

    private function normalizeVideoShotAssets(array $assets, int $seriesId, string $description): array
    {
        $normalized = [];
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $displayUrl = trim((string) ($asset['display_image_url'] ?? $asset['image_url'] ?? ''));
            $normalized[] = $this->finalizeVideoAssetReferenceFields([
                'id' => isset($asset['id']) ? (int) $asset['id'] : null,
                'asset_image_id' => isset($asset['asset_image_id']) && $asset['asset_image_id'] !== null ? (int) $asset['asset_image_id'] : null,
                'asset_image_version_id' => isset($asset['asset_image_version_id']) && $asset['asset_image_version_id'] !== null ? (int) $asset['asset_image_version_id'] : null,
                'series_id' => isset($asset['series_id']) ? (int) $asset['series_id'] : $seriesId,
                'name' => (string) ($asset['name'] ?? ''),
                'type' => (string) ($asset['type'] ?? ''),
                'reference_alias' => (string) ($asset['reference_alias'] ?? ''),
                'reference_role' => (string) ($asset['reference_role'] ?? 'view'),
                'reference_key' => (string) ($asset['reference_key'] ?? ''),
                'variant_name' => (string) ($asset['variant_name'] ?? ''),
                'character_name' => (string) ($asset['character_name'] ?? ''),
                'description' => (string) ($asset['description'] ?? ''),
                'image_prompt' => (string) ($asset['image_prompt'] ?? ''),
                'tags' => isset($asset['tags']) && is_array($asset['tags']) ? $asset['tags'] : [],
                'image_url' => $displayUrl,
                'display_image_url' => $displayUrl,
                'toapis_asset_url' => trim((string) ($asset['toapis_asset_url'] ?? '')),
                'toapis_status' => trim((string) ($asset['toapis_status'] ?? '')),
                'used_toapis' => (bool) ($asset['used_toapis'] ?? false),
            ], $seriesId);
        }

        return $normalized !== [] ? $normalized : $this->matchAssetsForShot($seriesId, $description);
    }

    private function pickPrimaryVideoSourceImageUrl(array $assets): string
    {
        foreach (['scene', 'character', 'prop'] as $preferredType) {
            foreach ($assets as $asset) {
                if (!is_array($asset)) {
                    continue;
                }
                $type = strtolower(trim((string) ($asset['type'] ?? '')));
                $url = trim((string) ($asset['image_url'] ?? $asset['url'] ?? ''));
                if ($type === $preferredType && $url !== '') {
                    return $url;
                }
            }
        }

        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $url = trim((string) ($asset['image_url'] ?? $asset['url'] ?? ''));
            if ($url !== '') {
                return $url;
            }
        }

        return '';
    }

    private function buildEpisodeAssetLibrary(int $seriesId): array
    {
        $this->ensureAssetLookState($seriesId);
        $userId = $this->effectiveUserId();
        $ownAssets = Asset::with(['images'])
            ->where('series_id', $seriesId)
            ->where('user_id', $userId)
            ->where('is_hidden', 0)
            ->order(['type' => 'asc', 'sort' => 'asc', 'id' => 'asc'])
            ->select();
        $assets = $ownAssets->all();

        $library = [];
        foreach ($assets as $asset) {
            if (!$asset instanceof Asset) {
                continue;
            }
            $mainImageUrl = $this->pickAssetReferenceImageUrl($asset);
            $library[] = [
                'id' => (int) $asset->getAttr('id'),
                'asset_id' => (int) $asset->getAttr('id'),
                'asset_image_id' => null,
                'series_id' => (int) $asset->getAttr('series_id'),
                'type' => (string) $asset->getAttr('type'),
                'name' => (string) $asset->getAttr('name'),
                'reference_role' => 'view',
                'variant_name' => '',
                'reference_key' => '',
                'character_name' => (string) $asset->getAttr('name'),
                'description' => mb_substr((string) $asset->getAttr('description'), 0, 600),
                'image_prompt' => (string) ($asset->getAttr('image_prompt') ?? ''),
                'tags' => $asset->getAttr('tags') ?: [],
                'main_image_url' => $mainImageUrl,
                'has_image' => $mainImageUrl !== '',
            ];

            if ((string) $asset->getAttr('type') !== 'character') {
                continue;
            }

            foreach ($asset->images as $image) {
                if (!$image instanceof AssetImage) {
                    continue;
                }
                if (strtolower(trim((string) ($image->getAttr('reference_role') ?? 'view'))) !== 'look') {
                    continue;
                }
                $url = trim((string) $image->getAttr('url'));
                $variantName = trim((string) ($image->getAttr('variant_name') ?? ''));
                if ($variantName === '') {
                    continue;
                }
                $library[] = [
                    'id' => (int) $asset->getAttr('id'),
                    'asset_id' => (int) $asset->getAttr('id'),
                    'asset_image_id' => (int) $image->getAttr('id'),
                    'asset_image_version_id' => null,
                    'series_id' => (int) $asset->getAttr('series_id'),
                    'type' => 'character',
                    'name' => $this->assetLookService()->makeReferenceName(
                        (string) $asset->getAttr('name'),
                        $variantName,
                    ),
                    'reference_role' => 'look',
                    'variant_name' => $variantName,
                    'reference_key' => (string) ($image->getAttr('reference_key') ?? ''),
                    'character_name' => (string) $asset->getAttr('name'),
                    'description' => mb_substr((string) ($image->getAttr('image_prompt') ?: $asset->getAttr('description')), 0, 600),
                    'image_prompt' => (string) ($image->getAttr('image_prompt') ?? ''),
                    'tags' => $asset->getAttr('tags') ?: [],
                    'main_image_url' => $url,
                    'toapis_asset_url' => trim((string) ($image->getAttr('toapis_asset_url') ?? '')),
                    'toapis_status' => trim((string) ($image->getAttr('toapis_status') ?? '')),
                    'has_image' => $url !== '',
                ];

                $lookLabel = $this->assetLookService()->makeReferenceName((string) $asset->getAttr('name'), $variantName);
                $versions = AssetImageVersion::where('asset_image_id', (int) $image->getAttr('id'))
                    ->order(['id' => 'asc'])
                    ->select();
                $versionIndex = 0;
                foreach ($versions as $version) {
                    if (!$version instanceof AssetImageVersion) {
                        continue;
                    }
                    $versionUrl = trim((string) $version->getAttr('url'));
                    if ($versionUrl === '') {
                        continue;
                    }
                    $versionIndex++;
                    if ((bool) $version->getAttr('is_selected') || ($url !== '' && $versionUrl === $url)) {
                        continue;
                    }
                    $library[] = [
                        'id' => (int) $asset->getAttr('id'),
                        'asset_id' => (int) $asset->getAttr('id'),
                        'asset_image_id' => (int) $image->getAttr('id'),
                        'asset_image_version_id' => (int) $version->getAttr('id'),
                        'series_id' => (int) $asset->getAttr('series_id'),
                        'type' => 'character',
                        'name' => $lookLabel . '（版本 ' . $versionIndex . '）',
                        'reference_role' => 'look',
                        'variant_name' => $variantName,
                        'reference_key' => (string) ($image->getAttr('reference_key') ?? ''),
                        'character_name' => (string) $asset->getAttr('name'),
                        'description' => mb_substr((string) ($version->getAttr('prompt') ?: $image->getAttr('image_prompt') ?: $asset->getAttr('description')), 0, 600),
                        'image_prompt' => (string) ($version->getAttr('prompt') ?: $image->getAttr('image_prompt') ?: ''),
                        'tags' => $asset->getAttr('tags') ?: [],
                        'main_image_url' => $versionUrl,
                        'toapis_asset_url' => '',
                        'toapis_status' => '',
                        'has_image' => true,
                    ];
                }
            }
        }

        return $library;
    }

    private function buildEpisodeAssetExtractionLibrary(int $seriesId): array
    {
        return AssetExtractionRequestOptimizer::compactLibrary($this->buildEpisodeAssetLibrary($seriesId));
    }

    /**
     * 用 AI 在分镜文本中匹配资产提及：返回 [['term' => '精卫', 'asset_id' => 3], ...]。
     * term 是文本中实际出现的字符串（可能是资产名的简称/别名，如「精卫」指代「精卫（少女形态）」）。
     * 仅返回有图片的资产；匹配失败时静默返回空数组，绝不影响节点本身的执行结果。
     */
    private function matchAssetMentionsWithAi(ModelConfig $model, string $text, int $seriesId, array $logContext): array
    {
        try {
            $library = $this->buildEpisodeAssetLibrary($seriesId);
            $candidates = [];
            $candidateIndex = [];
            foreach ($library as $asset) {
                if (empty($asset['has_image'])) {
                    continue;
                }
                $candidate = [
                    'id' => (int) $asset['id'],
                    'type' => (string) $asset['type'],
                    'name' => (string) $asset['name'],
                    'asset_image_id' => isset($asset['asset_image_id']) ? (int) $asset['asset_image_id'] : null,
                    'reference_role' => (string) ($asset['reference_role'] ?? 'view'),
                    'description' => mb_substr((string) ($asset['description'] ?? ''), 0, 200),
                    'tags' => $asset['tags'] ?? [],
                ];
                $candidates[] = $candidate;
                $candidateIndex[$this->assetMentionCandidateKey(
                    (int) $candidate['id'],
                    (int) ($candidate['asset_image_id'] ?? 0),
                    (string) $candidate['reference_role'],
                )] = true;
            }
            if ($candidates === [] || trim($text) === '') {
                return [];
            }

            $system = <<<'PROMPT'
你是影视制作资产匹配助手。给你一份资产列表（JSON）和一段分镜文本，找出文本中所有指代这些资产的词。
规则：
1. term 必须是分镜文本中实际出现的连续字符串，不要改写、不要增删字。
2. 普通资产可以匹配简称、别名、形态变体：例如资产名为「精卫（少女形态）」，文本写「精卫」也算指代它。
3. 如果候选资产是人物造型（reference_role="look"），只有当分镜文本中出现完整可见名称“人物名·造型名”时才能匹配；不要因为只出现人物名或只出现造型名就命中该 look。若完整人物造型名已经命中，不要再把其中的同名人物子串单独当成一次普通人物命中，除非文本别处又单独出现了该人物名。
4. 同一个 term 只输出一次；同一资产可以有多个不同的 term。
5. 只匹配确定指代某个资产的词，模糊的不要输出；找不到任何指代就输出空数组。
6. 如果命中的是人物造型版本，请同时返回 asset_image_id 与 reference_role="look"；普通资产返回 reference_role="view" 即可。
7. 只输出 JSON，不要解释、不要 Markdown 代码块，格式：{"mentions":[{"term":"精卫","asset_id":3,"asset_image_id":12,"reference_role":"look"}]}
PROMPT;
            $user = "资产列表：\n" . json_encode(array_values($candidates), JSON_UNESCAPED_UNICODE)
                . "\n\n分镜文本：\n" . $text;

            $raw = $this->callChatCompletions($model, [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ], array_merge(['source' => 'shot_asset_mention_match', 'series_id' => $seriesId], $logContext));

            $parsed = $this->safeParseJson($raw);
            $items = $parsed['mentions'] ?? ($this->isListArray($parsed) ? $parsed : []);
            if (!is_array($items)) {
                return [];
            }

            $mentions = [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $term = trim((string) ($item['term'] ?? ''));
                $assetId = (int) ($item['asset_id'] ?? 0);
                $assetImageId = (int) ($item['asset_image_id'] ?? 0);
                $referenceRole = trim((string) ($item['reference_role'] ?? ''));
                if ($term === '' || isset($mentions[$term])) {
                    continue;
                }
                $candidateKey = $this->assetMentionCandidateKey($assetId, $assetImageId, $referenceRole);
                if (!isset($candidateIndex[$candidateKey]) || mb_strpos($text, $term) === false) {
                    continue;
                }
                $mention = ['term' => $term, 'asset_id' => $assetId];
                if ($assetImageId > 0) {
                    $mention['asset_image_id'] = $assetImageId;
                }
                if ($referenceRole !== '') {
                    $mention['reference_role'] = $referenceRole;
                }
                $mentions[$term] = $mention;
            }

            return array_values($mentions);
        } catch (\Throwable $e) {
            Log::warning('shot asset mention match failed: ' . $e->getMessage(), $logContext);
            return [];
        }
    }

    private function hydrateShotAssetBindings(array $parsed, int $seriesId): array
    {
        $shots = isset($parsed['shots']) && is_array($parsed['shots'])
            ? $parsed['shots']
            : ($this->isListArray($parsed) ? $parsed : []);
        if ($shots === []) {
            return $parsed;
        }

        $assetsById = [];
        $assets = Asset::with(['images'])->where('series_id', $seriesId)->where('user_id', $this->effectiveUserId())->select();
        foreach ($assets as $asset) {
            if (!$asset instanceof Asset) {
                continue;
            }
            $assetsById[(int) $asset->getAttr('id')] = $asset;
        }

        $hydratedShots = [];
        foreach ($shots as $shot) {
            if (!is_array($shot)) {
                continue;
            }
            $bindings = is_array($shot['bindings'] ?? null) ? $shot['bindings'] : $shot;
            $referenceImages = [
                'image_1' => $this->hydrateBoundAsset($bindings['image_1_asset_id'] ?? $bindings['scene_asset_id'] ?? $bindings['image_1']['asset_id'] ?? null, 'scene', $assetsById),
                'image_2' => $this->hydrateBoundAsset($bindings['image_2_asset_id'] ?? $bindings['character_a_asset_id'] ?? $bindings['image_2']['asset_id'] ?? null, 'character', $assetsById),
                'image_3' => $this->hydrateBoundAsset($bindings['image_3_asset_id'] ?? $bindings['character_b_asset_id'] ?? $bindings['image_3']['asset_id'] ?? null, 'character', $assetsById),
                'props' => [],
            ];

            $propIds = $bindings['prop_asset_ids'] ?? $bindings['props'] ?? [];
            if (!is_array($propIds)) {
                $propIds = [];
            }
            foreach ($propIds as $prop) {
                $assetId = is_array($prop) ? ($prop['asset_id'] ?? $prop['id'] ?? null) : $prop;
                $hydrated = $this->hydrateBoundAsset($assetId, 'prop', $assetsById);
                if ($hydrated !== null) {
                    $referenceImages['props'][] = $hydrated;
                }
            }

            $shot['reference_images'] = $referenceImages;
            $hydratedShots[] = $shot;
        }

        return [
            'shots' => $hydratedShots,
            'generated_at' => date('Y-m-d H:i:s'),
            'binding_source' => 'ai_asset_id_with_local_validation',
        ];
    }

    /**
     * @param array<int, Asset> $assetsById
     */
    private function hydrateBoundAsset(mixed $assetId, string $expectedType, array $assetsById): ?array
    {
        $imageId = 0;
        if (is_array($assetId)) {
            $imageId = (int) ($assetId['asset_image_id'] ?? $assetId['image_id'] ?? 0);
            $assetId = $assetId['asset_id'] ?? $assetId['id'] ?? 0;
        }

        $id = (int) $assetId;
        if ($id <= 0 || !isset($assetsById[$id])) {
            return null;
        }

        $asset = $assetsById[$id];
        if ((string) $asset->getAttr('type') !== $expectedType) {
            return null;
        }

        $url = '';
        $referenceRole = 'view';
        $variantName = '';
        if ($imageId > 0) {
            foreach ($asset->images as $image) {
                if (!$image instanceof AssetImage || (int) $image->getAttr('id') !== $imageId) {
                    continue;
                }
                $url = trim((string) $image->getAttr('url'));
                $referenceRole = strtolower(trim((string) ($image->getAttr('reference_role') ?? 'view')));
                $variantName = trim((string) ($image->getAttr('variant_name') ?? ''));
                break;
            }
        }
        if ($url === '') {
            $url = $this->pickAssetReferenceImageUrl($asset);
        }
        if ($url === '') {
            return null;
        }

        return [
            'asset_id' => $id,
            'asset_image_id' => $imageId > 0 ? $imageId : null,
            'type' => (string) $asset->getAttr('type'),
            'name' => $referenceRole === 'look' && $variantName !== ''
                ? $this->assetLookService()->makeReferenceName((string) $asset->getAttr('name'), $variantName)
                : (string) $asset->getAttr('name'),
            'reference_role' => $referenceRole,
            'variant_name' => $variantName,
            'url' => $url,
            'description' => (string) $asset->getAttr('description'),
        ];
    }

    private function resolveLocalPublicPath(string $url): string
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
        if (str_starts_with($path, '/')) {
            $candidate = app()->getRootPath() . 'public' . str_replace('/', DIRECTORY_SEPARATOR, $path);
            return is_file($candidate) ? $candidate : '';
        }

        $candidate = app()->getRootPath() . 'public' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        return is_file($candidate) ? $candidate : '';
    }

    private function buildFrameImagePrompt(Episode $episode, string $label, string $nodePrompt, array $shotData, array $assets): string
    {
        $episodeNumber = (int) $episode->getAttr('number');
        $episodeTitle = (string) $episode->getAttr('title');
        $series = $episode->series;
        $visualStyle = $this->normalizeVisualStyle((string) ($series?->getAttr('visual_style') ?? 'realistic'));
        $visualStyleVariant = $this->normalizeVisualStyleVariant($visualStyle, (string) ($series?->getAttr('visual_style_variant') ?? ''));
        $regionRule = $this->seriesRegionPromptRule($series);
        $styleRule = $this->seriesVisualStylePromptRule($visualStyle, $visualStyleVariant);

        $assetLines = [];
        foreach ($assets as $asset) {
            $assetLines[] = sprintf(
                '- %s（%s）：%s',
                (string) $asset['name'],
                (string) $asset['type'],
                trim((string) ($asset['description'] ?: $asset['image_prompt']))
            );
        }
        $assetText = $assetLines === []
            ? '无明确匹配资产。'
            : implode("\n", $assetLines);

        $duration = trim((string) ($shotData['duration'] ?? ''));
        $durationText = $duration !== '' ? "镜头时长：{$duration}\n" : '';
        $description = trim((string) ($shotData['description'] ?? ''));

        return <<<PROMPT
你是短剧工作流中的【{$label}】节点，请根据单个分镜生成一张首帧图片。

节点指令：{$nodePrompt}
视觉风格要求：{$styleRule}
内容地区：{$regionRule}
剧集：第{$episodeNumber}集《{$episodeTitle}》
{$durationText}分镜内容：{$description}

涉及资产参考：
{$assetText}

硬性要求：
1) 只生成这个镜头的一张首帧，单张完整图片，单一镜头，不要三宫格、拼图、分屏、多面板、故事板。
2) 如果随请求提供了参考图，请优先保持人物外貌、服装、场景空间和核心道具一致。
3) 画面必须服务于分镜内容，不要把多个镜头合成到同一张图。
4) 横版短剧画面，主体清晰，构图干净。
English constraints: single cinematic frame, one image only, no collage, no triptych, no split screen, no storyboard, no multiple panels.
PROMPT;
    }

    private function compileStoryboardShotForVideo(int $seriesId, array $shotData, string $fallbackDescription): array
    {
        $richNodes = isset($shotData['content_rich_json']) && is_array($shotData['content_rich_json']) ? $shotData['content_rich_json'] : [];
        $assetRefs = isset($shotData['asset_refs']) && is_array($shotData['asset_refs']) ? $shotData['asset_refs'] : [];
        if (($richNodes === [] || $assetRefs === []) && trim($fallbackDescription) !== '') {
            $fallbackRichNodes = $this->storyboardRichNodesFromText($fallbackDescription, $seriesId);
            $fallbackAssetRefs = $this->storyboardAssetRefsFromRichNodes($fallbackRichNodes);
            if ($fallbackRichNodes !== [] && $fallbackAssetRefs !== []) {
                $richNodes = $fallbackRichNodes;
                $assetRefs = $fallbackAssetRefs;
            }
        }

        if ($richNodes === [] || $assetRefs === []) {
            $description = $this->stripStoryboardReferenceLine($fallbackDescription);
            $assets = $this->normalizeVideoShotAssets($shotData['assets'] ?? [], $seriesId, $fallbackDescription);
            return [
                'description' => $description,
                'assets' => $this->filterVideoAssetsByDescriptionMention($assets, $description),
            ];
        }

        $assets = $this->storyboardAssetsFromRefs($seriesId, $assetRefs);
        if ($assets === []) {
            $description = $this->stripStoryboardReferenceLine($fallbackDescription);
            $assets = $this->normalizeVideoShotAssets($shotData['assets'] ?? [], $seriesId, $fallbackDescription);
            return [
                'description' => $description,
                'assets' => $this->filterVideoAssetsByDescriptionMention($assets, $description),
            ];
        }

        $orderedAssets = $this->orderStoryboardAssetsForVideo($assets, $assetRefs);
        $assetLabelMap = [];
        foreach ($orderedAssets as $index => $asset) {
            $key = $this->storyboardAssetRefKey(
                (int) ($asset['id'] ?? 0),
                isset($asset['asset_image_id']) && $asset['asset_image_id'] !== null ? (int) $asset['asset_image_id'] : null,
                (string) ($asset['reference_role'] ?? 'view'),
                isset($asset['asset_image_version_id']) && $asset['asset_image_version_id'] !== null ? (int) $asset['asset_image_version_id'] : null,
            );
            $assetLabelMap[$key] = '@' . ltrim((string) ($asset['name'] ?? ''), '@');
        }

        $chunks = [];
        foreach ($richNodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            if (($node['type'] ?? '') === 'asset_ref') {
                $key = $this->storyboardAssetRefKey(
                    (int) ($node['asset_id'] ?? 0),
                    isset($node['asset_image_id']) && $node['asset_image_id'] !== null ? (int) $node['asset_image_id'] : null,
                    (string) ($node['reference_role'] ?? 'view'),
                    isset($node['asset_image_version_id']) && $node['asset_image_version_id'] !== null ? (int) $node['asset_image_version_id'] : null,
                );
                $chunks[] = $assetLabelMap[$key] ?? ('@' . ltrim((string) ($node['label'] ?? ''), '@'));
            } else {
                $chunks[] = (string) ($node['text'] ?? '');
            }
        }

        $description = $this->stripStoryboardReferenceLine(implode('', $chunks));
        return [
            'description' => $description,
            'assets' => $this->filterVideoAssetsByDescriptionMention($orderedAssets, $description),
        ];
    }

    private function orderStoryboardAssetsForVideo(array $assets, array $assetRefs): array
    {
        $positions = [];
        foreach ($assetRefs as $offset => $ref) {
            if (!is_array($ref)) {
                continue;
            }
            $positions[$this->storyboardAssetRefKey(
                (int) ($ref['asset_id'] ?? 0),
                isset($ref['asset_image_id']) && $ref['asset_image_id'] !== null ? (int) $ref['asset_image_id'] : null,
                (string) ($ref['reference_role'] ?? 'view'),
                isset($ref['asset_image_version_id']) && $ref['asset_image_version_id'] !== null ? (int) $ref['asset_image_version_id'] : null,
            )] = $offset;
        }

        usort($assets, function (array $a, array $b) use ($positions): int {
            $typeRank = static function (array $asset): int {
                return match (strtolower(trim((string) ($asset['type'] ?? '')))) {
                    'scene' => 0,
                    'character' => 1,
                    'prop' => 2,
                    default => 3,
                };
            };
            $rank = $typeRank($a) <=> $typeRank($b);
            if ($rank !== 0) {
                return $rank;
            }

            $keyA = $this->storyboardAssetRefKey((int) ($a['id'] ?? 0), isset($a['asset_image_id']) && $a['asset_image_id'] !== null ? (int) $a['asset_image_id'] : null, (string) ($a['reference_role'] ?? 'view'), isset($a['asset_image_version_id']) && $a['asset_image_version_id'] !== null ? (int) $a['asset_image_version_id'] : null);
            $keyB = $this->storyboardAssetRefKey((int) ($b['id'] ?? 0), isset($b['asset_image_id']) && $b['asset_image_id'] !== null ? (int) $b['asset_image_id'] : null, (string) ($b['reference_role'] ?? 'view'), isset($b['asset_image_version_id']) && $b['asset_image_version_id'] !== null ? (int) $b['asset_image_version_id'] : null);
            return ($positions[$keyA] ?? 999) <=> ($positions[$keyB] ?? 999);
        });

        return $assets;
    }

    private function filterVideoAssetsByDescriptionMention(array $assets, string $description): array
    {
        $description = trim($description);
        if ($description === '' || $assets === []) {
            return [];
        }

        $filtered = [];
        $seen = [];
        foreach ($assets as $asset) {
            if (!is_array($asset) || !$this->videoAssetMentionedInDescription($asset, $description)) {
                continue;
            }
            $key = $this->storyboardAssetRefKey(
                (int) ($asset['id'] ?? $asset['asset_id'] ?? 0),
                isset($asset['asset_image_id']) && $asset['asset_image_id'] !== null ? (int) $asset['asset_image_id'] : null,
                (string) ($asset['reference_role'] ?? 'view'),
                isset($asset['asset_image_version_id']) && $asset['asset_image_version_id'] !== null ? (int) $asset['asset_image_version_id'] : null,
            );
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $filtered[] = $asset;
        }

        return $filtered;
    }

    private function videoAssetMentionedInDescription(array $asset, string $description): bool
    {
        $name = trim((string) ($asset['name'] ?? ''));
        return $name !== '' && mb_stripos($description, $name) !== false;
    }

    private function stripStoryboardReferenceLine(string $text): string
    {
        $text = preg_replace('/^\s*引用资产[：:].*?(?=(?:镜头|分镜|shot)\s*\d+\s*(?:[（(]|[:：]))/imu', '', $text) ?? $text;
        $text = preg_replace('/^\s*引用资产[：:].*$/mu', '', $text) ?? $text;
        $text = preg_replace('/\R{3,}/u', "\n\n", $text) ?? $text;
        return trim($text);
    }

    private function replaceAssetMentionsWithVideoPlaceholders(string $text, array $assets): string
    {
        $text = trim($text);
        if ($text === '' || $assets === []) {
            return $text;
        }

        $orderedAssets = $this->orderStoryboardAssetsForVideo($assets, []);
        $pairs = [];
        foreach ($orderedAssets as $index => $asset) {
            $name = trim((string) ($asset['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $pairs[] = [
                'name' => $name,
                'placeholder' => '@图片' . ($index + 1),
            ];
        }

        usort($pairs, static fn (array $a, array $b): int => mb_strlen($b['name']) <=> mb_strlen($a['name']));
        foreach ($pairs as $pair) {
            $pattern = '/@?' . preg_quote($pair['name'], '/') . '/u';
            $text = preg_replace($pattern, $pair['placeholder'], $text) ?? $text;
        }

        return $text;
    }

    private function replaceVideoPromptNamesByReferenceAliases(string $text, array $referenceImageMeta): string
    {
        $text = trim($text);
        if ($text === '') {
            return $text;
        }

        $pairs = [];
        foreach ($this->videoReferenceAliases() as $alias) {
            if (!isset($referenceImageMeta[$alias])) {
                continue;
            }
            $item = $referenceImageMeta[$alias];
            $name = trim((string) ($item['name'] ?? ''));
            $type = strtolower(trim((string) ($item['type'] ?? '')));
            if ($name === '' || $type === 'continuity_reference') {
                continue;
            }
            $pairs[] = [
                'name' => $name,
                'placeholder' => '@图片' . (int) str_replace('image_', '', $alias),
            ];
        }

        usort($pairs, static fn (array $a, array $b): int => mb_strlen($b['name']) <=> mb_strlen($a['name']));
        foreach ($pairs as $pair) {
            $pattern = '/@?' . preg_quote($pair['name'], '/') . '/u';
            $text = preg_replace($pattern, $pair['placeholder'], $text) ?? $text;
        }

        return $text;
    }

    private function buildVideoPrompt(Episode $episode, string $label, string $nodePrompt, array $shotData, array $assets, int $videoDurationSeconds = 0, string $videoStylePrompt = '', bool $chainShots = true): string
    {
        $description = trim((string) ($shotData['description'] ?? ''));
        $sourceImageUrl = trim((string) ($shotData['source_image_url'] ?? ''));
        $inputImageUrl = trim((string) ($shotData['input_image_url'] ?? ''));
        $isContinuous = $chainShots && $sourceImageUrl !== '' && $inputImageUrl !== '' && $sourceImageUrl !== $inputImageUrl;
        $referenceImageMeta = $this->buildVideoReferenceImages(
            $isContinuous ? $inputImageUrl : $sourceImageUrl,
            $assets,
            $isContinuous
        );
        $description = $this->replaceVideoPromptNamesByReferenceAliases($description, $referenceImageMeta);
        $styleRule = $this->videoStylePromptForPrompt($videoStylePrompt);
        $referenceText = $this->buildChineseVideoReferencePrompt($referenceImageMeta, $isContinuous);
        $sceneLine = $this->extractVideoPromptSceneLine($description);
        $timelineText = $this->buildChineseVideoTimeline($description, $videoDurationSeconds);

        $parts = array_values(array_filter([
            $this->videoNoSubtitleHardRule(),
            $referenceText,
            $sceneLine !== '' ? "场景：{$sceneLine}" : '',
            $timelineText,
            "视觉制作规则：{$styleRule}",
            '要求：画面稳定，人物一致，服装一致，动作流畅自然，情感真实，镜头运动平滑，禁止新增无关人物、无关地点、字幕、水印、UI、分屏、故事板拼贴和明显变形。',
            $this->videoNoSubtitleHardRule(),
        ], static fn (string $value): bool => trim($value) !== ''));

        return $this->enforceVideoNoSubtitleRule(implode("\n\n", $parts));
    }

    /** 视频全局硬性规则：禁止任何形式字幕/屏幕文字。 */
    private function videoNoSubtitleHardRule(): string
    {
        return '硬性禁止字幕：画面中不得出现任何字幕、烧录字幕、台词文字、标题字卡、歌词、caption、subtitle、watermark text、UI 文字或屏幕文字。'
            . ' Hard ban: no subtitles, no burned-in captions, no on-screen dialogue text, no title cards, no lyrics, no watermark text, no UI text.';
    }

    /** 确保最终视频提示词包含禁止字幕规则（直出提示词也强制追加）。 */
    private function enforceVideoNoSubtitleRule(string $prompt): string
    {
        $prompt = trim(str_replace(["\r\n", "\r"], "\n", $prompt));
        $rule = $this->videoNoSubtitleHardRule();
        if ($prompt === '') {
            return $rule;
        }
        if (str_contains($prompt, '硬性禁止字幕') || str_contains(strtolower($prompt), 'hard ban: no subtitles')) {
            return $prompt;
        }

        return $prompt . "\n\n" . $rule;
    }

    private function defaultVideoNodePrompt(): string
    {
        return '基于当前分镜描述生成视频片段；涉及人物、人物造型、场景、道具时优先参考资产库保持一致性。画面不要字幕。';
    }

    private function videoStylePromptFromNodeParams(array $nodeParams): string
    {
        foreach (['video_style_prompt', 'videoStylePrompt', 'style_prompt', 'stylePrompt'] as $key) {
            if (!array_key_exists($key, $nodeParams)) {
                continue;
            }
            $value = $nodeParams[$key];
            if (is_array($value) || is_object($value)) {
                continue;
            }
            $prompt = trim(str_replace(["\r\n", "\r"], "\n", (string) $value));
            if ($prompt !== '') {
                return $prompt;
            }
        }
        return '';
    }

    private function videoStylePromptForPrompt(string $videoStylePrompt): string
    {
        $prompt = trim(str_replace(["\r\n", "\r"], "\n", $videoStylePrompt));
        if ($prompt !== '') {
            return $prompt;
        }
        return $this->defaultVideoStylePrompt();
    }

    private function videoStylePromptForQueuedJob(VideoJob $job, array &$context): string
    {
        $prompt = trim(str_replace(["\r\n", "\r"], "\n", (string) ($context['video_style_prompt'] ?? '')));
        if ($prompt !== '') {
            return $prompt;
        }

        $workflowId = (int) ($job->getAttr('workflow_id') ?? 0);
        $nodeId = trim((string) $job->getAttr('node_id'));
        if ($workflowId <= 0 || $nodeId === '') {
            $context['video_style_source'] = (string) ($context['video_style_source'] ?? 'system_default');
            return '';
        }

        $workflow = Workflow::find($workflowId);
        if (!$workflow instanceof Workflow) {
            $context['video_style_source'] = (string) ($context['video_style_source'] ?? 'system_default');
            return '';
        }

        $graph = $workflow->getAttr('graph') ?: [];
        $nodes = is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [];
        foreach ($nodes as $node) {
            if (!is_array($node) || (string) ($node['id'] ?? '') !== $nodeId) {
                continue;
            }
            $params = is_array($node['data']['params'] ?? null) ? $node['data']['params'] : [];
            $prompt = $this->videoStylePromptFromNodeParams($params);
            if ($prompt !== '') {
                $context['video_style_prompt'] = $prompt;
                $context['video_style_source'] = 'custom_current_workflow';
                return $prompt;
            }
            break;
        }

        $context['video_style_source'] = (string) ($context['video_style_source'] ?? 'system_default');
        return '';
    }

    private function defaultVideoStylePrompt(): string
    {
        return 'Style: live-action cinematic realism, natural motion, consistent lighting, coherent physics. No subtitles, no burned-in captions, no on-screen text.';
    }

    private function isDirectVideoPromptOverride(string $nodePrompt, array $context): bool
    {
        if (!((bool) ($context['candidate_media'] ?? false))) {
            return false;
        }
        $prompt = trim($nodePrompt);
        return $prompt !== '' && $prompt !== $this->defaultVideoNodePrompt();
    }

    private function normalizeVideoPromptBeat(string $description): string
    {
        $description = trim($description);
        if ($description === '') {
            return 'Animate the shot naturally from the provided references.';
        }
        $description = preg_replace('/\b\d+\s*[‑–—-]\s*\d+s\s*:\s*/iu', '', $description) ?? $description;
        $description = preg_replace('/\bfinal\s+second\s*:\s*/iu', '', $description) ?? $description;
        $description = preg_replace('/^\s*【\s*(?:Video\s+Node|视频节点)\s*\d{1,3}\s*｜[^】]+】\s*/iu', '', $description) ?? $description;
        $description = preg_replace('/(?:^|\R)\s*(?:主体|场景|动作|镜头|声音|定格)\s*[:：]\s*/u', "\n", $description) ?? $description;
        $description = preg_replace('/\R{2,}/u', "\n", $description) ?? $description;
        return trim($description);
    }

    private function videoReferenceIdentityRule(array $referenceImageMeta): string
    {
        $rules = [];
        foreach ($this->videoReferenceAliases() as $alias) {
            if (!isset($referenceImageMeta[$alias]) || (string) ($referenceImageMeta[$alias]['type'] ?? '') !== 'character') {
                continue;
            }
            $rules[] = sprintf(
                'The character in %s must remain visually consistent with %s, keeping face, hair, body type, age, costume and expression identity stable.',
                str_replace('image_', 'Image ', $alias),
                str_replace('image_', 'Image ', $alias)
            );
        }
        if (isset($referenceImageMeta['image_1'])) {
            $rules[] = 'The environment must match Image 1, preserving the same layout, furniture, materials, color temperature and spatial anchors.';
        }
        return implode(' ', $rules);
    }

    /**
     * 构造视频请求的参考图：按实际发送顺序从 image_1 连续编号。
     * Seedance / 方舟的「@图片N」对应 content 里第 N 张图，中间不能留空位。
     * 默认只发分镜里提到的资产图；镜头首帧若与资产重复则去重，不额外占一张。
     * 仅镜头衔接时才追加上一镜尾帧，并编为最后一个 @图片N。
     */
    private function buildVideoReferenceImages(string $inputImageUrl, array $assets, bool $includeContinuityFrame = false): array
    {
        $items = [];
        $seenUrls = [];
        $push = static function (array &$items, array &$seenUrls, string $url, array $meta): void {
            $url = trim($url);
            if ($url === '' || isset($seenUrls[$url])) {
                return;
            }
            $seenUrls[$url] = true;
            $items[] = $meta + ['url' => $url];
        };

        $bestAssets = [];
        foreach ($assets as $index => $asset) {
            $url = trim((string) ($asset['image_url'] ?? $asset['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $alias = strtolower(trim((string) ($asset['reference_alias'] ?? '')));
            $type = strtolower(trim((string) ($asset['type'] ?? '')));
            $role = strtolower(trim((string) ($asset['reference_role'] ?? 'view')));
            $visualStyle = $this->seriesVisualStyleForId((int) ($asset['series_id'] ?? 0));
            $needsToapis = $type === 'character' && $this->assetLookService()->needsLookToapisAvatar($visualStyle);
            if ($needsToapis && $role !== 'look') {
                continue;
            }
            if ($needsToapis && $role === 'look' && !(bool) ($asset['used_toapis'] ?? false)) {
                continue;
            }
            $key = $type === 'character'
                ? 'character:' . (int) ($asset['id'] ?? 0)
                : ($type === 'scene'
                    ? 'scene:' . ((int) ($asset['id'] ?? 0) > 0 ? (int) $asset['id'] : trim((string) ($asset['name'] ?? '')))
                    : ($type === 'prop'
                        ? 'prop:' . ((int) ($asset['id'] ?? 0) > 0 ? (int) $asset['id'] : trim((string) ($asset['name'] ?? '')))
                        : 'other:' . $index));
            $score = 0;
            if (in_array($alias, $this->videoReferenceAliases(), true)) {
                $score += 100;
            }
            if ($type === 'character') {
                $score += $role === 'look' ? 20 : 10;
            }
            $asset['_order'] = $index;
            $asset['_rank'] = match (true) {
                $type === 'character' && $role === 'look' => 0,
                $type === 'character' => 1,
                $type === 'prop' => 2,
                $type === 'scene' => 3,
                default => 4,
            };
            if (!isset($bestAssets[$key]) || $score > (int) $bestAssets[$key]['_score']) {
                $asset['_score'] = $score;
                $bestAssets[$key] = $asset;
            }
        }

        uasort($bestAssets, static function (array $a, array $b): int {
            $rankCompare = ((int) ($a['_rank'] ?? 4)) <=> ((int) ($b['_rank'] ?? 4));
            if ($rankCompare !== 0) {
                return $rankCompare;
            }
            $scoreCompare = ((int) ($b['_score'] ?? 0)) <=> ((int) ($a['_score'] ?? 0));
            if ($scoreCompare !== 0) {
                return $scoreCompare;
            }
            return ((int) ($a['_order'] ?? 0)) <=> ((int) ($b['_order'] ?? 0));
        });

        foreach ($bestAssets as $asset) {
            $type = strtolower(trim((string) ($asset['type'] ?? '')));
            $description = trim((string) ($asset['description'] ?? ''));
            if ($this->isVideoLayoutInstructionText($description)) {
                $description = '';
            }
            $push($items, $seenUrls, (string) ($asset['image_url'] ?? $asset['url'] ?? ''), [
                'name' => (string) ($asset['name'] ?? ''),
                'type' => $type !== '' ? $type : 'reference',
                'reference_role' => (string) ($asset['reference_role'] ?? 'view'),
                'description' => $description,
                'used_toapis' => (bool) ($asset['used_toapis'] ?? false),
            ]);
        }

        $inputImageUrl = trim($inputImageUrl);
        if ($includeContinuityFrame && $inputImageUrl !== '') {
            $maxAssets = 8;
            if (count($items) > $maxAssets) {
                $items = array_slice($items, 0, $maxAssets);
            }
            $push($items, $seenUrls, $inputImageUrl, [
                'name' => 'previous shot continuity reference',
                'type' => 'continuity_reference',
            ]);
        } elseif ($items === [] && $inputImageUrl !== '') {
            $push($items, $seenUrls, $inputImageUrl, [
                'name' => 'shot frame',
                'type' => 'shot_frame',
            ]);
        }

        if (count($items) > 9) {
            $items = array_slice($items, 0, 9);
        }

        $refs = [];
        foreach ($items as $index => $item) {
            unset($item['_order'], $item['_score']);
            $refs['image_' . ($index + 1)] = $item;
        }

        return $refs;
    }

    /**
     * 改词重跑：提示词按用户当前编辑直出。参考图只带提示词里还在的 @图片N，
     * 不再用冻住的资产列表或镜头衔接重新拼一套图。
     *
     * @param array<int, array<string, mixed>> $assets
     * @return array{prompt:string,assets:array<int, array<string, mixed>>,include_continuity_frame:bool,reference_meta:array<string, array<string, mixed>>}
     */
    private function applyDirectVideoPromptReferenceFilter(
        string $prompt,
        array $assets,
        string $imageUrl,
        bool $includeContinuityFrame
    ): array {
        $referenceMeta = $this->buildVideoReferenceImages($imageUrl, $assets, $includeContinuityFrame);
        $mentioned = $this->videoPromptMentionedImageNumbers($prompt);
        if ($mentioned === []) {
            return [
                'prompt' => $prompt,
                'assets' => [],
                'include_continuity_frame' => false,
                'reference_meta' => [],
            ];
        }

        $kept = [];
        foreach ($mentioned as $number) {
            $alias = 'image_' . $number;
            if (isset($referenceMeta[$alias]) && is_array($referenceMeta[$alias])) {
                $kept[$alias] = $referenceMeta[$alias];
            }
        }
        if ($kept === []) {
            return [
                'prompt' => $prompt,
                'assets' => [],
                'include_continuity_frame' => false,
                'reference_meta' => [],
            ];
        }

        [$prompt, $syncedMeta] = $this->reindexVideoPromptReferences($prompt, $kept);

        return [
            'prompt' => $prompt,
            'assets' => $this->videoAssetsMatchingReferenceMeta($assets, $syncedMeta),
            'include_continuity_frame' => $this->videoContinuityReferenceAlias($syncedMeta) !== '',
            'reference_meta' => $syncedMeta,
        ];
    }

    /**
     * @return list<int>
     */
    private function videoPromptMentionedImageNumbers(string $prompt): array
    {
        if (preg_match_all('/@图片\s*(\d+)/u', $prompt, $matches) < 1) {
            return [];
        }
        $numbers = [];
        foreach ($matches[1] as $raw) {
            $number = (int) $raw;
            if ($number >= 1 && $number <= 9) {
                $numbers[$number] = $number;
            }
        }
        ksort($numbers);

        return array_values($numbers);
    }

    /**
     * @param array<string, array<string, mixed>> $keptByOldAlias
     * @return array{0:string,1:array<string, array<string, mixed>>}
     */
    private function reindexVideoPromptReferences(string $prompt, array $keptByOldAlias): array
    {
        $oldToNew = [];
        $newMeta = [];
        $next = 1;
        foreach ($this->videoReferenceAliases() as $alias) {
            if (!isset($keptByOldAlias[$alias])) {
                continue;
            }
            $old = (int) str_replace('image_', '', $alias);
            $oldToNew[$old] = $next;
            $newMeta['image_' . $next] = $keptByOldAlias[$alias];
            $next++;
        }
        if ($oldToNew === []) {
            return [$prompt, []];
        }

        $identity = true;
        foreach ($oldToNew as $old => $new) {
            if ($old !== $new) {
                $identity = false;
                break;
            }
        }
        if ($identity) {
            return [$prompt, $newMeta];
        }

        $replaced = $prompt;
        foreach ($oldToNew as $old => $new) {
            $replaced = preg_replace('/@图片\s*' . $old . '(?!\d)/u', '@@IMG' . $old . '@@', $replaced) ?? $replaced;
        }
        foreach ($oldToNew as $old => $new) {
            $replaced = str_replace('@@IMG' . $old . '@@', '@图片' . $new, $replaced);
        }

        return [$replaced, $newMeta];
    }

    /**
     * @param array<int, array<string, mixed>> $assets
     * @param array<string, array<string, mixed>> $referenceMeta
     * @return array<int, array<string, mixed>>
     */
    private function videoAssetsMatchingReferenceMeta(array $assets, array $referenceMeta): array
    {
        $keepUrls = [];
        foreach ($this->videoReferenceAliases() as $alias) {
            if (!isset($referenceMeta[$alias]) || !is_array($referenceMeta[$alias])) {
                continue;
            }
            $type = strtolower(trim((string) ($referenceMeta[$alias]['type'] ?? '')));
            if ($type === 'continuity_reference' || $type === 'shot_frame') {
                continue;
            }
            $url = trim((string) ($referenceMeta[$alias]['url'] ?? ''));
            if ($url !== '') {
                $keepUrls[$url] = true;
            }
        }
        if ($keepUrls === []) {
            return [];
        }

        $kept = [];
        $seen = [];
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $url = trim((string) ($asset['image_url'] ?? $asset['url'] ?? ''));
            if ($url === '' || !isset($keepUrls[$url]) || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $kept[] = $asset;
        }

        return $kept;
    }

    private function videoReferenceImageUrls(array $referenceImages): array
    {
        $urls = [];
        foreach ($this->videoReferenceAliases() as $key) {
            $url = trim((string) ($referenceImages[$key]['url'] ?? ''));
            if ($url !== '' && !in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }
        return $urls;
    }

    private function videoReferenceAliases(): array
    {
        return array_map(static fn (int $number): string => 'image_' . $number, range(1, 9));
    }

    private function videoContinuityReferenceAlias(array $referenceImages): string
    {
        foreach ($this->videoReferenceAliases() as $alias) {
            if ((string) ($referenceImages[$alias]['type'] ?? '') === 'continuity_reference') {
                return $alias;
            }
        }
        return '';
    }

    private function buildChineseVideoReferencePrompt(array $referenceImageMeta, bool $isContinuous): string
    {
        $lines = [];
        foreach ($this->videoReferenceAliases() as $alias) {
            if (!isset($referenceImageMeta[$alias])) {
                continue;
            }
            $item = $referenceImageMeta[$alias];
            $type = strtolower(trim((string) ($item['type'] ?? '')));
            $name = trim((string) ($item['name'] ?? ''));
            $summary = $this->videoReferenceSummary((string) ($item['description'] ?? ''));
            $placeholder = '@图片' . (int) str_replace('image_', '', $alias);
            $extra = $summary !== '' ? "（{$summary}）" : '';

            if ($type === 'continuity_reference') {
                $lines[] = "{$placeholder} 作为上一镜头尾帧衔接参考，保持动作节奏、人物站位和镜头方向连续。";
                continue;
            }

            if ($type === 'shot_frame') {
                $lines[] = "{$placeholder} 作为本镜头画面参考，保持构图、人物站位和场景关系。";
                continue;
            }

            if ($type === 'scene') {
                $lines[] = "{$placeholder} 作为场景「{$name}」参考{$extra}，保持空间布局、光影和氛围一致。";
                continue;
            }

            if ($type === 'character') {
                $lines[] = "{$placeholder} 作为人物「{$name}」形象参考{$extra}，保持面部、发型、体型、年龄感和服装一致。";
                continue;
            }

            if ($type === 'prop') {
                $lines[] = "{$placeholder} 作为道具「{$name}」参考{$extra}，保持材质、比例和外形一致。";
                continue;
            }

            $label = $name !== '' ? "「{$name}」" : '';
            $lines[] = "{$placeholder} 作为参考{$label}{$extra}，保持外观一致。";
        }

        return implode("\n", $lines);
    }

    private function isVideoLayoutInstructionText(string $description): bool
    {
        return (bool) preg_match('/构图|过人脸|无头|井字|Three full-body|headless|face-pass/iu', $description);
    }

    private function videoReferenceSummary(string $description): string
    {
        $description = trim(preg_replace('/\s+/u', ' ', $description) ?? $description);
        if ($description === '' || $this->isVideoLayoutInstructionText($description)) {
            return '';
        }
        $description = preg_replace('/[。！？].*$/u', '', $description) ?? $description;
        return mb_substr($description, 0, 28);
    }

    private function extractVideoPromptSceneLine(string $description): string
    {
        foreach ($this->parseStructuredVideoPromptBlocks($description) as $block) {
            $scene = trim((string) ($block['scene'] ?? ''));
            if ($scene !== '') {
                return $scene;
            }
        }

        if (preg_match('/(?:^|\R)\s*场景\s*[:：]\s*(.+)$/mu', $description, $matches) === 1) {
            return trim((string) ($matches[1] ?? ''));
        }

        return '';
    }

    private function buildChineseVideoTimeline(string $description, int $videoDurationSeconds = 0): string
    {
        $blocks = $this->parseStructuredVideoPromptBlocks($description);
        $lines = [];
        foreach ($blocks as $block) {
            $time = $this->normalizeChineseTimelineLabel((string) ($block['time'] ?? ''));
            $parts = [];
            foreach (['subject', 'action', 'camera', 'sound', 'lighting'] as $field) {
                $value = trim((string) ($block[$field] ?? ''));
                if ($value !== '') {
                    $parts[] = $value;
                }
            }
            if ($parts === []) {
                $fallback = trim((string) ($block['body'] ?? ''));
                if ($fallback !== '') {
                    $parts[] = $fallback;
                }
            }
            if ($parts === []) {
                continue;
            }
            $lines[] = ($time !== '' ? "[{$time}] " : '') . implode(' ', $parts);
        }

        $finalFocus = $this->extractVideoFinalFocusText($description);
        if ($finalFocus !== '') {
            $lines[] = '[' . ($videoDurationSeconds > 0 ? '最后1秒' : '结尾定格') . "] {$finalFocus}";
        }

        if ($lines !== []) {
            return implode("\n", $lines);
        }

        $fallback = $this->normalizeVideoPromptBeat($description);
        return ($videoDurationSeconds > 0 ? '[全程] ' : '') . ($fallback !== '' ? $fallback : '人物按分镜要求自然完成动作与互动。');
    }

    private function parseStructuredVideoPromptBlocks(string $description): array
    {
        $description = trim($description);
        if ($description === '') {
            return [];
        }

        $pattern = '/(?:^|\R)\s*镜头\s*(\d+)\s*[（(]([^）)]*)[）)]\s*[:：]\s*(.*?)(?=(?:\R\s*镜头\s*\d+\s*[（(])|(?:\R\s*定焦画面\s*[:：])|\z)/isu';
        preg_match_all($pattern, $description, $matches, PREG_SET_ORDER);
        $blocks = [];
        foreach ($matches as $match) {
            $body = trim((string) ($match[3] ?? ''));
            $sections = $this->extractStructuredVideoPromptSections($body);
            $blocks[] = [
                'index' => (int) ($match[1] ?? 0),
                'time' => trim((string) ($match[2] ?? '')),
                'body' => $body,
                'subject' => $sections['主体'] ?? '',
                'scene' => $sections['场景'] ?? '',
                'action' => $sections['动作'] ?? '',
                'camera' => $sections['镜头'] ?? '',
                'sound' => $sections['声音'] ?? '',
                'lighting' => $sections['光色'] ?? '',
            ];
        }

        return $blocks;
    }

    private function extractStructuredVideoPromptSections(string $body): array
    {
        $pattern = '/(?:^|\R)\s*(主体|场景|动作|镜头|声音|光色)\s*[:：]\s*(.*?)(?=(?:\R\s*(?:主体|场景|动作|镜头|声音|光色)\s*[:：])|\z)/isu';
        preg_match_all($pattern, $body, $matches, PREG_SET_ORDER);
        $sections = [];
        foreach ($matches as $match) {
            $sections[(string) ($match[1] ?? '')] = trim((string) ($match[2] ?? ''));
        }
        return $sections;
    }

    private function normalizeChineseTimelineLabel(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*[-‑–—]\s*(\d+(?:\.\d+)?)\s*(?:s|秒)?/iu', $raw, $matches) === 1) {
            $start = $this->formatTimelineSecondNumber((string) ($matches[1] ?? '0'));
            $end = $this->formatTimelineSecondNumber((string) ($matches[2] ?? '0'));
            return "{$start}-{$end}秒";
        }
        return $raw;
    }

    private function formatTimelineSecondNumber(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '0';
        }
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }
        return $value !== '' ? $value : '0';
    }

    private function extractVideoFinalFocusText(string $description): string
    {
        if (preg_match('/(?:^|\R)\s*定焦画面\s*[:：]\s*(.+)$/isu', $description, $matches) === 1) {
            return trim((string) ($matches[1] ?? ''));
        }
        return '';
    }

    /**
     * 调用 ToAPIs 图片模型。
     * 有资产参考图时通过 image_urls JSON 字段传入。
     */
    private function callWorkflowImageGeneration(ModelConfig $model, string $prompt, array $assets, array $context = []): string
    {
        $startedAt = microtime(true);
        $endpoint = '';
        $httpStatus = 0;
        $rawBody = '';
        $curlErrno = 0;
        $curlError = '';
        $requestOk = 0;
        $errorMessage = '';
        $imageUrl = '';
        $requestLog = [];
        $headers = [];
        $basePayload = [];

        try {
            $referenceUrls = [];
            $assetRefs = [];
            foreach ($assets as $asset) {
                $assetImageUrl = trim((string) ($asset['image_url'] ?? ''));
                $assetRefs[] = [
                    'id' => $asset['id'] ?? null,
                    'name' => $asset['name'] ?? '',
                    'type' => $asset['type'] ?? '',
                    'description' => $asset['description'] ?? '',
                    'image_url' => $assetImageUrl,
                ];
                if ($assetImageUrl !== '') {
                    $referenceUrl = $this->normalizeImageUrlForToapis($assetImageUrl);
                    if ($referenceUrl === '') {
                        $errorMessage = 'ToAPIs 参考图必须是可访问的 HTTP(S) URL，请配置 MEDIA_PUBLIC_BASE_URL 或使用公网图片地址';
                        abort(422, $errorMessage);
                    }
                    $referenceUrls[] = $referenceUrl;
                }
            }

            $endpoint = trim((string) $model->getAttr('endpoint'));
            if ($endpoint === '') {
                $errorMessage = '图片模型 endpoint 为空';
                abort(422, $errorMessage);
            }

            $apiKey = trim((string) $model->getAttr('api_key'));
            $modelId = trim((string) $model->getAttr('model_id'));
            $options = $model->getAttr('options') ?: [];
            if (!is_array($options)) {
                $options = [];
            }

            $basePayload = ImageGenerationOptions::applyToPayload([
                'model' => $modelId ?: ImageGenerationOptions::DEFAULT_MODEL_ID,
                'prompt' => $prompt,
            ], $options, $modelId);
            if ($referenceUrls !== []) {
                $basePayload['image_urls'] = array_values(array_unique($referenceUrls));
            }

            $headers = [];
            if ($apiKey !== '') {
                $headers[] = 'Authorization: Bearer ' . $apiKey;
            }

            $endpoint = $this->resolveImageEndpoint($endpoint);
            $headers[] = 'Content-Type: application/json';
            $requestLog = $basePayload + ['asset_refs' => $assetRefs];
            $postFields = json_encode($basePayload, JSON_UNESCAPED_UNICODE);

            $ch = curl_init($endpoint);
            if ($ch === false) {
                $errorMessage = '初始化图片请求失败';
                abort(500, $errorMessage);
            }
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 600);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);

            $response = curl_exec($ch);
            $curlErrno = curl_errno($ch);
            $curlError = (string) curl_error($ch);
            $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($curlErrno !== 0) {
                $errorMessage = 'AI 图片请求失败：' . $curlError;
                abort(502, $errorMessage);
            }
            if (!is_string($response) || $response === '') {
                $errorMessage = 'AI 图片返回为空';
                abort(502, $errorMessage);
            }

            $rawBody = $response;
            if ($httpStatus < 200 || $httpStatus >= 300) {
                $errorMessage = 'AI 图片服务异常：HTTP ' . $httpStatus . '，响应：' . mb_substr($response, 0, 300);
                abort(502, $errorMessage);
            }

            $decoded = json_decode($response, true);
            if (!is_array($decoded)) {
                $errorMessage = 'AI 图片返回非 JSON';
                abort(502, $errorMessage);
            }

            try {
                $imageUrl = $this->extractWorkflowImageUrlFromResponse($decoded, $endpoint, $headers, $options, $rawBody, $context);
            } catch (\Throwable $e) {
                $errorMessage = $e->getMessage();
                abort(502, $errorMessage);
            }
            if ($imageUrl === '') {
                $errorMessage = 'AI 未返回图片 URL 或 b64_json';
                abort(502, $errorMessage);
            }

            $requestOk = 1;
            return $imageUrl;
        } finally {
            $workflowRunId = (int) ($context['workflow_run_id'] ?? 0);
            $workflowRunNodeId = (int) ($context['workflow_run_node_id'] ?? 0);
            $imageRequestLog = $this->buildAiRequestLogSnapshot(
                'POST',
                $endpoint,
                $headers,
                $requestLog ?? $basePayload ?? [],
            );
            $this->lastAiRequestLogId = $this->insertAiRequestLog([
                'source' => (string) ($context['source'] ?? 'episode_workflow_image'),
                'workflow_run_id' => $workflowRunId > 0 ? $workflowRunId : null,
                'workflow_run_node_id' => $workflowRunNodeId > 0 ? $workflowRunNodeId : null,
                'model_config_id' => (int) ($model->getAttr('id') ?? 0) ?: null,
                'llm_model' => (string) ($requestLog['model'] ?? $basePayload['model'] ?? ''),
                'endpoint' => $endpoint,
                'context_json' => $context,
                'request_json' => $imageRequestLog,
                'http_status' => $httpStatus,
                'response_body' => $this->truncateLogText($rawBody, 400000),
                'curl_errno' => $curlErrno,
                'curl_error' => mb_substr($curlError, 0, 512),
                'request_ok' => $requestOk,
                'error_message' => mb_substr($errorMessage, 0, 2000),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'content_preview' => $imageUrl,
            ]) ?? 0;
        }
    }

    /**
     * ToAPIs gpt-image-2 统一走 /images/generations；参考图通过 image_urls JSON 字段传入。
     */
    private function resolveImageEndpoint(string $endpoint): string
    {
        if (str_contains($endpoint, '/images/edits')) {
            return str_replace('/images/edits', '/images/generations', $endpoint);
        }
        if (str_contains($endpoint, '/images/generations')) {
            return $endpoint;
        }

        return rtrim($endpoint, '/') . '/images/generations';
    }

    private function extractWorkflowImageUrlFromResponse(array $decoded, string $endpoint, array $headers, array $options, string &$rawBody, array $context = []): string
    {
        $imageUrl = trim((string) ($decoded['data'][0]['url'] ?? $decoded['result']['data'][0]['url'] ?? $decoded['url'] ?? ''));
        $b64 = $decoded['data'][0]['b64_json'] ?? $decoded['result']['data'][0]['b64_json'] ?? '';
        if ($imageUrl === '' && is_string($b64) && $b64 !== '') {
            return $this->saveGeneratedWorkflowImageFromBase64($b64, $context);
        }
        if ($imageUrl !== '') {
            return $this->persistGeneratedWorkflowImageUrl($imageUrl, $context);
        }

        $taskId = $this->extractWorkflowImageTaskId($decoded);
        if ($taskId === '') {
            return '';
        }

        return $this->pollWorkflowImageTaskResult($endpoint, $headers, $taskId, $options, $rawBody, $context);
    }

    private function extractWorkflowImageTaskId(array $decoded): string
    {
        $object = (string) ($decoded['object'] ?? '');
        $status = (string) ($decoded['status'] ?? '');
        $id = trim((string) ($decoded['id'] ?? $decoded['data']['id'] ?? ''));

        if ($id !== '' && ($object === 'generation.task' || in_array($status, ['queued', 'in_progress', 'completed', 'failed'], true))) {
            return $id;
        }

        return '';
    }

    private function pollWorkflowImageTaskResult(string $submitEndpoint, array $headers, string $taskId, array $options, string &$rawBody, array $context = []): string
    {
        $interval = max(1, min(10, (int) ($options['poll_interval'] ?? 3)));
        $attempts = max(1, min(80, (int) ($options['poll_attempts'] ?? 40)));
        $statusEndpoint = $this->resolveImageTaskEndpoint($submitEndpoint, $taskId);

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            sleep($interval);

            $ch = curl_init($statusEndpoint);
            if ($ch === false) {
                throw new \RuntimeException('初始化图片任务查询失败');
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPGET => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_CONNECTTIMEOUT => 15,
            ]);
            $response = curl_exec($ch);
            $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErrno = curl_errno($ch);
            $curlError = (string) curl_error($ch);
            curl_close($ch);

            $rawBody .= "\n\n[POLL {$attempt} HTTP {$httpStatus}]\n" . (is_string($response) ? $response : '');
            if ($curlErrno !== 0) {
                throw new \RuntimeException('AI 图片任务查询失败：' . $curlError);
            }
            if (!is_string($response) || $response === '') {
                continue;
            }
            if ($httpStatus < 200 || $httpStatus >= 300) {
                throw new \RuntimeException('AI 图片任务服务异常：HTTP ' . $httpStatus . ' ' . mb_substr($response, 0, 300));
            }

            $decoded = json_decode($response, true);
            if (!is_array($decoded)) {
                continue;
            }

            $status = (string) ($decoded['status'] ?? '');
            if ($status === 'completed') {
                $imageUrl = trim((string) ($decoded['result']['data'][0]['url'] ?? $decoded['data'][0]['url'] ?? $decoded['url'] ?? ''));
                if ($imageUrl !== '') {
                    return $this->persistGeneratedWorkflowImageUrl($imageUrl, $context);
                }
                throw new \RuntimeException('AI 图片任务完成但未返回图片 URL');
            }
            if ($status === 'failed') {
                $message = (string) ($decoded['error']['message'] ?? '图片生成任务失败');
                throw new \RuntimeException('AI 图片任务失败：' . $message);
            }
        }

        throw new \RuntimeException('AI 图片任务超时：' . $taskId);
    }

    private function resolveImageTaskEndpoint(string $submitEndpoint, string $taskId): string
    {
        if (preg_match('#^(.*/v1)/images/(?:generations|edits)(?:/.*)?$#', $submitEndpoint, $m) === 1) {
            return rtrim($m[1], '/') . '/images/generations/' . rawurlencode($taskId);
        }

        return rtrim($submitEndpoint, '/') . '/' . rawurlencode($taskId);
    }

    /**
     * 调用图生视频模型。
     */
    public function callWorkflowVideoGeneration(ModelConfig $model, string $prompt, string $imageUrl, string $duration, array $assets, array $context = []): string
    {
        $this->lastAiRequestLogId = 0;
        $startedAt = microtime(true);
        $endpoint = '';
        $httpStatus = 0;
        $rawBody = '';
        $curlErrno = 0;
        $curlError = '';
        $requestOk = 0;
        $errorMessage = '';
        $videoUrl = '';
        $payload = [];
        $requestPayloadForLog = [];
        $requestSnapshot = [];
        $pollTrace = [];
        $tempFiles = [];
        $referenceUploadMap = [];
        $usageJson = [];
        // 全通道硬性：无论剧集、宣传片、速创还是用户直出提示词，最终提交前都强制禁止字幕。
        $prompt = $this->enforceVideoNoSubtitleRule($prompt);

        try {
            $endpoint = trim((string) $model->getAttr('endpoint'));
            if ($endpoint === '') {
                $errorMessage = '视频模型 endpoint 为空';
                abort(422, $errorMessage);
            }

            $apiKey = trim((string) $model->getAttr('api_key'));
            $modelId = trim((string) $model->getAttr('model_id'));
            if ($modelId === '') {
                $errorMessage = '视频模型 model_id 为空';
                abort(422, $errorMessage);
            }

            $modelOptions = $model->getAttr('options') ?: [];
            if (!is_array($modelOptions)) {
                $modelOptions = [];
            }
            $nodeVideoOptions = is_array($context['video_options'] ?? null) ? $context['video_options'] : [];
            $options = $this->mergeVideoOptions($modelOptions, $nodeVideoOptions);

            $endpoint = $this->resolveVideoEndpoint($endpoint, $modelId, $options);
            $options = $this->normalizeVideoRequestOptionsForModel($endpoint, $modelId, $options);
            $voiceAssets = is_array($context['voice_assets'] ?? null) ? $context['voice_assets'] : [];
            if ($voiceAssets !== [] && $this->isArkVideoEndpoint($endpoint, $options) && $this->isArkSeedance2Model($modelId)) {
                $voiceAssets = $this->prepareVideoVoiceAssetsForArk($voiceAssets, $imageUrl);
                if ($voiceAssets !== []) {
                    $options['generate_audio'] = true;
                }
            } else {
                $voiceAssets = [];
            }
            $includeContinuityFrame = (bool) ($context['chained_from_previous_end_frame'] ?? false);
            $directReferenceMeta = is_array($context['direct_prompt_reference_meta'] ?? null)
                ? $context['direct_prompt_reference_meta']
                : [];
            if (!empty($context['direct_prompt_override']) && $directReferenceMeta === []) {
                $synced = $this->applyDirectVideoPromptReferenceFilter($prompt, $assets, $imageUrl, $includeContinuityFrame);
                $prompt = $synced['prompt'];
                $assets = $synced['assets'];
                $includeContinuityFrame = $synced['include_continuity_frame'];
                $directReferenceMeta = $synced['reference_meta'];
                $context['final_prompt'] = $prompt;
                $context['chained_from_previous_end_frame'] = $includeContinuityFrame;
            }
            $referenceImages = $directReferenceMeta !== []
                ? $this->videoReferenceImageUrls($directReferenceMeta)
                : $this->videoReferenceImageUrls(
                    $this->buildVideoReferenceImages($imageUrl, $assets, $includeContinuityFrame)
                );
            $referenceImages = $this->prepareVideoReferenceImagesForPayload(
                $referenceImages,
                $endpoint,
                $modelId,
                $apiKey,
                $options,
                $tempFiles,
                $referenceUploadMap
            );
            $seconds = $this->isMiniMaxVideoEndpoint($endpoint, $options)
                ? $this->durationSecondsForMiniMax($duration, $options)
                : $this->durationSecondsForVideo($duration, $options);
            if ($this->isMini23VideoEndpoint($endpoint, $options)) {
                $seconds = max(1, min(60, $seconds > 0 ? $seconds : 15));
            }
            $creditUserId = (int) ($context['user_id'] ?? 0);
            if ($creditUserId <= 0) {
                $creditUserId = $this->aiRequestLogUserId([
                    'user_id' => 0,
                    'context_json' => $context,
                    'workflow_run_id' => (int) ($context['workflow_run_id'] ?? 0),
                ]);
            }
            $context['duration_seconds'] = $seconds > 0 ? $seconds : 15;
            if (!isset($context['resolution'])) {
                $context['resolution'] = (string) ($options['resolution'] ?? '480p');
            }
            \app\support\CreditService::assertVideoAffordable(
                $creditUserId,
                (int) $context['duration_seconds'],
                (string) $context['resolution'],
                $modelId
            );
            $referenceVideos = [];
            if ($this->isMiniMaxVideoEndpoint($endpoint, $options)) {
                $seconds = max(4, min(15, $seconds > 0 ? $seconds : 15));
                $referenceVideos = $this->prepareMiniMaxReferenceVideoUrls(
                    is_array($context['reference_videos'] ?? null) ? $context['reference_videos'] : []
                );
            }

            $mini23ReferenceFiles = [];
            $requestPayloadForLog = [];
            if ($this->isMini23VideoEndpoint($endpoint, $options)) {
                [$payload, $mini23ReferenceFiles] = $this->buildMini23VideoPayload(
                    $prompt,
                    $referenceImages,
                    $seconds,
                    $options,
                    $tempFiles
                );
                $requestPayloadForLog = $payload;
                if ($mini23ReferenceFiles !== []) {
                    $requestPayloadForLog['refs'] = ['count' => count($mini23ReferenceFiles)];
                }
            } elseif ($this->isMiniMaxVideoEndpoint($endpoint, $options)) {
                $payload = $this->buildMiniMaxVideoPayload($modelId, $prompt, $referenceImages, $referenceVideos, $seconds, $options);
            } elseif ($this->usesContentArrayVideoPayload($endpoint, $options)) {
                $payload = $this->buildArkVideoPayload($modelId, $prompt, $referenceImages, $seconds, $options, $voiceAssets);
            } else {
                $payload = [
                    'prompt' => $prompt,
                ];
                $this->applyVideoReferenceImagesToPayload($payload, $referenceImages, $options);
                if ($this->shouldIncludeModelInVideoPayload($endpoint, $options)) {
                    $payload['model'] = $modelId;
                }
                if (
                    $this->shouldIncludeVideoPayloadParam('duration', $options)
                    && (array_key_exists('duration', $options) || $seconds > 0)
                ) {
                    $payload['duration'] = $seconds;
                }
                $metadataParams = isset($options['metadata_params']) && is_array($options['metadata_params'])
                    ? array_map(static fn ($value): string => (string) $value, $options['metadata_params'])
                    : [];
                foreach (['aspect_ratio', 'resolution', 'seed', 'fps', 'guidance_scale', 'negative_prompt', 'generate_audio', 'enable_web_search'] as $key) {
                    if (!$this->shouldIncludeVideoPayloadParam($key, $options)) {
                        continue;
                    }
                    if (isset($options[$key]) && trim((string) $options[$key]) !== '') {
                        $payloadKey = in_array($key, $metadataParams, true) ? 'metadata' : $key;
                        if ($key === 'generate_audio' && trim((string) ($options['audio_field'] ?? '')) !== '') {
                            $payload[trim((string) $options['audio_field'])] = is_bool($options[$key])
                                ? $options[$key]
                                : in_array(strtolower((string) $options[$key]), ['1', 'true', 'yes', 'on'], true);
                            continue;
                        }
                        if (is_bool($options[$key])) {
                            $value = $options[$key];
                        } elseif (in_array(strtolower((string) $options[$key]), ['true', 'false'], true)) {
                            $value = strtolower((string) $options[$key]) === 'true';
                        } else {
                            $value = is_numeric($options[$key]) ? $options[$key] + 0 : $options[$key];
                        }
                        if ($payloadKey === 'metadata') {
                            $payload['metadata'][$key] = $value;
                        } else {
                            $payload[$key] = $value;
                        }
                    }
                }
            }

            $headers = ['Content-Type: application/json'];
            if ($apiKey !== '') {
                $headers[] = 'Authorization: Bearer ' . $apiKey;
            }
            if ($this->isYinheAsyncVideoEndpoint($endpoint, $options) || $this->isMini23VideoEndpoint($endpoint, $options)) {
                $idempotencyKey = trim((string) ($context['idempotency_key'] ?? ''));
                $idempotencyKey = $idempotencyKey !== '' ? $idempotencyKey : bin2hex(random_bytes(16));
                $headers[] = 'Idempotency-Key: ' . mb_substr($idempotencyKey, 0, 120);
            }

            $requestSnapshot = $this->buildAiRequestLogSnapshot('POST', $endpoint, $headers, $requestPayloadForLog ?: $payload);
            if ($referenceUploadMap !== []) {
                $requestSnapshot['reference_uploads'] = $referenceUploadMap;
            }
            $response = $this->isMini23VideoEndpoint($endpoint, $options) && $mini23ReferenceFiles !== []
                ? $this->curlMini23MultipartPost($endpoint, $headers, $payload, $mini23ReferenceFiles, $httpStatus, $curlErrno, $curlError, 900)
                : $this->curlJsonPost($endpoint, $headers, $payload, $httpStatus, $curlErrno, $curlError, 900);
            $rawBody = $response;
            if ($curlErrno !== 0) {
                $errorMessage = 'AI 视频请求失败：' . $curlError;
                abort(502, $errorMessage);
            }
            if ($httpStatus < 200 || $httpStatus >= 300) {
                $errorMessage = 'AI 视频服务异常：HTTP ' . $httpStatus . '，响应：' . mb_substr($response, 0, 300);
                abort(502, $errorMessage);
            }

            $decoded = json_decode($response, true);
            if (!is_array($decoded)) {
                $errorMessage = 'AI 视频返回非 JSON';
                abort(502, $errorMessage);
            }

            $videoUrl = $this->extractVideoUrlFromResponse($decoded);
            $usageJson = is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];
            if ($videoUrl === '') {
                $taskId = $this->extractVideoTaskIdFromResponse($decoded);
                if ($taskId !== '') {
                    $resultEndpoint = trim((string) ($decoded['data']['urls']['get'] ?? ''));
                    $videoJobId = (int) ($context['video_job_id'] ?? 0);
                    if ($videoJobId > 0) {
                        $pollEndpoint = $this->resolveVideoResultEndpoint($endpoint, $taskId, $options, $resultEndpoint);
                        $providerLabel = $this->videoProviderLabel($endpoint);
                        $jobContext = VideoJob::where('id', $videoJobId)->value('request_context_json') ?: [];
                        if (!is_array($jobContext)) {
                            $jobContext = [];
                        }
                        $jobContext['task_id'] = $taskId;
                        $jobContext['provider_task_id'] = $taskId;
                        $jobContext['result_endpoint'] = $pollEndpoint;
                        VideoJob::where('id', $videoJobId)->update([
                            'error_message' => '已提交到' . $providerLabel . '，正在轮询结果：' . $taskId,
                            'request_context_json' => $jobContext,
                            'update_time' => date('Y-m-d H:i:s'),
                        ]);
                        Log::info("[VideoJob#{$videoJobId}] {$providerLabel} task_id={$taskId}, result_endpoint={$pollEndpoint}");
                    }
                    $pollResult = $this->pollWorkflowVideoResult($endpoint, $headers, $taskId, $options, $resultEndpoint);
                    $pollTrace = is_array($pollResult['trace'] ?? null) ? $pollResult['trace'] : [];
                    $rawBody .= "\n\n[POLL_RESULT]\n" . ($pollResult['raw'] ?? '');
                    $videoUrl = (string) ($pollResult['video_url'] ?? '');
                    $pollErrorMessage = trim((string) ($pollResult['error_message'] ?? ''));
                    if ($videoUrl === '' && $pollErrorMessage !== '') {
                        $errorMessage = 'AI 视频任务失败：' . $pollErrorMessage;
                    }
                    if ($usageJson === []) {
                        $pollDecoded = json_decode((string) ($pollResult['raw'] ?? ''), true);
                        if (is_array($pollDecoded)) {
                            if (is_array($pollDecoded['usage'] ?? null)) {
                                $usageJson = $pollDecoded['usage'];
                            } elseif (is_array($pollDecoded['data']['tokenUsage'] ?? null)) {
                                $usageJson = $pollDecoded['data']['tokenUsage'];
                            }
                        }
                    }
                }
            }

            if ($videoUrl === '') {
                $errorMessage = $errorMessage !== '' ? $errorMessage : 'AI 未返回视频 URL';
                abort(502, $errorMessage);
            }

            $downloadHeaders = $this->isMini23VideoEndpoint($endpoint, $options) && $apiKey !== ''
                ? ['Authorization: Bearer ' . $apiKey]
                : [];
            try {
                $videoUrl = $this->persistGeneratedVideoUrl($videoUrl, $context, $downloadHeaders);
            } catch (\Throwable $persistError) {
                // 上游已出片，仅本机落盘失败：把明细写进日志，避免界面只剩空泛「下载失败」。
                $errorMessage = mb_substr($persistError->getMessage(), 0, 2000);
                throw $persistError;
            }
            $requestOk = 1;
            return $videoUrl;
        } finally {
            $workflowRunId = (int) ($context['workflow_run_id'] ?? 0);
            $workflowRunNodeId = (int) ($context['workflow_run_node_id'] ?? 0);
            $requestLogBody = $requestSnapshot !== []
                ? $requestSnapshot
                : $this->buildAiRequestLogSnapshot('POST', $endpoint, ['Content-Type: application/json'], $requestPayloadForLog ?: $payload);
            if ($pollTrace !== []) {
                $requestLogBody['poll'] = $pollTrace;
            }
            if ($requestSnapshot !== [] && $referenceUploadMap !== []) {
                $requestLogBody['reference_uploads'] = $referenceUploadMap;
            }
            $this->lastAiRequestLogId = $this->insertAiRequestLog([
                'source' => (string) ($context['source'] ?? 'episode_workflow_video'),
                'workflow_run_id' => $workflowRunId > 0 ? $workflowRunId : null,
                'workflow_run_node_id' => $workflowRunNodeId > 0 ? $workflowRunNodeId : null,
                'model_config_id' => (int) ($model->getAttr('id') ?? 0) ?: null,
                'llm_model' => (string) ($model->getAttr('model_id') ?? ''),
                'endpoint' => $endpoint,
                'context_json' => $context,
                'request_json' => $requestLogBody,
                'usage_json' => $usageJson !== [] ? $usageJson : null,
                'http_status' => $httpStatus,
                'response_body' => $this->truncateLogText($rawBody, 400000),
                'curl_errno' => $curlErrno,
                'curl_error' => mb_substr($curlError, 0, 512),
                'request_ok' => $requestOk,
                'error_message' => mb_substr($errorMessage, 0, 2000),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'content_preview' => $videoUrl,
            ]) ?? 0;
            foreach ($tempFiles as $tempFile) {
                if (is_string($tempFile) && $tempFile !== '' && is_file($tempFile)) {
                    @unlink($tempFile);
                }
            }
        }
    }

    public function runQueuedVideoJob(int $jobId): void
    {
        $job = VideoJob::find($jobId);
        if (!$job instanceof VideoJob) {
            throw new \RuntimeException("视频任务不存在：{$jobId}");
        }
        $jobUserId = (int) ($job->getAttr('user_id') ?? 0);
        if ($this->isVideoGenerationRestrictedUserId($jobUserId)) {
            $job->save([
                'status' => 'failed',
                'video_url' => '',
                'end_frame_url' => '',
                'error_message' => $this->videoGenerationRestrictedMessage(),
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
            $this->refreshVideoWorkflowProgress((int) $job->getAttr('workflow_run_node_id'));
            return;
        }

        $shot = Shot::find((int) $job->getAttr('shot_id'));
        if (!$shot instanceof Shot) {
            $job->save([
                'status' => 'failed',
                'error_message' => '镜头记录不存在，无法生成视频。',
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
            throw new \RuntimeException('镜头记录不存在，无法生成视频。');
        }

        $episode = Episode::find((int) $job->getAttr('episode_id'));
        if (!$episode instanceof Episode) {
            $job->save([
                'status' => 'failed',
                'error_message' => '剧集记录不存在，无法生成视频。',
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
            throw new \RuntimeException('剧集记录不存在，无法生成视频。');
        }
        if (!$this->isVideoJobForCurrentStoryboardRevision($job)) {
            $job->save([
                'status' => 'stale',
                'video_url' => '',
                'end_frame_url' => '',
                'error_message' => '分镜内容已更新，这个旧视频任务不再生成。',
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
            $this->refreshVideoWorkflowProgress((int) $job->getAttr('workflow_run_node_id'));
            return;
        }

        $model = ModelConfigResolver::resolve('video', (int) ($job->getAttr('user_id') ?: 1), (int) $job->getAttr('model_config_id'));
        if (!$model instanceof ModelConfig) {
            $this->failQueuedVideoJob($job, $shot, '视频模型不存在或已被删除。');
            return;
        }

        $chainShots = (bool) $job->getAttr('chain_shots');
        $sourceImageUrl = trim((string) $job->getAttr('source_image_url'));
        $inputImageUrl = trim((string) $job->getAttr('input_image_url'));
        $assets = $job->getAttr('assets_json') ?: [];
        if (!is_array($assets)) {
            $assets = [];
        }
        // 资产库可能在入队后替换了参考图；执行前按 asset_id 刷新 URL，避免旧 job 重试仍带卡脸旧图。
        $assetImageRefresh = $this->refreshVideoJobAssetImages($job, $assets, $sourceImageUrl, $inputImageUrl);
        $assets = $assetImageRefresh['assets'];
        $sourceImageUrl = $assetImageRefresh['source_image_url'];
        $inputImageUrl = $assetImageRefresh['input_image_url'];
        if ($sourceImageUrl === '') {
            $sourceImageUrl = $this->pickPrimaryVideoSourceImageUrl($assets);
            if ($sourceImageUrl !== '') {
                $job->save(['source_image_url' => $sourceImageUrl]);
            }
        }
        $dependsOnJobId = (int) ($job->getAttr('depends_on_job_id') ?? 0);
        if ($chainShots && $dependsOnJobId > 0) {
            $previous = VideoJob::find($dependsOnJobId);
            if (!$previous instanceof VideoJob || (string) $previous->getAttr('status') !== 'success') {
                $job->save(['status' => 'blocked']);
                return;
            }
            $previousEndFrame = trim((string) $previous->getAttr('end_frame_url'));
            if ($previousEndFrame !== '') {
                $inputImageUrl = $previousEndFrame;
                $job->save([
                    'input_image_url' => $inputImageUrl,
                    'previous_end_frame_url' => $previousEndFrame,
                ]);
            }
        }
        if ($inputImageUrl === '') {
            $inputImageUrl = $sourceImageUrl;
        }
        $shotData = $job->getAttr('shot_data_json') ?: [];
        if (!is_array($shotData)) {
            $shotData = [];
        }
        $options = $job->getAttr('video_options_json') ?: [];
        if (!is_array($options)) {
            $options = [];
        }
        $context = $job->getAttr('request_context_json') ?: [];
        if (!is_array($context)) {
            $context = [];
        }
        if (!array_key_exists('voice_assets', $context)) {
            $context = $this->refreshVideoJobVoiceContext($job, $context, $assets);
        }

        $shotData['input_image_url'] = $inputImageUrl;
        $shotData['source_image_url'] = $sourceImageUrl;
        $context['image_url'] = $inputImageUrl;
        $context['source_image_url'] = $sourceImageUrl;
        $context['chained_from_previous_end_frame'] = $inputImageUrl !== $sourceImageUrl;
        $context['video_job_id'] = (int) $job->getAttr('id');
        $context['user_id'] = (int) ($context['user_id'] ?? 0) ?: (int) $job->getAttr('user_id');
        $context['video_options'] = $options;
        if (trim((string) ($context['idempotency_key'] ?? '')) === '') {
            $context['idempotency_key'] = sprintf(
                'malulu-video-%d-%s',
                (int) $job->getAttr('id'),
                bin2hex(random_bytes(12)),
            );
        }

        $duration = trim((string) $job->getAttr('duration'));
        if ($duration === '' || strtolower($duration) === 'auto') {
            $duration = trim((string) ($shotData['duration'] ?? ''));
        }
        if ($this->parseVideoDurationSeconds($duration) <= 0) {
            $duration = $this->extractDurationFromText(
                (string) ($shotData['description'] ?? $shotData['content_text'] ?? '')
            );
        }
        $videoSeconds = $this->durationSecondsForVideo($duration, $options);
        $nodePrompt = trim((string) $job->getAttr('node_prompt'));
        $isDirectPromptOverride = $this->isDirectVideoPromptOverride($nodePrompt, $context);
        $videoStylePrompt = $isDirectPromptOverride ? '' : $this->videoStylePromptForQueuedJob($job, $context);
        if ($isDirectPromptOverride) {
            $prompt = $nodePrompt;
            $includeContinuityFrame = (bool) ($context['chained_from_previous_end_frame'] ?? false);
            $synced = $this->applyDirectVideoPromptReferenceFilter($prompt, $assets, $inputImageUrl, $includeContinuityFrame);
            $prompt = $synced['prompt'];
            $assets = $synced['assets'];
            $context['direct_prompt_override'] = true;
            $context['direct_prompt_reference_meta'] = $synced['reference_meta'];
            $context['chained_from_previous_end_frame'] = $synced['include_continuity_frame'];
        } else {
            $prompt = $this->buildVideoPrompt(
                $episode,
                (string) $job->getAttr('node_label'),
                $nodePrompt,
                $shotData,
                $assets,
                $videoSeconds,
                $videoStylePrompt,
                $chainShots,
            );
        }
        $prompt = $this->enforceVideoNoSubtitleRule($prompt);
        $context['final_prompt'] = $prompt;

        // 仅当仍为 queued/blocked 时切入 running，避免取消后被 worker 覆盖复活。
        if (!$this->claimVideoJobRunning($job, $context)) {
            return;
        }
        $runNode = WorkflowRunNode::find((int) $job->getAttr('workflow_run_node_id'));
        if ($runNode instanceof WorkflowRunNode && in_array((string) $runNode->getAttr('status'), ['skipped', 'cancelled'], true)) {
            $this->cancelOneVideoJob($job, '视频任务已取消');
            return;
        }
        $shot->save(['status' => 'generating']);
        $this->refreshVideoWorkflowProgress((int) $job->getAttr('workflow_run_node_id'));

        try {
            if ($this->isVideoJobCancelTerminal((int) $job->getAttr('id'))) {
                return;
            }
            $videoUrl = $this->callWorkflowVideoGeneration($model, $prompt, $inputImageUrl, (string) $videoSeconds, $assets, $context);
            $targetJob = $this->resolveCompletableVideoJob($job);
            if (!$targetJob instanceof VideoJob) {
                // 旧 job 已被改分镜 purge/stale，且找不到可回填的新 revision 任务：丢弃避免写脏数据。
                return;
            }
            $this->completeVideoJobWithUrl($targetJob, $model, $videoUrl, '', $this->lastAiRequestLogId ?: null);
        } catch (\Throwable $e) {
            $targetJob = $this->resolveCompletableVideoJob($job);
            if (!$targetJob instanceof VideoJob) {
                return;
            }
            $targetShot = Shot::find((int) $targetJob->getAttr('shot_id'));
            if (!$targetShot instanceof Shot) {
                return;
            }
            $this->failQueuedVideoJob($targetJob, $targetShot, mb_substr($e->getMessage(), 0, 2000));
            throw $e;
        }
    }

    /**
     * 改分镜会 clone+purge 旧 video_jobs：worker 仍持有旧 id。
     * 成功/失败落库前，解析到当前 revision 上可写回的继承任务（同镜、同 provider_task_id 优先）。
     */
    private function resolveCompletableVideoJob(VideoJob $job): ?VideoJob
    {
        $jobId = (int) $job->getAttr('id');
        $fresh = $jobId > 0 ? VideoJob::find($jobId) : null;
        if ($fresh instanceof VideoJob) {
            $status = (string) $fresh->getAttr('status');
            if (!in_array($status, ['cancelled', 'stale'], true)) {
                return $fresh;
            }
        }

        $episodeId = (int) $job->getAttr('episode_id');
        $shotIndex = (int) $job->getAttr('shot_index');
        $currentRevisionId = $this->currentStoryboardRevisionIdForEpisodeId($episodeId);
        if ($episodeId <= 0 || $shotIndex <= 0 || $currentRevisionId <= 0) {
            return null;
        }

        $context = $job->getAttr('request_context_json') ?: [];
        if (!is_array($context)) {
            $context = [];
        }
        $taskId = trim((string) ($context['provider_task_id'] ?? $context['task_id'] ?? ''));

        $candidates = VideoJob::where('episode_id', $episodeId)
            ->where('storyboard_revision_id', $currentRevisionId)
            ->where('shot_index', $shotIndex)
            ->whereIn('status', ['queued', 'blocked', 'running'])
            ->order(['id' => 'desc'])
            ->select();

        $fallback = null;
        foreach ($candidates as $candidate) {
            if (!$candidate instanceof VideoJob) {
                continue;
            }
            $fallback ??= $candidate;
            if ($taskId === '') {
                continue;
            }
            $candidateContext = $candidate->getAttr('request_context_json') ?: [];
            if (!is_array($candidateContext)) {
                continue;
            }
            $candidateTaskId = trim((string) ($candidateContext['provider_task_id'] ?? $candidateContext['task_id'] ?? ''));
            if ($candidateTaskId !== '' && $candidateTaskId === $taskId) {
                return $candidate;
            }
            $migratedFrom = (int) ($candidateContext['migrated_from_video_job_id'] ?? 0);
            if ($migratedFrom > 0 && $migratedFrom === $jobId) {
                return $candidate;
            }
        }

        return $fallback;
    }

    /**
     * 条件切入 running：仅 queued/blocked/running 可成功；已取消/成功/失败则放弃。
     * VideoJobWorker 可能已先标 running，此处用条件 update 写入 context/attempts，取消后 affected=0。
     *
     * @param array<string, mixed> $context
     */
    private function claimVideoJobRunning(VideoJob $job, array $context): bool
    {
        $jobId = (int) $job->getAttr('id');
        $fresh = VideoJob::find($jobId);
        if (!$fresh instanceof VideoJob) {
            return false;
        }
        $status = (string) $fresh->getAttr('status');
        if (in_array($status, ['cancelled', 'stale', 'success', 'failed'], true)) {
            return false;
        }
        if (!in_array($status, ['queued', 'blocked', 'running'], true)) {
            return false;
        }

        $startedAt = $status === 'running' && trim((string) ($fresh->getAttr('started_at') ?? '')) !== ''
            ? (string) $fresh->getAttr('started_at')
            : date('Y-m-d H:i:s');
        $attempts = (int) $fresh->getAttr('attempts') + 1;
        $affected = VideoJob::where('id', $jobId)
            ->whereIn('status', ['queued', 'blocked', 'running'])
            ->update([
                'status' => 'running',
                'attempts' => $attempts,
                'request_context_json' => $context,
                'started_at' => $startedAt,
                'error_message' => '',
            ]);
        if ($affected <= 0) {
            return false;
        }

        $job->setAttr('status', 'running');
        $job->setAttr('attempts', $attempts);
        $job->setAttr('request_context_json', $context);
        $job->setAttr('started_at', $startedAt);
        $job->setAttr('error_message', '');

        return true;
    }

    private function isVideoJobCancelTerminal(int $jobId): bool
    {
        if ($jobId <= 0) {
            return true;
        }
        $fresh = VideoJob::find($jobId);
        if (!$fresh instanceof VideoJob) {
            return true;
        }

        return in_array((string) $fresh->getAttr('status'), ['cancelled', 'stale'], true);
    }

    private function refreshVideoJobVoiceContext(VideoJob $job, array $context, array $assets = []): array
    {
        if ($assets === []) {
            $assets = $job->getAttr('assets_json') ?: [];
            if (!is_array($assets)) {
                $assets = [];
            }
        }

        $voiceAssets = (new VideoVoiceAssetService())->resolveForVideoAssets(
            $assets,
            (int) ($job->getAttr('user_id') ?? 0),
        );
        $context['voice_asset_count'] = count($voiceAssets);
        $context['voice_assets'] = $voiceAssets;
        $context['voice_assets_snapshot_at'] = date('Y-m-d H:i:s');

        return $context;
    }

    /**
     * 执行视频任务前，按资产库当前图片刷新 assets_json / source / input 中的冻结 URL。
     *
     * @param array<int, array<string, mixed>> $assets
     * @return array{assets: array<int, array<string, mixed>>, source_image_url: string, input_image_url: string, changed: bool}
     */
    private function refreshVideoJobAssetImages(VideoJob $job, array $assets, string $sourceImageUrl, string $inputImageUrl): array
    {
        $result = [
            'assets' => $assets,
            'source_image_url' => trim($sourceImageUrl),
            'input_image_url' => trim($inputImageUrl),
            'changed' => false,
        ];
        if ($assets === []) {
            return $result;
        }

        $assetIds = [];
        $versionIds = [];
        foreach ($assets as $item) {
            if (!is_array($item)) {
                continue;
            }
            $assetId = (int) ($item['id'] ?? $item['asset_id'] ?? 0);
            if ($assetId > 0) {
                $assetIds[$assetId] = true;
            }
            $versionId = isset($item['asset_image_version_id']) && $item['asset_image_version_id'] !== null
                ? (int) $item['asset_image_version_id']
                : 0;
            if ($versionId > 0) {
                $versionIds[$versionId] = true;
            }
        }
        if ($assetIds === []) {
            return $result;
        }

        $assetsById = $this->loadVideoJobAssetsById(array_keys($assetIds));
        if ($assetsById === []) {
            return $result;
        }

        $versionsById = $versionIds === []
            ? []
            : $this->loadVideoJobAssetImageVersionsById(array_keys($versionIds));

        $visualStyle = $this->seriesVisualStyleForId((int) ($job->getAttr('series_id') ?? 0));
        $refreshed = VideoAssetImageRefreshService::refreshAssets($assets, $assetsById, $versionsById, $visualStyle);
        $remapped = VideoAssetImageRefreshService::remapJobImageUrls(
            $result['source_image_url'],
            $result['input_image_url'],
            $refreshed['url_map'],
        );

        $result['assets'] = $refreshed['assets'];
        $result['source_image_url'] = $remapped['source_image_url'];
        $result['input_image_url'] = $remapped['input_image_url'];
        $result['changed'] = $refreshed['changed'] || $remapped['changed'];

        if ($result['changed']) {
            $job->save([
                'assets_json' => $result['assets'],
                'source_image_url' => $result['source_image_url'],
                'input_image_url' => $result['input_image_url'],
            ]);
            Log::info('video job asset image urls refreshed before request', [
                'video_job_id' => (int) $job->getAttr('id'),
                'url_map' => $refreshed['url_map'],
            ]);
        }

        return $result;
    }

    /**
     * @param list<int> $assetIds
     * @return array<int, array{id:int,type:string,name:string,description:string,image_prompt:string,images:list<array<string,mixed>>}>
     */
    private function loadVideoJobAssetsById(array $assetIds): array
    {
        $assetIds = array_values(array_unique(array_filter(array_map('intval', $assetIds), static fn (int $id): bool => $id > 0)));
        if ($assetIds === []) {
            return [];
        }

        $rows = Asset::with(['images'])->whereIn('id', $assetIds)->select();
        $mapped = [];
        foreach ($rows as $asset) {
            if (!$asset instanceof Asset) {
                continue;
            }
            $images = [];
            foreach ($asset->images as $image) {
                if (!$image instanceof AssetImage) {
                    continue;
                }
                $images[] = [
                    'id' => (int) $image->getAttr('id'),
                    'view_type' => (string) $image->getAttr('view_type'),
                    'reference_role' => (string) ($image->getAttr('reference_role') ?? 'view'),
                    'variant_name' => (string) ($image->getAttr('variant_name') ?? ''),
                    'reference_key' => (string) ($image->getAttr('reference_key') ?? ''),
                    'url' => trim((string) $image->getAttr('url')),
                    'toapis_asset_url' => trim((string) ($image->getAttr('toapis_asset_url') ?? '')),
                    'toapis_status' => trim((string) ($image->getAttr('toapis_status') ?? '')),
                ];
            }
            $mapped[(int) $asset->getAttr('id')] = [
                'id' => (int) $asset->getAttr('id'),
                'type' => (string) $asset->getAttr('type'),
                'name' => (string) $asset->getAttr('name'),
                'description' => (string) $asset->getAttr('description'),
                'image_prompt' => (string) ($asset->getAttr('image_prompt') ?? ''),
                'series_id' => (int) $asset->getAttr('series_id'),
                'images' => $images,
            ];
        }

        return $mapped;
    }

    /**
     * @param list<int> $versionIds
     * @return array<int, array{id:int,asset_id:int,asset_image_id:int,url:string,is_selected:bool}>
     */
    private function loadVideoJobAssetImageVersionsById(array $versionIds): array
    {
        $versionIds = array_values(array_unique(array_filter(array_map('intval', $versionIds), static fn (int $id): bool => $id > 0)));
        if ($versionIds === []) {
            return [];
        }

        $rows = AssetImageVersion::whereIn('id', $versionIds)->select();
        $mapped = [];
        foreach ($rows as $version) {
            if (!$version instanceof AssetImageVersion) {
                continue;
            }
            $mapped[(int) $version->getAttr('id')] = [
                'id' => (int) $version->getAttr('id'),
                'asset_id' => (int) $version->getAttr('asset_id'),
                'asset_image_id' => (int) ($version->getAttr('asset_image_id') ?? 0),
                'url' => trim((string) $version->getAttr('url')),
                'is_selected' => (bool) $version->getAttr('is_selected'),
            ];
        }

        return $mapped;
    }

    private function completeVideoJobWithUrl(VideoJob $job, ModelConfig $model, string $videoUrl, string $endFrameUrl = '', ?int $aiRequestLogId = null): void
    {
        $videoUrl = trim($videoUrl);
        if ($videoUrl === '') {
            return;
        }

        $jobId = (int) $job->getAttr('id');
        $fresh = VideoJob::find($jobId);
        if ($fresh instanceof VideoJob) {
            $job = $fresh;
        }
        $status = (string) $job->getAttr('status');
        if (in_array($status, ['cancelled', 'stale'], true)) {
            return;
        }
        if ($status === 'success' && trim((string) $job->getAttr('video_url')) === $videoUrl) {
            return;
        }

        $shot = Shot::find((int) $job->getAttr('shot_id'));
        if (!$shot instanceof Shot) {
            return;
        }
        $jobRevisionId = (int) ($job->getAttr('storyboard_revision_id') ?? 0);
        $shotRevisionId = (int) ($shot->getAttr('storyboard_revision_id') ?? 0);
        $currentRevisionId = $this->currentStoryboardRevisionIdForEpisodeId((int) $job->getAttr('episode_id'));
        $isCurrentStoryboardRevision = $jobRevisionId > 0
            && $jobRevisionId === $shotRevisionId
            && $jobRevisionId === $currentRevisionId;

        $context = $job->getAttr('request_context_json') ?: [];
        if (!is_array($context)) {
            $context = [];
        }
        $context = $this->videoJobMediaContext($job, $context);
        $modelOptions = $model->getAttr('options') ?: [];
        if (!is_array($modelOptions)) {
            $modelOptions = [];
        }
        $downloadHeaders = $this->isMini23VideoEndpoint((string) $model->getAttr('endpoint'), $modelOptions)
            && trim((string) $model->getAttr('api_key')) !== ''
            ? ['Authorization: Bearer ' . trim((string) $model->getAttr('api_key'))]
            : [];
        try {
            $videoUrl = $this->persistGeneratedVideoUrl($videoUrl, $context, $downloadHeaders);
        } catch (\Throwable $persistError) {
            throw new \RuntimeException(mb_substr($persistError->getMessage(), 0, 2000), 0, $persistError);
        }

        $chainShots = (bool) $job->getAttr('chain_shots');
        $sourceImageUrl = trim((string) $job->getAttr('source_image_url'));
        $inputImageUrl = trim((string) $job->getAttr('input_image_url')) ?: $sourceImageUrl;
        if ($endFrameUrl === '') {
            $needsNextShotChain = $chainShots && (int) $job->getAttr('shot_index') < (int) $job->getAttr('total_shots');
            $endFrameUrl = $this->extractAndUploadVideoEndFrame(
                $videoUrl,
                $needsNextShotChain,
                (string) $job->getAttr('node_label'),
                (int) $job->getAttr('shot_index'),
                $this->mediaStorageContext([
                    'source' => 'video_end_frame',
                    'user_id' => (int) $job->getAttr('user_id'),
                    'series_id' => (int) $job->getAttr('series_id'),
                    'episode_id' => (int) $job->getAttr('episode_id'),
                    'shot_index' => (int) $job->getAttr('shot_index'),
                    'node_label' => (string) $job->getAttr('node_label'),
                    'video_job_id' => (int) $job->getAttr('id'),
                ], 'video_end_frame')
            );
        }

        $isCandidateMedia = (bool) ($context['candidate_media'] ?? false);
        if ($isCandidateMedia) {
            $shot->save([
                'status' => 'done',
                'image_url' => $shot->getAttr('image_url') ?: $sourceImageUrl,
            ]);
        } elseif ($isCurrentStoryboardRevision) {
            $shot->save([
                'status' => 'done',
                'image_url' => $shot->getAttr('image_url') ?: $sourceImageUrl,
                'video_url' => $videoUrl,
                'video_end_frame_url' => $endFrameUrl,
            ]);
        }

        $parentVersionId = 0;
        if ($chainShots && (int) $job->getAttr('shot_index') > 1) {
            $parentVersionId = $this->videoVersionIdForVideoJob((int) ($job->getAttr('depends_on_job_id') ?? 0));
            if ($parentVersionId <= 0) {
                $parentVersionId = $this->selectedVideoVersionIdForShotIndex(
                    (int) $job->getAttr('episode_id'),
                    $jobRevisionId,
                    (int) $job->getAttr('shot_index') - 1,
                );
            }
        }

        // 落库成功前再确认一次：取消必须压过完成，禁止复活。
        if ($this->isVideoJobCancelTerminal($jobId)) {
            return;
        }

        $this->createShotMediaVersion($shot, 'video', $videoUrl, [
            'workflow_run_node_id' => (int) $job->getAttr('workflow_run_node_id'),
            'storyboard_revision_id' => $jobRevisionId,
            'video_job_id' => (int) $job->getAttr('id'),
            'parent_version_id' => $parentVersionId,
            'model_config_id' => (int) $model->getAttr('id'),
            'poster_url' => $sourceImageUrl,
            'end_frame_url' => $endFrameUrl,
            'prompt' => (string) ($context['final_prompt'] ?? $job->getAttr('node_prompt')),
            'source' => $isCandidateMedia ? 'rerun' : 'workflow',
            'is_selected' => !$isCandidateMedia && $isCurrentStoryboardRevision,
            'ai_request_log_id' => $aiRequestLogId,
            'meta_json' => [
                'node_label' => (string) $job->getAttr('node_label'),
                'node_id' => (string) $job->getAttr('node_id'),
                'shot_index' => (int) $job->getAttr('shot_index'),
                'input_image_url' => $inputImageUrl,
            ],
        ]);

        $affected = VideoJob::where('id', $jobId)
            ->whereNotIn('status', ['cancelled', 'stale'])
            ->update([
                'status' => 'success',
                'video_url' => $videoUrl,
                'end_frame_url' => $endFrameUrl,
                'ai_request_log_id' => $aiRequestLogId,
                'error_message' => '',
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
        if ($affected <= 0) {
            return;
        }

        $job->setAttr('status', 'success');
        $job->setAttr('video_url', $videoUrl);
        $job->setAttr('end_frame_url', $endFrameUrl);

        $this->releaseNextVideoJobs((int) $job->getAttr('id'));
        $this->refreshVideoWorkflowProgress((int) $job->getAttr('workflow_run_node_id'));
    }

    private function isVideoJobForCurrentStoryboardRevision(VideoJob $job): bool
    {
        $storyboardRevisionId = (int) ($job->getAttr('storyboard_revision_id') ?? 0);
        if ($storyboardRevisionId <= 0) {
            return false;
        }

        return $storyboardRevisionId === $this->currentStoryboardRevisionIdForEpisodeId((int) $job->getAttr('episode_id'));
    }

    private function failQueuedVideoJob(VideoJob $job, Shot $shot, string $message): void
    {
        $jobId = (int) $job->getAttr('id');
        if ($this->isVideoJobCancelTerminal($jobId)) {
            return;
        }

        $context = $job->getAttr('request_context_json') ?: [];
        $isCandidateMedia = is_array($context) && (bool) ($context['candidate_media'] ?? false);
        $shot->save([
            'status' => $isCandidateMedia && trim((string) $shot->getAttr('video_url')) !== '' ? 'done' : 'pending',
        ]);
        $affected = VideoJob::where('id', $jobId)
            ->whereNotIn('status', ['cancelled', 'stale', 'success'])
            ->update([
                'status' => 'failed',
                'error_message' => $message,
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
        if ($affected <= 0) {
            return;
        }
        if ($this->isTerminalVideoProviderQuotaError($message)) {
            $this->failSiblingQueuedVideoJobsForTerminalError($job, $message);
        }
        $this->refreshVideoWorkflowProgress((int) $job->getAttr('workflow_run_node_id'), $message);
    }

    private function recoverVideoJobWithPersistedRemoteUrl(VideoJob $job): void
    {
        if ((string) $job->getAttr('status') !== 'failed') {
            return;
        }

        $videoUrl = trim((string) $job->getAttr('video_url'));
        if ($videoUrl === '') {
            return;
        }

        $errorMessage = (string) $job->getAttr('error_message');
        if (!str_contains($errorMessage, '下载远端媒体失败')) {
            return;
        }

        try {
            $model = ModelConfig::find((int) $job->getAttr('model_config_id'));
            if (!$model instanceof ModelConfig) {
                return;
            }

            $this->completeVideoJobWithUrl(
                $job,
                $model,
                $videoUrl,
                (string) $job->getAttr('end_frame_url'),
                (int) ($job->getAttr('ai_request_log_id') ?? 0) ?: null,
            );
        } catch (\Throwable $e) {
            Log::warning('[VideoJob] recover persisted remote video failed: ' . $e->getMessage(), [
                'video_job_id' => (int) $job->getAttr('id'),
                'workflow_run_node_id' => (int) $job->getAttr('workflow_run_node_id'),
            ]);
        }
    }

    private function videoJobMediaContext(VideoJob $job, array $context): array
    {
        $context['source'] = trim((string) ($context['source'] ?? '')) ?: 'episode_workflow_video';
        $context['user_id'] = (int) ($context['user_id'] ?? 0) ?: (int) $job->getAttr('user_id');
        $context['series_id'] = (int) ($context['series_id'] ?? 0) ?: (int) $job->getAttr('series_id');
        $context['episode_id'] = (int) ($context['episode_id'] ?? 0) ?: (int) $job->getAttr('episode_id');
        $context['shot_index'] = (int) ($context['shot_index'] ?? 0) ?: (int) $job->getAttr('shot_index');
        $context['video_job_id'] = (int) ($context['video_job_id'] ?? 0) ?: (int) $job->getAttr('id');
        $context['node_label'] = trim((string) ($context['node_label'] ?? '')) ?: (string) $job->getAttr('node_label');

        return $context;
    }

    private function isTerminalVideoProviderQuotaError(string $message): bool
    {
        $message = mb_strtolower(trim($message));
        if ($message === '') {
            return false;
        }

        foreach ([
            'quota_not_enough',
            'insufficient_quota',
            'quota exceeded',
            'quota_exceeded',
            '余额不足',
            '配额不足',
            // 平台积分（CreditService::assertAffordable）文案
            '积分不足',
            '请联系管理员充值',
            'insufficient credit',
            'not enough credit',
        ] as $needle) {
            if (str_contains($message, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    /**
     * 视频入队中途失败时，把本批已创建但仍在排队的任务标失败，镜头退回 pending。
     *
     * @param array<int, int> $jobIds
     */
    private function failCreatedVideoJobsOnEnqueueError(array $jobIds, string $message): void
    {
        $jobIds = array_values(array_unique(array_filter(array_map('intval', $jobIds), static fn (int $id): bool => $id > 0)));
        if ($jobIds === []) {
            return;
        }

        $message = trim($message) !== '' ? $message : '视频任务入队失败';
        $runNodeIds = [];
        foreach ($jobIds as $jobId) {
            $job = VideoJob::find($jobId);
            if (!$job instanceof VideoJob) {
                continue;
            }
            if (!in_array((string) $job->getAttr('status'), ['queued', 'blocked', 'running'], true)) {
                continue;
            }
            $job->save([
                'status' => 'failed',
                'error_message' => $message,
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
            $runNodeId = (int) $job->getAttr('workflow_run_node_id');
            if ($runNodeId > 0) {
                $runNodeIds[$runNodeId] = true;
            }
            $shot = Shot::find((int) $job->getAttr('shot_id'));
            if ($shot instanceof Shot && trim((string) $shot->getAttr('video_url')) === '') {
                $shot->save(['status' => 'pending']);
            }
        }
        foreach (array_keys($runNodeIds) as $runNodeId) {
            $this->refreshVideoWorkflowProgress((int) $runNodeId, $message);
        }
    }

    private function failSiblingQueuedVideoJobsForTerminalError(VideoJob $job, string $message): void
    {
        $runNodeId = (int) $job->getAttr('workflow_run_node_id');
        $storyboardRevisionId = (int) ($job->getAttr('storyboard_revision_id') ?? 0);
        if ($runNodeId <= 0) {
            return;
        }

        $siblings = VideoJob::where('workflow_run_node_id', $runNodeId)
            ->whereIn('status', ['queued', 'blocked'])
            ->select();
        foreach ($siblings as $sibling) {
            if (!$sibling instanceof VideoJob || (int) $sibling->getAttr('id') === (int) $job->getAttr('id')) {
                continue;
            }
            // 不同版本的旧任务也不能继续排队；积分不足是当前节点的终止性错误。
            if ($storyboardRevisionId > 0 && (int) ($sibling->getAttr('storyboard_revision_id') ?? 0) > 0
                && (int) $sibling->getAttr('storyboard_revision_id') !== $storyboardRevisionId) {
                continue;
            }

            $sibling->save([
                'status' => 'failed',
                'error_message' => $message,
                'finished_at' => date('Y-m-d H:i:s'),
            ]);

            $siblingShot = Shot::find((int) $sibling->getAttr('shot_id'));
            if ($siblingShot instanceof Shot) {
                $siblingShot->save([
                    'status' => trim((string) $siblingShot->getAttr('video_url')) !== '' ? 'done' : 'pending',
                ]);
            }
        }
    }

    private function releaseNextVideoJobs(int $jobId): void
    {
        $job = VideoJob::find($jobId);
        if ($job instanceof VideoJob) {
            $runNode = WorkflowRunNode::find((int) $job->getAttr('workflow_run_node_id'));
            if ($runNode instanceof WorkflowRunNode && (string) $runNode->getAttr('status') === 'cancelled') {
                return;
            }
        }

        VideoJob::where('depends_on_job_id', $jobId)
            ->where('status', 'blocked')
            ->update(['status' => 'queued', 'error_message' => '']);
    }

    private function cancelVideoJobsForRunNode(int $runNodeId, bool $markNodeCancelled): int
    {
        if ($runNodeId <= 0) {
            return 0;
        }

        $jobs = VideoJob::where('workflow_run_node_id', $runNodeId)
            ->whereIn('status', ['queued', 'blocked', 'running'])
            ->select();
        $cancelled = 0;
        foreach ($jobs as $job) {
            if (!$job instanceof VideoJob) {
                continue;
            }
            if ($this->cancelOneVideoJob($job, '视频任务已取消')) {
                $cancelled++;
            }
        }

        if ($markNodeCancelled) {
            $runNode = WorkflowRunNode::find($runNodeId);
            if ($runNode instanceof WorkflowRunNode) {
                $output = $runNode->getAttr('output_json') ?: [];
                if (!is_array($output)) {
                    $output = [];
                }
                $output['status'] = 'cancelled';
                $output['cancelled_count'] = $cancelled;
                $runNode->save([
                    'status' => 'skipped',
                    'output_json' => $output,
                    'raw_output' => json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '',
                    'error_message' => '视频任务已取消',
                    'finished_at' => date('Y-m-d H:i:s'),
                ]);
                RedisCache::bumpVersion('workflow_run:' . (int) $runNode->getAttr('run_id'));
            }
        }

        return $cancelled;
    }

    /**
     * 取消单个视频任务及其仍在排队/等待的下游依赖任务。已 success 的任务不会被改动。
     */
    private function cancelVideoJobAndDependents(VideoJob $job, string $message): int
    {
        $cancelled = 0;
        $queue = [(int) $job->getAttr('id')];
        $seen = [];

        while ($queue !== []) {
            $currentId = (int) array_shift($queue);
            if ($currentId <= 0 || isset($seen[$currentId])) {
                continue;
            }
            $seen[$currentId] = true;

            $current = $currentId === (int) $job->getAttr('id')
                ? $job
                : VideoJob::find($currentId);
            if (!$current instanceof VideoJob) {
                continue;
            }
            if ($this->cancelOneVideoJob($current, $message)) {
                $cancelled++;
            }

            $dependents = VideoJob::where('depends_on_job_id', $currentId)
                ->whereIn('status', ['queued', 'blocked', 'running'])
                ->select();
            foreach ($dependents as $dependent) {
                if ($dependent instanceof VideoJob) {
                    $queue[] = (int) $dependent->getAttr('id');
                }
            }
        }

        return $cancelled;
    }

    private function cancelOneVideoJob(VideoJob $job, string $message): bool
    {
        $jobId = (int) $job->getAttr('id');
        $affected = VideoJob::where('id', $jobId)
            ->whereIn('status', ['queued', 'blocked', 'running'])
            ->update([
                'status' => 'cancelled',
                'error_message' => $message,
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
        if ($affected <= 0) {
            return false;
        }

        $job->setAttr('status', 'cancelled');
        $job->setAttr('error_message', $message);
        $job->setAttr('finished_at', date('Y-m-d H:i:s'));

        $shot = Shot::find((int) $job->getAttr('shot_id'));
        if ($shot instanceof Shot && trim((string) $shot->getAttr('video_url')) === '') {
            $shot->save(['status' => 'pending']);
        }

        return true;
    }

    private function invalidateEpisodeWorkflowNodesAfter(int $runId, int $sort): void
    {
        if ($runId <= 0) {
            return;
        }

        // 先冻结当前分镜版本，避免后续清空 current_storyboard_revision_id 后，
        // markVideoOutputsStaleForRunNode() 再也找不到需要失效的视频任务。
        $storyboardRevisionId = $this->currentStoryboardRevisionIdForRunId($runId);
        $nodes = WorkflowRunNode::where('run_id', $runId)
            ->where('sort', '>', $sort)
            ->select();
        foreach ($nodes as $node) {
            if ($node instanceof WorkflowRunNode && $this->isStoryboardRevisionNode((string) $node->getAttr('label'), (string) $node->getAttr('kind'))) {
                $this->clearCurrentStoryboardRevisionForRunId($runId);
                break;
            }
        }
        foreach ($nodes as $node) {
            if (!$node instanceof WorkflowRunNode) {
                continue;
            }
            $nextStatus = 'queued';
            $errorMessage = '';
            if ((string) $node->getAttr('kind') === 'video') {
                $this->cancelVideoJobsForRunNode((int) $node->getAttr('id'), false);
                $this->markVideoOutputsStaleForRunNode((int) $node->getAttr('id'), $storyboardRevisionId);
                $nextStatus = 'stale';
                $errorMessage = '上游节点已变更，需要重新生成';
            } elseif ((string) $node->getAttr('kind') === 'image') {
                $this->markImageOutputsStaleForRunNode((int) $node->getAttr('id'));
            }
            $node->save([
                'status' => 'queued',
                'raw_output' => '',
                'output_json' => [],
                'error_message' => '',
                'started_at' => null,
                'finished_at' => null,
                'duration_ms' => 0,
            ]);
            $this->syncEpisodeWorkflowStateForRunNode($node, $nextStatus, [], '', $errorMessage);
        }
    }

    /**
     * 单镜头视频重生成只影响最终输出节点，不重新计算或清空上游节点。
     */
    private function invalidateEpisodeOutputNodesAfterVideoShotRerun(int $runId, int $videoSort): void
    {
        if ($runId <= 0) {
            return;
        }

        $nodes = WorkflowRunNode::where('run_id', $runId)
            ->where('sort', '>', $videoSort)
            ->select();
        foreach ($nodes as $node) {
            if (!$node instanceof WorkflowRunNode || (string) $node->getAttr('kind') !== 'output') {
                continue;
            }

            $node->save([
                'status' => 'queued',
                'raw_output' => '',
                'output_json' => [],
                'error_message' => '',
                'started_at' => null,
                'finished_at' => null,
                'duration_ms' => 0,
            ]);
            $this->syncEpisodeWorkflowStateForRunNode($node, 'queued', [], '', '');
        }
    }

    /**
     * 返回目标节点之前最近的分镜节点排序，用于阻止错误的运行节点映射触碰上游。
     *
     * @param array<int, array<string, mixed>> $orderedNodes
     */
    private function lastStoryboardNodeSortBefore(array $orderedNodes, string $targetNodeId): int
    {
        $sort = 0;
        foreach (array_values($orderedNodes) as $index => $node) {
            if (!is_array($node)) {
                continue;
            }
            if ((string) ($node['id'] ?? '') === $targetNodeId) {
                break;
            }

            $label = trim((string) ($node['label'] ?? $node['data']['label'] ?? ''));
            $kind = trim((string) ($node['data']['kind'] ?? $node['kind'] ?? ''));
            if ($this->isStoryboardRevisionNode($label, $kind)) {
                $sort = $index + 1;
            }
        }

        return $sort;
    }

    private function invalidateEpisodeWorkflowNodesAfterSingleStoryboardShotUpdate(
        int $runId,
        int $sort,
        int $storyboardRevisionId,
        int $changedShotIndex,
    ): void {
        $this->invalidateEpisodeWorkflowNodesAfterStoryboardShotUpdates(
            $runId,
            $sort,
            $storyboardRevisionId,
            [$changedShotIndex],
        );
    }

    /** @param array<int, int> $changedShotIndexes */
    private function invalidateEpisodeWorkflowNodesAfterStoryboardShotUpdates(
        int $runId,
        int $sort,
        int $storyboardRevisionId,
        array $changedShotIndexes,
    ): void {
        $changedIndexSet = $this->normalizeChangedShotIndexes($changedShotIndexes);
        if ($runId <= 0 || $storyboardRevisionId <= 0 || $changedIndexSet === []) {
            return;
        }

        $nodes = WorkflowRunNode::where('run_id', $runId)
            ->where('sort', '>', $sort)
            ->select();
        foreach ($nodes as $node) {
            if (!$node instanceof WorkflowRunNode) {
                continue;
            }

            $kind = (string) $node->getAttr('kind');
            if ($kind === 'video') {
                foreach (array_keys($changedIndexSet) as $changedShotIndex) {
                    $shot = Shot::where('episode_id', $this->episodeIdForWorkflowRun($runId))
                        ->where('storyboard_revision_id', $storyboardRevisionId)
                        ->where('index', $changedShotIndex)
                        ->find();
                    if ($shot instanceof Shot) {
                        $this->markShotVideoJobStale((int) $node->getAttr('id'), $shot, '当前镜头分镜已修改，这段视频需要重新生成');
                    }
                }
                $node->save([
                    'status' => 'queued',
                    'raw_output' => '',
                    'output_json' => [],
                    'error_message' => '',
                    'started_at' => null,
                    'finished_at' => null,
                    'duration_ms' => 0,
                ]);
                $episodeId = $this->episodeIdForWorkflowRun($runId);
                $inFlight = $episodeId > 0
                    ? (int) VideoJob::where('episode_id', $episodeId)
                        ->where('storyboard_revision_id', $storyboardRevisionId)
                        ->whereIn('status', ['queued', 'blocked', 'running'])
                        ->count()
                    : 0;
                // 仍有未改镜头在跑时，不要整节点标 stale，否则刷新跳过供应商回填，镜2会永久「生成中」。
                $this->syncEpisodeWorkflowStateForRunNode(
                    $node,
                    $inFlight > 0 ? 'running' : 'stale',
                    [],
                    '',
                    count($changedIndexSet) . ' 个镜头分镜已修改，需要重新生成对应视频',
                );
                if ($inFlight > 0) {
                    $this->refreshVideoWorkflowProgress((int) $node->getAttr('id'));
                }
                continue;
            }

            if ($kind === 'output') {
                $node->save([
                    'status' => 'queued',
                    'raw_output' => '',
                    'output_json' => [],
                    'error_message' => '',
                    'started_at' => null,
                    'finished_at' => null,
                    'duration_ms' => 0,
                ]);
                $this->syncEpisodeWorkflowStateForRunNode($node, 'queued', [], '', '');
            }
        }
    }

    private function markVideoOutputsStaleForRunNode(int $runNodeId, ?int $storyboardRevisionId = null): void
    {
        if ($runNodeId <= 0) {
            return;
        }

        $storyboardRevisionId ??= $this->currentStoryboardRevisionIdForRunNode($runNodeId);
        if ($storyboardRevisionId <= 0) {
            return;
        }

        $jobs = VideoJob::where('workflow_run_node_id', $runNodeId)
            ->where('storyboard_revision_id', $storyboardRevisionId)
            ->select();
        $shotIds = [];
        foreach ($jobs as $job) {
            if (!$job instanceof VideoJob) {
                continue;
            }
            $shotId = (int) $job->getAttr('shot_id');
            if ($shotId > 0) {
                $shotIds[] = $shotId;
            }
            $status = (string) $job->getAttr('status');
            if (in_array($status, ['success', 'failed', 'stale'], true)) {
                $job->save([
                    'status' => 'stale',
                    'video_url' => '',
                    'end_frame_url' => '',
                    'error_message' => '上游节点重新执行，这段视频需要重新生成',
                    'started_at' => null,
                    'finished_at' => null,
                ]);
            }
        }

        $shotIds = array_values(array_unique(array_filter($shotIds, static fn (int $id): bool => $id > 0)));
        if ($shotIds === []) {
            return;
        }

        ShotMediaVersion::whereIn('shot_id', $shotIds)
            ->where('media_type', 'video')
            ->update(['is_selected' => 0]);

        Shot::whereIn('id', $shotIds)->update([
            'status' => 'pending',
            'video_url' => '',
            'video_end_frame_url' => '',
        ]);
    }

    private function markImageOutputsStaleForRunNode(int $runNodeId): void
    {
        if ($runNodeId <= 0) {
            return;
        }

        $storyboardRevisionId = $this->currentStoryboardRevisionIdForRunNode($runNodeId);
        if ($storyboardRevisionId <= 0) {
            return;
        }

        $shotIds = ShotMediaVersion::where('workflow_run_node_id', $runNodeId)
            ->where('storyboard_revision_id', $storyboardRevisionId)
            ->where('media_type', 'image')
            ->column('shot_id');
        $shotIds = array_values(array_unique(array_map('intval', is_array($shotIds) ? $shotIds : [])));
        if ($shotIds === []) {
            return;
        }

        ShotMediaVersion::whereIn('shot_id', $shotIds)
            ->where('media_type', 'image')
            ->update(['is_selected' => 0]);

        foreach (Shot::whereIn('id', $shotIds)->select() as $shot) {
            if (!$shot instanceof Shot) {
                continue;
            }
            $data = ['image_url' => ''];
            if (trim((string) $shot->getAttr('video_url')) === '') {
                $data['status'] = 'pending';
            }
            $shot->save($data);
        }
    }

    /**
     * Worker / external entry: refresh video node progress (cancelled is terminal).
     */
    public function syncVideoWorkflowProgress(int $runNodeId, string $errorMessage = ''): void
    {
        $this->refreshVideoWorkflowProgress($runNodeId, $errorMessage);
    }

    private function refreshVideoWorkflowProgress(int $runNodeId, string $errorMessage = ''): void
    {
        if ($runNodeId <= 0) {
            return;
        }
        $runNode = WorkflowRunNode::find($runNodeId);
        if (!$runNode instanceof WorkflowRunNode) {
            return;
        }

        $jobs = $this->latestVideoJobsForRunNode($runNodeId);
        if ($jobs === []) {
            // per-shot path may filter out all cancelled/stale jobs; unstick if still running.
            $this->unstickVideoNodeIfOnlyTerminalJobs($runNode, $errorMessage);
            return;
        }
        $total = count($jobs);
        $success = 0;
        $failed = 0;
        $stale = 0;
        $active = 0;
        $blocked = 0;
        $cancelled = 0;
        $shots = [];
        foreach ($jobs as $job) {
            if (!$job instanceof VideoJob) {
                continue;
            }
            $this->recoverVideoJobWithPersistedRemoteUrl($job);
            $job->refresh();
            $status = (string) $job->getAttr('status');
            if ($status === 'success') {
                $success++;
            } elseif ($status === 'failed') {
                $failed++;
            } elseif ($status === 'stale') {
                $stale++;
            } elseif ($status === 'cancelled') {
                $cancelled++;
            } elseif (in_array($status, ['queued', 'running'], true)) {
                $active++;
            } elseif ($status === 'blocked') {
                $blocked++;
            }
            $context = $job->getAttr('request_context_json') ?: [];
            if (!is_array($context)) {
                $context = [];
            }
            $shots[] = [
                'job_id' => (int) $job->getAttr('id'),
                'shot_id' => (int) $job->getAttr('shot_id'),
                'storyboard_revision_id' => (int) ($job->getAttr('storyboard_revision_id') ?? 0) ?: null,
                'index' => (int) $job->getAttr('shot_index'),
                'status' => $status,
                'chain_shots' => (bool) $job->getAttr('chain_shots'),
                'description' => trim((string) (($job->getAttr('shot_data_json') ?: [])['description'] ?? '')),
                'prompt' => (string) $job->getAttr('node_prompt'),
                'final_prompt' => (string) ($context['final_prompt'] ?? ''),
                'image_url' => (string) $job->getAttr('source_image_url'),
                'input_image_url' => (string) $job->getAttr('input_image_url'),
                'video_url' => (string) $job->getAttr('video_url'),
                'end_frame_url' => (string) $job->getAttr('end_frame_url'),
                'error_message' => (string) $job->getAttr('error_message'),
                'ai_request_log_id' => (int) ($job->getAttr('ai_request_log_id') ?? 0) ?: null,
            ];
        }

        // cancelled is terminal and must never fall through to running.
        $decision = VideoWorkflowProgress::decideStatus($total, $success, $failed, $stale, $active, $blocked, $cancelled);
        $nodeStatus = $decision['node'];
        $stateStatus = $decision['state'];
        if ($errorMessage === '' && $stale > 0 && $failed === 0 && $active === 0) {
            $errorMessage = '部分镜头因前序版本变化需要手动重新生成，请逐个点击重新生成。';
        }
        if ($errorMessage === '' && $cancelled > 0 && $success === 0 && $failed === 0 && $stale === 0 && $active === 0) {
            $errorMessage = '视频任务已取消';
        }
        if ($nodeStatus === 'running' || $nodeStatus === 'success') {
            $errorMessage = '';
        }
        $output = $runNode->getAttr('output_json') ?: [];
        if (!is_array($output)) {
            $output = [];
        }
        $output['async'] = true;
        $output['storyboard_revision_id'] = $this->currentStoryboardRevisionIdForRunNode($runNodeId) ?: null;
        $output['status'] = $nodeStatus;
        $output['shot_count'] = $total;
        $output['finished_count'] = $success;
        $output['failed_count'] = $failed;
        $output['cancelled_count'] = $cancelled;
        $output['shots'] = $shots;

        $runNode->save([
            'status' => $nodeStatus,
            'output_json' => $output,
            'raw_output' => json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '',
            'error_message' => $errorMessage,
            'finished_at' => $nodeStatus === 'success' || $nodeStatus === 'failed' ? date('Y-m-d H:i:s') : null,
        ]);
        $this->syncEpisodeWorkflowStateForRunNode(
            $runNode,
            $stateStatus,
            $output,
            json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '',
            $errorMessage,
        );

        $runId = (int) $runNode->getAttr('run_id');
        $run = WorkflowRun::find($runId);
        $runPayload = $run instanceof WorkflowRun ? ($run->getAttr('payload_json') ?: []) : [];
        $isAutoVideoAwaiting = $run instanceof WorkflowRun
            && is_array($runPayload)
            && (string) ($runPayload['execution_mode'] ?? '') === 'auto'
            && (string) $run->getAttr('status') === 'waiting_async'
            && (string) ($runPayload['awaiting_kind'] ?? '') === 'video'
            && (string) ($runPayload['awaiting_node_id'] ?? '') === (string) $runNode->getAttr('workflow_node_id');
        if ($nodeStatus === 'success') {
            $this->refreshEpisodeWorkflowRunProgress($runId);
            if ($isAutoVideoAwaiting) {
                unset($runPayload['awaiting_node_id'], $runPayload['awaiting_kind']);
                WorkflowRun::where('id', $runId)->update([
                    'status' => 'queued',
                    'payload_json' => $runPayload,
                    'current_node_label' => '',
                    'error_message' => '',
                    'finished_at' => null,
                ]);
            }
        } elseif ($nodeStatus === 'failed') {
            if ($run instanceof WorkflowRun && (string) $run->getAttr('status') !== 'cancelled') {
                WorkflowRun::where('id', $runId)->update([
                    'status' => 'failed',
                    'error_message' => $errorMessage,
                    'finished_at' => date('Y-m-d H:i:s'),
                ]);
            }
        } else {
            if ($run instanceof WorkflowRun && (string) $run->getAttr('status') !== 'cancelled') {
                WorkflowRun::where('id', $runId)->update([
                    'status' => 'running',
                    'current_node_label' => (string) $runNode->getAttr('label'),
                    'error_message' => '',
                ]);
            }
        }

        RedisCache::bumpVersion('workflow_run:' . $runId);
    }

    /**
     * per-shot query excludes cancelled/stale; if that yields [] while node is still running, mark failed.
     */
    private function unstickVideoNodeIfOnlyTerminalJobs(WorkflowRunNode $runNode, string $errorMessage = ''): void
    {
        if ((string) $runNode->getAttr('status') !== 'running') {
            return;
        }
        $runNodeId = (int) $runNode->getAttr('id');
        $revisionId = $this->currentStoryboardRevisionIdForRunNode($runNodeId);
        if ($revisionId <= 0) {
            return;
        }

        $activeOrSuccess = (int) VideoJob::where('workflow_run_node_id', $runNodeId)
            ->where('storyboard_revision_id', $revisionId)
            ->whereIn('status', ['queued', 'running', 'blocked', 'success'])
            ->count();
        if ($activeOrSuccess > 0) {
            return;
        }

        $terminal = (int) VideoJob::where('workflow_run_node_id', $runNodeId)
            ->where('storyboard_revision_id', $revisionId)
            ->whereIn('status', ['cancelled', 'failed', 'stale'])
            ->count();
        if ($terminal <= 0) {
            return;
        }

        if ($errorMessage === '') {
            $errorMessage = '视频任务已取消';
        }
        $output = $runNode->getAttr('output_json') ?: [];
        if (!is_array($output)) {
            $output = [];
        }
        $output['async'] = true;
        $output['status'] = 'failed';
        $output['cancelled_count'] = $terminal;
        $runNode->save([
            'status' => 'failed',
            'output_json' => $output,
            'raw_output' => json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '',
            'error_message' => $errorMessage,
            'finished_at' => date('Y-m-d H:i:s'),
        ]);
        $this->syncEpisodeWorkflowStateForRunNode(
            $runNode,
            'failed',
            $output,
            json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '',
            $errorMessage,
        );
        $runId = (int) $runNode->getAttr('run_id');
        $run = WorkflowRun::find($runId);
        if ($run instanceof WorkflowRun && (string) $run->getAttr('status') !== 'cancelled') {
            WorkflowRun::where('id', $runId)->update([
                'status' => 'failed',
                'error_message' => $errorMessage,
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
        }
        RedisCache::bumpVersion('workflow_run:' . $runId);
    }

    /**
     * 同一个视频节点可能被重复执行，旧失败/取消任务不能污染当前节点状态。
     * 统一按 shot_index 取最新非 cancelled/stale 任务。
     * （旧逻辑用 max(id)-total_shots 窗口：在「镜2单镜重生」后会漏掉更早的镜1 success，导致合并只有 15s。）
     *
     * @return array<int, VideoJob>
     */
    private function latestVideoJobsForRunNode(int $runNodeId, int $storyboardRevisionId = 0): array
    {
        if ($runNodeId <= 0) {
            return [];
        }
        if ($storyboardRevisionId <= 0) {
            $storyboardRevisionId = $this->currentStoryboardRevisionIdForRunNode($runNodeId);
        }
        if ($storyboardRevisionId <= 0) {
            return [];
        }

        return $this->latestVideoJobsPerShotForRunNode($runNodeId, $storyboardRevisionId);
    }

    /**
     * 合并成片：按当前 revision 镜头顺序收集 URL；job 缺失时回退到镜头当前选用 video_url。
     * 任一镜头缺失则 422，禁止静默少拼。
     *
     * @return array{video_urls: array<int, string>, source_videos: array<int, array<string, mixed>>}
     */
    private function collectEpisodeVideosForMerge(
        int $episodeId,
        int $storyboardRevisionId,
        int $videoRunNodeId,
        string $nodeLabel,
    ): array {
        $shots = Shot::where('episode_id', $episodeId)
            ->where('storyboard_revision_id', $storyboardRevisionId)
            ->order(['index' => 'asc', 'id' => 'asc'])
            ->select();
        if ($shots->isEmpty()) {
            abort(422, "节点【{$nodeLabel}】当前分镜没有镜头，无法合并");
        }

        $jobs = $this->latestVideoJobsPerShotForRunNode($videoRunNodeId, $storyboardRevisionId);
        $jobsByIndex = [];
        foreach ($jobs as $job) {
            if (!$job instanceof VideoJob) {
                continue;
            }
            $shotIndex = (int) $job->getAttr('shot_index');
            if ($shotIndex > 0) {
                $jobsByIndex[$shotIndex] = $job;
            }
        }

        $videoUrls = [];
        $sourceVideos = [];
        foreach ($shots as $shot) {
            if (!$shot instanceof Shot) {
                continue;
            }
            $shotIndex = (int) $shot->getAttr('index');
            if ($shotIndex <= 0) {
                continue;
            }

            $job = $jobsByIndex[$shotIndex] ?? null;
            $jobUrl = '';
            $jobId = 0;
            $jobStatus = '';
            if ($job instanceof VideoJob) {
                $jobId = (int) $job->getAttr('id');
                $jobStatus = (string) $job->getAttr('status');
                if ($jobStatus === 'success') {
                    $jobUrl = trim((string) $job->getAttr('video_url'));
                }
            }

            $shotUrl = trim((string) ($shot->getAttr('video_url') ?? ''));
            $videoUrl = $jobUrl !== '' ? $jobUrl : $shotUrl;
            if ($videoUrl === '') {
                abort(422, "节点【{$nodeLabel}】第 {$shotIndex} 个视频还未成功生成，暂不能合并");
            }

            $videoUrls[] = $videoUrl;
            $sourceVideos[] = [
                'shot_index' => $shotIndex,
                'shot_id' => (int) $shot->getAttr('id'),
                'video_job_id' => $jobId > 0 ? $jobId : null,
                'job_status' => $jobStatus !== '' ? $jobStatus : null,
                'video_url' => $videoUrl,
                'source' => $jobUrl !== '' ? 'video_job' : 'shot_video_url',
            ];
        }

        if ($videoUrls === []) {
            abort(422, "节点【{$nodeLabel}】没有可合并的视频片段，请等待视频生成完成");
        }

        return [
            'video_urls' => $videoUrls,
            'source_videos' => $sourceVideos,
        ];
    }

    private function isSingleShotVideoJob(VideoJob $job): bool
    {
        $context = $job->getAttr('request_context_json') ?: [];
        return is_array($context) && (int) ($context['only_shot_index'] ?? 0) > 0;
    }

    /**
     * 单段生成时，每个镜头都是独立提交，产物区应展示每个镜头最新一次任务。
     *
     * @return array<int, VideoJob>
     */
    private function latestVideoJobsPerShotForRunNode(int $runNodeId, int $storyboardRevisionId): array
    {
        $jobs = VideoJob::where('workflow_run_node_id', $runNodeId)
            ->where('storyboard_revision_id', $storyboardRevisionId)
            ->whereNotIn('status', ['cancelled', 'stale'])
            ->order(['shot_index' => 'asc', 'id' => 'desc'])
            ->select();

        $latest = [];
        foreach ($jobs as $job) {
            if (!$job instanceof VideoJob) {
                continue;
            }
            $shotIndex = (int) $job->getAttr('shot_index');
            if ($shotIndex <= 0 || isset($latest[$shotIndex])) {
                continue;
            }
            $latest[$shotIndex] = $job;
        }

        ksort($latest);
        return array_values($latest);
    }

    /**
     * 找指定剧集中某镜头序号当前「被选中」的视频版本 id。
     * 链式生成时，镜头 N 的输入帧来自镜头 N-1 的尾帧，故镜头 N-1 当前选中版本即镜头 N 的血缘父版本。
     */
    private function selectedVideoVersionIdForShotIndex(int $episodeId, int $storyboardRevisionId, int $shotIndex): int
    {
        if ($episodeId <= 0 || $storyboardRevisionId <= 0 || $shotIndex <= 0) {
            return 0;
        }

        $shot = Shot::where('episode_id', $episodeId)
            ->where('storyboard_revision_id', $storyboardRevisionId)
            ->where('index', $shotIndex)
            ->find();
        if (!$shot instanceof Shot) {
            return 0;
        }

        $version = ShotMediaVersion::where('shot_id', (int) $shot->getAttr('id'))
            ->where('media_type', 'video')
            ->where('is_selected', 1)
            ->order('id', 'desc')
            ->find();

        return $version instanceof ShotMediaVersion ? (int) $version->getAttr('id') : 0;
    }

    private function videoVersionIdForVideoJob(int $videoJobId): int
    {
        if ($videoJobId <= 0) {
            return 0;
        }

        $version = ShotMediaVersion::where('video_job_id', $videoJobId)
            ->where('media_type', 'video')
            ->order('id', 'desc')
            ->find();

        return $version instanceof ShotMediaVersion ? (int) $version->getAttr('id') : 0;
    }

    private function latestSuccessfulVideoEndFrameForShot(int $runNodeId, int $episodeId, int $storyboardRevisionId, int $shotIndex): string
    {
        if ($runNodeId <= 0 || $episodeId <= 0 || $storyboardRevisionId <= 0 || $shotIndex <= 0) {
            return '';
        }

        $job = VideoJob::where('workflow_run_node_id', $runNodeId)
            ->where('episode_id', $episodeId)
            ->where('storyboard_revision_id', $storyboardRevisionId)
            ->where('shot_index', $shotIndex)
            ->where('status', 'success')
            ->where('end_frame_url', '<>', '')
            ->order('id', 'desc')
            ->find();

        return $job instanceof VideoJob ? trim((string) $job->getAttr('end_frame_url')) : '';
    }

    private function resolveVideoEndpoint(string $endpoint, string $modelId, array $options): string
    {
        $endpoint = rtrim(ToapisPrivateAvatarService::rewriteLegacyEndpoint($endpoint), '/');
        $path = trim((string) ($options['path'] ?? $options['provider_path'] ?? ''));
        if ($path !== '') {
            return $endpoint . '/' . ltrim($path, '/');
        }
        if ($this->isArkVideoEndpoint($endpoint, $options) && !str_contains($endpoint, '/contents/generations/tasks')) {
            return $endpoint . '/contents/generations/tasks';
        }
        if (str_contains($endpoint, $modelId)) {
            return $endpoint;
        }
        if (str_contains($endpoint, 'api.wavespeed.ai') && str_starts_with($modelId, 'seedance-')) {
            return $endpoint . '/bytedance/' . $modelId;
        }
        return $endpoint;
    }

    private function isArkVideoEndpoint(string $endpoint, array $options = []): bool
    {
        $provider = strtolower(trim((string) ($options['provider'] ?? '')));
        if (in_array($provider, ['ark', 'volcengine_ark', 'volcano_ark'], true)) {
            return true;
        }

        $endpoint = strtolower($endpoint);
        return str_contains($endpoint, 'ark.cn-beijing.volces.com');
    }

    private function isYinheAsyncVideoEndpoint(string $endpoint, array $options = []): bool
    {
        $provider = strtolower(trim((string) ($options['provider'] ?? '')));
        if (in_array($provider, ['yinhe', 'yinhe_async', 'fzyinghe'], true)) {
            return true;
        }

        $endpoint = strtolower(rtrim($endpoint, '/'));
        return str_contains($endpoint, 'api-aigc.fzyinghe.com')
            && str_ends_with($endpoint, '/video/generation/tasks');
    }

    private function isMiniMaxVideoEndpoint(string $endpoint, array $options = []): bool
    {
        $provider = strtolower(trim((string) ($options['provider'] ?? '')));
        if (in_array($provider, ['minimax', 'minimax_v2'], true)) {
            return true;
        }

        return str_contains(strtolower($endpoint), 'api.minimaxi.com/v2/video_generation');
    }

    private function isMini23VideoEndpoint(string $endpoint, array $options = []): bool
    {
        $provider = strtolower(trim((string) ($options['provider'] ?? '')));
        if (in_array($provider, ['mini23', 'mini23_gpu'], true)) {
            return true;
        }

        return str_contains(strtolower(rtrim($endpoint, '/')), '/api/v1/videos');
    }

    private function usesContentArrayVideoPayload(string $endpoint, array $options = []): bool
    {
        return $this->isArkVideoEndpoint($endpoint, $options)
            || $this->isYinheAsyncVideoEndpoint($endpoint, $options)
            || $this->isMiniMaxVideoEndpoint($endpoint, $options);
    }

    private function shouldIncludeModelInVideoPayload(string $endpoint, array $options): bool
    {
        if (array_key_exists('include_model', $options)) {
            return (bool) $options['include_model'];
        }
        return !str_contains($endpoint, 'api.wavespeed.ai');
    }

    private function mergeVideoOptions(array $modelOptions, array $nodeVideoOptions): array
    {
        $modelOptions = $this->sanitizeVideoOptions($modelOptions);
        $nodeVideoOptions = $this->sanitizeVideoOptions($nodeVideoOptions);
        $options = array_merge($modelOptions, $nodeVideoOptions);

        foreach (['provider', 'path', 'provider_path', 'result_endpoint', 'include_model', 'image_field', 'image_role', 'allowed_params'] as $key) {
            if (array_key_exists($key, $modelOptions)) {
                $options[$key] = $modelOptions[$key];
            }
        }

        $forced = $modelOptions['force_model_options'] ?? [];
        if (is_array($forced)) {
            foreach ($forced as $key) {
                $key = trim((string) $key);
                if ($key !== '' && array_key_exists($key, $modelOptions)) {
                    $options[$key] = $modelOptions[$key];
                }
            }
        }

        return $this->sanitizeVideoOptions($options);
    }

    private function sanitizeVideoOptions(array $options): array
    {
        unset($options['q']);

        if (isset($options['allowed_params']) && is_array($options['allowed_params'])) {
            $options['allowed_params'] = array_values(array_filter(
                array_map(static fn ($value): string => (string) $value, $options['allowed_params']),
                static fn (string $param): bool => $param !== 'q'
            ));
        }

        if (isset($options['metadata_params']) && is_array($options['metadata_params'])) {
            $options['metadata_params'] = array_values(array_filter(
                array_map(static fn ($value): string => (string) $value, $options['metadata_params']),
                static fn (string $param): bool => $param !== 'q'
            ));
        }

        return $options;
    }

    private function normalizeVideoRequestOptionsForModel(string $endpoint, string $modelId, array $options): array
    {
        $endpointLower = strtolower($endpoint);
        $modelLower = strtolower($modelId);

        if ($this->isMiniMaxVideoEndpoint($endpoint, $options)) {
            $options['provider'] = 'minimax_v2';
            $options['image_role'] = 'reference_image';
            $options['max_reference_images'] = max(1, min(9, (int) ($options['max_reference_images'] ?? 9)));
            $options['max_reference_videos'] = max(1, min(3, (int) ($options['max_reference_videos'] ?? 3)));
            $nodeAspectRatio = trim((string) ($options['aspect_ratio'] ?? ''));
            if ($nodeAspectRatio !== '') {
                $options['ratio'] = $nodeAspectRatio;
            }
            $resolution = strtolower(trim((string) ($options['resolution'] ?? '')));
            $options['resolution'] = match ($resolution) {
                '2k', '1080p' => '2K',
                default => '768P',
            };
            $options = $this->ensureVideoAllowedParams($options, ['duration', 'resolution', 'ratio', 'watermark']);
            $options = $this->removeVideoAllowedParams($options, [
                'aspect_ratio',
                'generate_audio',
                'enable_web_search',
                'seed',
                'fps',
                'guidance_scale',
                'negative_prompt',
            ]);

            return $options;
        }

        if ($this->isMini23VideoEndpoint($endpoint, $options)) {
            $options['provider'] = 'mini23';
            $options['max_reference_images'] = max(1, min(9, (int) ($options['max_reference_images'] ?? 9)));
            $options['width'] = $this->mini23Dimension((int) ($options['width'] ?? 864));
            $options['height'] = $this->mini23Dimension((int) ($options['height'] ?? 480));
            $options['poll_interval'] = max(2, min(20, (int) ($options['poll_interval'] ?? 5)));
            // GPU 机器可能经历排队与冷启动，默认允许等待 15 分钟。
            $options['poll_attempts'] = max(1, min(180, (int) ($options['poll_attempts'] ?? 180)));

            return $options;
        }

        if ($this->isYinheAsyncVideoEndpoint($endpoint, $options)) {
            $options['provider'] = 'yinhe_async';
            $options['image_role'] = trim((string) ($options['image_role'] ?? 'reference_image')) ?: 'reference_image';
            $options['max_reference_images'] = max(1, min(9, (int) ($options['max_reference_images'] ?? 9)));
            $nodeAspectRatio = trim((string) ($options['aspect_ratio'] ?? ''));
            if ($nodeAspectRatio !== '') {
                $options['ratio'] = $nodeAspectRatio;
            }
            $options = $this->ensureVideoAllowedParams($options, [
                'duration',
                'resolution',
                'ratio',
                'generate_audio',
                'watermark',
            ]);
            $options = $this->removeVideoAllowedParams($options, [
                'aspect_ratio',
                'seed',
                'fps',
                'guidance_scale',
                'negative_prompt',
                'enable_web_search',
            ]);
            $options = $this->removeVideoMetadataParams($options, ['resolution', 'generate_audio']);

            return $options;
        }

        if ($this->isArkVideoEndpoint($endpoint, $options)) {
            $options['provider'] = 'ark';
            $options['image_field'] = 'ark_content';
            $options['image_role'] = trim((string) ($options['image_role'] ?? 'reference_image')) ?: 'reference_image';
            $options['max_reference_images'] = (int) ($options['max_reference_images'] ?? 9) ?: 9;
            // 节点明确选择的画幅应覆盖模型配置中的默认 ratio（通常为 16:9）。
            $nodeAspectRatio = trim((string) ($options['aspect_ratio'] ?? ''));
            if ($nodeAspectRatio !== '') {
                $options['ratio'] = $nodeAspectRatio;
            }
            $options['resolution'] = $this->normalizeArkVideoResolution($options);
            $options = $this->ensureVideoAllowedParams($options, [
                'duration',
                'resolution',
                'ratio',
                'generate_audio',
                'watermark',
                'return_last_frame',
                'priority',
                'safety_identifier',
                'execution_expires_after',
                'tools',
            ]);
            $options = $this->removeVideoAllowedParams($options, ['aspect_ratio', 'seed', 'fps', 'guidance_scale', 'negative_prompt']);
            $options = $this->removeVideoMetadataParams($options, ['resolution', 'generate_audio']);

            return $options;
        }

        if (!ToapisPrivateAvatarService::isToapisEndpoint($endpointLower)) {
            return $options;
        }

        if ($this->isToapisSeedance2Model($modelLower)) {
            $options['image_field'] = 'image_with_roles';
            $options['image_role'] = 'reference_image';
            $options['max_reference_images'] = 9;
            $options = $this->removeVideoMetadataParams($options, ['resolution', 'generate_audio']);
            $options = $this->ensureVideoAllowedParams($options, ['image_with_roles', 'duration', 'aspect_ratio', 'resolution', 'generate_audio', 'seed']);
            $options = $this->removeVideoAllowedParams($options, ['metadata']);
        } elseif (str_starts_with($modelLower, 'wan2.6')) {
            $options['image_field'] = 'image_urls';
            $options['max_reference_images'] = 1;
            $options['audio_field'] = 'audio';
            $options = $this->ensureVideoAllowedParams($options, ['image_urls', 'audio']);
            $options = $this->removeVideoAllowedParams($options, ['aspect_ratio', 'generate_audio']);
        } elseif (str_starts_with($modelLower, 'veo3.1')) {
            $options['image_field'] = 'image_urls';
            $options['max_reference_images'] = 3;
            $options['veo_reference_mode'] = true;
            $options = $this->ensureVideoAllowedParams($options, ['image_urls', 'metadata', 'generate_audio']);
        } elseif (str_starts_with($modelLower, 'viduq3')) {
            $options['image_field'] = 'image_urls';
            $options['max_reference_images'] = str_ends_with($modelLower, '-pro') || str_ends_with($modelLower, '-turbo') ? 2 : 7;
            $options['audio_field'] = 'audio';
            $options = $this->ensureVideoAllowedParams($options, ['image_urls', 'audio']);
            $options = $this->removeVideoAllowedParams($options, ['generate_audio']);
        }

        return $options;
    }

    private function buildArkVideoPayload(string $modelId, string $prompt, array $referenceImages, int $seconds, array $options, array $voiceAssets = []): array
    {
        $content = [];
        if ($voiceAssets !== []) {
            $prompt = VideoVoiceAssetService::prependArkPrompt($prompt, $voiceAssets);
        }
        $prompt = trim($prompt);
        if ($prompt !== '') {
            $content[] = [
                'type' => 'text',
                'text' => $prompt,
            ];
        }

        $role = trim((string) ($options['image_role'] ?? 'reference_image')) ?: 'reference_image';
        foreach ($referenceImages as $url) {
            $url = trim((string) $url);
            if ($url === '') {
                continue;
            }
            $content[] = [
                'type' => 'image_url',
                'image_url' => ['url' => $url],
                'role' => $role,
            ];
        }
        foreach (VideoVoiceAssetService::arkContentItems($voiceAssets) as $voiceItem) {
            $content[] = $voiceItem;
        }

        $payload = [
            'model' => $modelId,
            'content' => $content,
        ];

        if ($this->shouldIncludeVideoPayloadParam('duration', $options) && $seconds > 0) {
            $payload['duration'] = $seconds;
        }
        if ($this->shouldIncludeVideoPayloadParam('resolution', $options) && trim((string) ($options['resolution'] ?? '')) !== '') {
            $payload['resolution'] = trim((string) $options['resolution']);
        }
        if ($this->shouldIncludeVideoPayloadParam('ratio', $options) && trim((string) ($options['ratio'] ?? '')) !== '') {
            $payload['ratio'] = trim((string) $options['ratio']);
        }
        foreach (['generate_audio', 'watermark', 'return_last_frame'] as $key) {
            if ($this->shouldIncludeVideoPayloadParam($key, $options) && array_key_exists($key, $options)) {
                $payload[$key] = is_bool($options[$key])
                    ? $options[$key]
                    : in_array(strtolower((string) $options[$key]), ['1', 'true', 'yes', 'on'], true);
            }
        }
        foreach (['priority', 'execution_expires_after'] as $key) {
            if ($this->shouldIncludeVideoPayloadParam($key, $options) && isset($options[$key]) && trim((string) $options[$key]) !== '') {
                $payload[$key] = (int) $options[$key];
            }
        }
        if ($this->shouldIncludeVideoPayloadParam('safety_identifier', $options) && trim((string) ($options['safety_identifier'] ?? '')) !== '') {
            $payload['safety_identifier'] = mb_substr(trim((string) $options['safety_identifier']), 0, 64);
        }
        if ($this->shouldIncludeVideoPayloadParam('tools', $options) && isset($options['tools']) && is_array($options['tools'])) {
            $payload['tools'] = array_values($options['tools']);
        }

        return $payload;
    }

    private function buildMiniMaxVideoPayload(
        string $modelId,
        string $prompt,
        array $referenceImages,
        array $referenceVideos,
        int $seconds,
        array $options
    ): array {
        $prompt = trim($prompt);
        if ($prompt === '') {
            abort(422, 'MiniMax 视频生成必须包含非空提示词');
        }

        $content = [[
            'type' => 'text',
            'text' => $prompt,
        ]];
        foreach (array_slice($referenceImages, 0, 9) as $url) {
            $content[] = [
                'type' => 'image_url',
                'image_url' => ['url' => (string) $url],
                'role' => 'reference_image',
            ];
        }
        foreach (array_slice($referenceVideos, 0, 3) as $url) {
            $content[] = [
                'type' => 'video_url',
                'video_url' => ['url' => (string) $url],
                'role' => 'reference_video',
            ];
        }

        $hasReferences = count($content) > 1;
        $ratio = trim((string) ($options['ratio'] ?? ''));
        if ($ratio === '' || (!$hasReferences && $ratio === 'adaptive')) {
            $ratio = $hasReferences ? 'adaptive' : '16:9';
        }

        $payload = [
            'model' => $modelId,
            'content' => $content,
            'resolution' => trim((string) ($options['resolution'] ?? '')) === '2K' ? '2K' : '768P',
            'duration' => max(4, min(15, $seconds)),
            'ratio' => $ratio,
        ];
        if (array_key_exists('watermark', $options)) {
            $payload['aigc_watermark'] = is_bool($options['watermark'])
                ? $options['watermark']
                : in_array(strtolower((string) $options['watermark']), ['1', 'true', 'yes', 'on'], true);
        }

        return $payload;
    }

    /**
     * @param array<int, string> $referenceImages
     * @param array<int, string> $tempFiles
     * @return array{0:array<string, int|string>,1:array<int, array{path:string,name:string,mime:string}>}
     */
    private function buildMini23VideoPayload(
        string $prompt,
        array $referenceImages,
        int $seconds,
        array $options,
        array &$tempFiles
    ): array {
        $prompt = trim($prompt);
        if ($prompt === '') {
            abort(422, 'Mini23 视频生成必须包含非空提示词');
        }

        $referenceImages = array_values(array_filter(array_map('trim', $referenceImages)));
        $referenceImages = array_slice($referenceImages, 0, max(1, min(9, (int) ($options['max_reference_images'] ?? 9))));
        $referenceFiles = $this->prepareMini23ReferenceFiles($referenceImages, $tempFiles);
        [$width, $height] = $this->mini23DimensionsForOptions($options);

        return [[
            'prompt' => $prompt,
            'mode' => $referenceFiles === [] ? 't2v' : 'r2v',
            'width' => $width,
            'height' => $height,
            'seconds' => max(1, min(60, $seconds)),
        ], $referenceFiles];
    }

    /**
     * @param array<int, string> $referenceImages
     * @param array<int, string> $tempFiles
     * @return array<int, array{path:string,name:string,mime:string}>
     */
    private function prepareMini23ReferenceFiles(array $referenceImages, array &$tempFiles): array
    {
        $files = [];
        foreach ($referenceImages as $index => $url) {
            $tempPath = tempnam(sys_get_temp_dir(), 'malulu-mini23-ref-');
            if ($tempPath === false) {
                abort(500, '无法创建 Mini23 参考图临时文件');
            }
            $stream = fopen($tempPath, 'wb');
            if ($stream === false) {
                @unlink($tempPath);
                abort(500, '无法写入 Mini23 参考图临时文件');
            }

            $maxBytes = 20 * 1024 * 1024;
            $downloadedBytes = 0;
            $ch = curl_init($url);
            if ($ch === false) {
                fclose($stream);
                @unlink($tempPath);
                abort(502, '无法初始化 Mini23 参考图下载');
            }
            curl_setopt_array($ch, [
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$downloadedBytes, $stream, $maxBytes): int {
                    $length = strlen($chunk);
                    if ($downloadedBytes + $length > $maxBytes) {
                        return 0;
                    }
                    $written = fwrite($stream, $chunk);
                    if ($written === false) {
                        return 0;
                    }
                    $downloadedBytes += $written;
                    return $written;
                },
            ]);
            curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $contentType = strtolower(trim((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE)));
            $errno = curl_errno($ch);
            $error = (string) curl_error($ch);
            curl_close($ch);
            fclose($stream);

            if ($errno !== 0 || $status < 200 || $status >= 300 || $downloadedBytes <= 0) {
                @unlink($tempPath);
                $detail = $error !== '' ? $error : 'HTTP ' . $status;
                abort(502, 'Mini23 参考图下载失败：' . mb_substr($detail, 0, 300));
            }
            $mime = strtolower(trim(explode(';', $contentType)[0] ?? ''));
            if (!str_starts_with($mime, 'image/')) {
                @unlink($tempPath);
                abort(422, 'Mini23 参考素材必须是图片文件');
            }

            $tempFiles[] = $tempPath;
            $extension = match ($mime) {
                'image/jpeg' => 'jpg',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
                default => 'png',
            };
            $files[] = [
                'path' => $tempPath,
                'name' => 'reference-' . ($index + 1) . '.' . $extension,
                'mime' => $mime,
            ];
        }

        return $files;
    }

    /** @return array{0:int,1:int} */
    private function mini23DimensionsForOptions(array $options): array
    {
        $height = $this->mini23Dimension((int) ($options['height'] ?? 480));
        $resolution = strtolower(trim((string) ($options['resolution'] ?? '')));
        $height = match ($resolution) {
            '480p' => 480,
            '720p' => 720,
            '1080p', '2k' => 1080,
            default => $height,
        };
        $width = $this->mini23Dimension((int) ($options['width'] ?? 864));
        $ratio = trim((string) ($options['aspect_ratio'] ?? $options['ratio'] ?? ''));
        if (preg_match('/^(\d+(?:\.\d+)?)\s*:\s*(\d+(?:\.\d+)?)$/', $ratio, $matches) === 1 && (float) $matches[2] > 0) {
            $width = $this->mini23Dimension((int) round($height * ((float) $matches[1] / (float) $matches[2])));
        }

        return [$width, $height];
    }

    private function mini23Dimension(int $value): int
    {
        $value = max(32, min(4096, $value));
        return max(32, min(4096, (int) (round($value / 32) * 32)));
    }

    private function prepareMiniMaxReferenceVideoUrls(array $referenceVideos): array
    {
        $prepared = [];
        foreach (array_slice($referenceVideos, 0, 3) as $referenceVideo) {
            $url = is_array($referenceVideo)
                ? trim((string) ($referenceVideo['url'] ?? $referenceVideo['video_url'] ?? ''))
                : trim((string) $referenceVideo);
            if ($url === '') {
                continue;
            }
            $url = $this->normalizeImageUrlForArk($url);
            if ($url === '') {
                abort(422, 'MiniMax 参考视频必须是可访问的公网 HTTP(S) URL，请配置 MEDIA_PUBLIC_BASE_URL');
            }
            if (!in_array($url, $prepared, true)) {
                $prepared[] = $url;
            }
        }

        return $prepared;
    }

    private function isArkSeedance2Model(string $modelId): bool
    {
        $modelId = strtolower(trim($modelId));
        return str_contains($modelId, 'seedance-2-0') || str_contains($modelId, 'seedance-2.0');
    }

    /**
     * 火山 Ark Seedance 分辨率：优先使用模型 options.resolution_options，缺省 480p/720p/1080p。
     *
     * @param array<string, mixed> $options
     */
    private function normalizeArkVideoResolution(array $options): string
    {
        $allowed = [];
        if (isset($options['resolution_options']) && is_array($options['resolution_options'])) {
            foreach ($options['resolution_options'] as $item) {
                $value = trim((string) $item);
                if ($value !== '') {
                    $allowed[] = $value;
                }
            }
        }
        if ($allowed === []) {
            $allowed = ['480p', '720p', '1080p'];
        }

        $resolution = strtolower(trim((string) ($options['resolution'] ?? '')));
        $normalized = match ($resolution) {
            '480', '480p' => '480p',
            '720', '720p' => '720p',
            '1080', '1080p' => '1080p',
            default => trim((string) ($options['resolution'] ?? '')),
        };

        if ($normalized !== '' && in_array($normalized, $allowed, true)) {
            return $normalized;
        }

        if (in_array('480p', $allowed, true)) {
            return '480p';
        }

        return $allowed[0] ?? '480p';
    }

    /** @return array<int, array<string, mixed>> */
    private function prepareVideoVoiceAssetsForArk(array $voiceAssets, string $fallbackMediaUrl = ''): array
    {
        $prepared = [];
        foreach ($voiceAssets as $voice) {
            if (!is_array($voice)) {
                continue;
            }
            $url = $this->normalizeImageUrlForArk((string) ($voice['source_url'] ?? ''));
            if ($url === '') {
                $url = $this->resolveRelativeMediaUrlFromReference(
                    (string) ($voice['source_url'] ?? ''),
                    $fallbackMediaUrl,
                );
            }
            if ($url === '') {
                abort(422, '火山方舟角色音色必须是可访问的公网 HTTP(S) URL，请配置 MEDIA_PUBLIC_BASE_URL');
            }
            $voice['source_url'] = $url;
            $prepared[] = $voice;
        }
        return array_slice($prepared, 0, 9);
    }

    private function resolveRelativeMediaUrlFromReference(string $mediaUrl, string $referenceUrl): string
    {
        $mediaPath = (string) (parse_url(trim($mediaUrl), PHP_URL_PATH) ?: trim($mediaUrl));
        if (!str_starts_with($mediaPath, '/storage/')) {
            return '';
        }

        $referenceUrl = trim($referenceUrl);
        if (preg_match('#^https?://#i', $referenceUrl) !== 1) {
            return '';
        }
        $scheme = (string) parse_url($referenceUrl, PHP_URL_SCHEME);
        $host = (string) parse_url($referenceUrl, PHP_URL_HOST);
        $port = parse_url($referenceUrl, PHP_URL_PORT);
        if ($scheme === '' || $host === '') {
            return '';
        }

        return $scheme . '://' . $host . ($port !== null ? ':' . (int) $port : '') . $mediaPath;
    }

    private function isToapisSeedance2Model(string $modelLower): bool
    {
        return in_array($modelLower, [
            'seedance-2',
            'seedance-2-fast',
            'doubao-seedance-2-0',
            'doubao-seedance-2-0-fast',
        ], true);
    }

    private function ensureVideoAllowedParams(array $options, array $params): array
    {
        if (!isset($options['allowed_params']) || !is_array($options['allowed_params'])) {
            return $options;
        }

        $allowed = array_map(static fn ($value): string => (string) $value, $options['allowed_params']);
        foreach ($params as $param) {
            if (!in_array($param, $allowed, true)) {
                $allowed[] = $param;
            }
        }
        $options['allowed_params'] = $allowed;

        return $options;
    }

    private function removeVideoAllowedParams(array $options, array $params): array
    {
        if (!isset($options['allowed_params']) || !is_array($options['allowed_params'])) {
            return $options;
        }

        $remove = array_flip($params);
        $options['allowed_params'] = array_values(array_filter(
            array_map(static fn ($value): string => (string) $value, $options['allowed_params']),
            static fn (string $param): bool => !isset($remove[$param])
        ));

        return $options;
    }

    private function removeVideoMetadataParams(array $options, array $params): array
    {
        if (!isset($options['metadata_params']) || !is_array($options['metadata_params'])) {
            return $options;
        }

        $remove = array_flip($params);
        $options['metadata_params'] = array_values(array_filter(
            array_map(static fn ($value): string => (string) $value, $options['metadata_params']),
            static fn (string $param): bool => !isset($remove[$param])
        ));

        return $options;
    }

    private function shouldIncludeVideoPayloadParam(string $key, array $options): bool
    {
        $allowed = $options['allowed_params'] ?? null;
        if (!is_array($allowed) || $allowed === []) {
            return true;
        }

        return in_array($key, array_map(static fn ($value): string => (string) $value, $allowed), true);
    }

    private function prepareVideoReferenceImagesForPayload(
        array $referenceImages,
        string $endpoint,
        string $modelId,
        string $apiKey,
        array $options,
        array &$tempFiles,
        array &$referenceUploadMap
    ): array
    {
        if ($referenceImages === []) {
            return [];
        }

        $maxImages = (int) ($options['max_reference_images'] ?? 0);
        if ($maxImages > 0) {
            $referenceImages = array_slice($referenceImages, 0, $maxImages);
        }

        $endpointLower = strtolower($endpoint);
        $modelLower = strtolower($modelId);
        $isToapis = ToapisPrivateAvatarService::isToapisEndpoint($endpointLower);
        $isArk = $this->isArkVideoEndpoint($endpoint, $options);
        $isYinhe = $this->isYinheAsyncVideoEndpoint($endpoint, $options);
        $isMiniMax = $this->isMiniMaxVideoEndpoint($endpoint, $options);
        $prepared = [];
        foreach ($referenceImages as $url) {
            $url = $this->unwrapVideoReferenceUrl((string) $url);
            if ($url === '') {
                continue;
            }

            if (ToapisPrivateAvatarService::isAssetUri($url)) {
                if (!in_array($url, $prepared, true)) {
                    $prepared[] = $url;
                }
                continue;
            }

            if ($isToapis) {
                $url = $this->normalizeImageUrlForToapis($url);
                if ($url === '') {
                    abort(422, '视频参考图必须是可访问的 HTTP(S) URL，请配置 MEDIA_PUBLIC_BASE_URL 或使用公网图片地址');
                }
            }

            if ($isArk) {
                $url = $this->normalizeImageUrlForArk($url);
                if ($url === '') {
                    abort(422, '火山方舟视频参考图必须是可访问的公网 HTTP(S) URL，请配置 MEDIA_PUBLIC_BASE_URL 或使用公网图片地址');
                }
            }

            if ($isYinhe) {
                $url = $this->normalizeImageUrlForArk($url);
                if ($url === '') {
                    abort(422, '银河视频参考图必须是可访问的公网 HTTP(S) URL，请配置 MEDIA_PUBLIC_BASE_URL 或使用公网图片地址');
                }
            }

            if ($isMiniMax) {
                $url = $this->normalizeImageUrlForArk($url);
                if ($url === '') {
                    abort(422, 'MiniMax 视频参考图必须是可访问的公网 HTTP(S) URL，请配置 MEDIA_PUBLIC_BASE_URL 或使用公网图片地址');
                }
            }

            if ($isToapis && $this->isToapisSeedance2Model($modelLower)) {
                $uploadedUrl = $this->uploadImageToToapis($endpoint, $apiKey, $url, $tempFiles);
                if ($uploadedUrl === '') {
                    abort(422, 'ToAPIs 视频参考图上传失败：' . $url);
                }
                $referenceUploadMap[] = [
                    'source_url' => $url,
                    'toapis_url' => $uploadedUrl,
                ];
                $url = $uploadedUrl;
            }

            if (!in_array($url, $prepared, true)) {
                $prepared[] = $url;
            }
        }

        return $prepared;
    }

    /**
     * 上游只接受裸 URL；清理历史记录或前端回填产生的 Markdown 链接包装。
     */
    private function unwrapVideoReferenceUrl(string $url): string
    {
        $url = trim($url);
        if (preg_match('/^\[[^\]]*\]\((https?:\/\/[^)\s]+)\)$/i', $url, $matches) === 1) {
            return trim($matches[1]);
        }
        if (preg_match('/^<((?:https?):\/\/[^>\s]+)>$/i', $url, $matches) === 1) {
            return trim($matches[1]);
        }

        return $url;
    }

    private function applyVideoReferenceImagesToPayload(array &$payload, array $referenceImages, array $options): void
    {
        if ($referenceImages === []) {
            return;
        }

        $maxImages = (int) ($options['max_reference_images'] ?? 0);
        if ($maxImages > 0) {
            $referenceImages = array_slice($referenceImages, 0, $maxImages);
        }

        $field = trim((string) ($options['image_field'] ?? 'reference_images'));
        if ($field === 'image_urls') {
            $payload['image_urls'] = $referenceImages;
            if (!empty($options['veo_reference_mode']) && count($referenceImages) >= 3) {
                $payload['metadata']['generation_type'] = 'reference';
            }
            return;
        }
        if ($field === 'image_with_roles') {
            $role = trim((string) ($options['image_role'] ?? 'reference_image')) ?: 'reference_image';
            $payload['image_with_roles'] = array_map(
                static fn (string $url): array => ['url' => $url, 'role' => $role],
                $referenceImages
            );
            return;
        }
        if ($field === 'metadata.image_list') {
            $payload['metadata']['image_list'] = array_map(
                static fn (string $url): array => ['image_url' => $url],
                $referenceImages
            );
            return;
        }

        $payload['reference_images'] = $referenceImages;
    }

    /**
     * 分镜脚本时长优先（可自动跟随 8s/15s 等）；节点未写死时长时才用节点/模型默认。
     */
    private function resolveWorkflowVideoDuration(string $shotDuration, array $nodeParams): int
    {
        $options = $this->videoNodeRequestOptions($nodeParams);

        $shotSeconds = $this->parseVideoDurationSeconds($shotDuration);
        if ($shotSeconds > 0) {
            return $this->clampWorkflowVideoDuration($shotSeconds);
        }

        $nodeSeconds = $this->explicitWorkflowVideoDurationSeconds($options);
        if ($nodeSeconds > 0) {
            return $this->clampWorkflowVideoDuration($nodeSeconds);
        }

        if (array_key_exists('duration', $options) && (int) $options['duration'] === 0) {
            return 0;
        }

        return $this->durationSecondsForVideo('', $options);
    }

    private function durationSecondsForVideo(string $shotDuration, array $options): int
    {
        $shotSeconds = $this->parseVideoDurationSeconds($shotDuration);
        if ($shotSeconds > 0) {
            return $this->clampWorkflowVideoDuration($shotSeconds);
        }

        if (array_key_exists('duration', $options) && (int) $options['duration'] === 0) {
            return 0;
        }

        if (isset($options['duration']) && (float) $options['duration'] > 0) {
            return $this->clampWorkflowVideoDuration((int) round((float) $options['duration']));
        }

        return 15;
    }

    private function durationSecondsForMiniMax(string $requestedDuration, array $options): int
    {
        $seconds = $this->parseVideoDurationSeconds($requestedDuration);
        if ($seconds <= 0 && isset($options['duration'])) {
            $seconds = (int) round((float) $options['duration']);
        }

        return max(4, min(15, $seconds > 0 ? $seconds : 15));
    }

    private function parseVideoDurationSeconds(string $duration): int
    {
        $duration = trim($duration);
        if ($duration === '' || strtolower($duration) === 'auto') {
            return 0;
        }

        // Timeline ranges like 0-15s / 0‑8秒 → use span (or end if start is 0).
        if (preg_match('/(\d+(?:\.\d+)?)\s*[-‑–—]\s*(\d+(?:\.\d+)?)\s*(?:秒|s|sec|secs|second|seconds)?\b/iu', $duration, $range)) {
            $start = (float) ($range[1] ?? 0);
            $end = (float) ($range[2] ?? 0);
            $span = (int) round($end - $start);
            if ($span > 0) {
                return $span;
            }
            if ($end > 0) {
                return (int) round($end);
            }
        }

        if (preg_match('/(?:(\d{1,2})\s*[:：]\s*)?(\d{1,2}(?:\.\d+)?)\s*(?:秒|s|sec|secs|second|seconds)(?:\b|$)/iu', $duration, $matches)) {
            $minutes = isset($matches[1]) && $matches[1] !== '' ? (int) $matches[1] : 0;
            $seconds = (float) $matches[2];
            return (int) round(($minutes * 60) + $seconds);
        }

        if (preg_match('/^(\d{1,2})\s*[:：]\s*(\d{1,2})(?:\.\d+)?$/u', $duration, $matches)) {
            return ((int) $matches[1] * 60) + (int) $matches[2];
        }

        if (preg_match('/^\d+(?:\.\d+)?$/', $duration)) {
            return (int) round((float) $duration);
        }

        return 0;
    }

    private function clampWorkflowVideoDuration(int $seconds): int
    {
        return max(4, min(15, $seconds));
    }

    private function explicitWorkflowVideoDurationSeconds(array $options): int
    {
        if (!array_key_exists('duration', $options)) {
            return 0;
        }

        $seconds = (int) round((float) $options['duration']);
        return $seconds > 0 ? $seconds : 0;
    }

    private function formatVideoTimestamp(int $seconds): string
    {
        $seconds = max(0, $seconds);

        return sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    private function videoNodeRequestOptions(array $nodeParams): array
    {
        $options = [];
        if (array_key_exists('duration', $nodeParams)) {
            $durationRaw = trim((string) $nodeParams['duration']);
            if ($durationRaw === '' || strtolower($durationRaw) === 'auto') {
                $options['duration'] = 0;
            } elseif ((float) $durationRaw <= 0) {
                $options['duration'] = 0;
            } else {
                $options['duration'] = (int) round((float) $durationRaw);
            }
        }
        foreach (['aspect_ratio', 'resolution'] as $key) {
            $value = trim((string) ($nodeParams[$key] ?? ''));
            if ($value !== '') {
                $options[$key] = $value;
            }
        }
        foreach (['generate_audio', 'enable_web_search'] as $key) {
            if (array_key_exists($key, $nodeParams)) {
                $value = $nodeParams[$key];
                $options[$key] = is_bool($value)
                    ? $value
                    : in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
            }
        }
        return $this->sanitizeVideoOptions($options);
    }

    private function curlJsonPost(string $endpoint, array $headers, array $payload, int &$httpStatus, int &$curlErrno, string &$curlError, int $timeout): string
    {
        $ch = curl_init($endpoint);
        if ($ch === false) {
            $httpStatus = 0;
            $curlErrno = -1;
            $curlError = '初始化视频请求失败';
            return '';
        }

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));

        $response = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = (string) curl_error($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return is_string($response) ? $response : '';
    }

    /**
     * Mini23 的图生视频使用重复的 refs multipart 字段；手工构造 multipart，
     * 避免 PHP 数组默认的 refs[0]，与服务端 DTO 保持一致。
     *
     * @param array<int, array{path:string,name:string,mime:string}> $referenceFiles
     */
    private function curlMini23MultipartPost(
        string $endpoint,
        array $headers,
        array $payload,
        array $referenceFiles,
        int &$httpStatus,
        int &$curlErrno,
        string &$curlError,
        int $timeout
    ): string {
        $ch = curl_init($endpoint);
        if ($ch === false) {
            $httpStatus = 0;
            $curlErrno = -1;
            $curlError = '初始化 Mini23 视频请求失败';
            return '';
        }

        [$bodyPath, $boundary] = $this->writeMini23MultipartBody($payload, $referenceFiles);
        $bodyStream = fopen($bodyPath, 'rb');
        if ($bodyStream === false) {
            @unlink($bodyPath);
            curl_close($ch);
            throw new \RuntimeException('无法读取 Mini23 multipart 请求体');
        }
        try {
            $bodySize = filesize($bodyPath);
            if ($bodySize === false || $bodySize <= 0) {
                throw new \RuntimeException('Mini23 multipart 请求体为空');
            }
            $headers = array_values(array_filter($headers, static fn (string $header): bool => !str_starts_with(strtolower($header), 'content-type:')));
            $headers[] = 'Content-Type: multipart/form-data; boundary=' . $boundary;
            $headers[] = 'Content-Length: ' . $bodySize;
            $headers[] = 'Expect:';
            curl_setopt_array($ch, [
                CURLOPT_UPLOAD => true,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_INFILE => $bodyStream,
                CURLOPT_INFILESIZE => $bodySize,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_HTTPHEADER => $headers,
            ]);

            $response = curl_exec($ch);
            $curlErrno = curl_errno($ch);
            $curlError = (string) curl_error($ch);
            $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        } finally {
            fclose($bodyStream);
            @unlink($bodyPath);
            curl_close($ch);
        }

        return is_string($response) ? $response : '';
    }

    /**
     * @param array<string, int|string> $payload
     * @param array<int, array{path:string,name:string,mime:string}> $referenceFiles
     * @return array{0:string,1:string}
     */
    private function writeMini23MultipartBody(array $payload, array $referenceFiles): array
    {
        $bodyPath = tempnam(sys_get_temp_dir(), 'malulu-mini23-body-');
        if ($bodyPath === false) {
            throw new \RuntimeException('无法创建 Mini23 multipart 临时文件');
        }
        $stream = fopen($bodyPath, 'wb');
        if ($stream === false) {
            @unlink($bodyPath);
            throw new \RuntimeException('无法写入 Mini23 multipart 临时文件');
        }

        $boundary = '----maluluMini23' . bin2hex(random_bytes(16));
        try {
            foreach ($payload as $key => $value) {
                fwrite($stream, '--' . $boundary . "\r\n");
                fwrite($stream, 'Content-Disposition: form-data; name="' . $key . '"' . "\r\n\r\n");
                fwrite($stream, (string) $value . "\r\n");
            }
            foreach ($referenceFiles as $referenceFile) {
                $source = fopen($referenceFile['path'], 'rb');
                if ($source === false) {
                    throw new \RuntimeException('无法读取 Mini23 参考图');
                }
                try {
                    $name = str_replace(['"', "\r", "\n"], '', $referenceFile['name']);
                    fwrite($stream, '--' . $boundary . "\r\n");
                    fwrite($stream, 'Content-Disposition: form-data; name="refs"; filename="' . $name . '"' . "\r\n");
                    fwrite($stream, 'Content-Type: ' . $referenceFile['mime'] . "\r\n\r\n");
                    stream_copy_to_stream($source, $stream);
                    fwrite($stream, "\r\n");
                } finally {
                    fclose($source);
                }
            }
            fwrite($stream, '--' . $boundary . "--\r\n");
        } finally {
            fclose($stream);
        }

        return [$bodyPath, $boundary];
    }

    /**
     * @param array<int, array<string, mixed>> $shots
     * @return array<int, array<string, mixed>>
     */
    private function filterEpisodeVideoShotsReady(array $shots): array
    {
        $ready = [];
        foreach ($shots as $shotData) {
            if (!is_array($shotData)) {
                continue;
            }
            $description = trim((string) ($shotData['description'] ?? ''));
            if ($description === '') {
                continue;
            }
            $ready[] = $shotData;
        }

        return $ready;
    }

    private function assertFfmpegAvailable(string $nodeLabel): void
    {
        if ($this->findExecutable('ffmpeg') !== '') {
            return;
        }

        abort(422, "节点【{$nodeLabel}】已开启镜头衔接，需要 ffmpeg 截取视频末帧作为下一镜头起点。"
            . '当前环境未检测到 ffmpeg，请先安装后再执行。'
            . $this->ffmpegInstallHint());
    }

    private function ffmpegInstallHint(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return ' Windows：可用 winget install ffmpeg 或 choco install ffmpeg，安装后把 ffmpeg 加入 PATH 并重启 PHP 服务。';
        }

        if (is_file('/.dockerenv')) {
            return ' Docker：在项目根目录执行 docker compose build php --no-cache && docker compose up -d，镜像已包含 ffmpeg。';
        }

        return ' Linux/macOS：例如 apt install ffmpeg 或 brew install ffmpeg，安装后确保 php-fpm / worker 进程能访问 ffmpeg 命令。';
    }

    private function extractAndUploadVideoEndFrame(
        string $videoUrl,
        bool $required = false,
        string $nodeLabel = '',
        int $shotIndex = 0,
        array $context = [],
    ): string {
        $tempFiles = [];
        try {
            $videoPath = $this->downloadRemoteVideoToTemp($videoUrl, $tempFiles);
            if ($videoPath === '') {
                if ($required) {
                    $this->abortVideoEndFrameFailure($nodeLabel, $shotIndex, '无法下载已生成的视频文件，无法截取末帧');
                }
                return '';
            }

            $framePath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR
                . 'malulu-video-end-frame-'
                . bin2hex(random_bytes(8))
                . '.png';
            $tempFiles[] = $framePath;

            $extractError = $this->extractLastFrameWithFfmpeg($videoPath, $framePath);
            if ($extractError !== '' || !is_file($framePath)) {
                if ($required) {
                    $message = $extractError !== ''
                        ? $extractError
                        : 'ffmpeg 未能从视频中导出末帧图片';
                    $this->abortVideoEndFrameFailure($nodeLabel, $shotIndex, $message);
                }
                return '';
            }

            $binary = file_get_contents($framePath);
            if ($binary === false || $binary === '') {
                if ($required) {
                    $this->abortVideoEndFrameFailure($nodeLabel, $shotIndex, '末帧图片为空');
                }
                return '';
            }

            $uploaded = MediaStorage::uploadImageBinary($binary, 'png', 'generated/video-end-frames', 'image/png', $context);
            if ($required && trim($uploaded) === '') {
                $this->abortVideoEndFrameFailure($nodeLabel, $shotIndex, '末帧图片上传失败');
            }

            return $uploaded;
        } catch (\Throwable $e) {
            Log::warning('视频末帧截取失败：' . $e->getMessage(), ['video_url' => $videoUrl]);
            if ($required) {
                $this->abortVideoEndFrameFailure($nodeLabel, $shotIndex, $e->getMessage());
            }
            return '';
        } finally {
            foreach ($tempFiles as $tempFile) {
                if (is_string($tempFile) && is_file($tempFile)) {
                    @unlink($tempFile);
                }
            }
        }
    }

    private function resolveUpstreamVideoRunNodeId(array $previousOutput, array $upstreamOutputs): int
    {
        $candidates = [];
        if (isset($previousOutput['run_node']) && $previousOutput['run_node'] instanceof WorkflowRunNode) {
            $candidates[] = $previousOutput['run_node'];
        }
        foreach (array_reverse($upstreamOutputs) as $output) {
            if (is_array($output) && isset($output['run_node']) && $output['run_node'] instanceof WorkflowRunNode) {
                $candidates[] = $output['run_node'];
            }
        }

        foreach ($candidates as $node) {
            if ((string) $node->getAttr('kind') === 'video') {
                return (int) $node->getAttr('id');
            }
        }

        return 0;
    }

    private function resolvePreviousVideoRunNodeId(int $runId, array $orderedNodes, string $targetNodeId): int
    {
        $previousNodes = [];
        foreach ($orderedNodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            if ((string) ($node['id'] ?? '') === $targetNodeId) {
                break;
            }
            $previousNodes[] = $node;
        }

        for ($i = count($previousNodes) - 1; $i >= 0; $i--) {
            $node = $previousNodes[$i];
            $kind = trim((string) ($node['data']['kind'] ?? $node['kind'] ?? ''));
            if ($kind !== 'video') {
                continue;
            }
            $runNode = $this->findWorkflowRunNode($runId, $node);
            if ($runNode instanceof WorkflowRunNode) {
                return (int) $runNode->getAttr('id');
            }
        }

        return 0;
    }

    private function mergeAndUploadEpisodeVideos(array $videoUrls, string $nodeLabel, array $context = []): string
    {
        $this->assertFfmpegAvailable($nodeLabel);
        $tempFiles = [];
        try {
            $localVideos = [];
            foreach ($videoUrls as $url) {
                $path = $this->downloadRemoteVideoToTemp((string) $url, $tempFiles);
                if ($path === '') {
                    abort(422, "节点【{$nodeLabel}】合并失败：无法下载视频片段");
                }
                $localVideos[] = $path;
            }
            if ($localVideos === []) {
                abort(422, "节点【{$nodeLabel}】没有可合并的视频片段");
            }

            $listPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR
                . 'malulu-video-concat-'
                . bin2hex(random_bytes(8))
                . '.txt';
            $tempFiles[] = $listPath;
            $list = '';
            foreach ($localVideos as $path) {
                $list .= "file '" . str_replace("'", "'\\''", $path) . "'\n";
            }
            if (file_put_contents($listPath, $list) === false) {
                abort(422, "节点【{$nodeLabel}】合并失败：无法写入临时列表");
            }

            $outputPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR
                . 'malulu-merged-video-'
                . bin2hex(random_bytes(8))
                . '.mp4';
            $tempFiles[] = $outputPath;
            $error = $this->concatVideosWithFfmpeg($listPath, $outputPath);
            if ($error !== '' || !is_file($outputPath)) {
                abort(422, "节点【{$nodeLabel}】合并失败：" . ($error !== '' ? $error : 'ffmpeg 未输出文件'));
            }

            return MediaStorage::uploadBinaryFile($outputPath, 'mp4', 'generated/videos', 'video/mp4', $this->mediaStorageContext($context, 'merged_video'));
        } finally {
            foreach ($tempFiles as $tempFile) {
                if (is_string($tempFile) && is_file($tempFile)) {
                    @unlink($tempFile);
                }
            }
        }
    }

    private function downloadRemoteVideoToTemp(string $url, array &$tempFiles): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $localPath = $this->localMediaPathFromUrl($url);
        if ($localPath !== '') {
            return $localPath;
        }

        if (!preg_match('#^https?://#i', $url)) {
            return '';
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return '';
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
        curl_setopt($ch, CURLOPT_MAXFILESIZE, 200 * 1024 * 1024);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $mime = strtolower((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
        curl_close($ch);

        if (!is_string($body) || $body === '' || $status < 200 || $status >= 300) {
            return '';
        }

        $extension = str_contains($mime, 'webm') ? 'webm' : 'mp4';
        $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'malulu-video-'
            . bin2hex(random_bytes(8))
            . '.'
            . $extension;
        if (file_put_contents($path, $body) === false) {
            return '';
        }
        $tempFiles[] = $path;

        return $path;
    }

    private function localMediaPathFromUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $path = $url;
        if (preg_match('#^https?://#i', $url)) {
            $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
        }

        if (!str_starts_with($path, '/storage/')) {
            return '';
        }

        $relativePath = ltrim(substr($path, strlen('/storage/')), '/');
        if ($relativePath === '') {
            return '';
        }

        $absolutePath = rtrim(app()->getRootPath(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'public'
            . DIRECTORY_SEPARATOR
            . 'storage'
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        return is_file($absolutePath) ? $absolutePath : '';
    }

    private function abortVideoEndFrameFailure(string $nodeLabel, int $shotIndex, string $reason): void
    {
        $shotText = $shotIndex > 0 ? "镜头 {$shotIndex} " : '';
        abort(
            422,
            "节点【{$nodeLabel}】{$shotText}视频已生成，但末帧截取失败，无法衔接下一镜头：{$reason}。"
            . '请检查 ffmpeg 是否可用、视频地址是否可访问后重试。'
            . $this->ffmpegInstallHint()
        );
    }

    /**
     * @return string 成功返回空字符串；失败返回错误说明
     */
    private function extractLastFrameWithFfmpeg(string $videoPath, string $framePath): string
    {
        $ffmpeg = $this->findExecutable('ffmpeg');
        if ($ffmpeg === '') {
            return '未检测到 ffmpeg';
        }

        $command = [
            $ffmpeg,
            '-y',
            '-sseof',
            '-1',
            '-i',
            $videoPath,
            '-an',
            '-vsync',
            '0',
            '-update',
            '1',
            $framePath,
        ];

        $result = $this->runProcess($command, 120);
        if ($result['code'] === 0 && is_file($framePath)) {
            return '';
        }

        $fallbackCommand = [
            $ffmpeg,
            '-y',
            '-sseof',
            '-0.08',
            '-i',
            $videoPath,
            '-frames:v',
            '1',
            $framePath,
        ];
        $fallbackResult = $this->runProcess($fallbackCommand, 120);
        if ($fallbackResult['code'] === 0 && is_file($framePath)) {
            return '';
        }

        $stderr = trim((string) ($fallbackResult['stderr'] ?: $result['stderr']));
        Log::warning('ffmpeg 截取末帧失败：' . $stderr);
        return $stderr !== '' ? $stderr : 'ffmpeg 截取末帧失败';
    }

    /**
     * @return string 成功返回空字符串；失败返回错误说明
     */
    private function concatVideosWithFfmpeg(string $listPath, string $outputPath): string
    {
        $ffmpeg = $this->findExecutable('ffmpeg');
        if ($ffmpeg === '') {
            return '未检测到 ffmpeg';
        }

        $copyCommand = [
            $ffmpeg,
            '-hide_banner',
            '-y',
            '-f',
            'concat',
            '-safe',
            '0',
            '-i',
            $listPath,
            '-c',
            'copy',
            $outputPath,
        ];
        $copyResult = $this->runProcess($copyCommand, 600);
        if ($copyResult['code'] === 0 && is_file($outputPath) && filesize($outputPath) > 0) {
            return '';
        }

        $encodeCommand = [
            $ffmpeg,
            '-hide_banner',
            '-y',
            '-f',
            'concat',
            '-safe',
            '0',
            '-i',
            $listPath,
            '-c:v',
            'libx264',
            '-preset',
            'veryfast',
            '-pix_fmt',
            'yuv420p',
            '-c:a',
            'aac',
            '-movflags',
            '+faststart',
            $outputPath,
        ];
        $encodeResult = $this->runProcess($encodeCommand, 900);
        if ($encodeResult['code'] === 0 && is_file($outputPath) && filesize($outputPath) > 0) {
            return '';
        }

        $stderr = trim((string) ($encodeResult['stderr'] ?: $copyResult['stderr']));
        Log::warning('ffmpeg 合并视频失败：' . $stderr);
        return $stderr !== '' ? $stderr : 'ffmpeg 合并视频失败';
    }

    private function findExecutable(string $name): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $result = $this->runProcess(['where', $name], 10);
            if ($result['code'] === 0) {
                $lines = preg_split('/\R/', trim($result['stdout'])) ?: [];
                $path = trim((string) ($lines[0] ?? ''));
                if ($path !== '' && is_file($path)) {
                    return $path;
                }
            }

            $probe = $this->runProcess([$name, '-version'], 10);
            return $probe['code'] === 0 ? $name : '';
        }

        $result = $this->runProcess(['sh', '-lc', 'command -v ' . escapeshellarg($name)], 10);
        if ($result['code'] !== 0) {
            return '';
        }
        $path = trim($result['stdout']);
        return $path !== '' ? $path : '';
    }

    /**
     * @return array{code:int,stdout:string,stderr:string}
     */
    private function runProcess(array $command, int $timeoutSeconds): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            return ['code' => -1, 'stdout' => '', 'stderr' => '无法启动进程'];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $startedAt = time();
        $timedOut = false;
        $exitCode = null;

        while (true) {
            $status = proc_get_status($process);
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';

            if (!$status['running']) {
                $exitCode = is_int($status['exitcode'] ?? null) ? (int) $status['exitcode'] : null;
                break;
            }
            if (time() - $startedAt > $timeoutSeconds) {
                proc_terminate($process);
                $timedOut = true;
                break;
            }
            usleep(100000);
        }

        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code === -1 && $exitCode !== null) {
            $code = $exitCode;
        }

        if ($timedOut) {
            $stderr = trim($stderr . "\n进程超时");
            $code = -1;
        }

        return [
            'code' => is_int($code) ? $code : -1,
            'stdout' => trim($stdout),
            'stderr' => trim($stderr),
        ];
    }

    private function pollWorkflowVideoResult(string $submitEndpoint, array $headers, string $taskId, array $options, string $responseResultEndpoint = ''): array
    {
        $resultEndpoint = $this->resolveVideoResultEndpoint($submitEndpoint, $taskId, $options, $responseResultEndpoint);
        $interval = max(2, min(20, (int) ($options['poll_interval'] ?? 5)));
        $maxAttempts = max(1, min(180, (int) ($options['poll_attempts'] ?? 120)));
        $lastRaw = '';
        $errorMessage = '';
        $trace = [
            'task_id' => $taskId,
            'interval_seconds' => $interval,
            'max_attempts' => $maxAttempts,
            'requests' => [],
        ];

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            if ($attempt > 0) {
                sleep($interval);
            }

            $ch = curl_init($resultEndpoint);
            if ($ch === false) {
                continue;
            }
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            $response = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $trace['requests'][] = $this->buildAiRequestLogSnapshot('GET', $resultEndpoint, $headers, [
                'attempt' => $attempt + 1,
                'http_status' => $status,
            ]);

            if (!is_string($response) || $response === '') {
                continue;
            }
            $lastRaw = $response;
            if ($status < 200 || $status >= 300) {
                continue;
            }

            $decoded = json_decode($response, true);
            if (!is_array($decoded)) {
                continue;
            }
            $videoUrl = $this->extractVideoUrlFromResponse($decoded);
            if ($videoUrl === '' && $this->isMini23VideoEndpoint($submitEndpoint, $options)) {
                $videoUrl = $this->extractMini23ContentUrl($decoded, $submitEndpoint);
            }
            if ($videoUrl !== '') {
                return ['video_url' => $videoUrl, 'raw' => $lastRaw, 'trace' => $trace];
            }

            $statusText = $this->extractVideoStatusFromResponse($decoded);
            if (in_array($statusText, ['failed', 'error', 'expired', 'canceled', 'cancelled'], true)) {
                $errorMessage = $this->extractVideoErrorFromResponse($decoded, $statusText);
                break;
            }
        }

        return ['video_url' => '', 'raw' => $lastRaw, 'trace' => $trace, 'error_message' => $errorMessage];
    }

    private function fetchWorkflowVideoResultOnce(string $submitEndpoint, array $headers, string $taskId, array $options, string $responseResultEndpoint = ''): array
    {
        $resultEndpoint = $this->resolveVideoResultEndpoint($submitEndpoint, $taskId, $options, $responseResultEndpoint);
        $ch = curl_init($resultEndpoint);
        if ($ch === false) {
            return ['video_url' => '', 'status' => '', 'raw' => ''];
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($response) || $response === '' || $status < 200 || $status >= 300) {
            return ['video_url' => '', 'status' => '', 'raw' => is_string($response) ? $response : ''];
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return ['video_url' => '', 'status' => '', 'raw' => $response];
        }

        $videoUrl = $this->extractVideoUrlFromResponse($decoded);
        if ($videoUrl === '' && $this->isMini23VideoEndpoint($submitEndpoint, $options)) {
            $videoUrl = $this->extractMini23ContentUrl($decoded, $submitEndpoint);
        }

        return [
            'video_url' => $videoUrl,
            'status' => $this->extractVideoStatusFromResponse($decoded),
            'raw' => $response,
        ];
    }

    private function resolveVideoResultEndpoint(string $submitEndpoint, string $taskId, array $options, string $responseResultEndpoint = ''): string
    {
        $responseResultEndpoint = ToapisPrivateAvatarService::rewriteLegacyEndpoint(trim($responseResultEndpoint));
        if ($responseResultEndpoint !== '') {
            return str_replace('{id}', rawurlencode($taskId), $responseResultEndpoint);
        }
        $resultEndpoint = ToapisPrivateAvatarService::rewriteLegacyEndpoint(trim((string) ($options['result_endpoint'] ?? '')));
        if ($resultEndpoint !== '') {
            return str_replace('{id}', rawurlencode($taskId), $resultEndpoint);
        }
        if (str_contains($submitEndpoint, 'api.wavespeed.ai')) {
            return 'https://api.wavespeed.ai/api/v3/predictions/' . rawurlencode($taskId) . '/result';
        }
        if ($this->isMiniMaxVideoEndpoint($submitEndpoint, $options)) {
            $origin = preg_replace('#^(https?://[^/]+).*$#i', '$1', $submitEndpoint) ?: 'https://api.minimaxi.com';
            return rtrim($origin, '/') . '/v2/query/video_generation/' . rawurlencode($taskId);
        }
        return rtrim($submitEndpoint, '/') . '/' . rawurlencode($taskId);
    }

    private function videoProviderLabel(string $endpoint): string
    {
        $endpoint = strtolower($endpoint);
        if (str_contains($endpoint, 'api-aigc.fzyinghe.com')) {
            return '银河 AIGC';
        }
        if (ToapisPrivateAvatarService::isToapisEndpoint($endpoint)) {
            return '视频通道';
        }
        if (str_contains($endpoint, 'api.wavespeed.ai')) {
            return 'WaveSpeed';
        }
        if (str_contains($endpoint, 'ark.cn-beijing.volces.com')) {
            return '火山方舟';
        }
        if (str_contains($endpoint, 'api.minimaxi.com')) {
            return 'MiniMax';
        }
        return 'AI 视频服务';
    }

    private function extractVideoStatusFromResponse(array $decoded): string
    {
        return strtolower(trim((string) (
            $decoded['task']['status']
            ?? $decoded['data']['status']
            ?? $decoded['status']
            ?? ''
        )));
    }

    private function extractVideoErrorFromResponse(array $decoded, string $fallback = ''): string
    {
        return trim((string) (
            $decoded['task']['error']['message']
            ?? $decoded['task']['error_message']
            ?? $decoded['task']['message']
            ?? $decoded['data']['failReason']
            ?? $decoded['data']['message']
            ?? $decoded['msg']
            ?? $decoded['message']
            ?? $fallback
        ));
    }

    private function extractVideoTaskIdFromResponse(array $decoded): string
    {
        foreach ([
            ['output', 'task_id'],
            ['data', 'taskId'],
            ['data', 'id'],
            ['data', 'task_id'],
            ['data', 'request_id'],
            ['taskId'],
            ['id'],
            ['task_id'],
            ['request_id'],
        ] as $path) {
            $value = $decoded;
            foreach ($path as $key) {
                if (!is_array($value) || !array_key_exists($key, $value)) {
                    $value = null;
                    break;
                }
                $value = $value[$key];
            }
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }
        return '';
    }

    private function extractVideoUrlFromResponse(array $decoded): string
    {
        $candidates = [
            $decoded['task']['content']['url'] ?? null,
            $decoded['task']['content']['video_url'] ?? null,
            $decoded['content']['video_url'] ?? null,
            $decoded['content']['url'] ?? null,
            $decoded['data']['resultUrl'] ?? null,
            $decoded['data']['video_url'] ?? null,
            $decoded['data']['url'] ?? null,
            $decoded['data']['metadata']['url'] ?? null,
            $decoded['data']['metadata']['video_url'] ?? null,
            $decoded['data']['metadata']['output'] ?? null,
            $decoded['data']['output'] ?? null,
            $decoded['data']['outputs'][0] ?? null,
            $decoded['data']['result']['video_url'] ?? null,
            $decoded['data']['result']['url'] ?? null,
            $decoded['data']['result']['metadata']['url'] ?? null,
            $decoded['data']['result']['metadata']['video_url'] ?? null,
            $decoded['metadata']['url'] ?? null,
            $decoded['metadata']['video_url'] ?? null,
            $decoded['metadata']['output'] ?? null,
            $decoded['result']['video_url'] ?? null,
            $decoded['result']['url'] ?? null,
            $decoded['result']['data'][0]['url'] ?? null,
            $decoded['result']['metadata']['url'] ?? null,
            $decoded['result']['metadata']['video_url'] ?? null,
            $decoded['output'] ?? null,
            $decoded['outputs'][0] ?? null,
            $decoded['resultUrl'] ?? null,
            $decoded['video_url'] ?? null,
            $decoded['url'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && preg_match('#^https?://#i', $candidate)) {
                return trim($candidate);
            }
            if (is_array($candidate)) {
                $nested = $this->extractVideoUrlFromResponse($candidate);
                if ($nested !== '') {
                    return $nested;
                }
            }
        }

        return '';
    }

    private function extractMini23ContentUrl(array $decoded, string $submitEndpoint): string
    {
        $contentUrl = trim((string) ($decoded['content_url'] ?? $decoded['data']['content_url'] ?? ''));
        if ($contentUrl === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $contentUrl)) {
            return $contentUrl;
        }
        if (!str_starts_with($contentUrl, '/api/v1/videos/')) {
            return '';
        }

        $origin = preg_replace('#^(https?://[^/]+).*$#i', '$1', $submitEndpoint) ?: '';
        return $origin !== '' ? rtrim($origin, '/') . $contentUrl : '';
    }

    private function normalizeImageUrlForArk(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?: $url);
        if (str_starts_with($path, '/storage/')) {
            $baseUrl = rtrim(trim((string) env('MEDIA_PUBLIC_BASE_URL', '')), '/');
            if ($baseUrl !== '') {
                return $baseUrl . $path;
            }
        }

        return '';
    }

    private function normalizeImageUrlForToapis(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (ToapisPrivateAvatarService::isAssetUri($url) || preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?: $url);
        if (str_starts_with($path, '/storage/')) {
            $baseUrl = rtrim(trim((string) env('MEDIA_PUBLIC_BASE_URL', '')), '/');
            return $baseUrl !== '' ? $baseUrl . $path : '';
        }

        return '';
    }

    private function uploadImageToToapis(string $submitEndpoint, string $apiKey, string $url, array &$tempFiles): string
    {
        $path = $this->resolveLocalPublicPath($url);
        if ($path === '') {
            $path = $this->downloadRemoteImageToTemp($url, $tempFiles);
        }
        if ($path === '' || !is_file($path)) {
            return '';
        }
        $mime = $this->guessMimeType($path);
        if (!str_starts_with(strtolower($mime), 'image/')) {
            return '';
        }
        $uploadEndpoint = $this->resolveToapisImageUploadEndpoint($submitEndpoint);
        $ch = curl_init($uploadEndpoint);
        if ($ch === false) {
            return '';
        }
        $headers = [];
        if ($apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_POSTFIELDS => [
                'file' => new \CURLFile($path, $mime, basename($path)),
            ],
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($response) || $response === '' || $status < 200 || $status >= 300) {
            return '';
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return '';
        }

        return trim((string) (
            $decoded['data']['url']
            ?? $decoded['url']
            ?? $decoded['data']['uri']
            ?? $decoded['uri']
            ?? ''
        ));
    }

    private function resolveToapisImageUploadEndpoint(string $submitEndpoint): string
    {
        $submitEndpoint = ToapisPrivateAvatarService::rewriteLegacyEndpoint($submitEndpoint);
        if (preg_match('#^(.*/v1)/#', $submitEndpoint, $m) === 1) {
            return rtrim($m[1], '/') . '/uploads/images';
        }

        return ToapisPrivateAvatarService::DEFAULT_BASE . '/v1/uploads/images';
    }

    private function downloadRemoteImageToTemp(string $url, array &$tempFiles): string
    {
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return '';
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return '';
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_MAXFILESIZE, 15 * 1024 * 1024);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $mime = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if (!is_string($body) || $body === '' || $status < 200 || $status >= 300 || !str_starts_with(strtolower($mime), 'image/')) {
            return '';
        }

        $extension = match (true) {
            str_contains($mime, 'jpeg'), str_contains($mime, 'jpg') => 'jpg',
            str_contains($mime, 'webp') => 'webp',
            str_contains($mime, 'gif') => 'gif',
            default => 'png',
        };
        $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'malulu-ref-' . bin2hex(random_bytes(8)) . '.' . $extension;
        if (file_put_contents($path, $body) === false) {
            return '';
        }
        $tempFiles[] = $path;

        return $path;
    }

    private function guessMimeType(string $path): string
    {
        $mime = function_exists('mime_content_type') ? mime_content_type($path) : false;
        if (is_string($mime) && $mime !== '') {
            return $mime;
        }

        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'image/png',
        };
    }

    private function persistGeneratedWorkflowImageUrl(string $url, array $context = []): string
    {
        return MediaStorage::persistRemoteUrl($url, 'generated/frames', $this->mediaStorageContext($context, 'frame_image'));
    }

    private function persistGeneratedVideoUrl(string $url, array $context = [], array $downloadHeaders = []): string
    {
        if ((string) ($context['source'] ?? '') === 'quick_create_video' && SupabaseStorage::isEnabledForQuickCreate()) {
            return SupabaseStorage::persistRemoteUrl($url, 'generated/quick-create/videos', [
                'user_id' => (int) ($context['user_id'] ?? 0),
                'source' => 'quick-create-generated-video',
            ], $downloadHeaders);
        }

        return MediaStorage::persistRemoteUrl($url, 'generated/videos', $this->mediaStorageContext($context, 'video'), $downloadHeaders);
    }

    private function saveGeneratedWorkflowImageFromBase64(string $b64, array $context = []): string
    {
        $binary = base64_decode($b64, true);
        if ($binary === false || $binary === '') {
            abort(502, 'AI 返回的 b64_json 无法解析');
        }

        return MediaStorage::uploadImageBinary($binary, 'png', 'generated/frames', 'image/png', $this->mediaStorageContext($context, 'frame_image'));
    }

    private function mediaStorageContext(array $context = [], string $source = 'media'): array
    {
        $context['user_id'] = (int) ($context['user_id'] ?? 0) ?: $this->effectiveUserId();
        $context['source'] = trim((string) ($context['source'] ?? '')) ?: $source;

        return $context;
    }

    /**
     * 调用 AI 文本模型。
     * 请求 OpenAI 兼容 chat/completions 接口，解析 content，并写入 ai_request_logs。
     */
    private function callChatCompletions(ModelConfig $model, array $messages, array $context = []): string
    {
        $this->lastChatFinishReason = '';
        $this->lastChatContentBytes = 0;
        $this->lastAiRequestLogId = 0;
        $this->lastChatMaxTokens = 0;
        $startedAt = microtime(true);
        $endpoint = '';
        $httpStatus = 0;
        $rawBody = '';
        $curlErrno = 0;
        $curlError = '';
        $requestOk = 0;
        $errorMessage = '';
        $contentPreview = '';
        $assistantContent = '';
        $usageJson = [];
        $finishReason = '';
        $timeoutSeconds = $this->resolveModelTimeoutSeconds($model, 'AI_TEXT_TIMEOUT_SECONDS', 600, 60, 1800);
        $connectTimeoutSeconds = $this->resolveModelTimeoutSeconds($model, 'AI_TEXT_CONNECT_TIMEOUT_SECONDS', 20, 5, 120);
        $payload = [
            'model' => '',
            'messages' => $messages,
            'temperature' => 0.3,
        ];

        try {
            $endpoint = trim((string) $model->getAttr('endpoint'));
            if ($endpoint === '') {
                $errorMessage = '文本模型 endpoint 为空';
                abort(422, $errorMessage);
            }
            if (!str_contains($endpoint, '/chat/completions')) {
                $endpoint = rtrim($endpoint, '/') . '/chat/completions';
            }

            $apiKey = trim((string) $model->getAttr('api_key'));
            $modelId = trim((string) $model->getAttr('model_id'));
            if ($modelId === '') {
                $errorMessage = '文本模型 model_id 为空';
                abort(422, $errorMessage);
            }

            $payload['model'] = $modelId;
            $options = $model->getAttr('options') ?: [];
            if (!is_array($options)) {
                $options = [];
            }
            $contextMaxTokens = (int) ($context['max_tokens'] ?? 0);
            $maxTokens = $contextMaxTokens > 0
                ? $contextMaxTokens
                : (int) ($options['max_tokens'] ?? $options['max_completion_tokens'] ?? 16384);
            $payload['max_tokens'] = max(2048, min(65536, $maxTokens));
            $this->lastChatMaxTokens = (int) $payload['max_tokens'];
            // DeepSeek V4 默认开启 thinking；推理 token 计入 max_tokens，易导致 content 为空。
            // 资产提取/分镜等节点需要稳定 JSON/正文，因此对 DeepSeek 官方接口关闭 thinking。
            if ($this->shouldDisableDeepSeekThinking($endpoint, $modelId)) {
                $payload['thinking'] = ['type' => 'disabled'];
            }

            $creditUserId = $this->aiRequestLogUserId([
                'user_id' => (int) ($context['user_id'] ?? 0),
                'context_json' => $context,
                'workflow_run_id' => (int) ($context['workflow_run_id'] ?? 0),
            ]);
            \app\support\CreditService::assertTextAffordable(
                $creditUserId,
                \app\support\CreditService::estimatePromptTokensFromMessages($messages),
                (int) $payload['max_tokens'],
                $modelId
            );

            $headers = ['Content-Type: application/json'];
            if ($apiKey !== '') {
                $headers[] = 'Authorization: Bearer ' . $apiKey;
            }

            $ch = curl_init($endpoint);
            if ($ch === false) {
                $errorMessage = '初始化 AI 请求失败';
                abort(500, $errorMessage);
            }

            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeoutSeconds);
            // OpenRouter and some reverse proxies occasionally terminate long HTTP/2
            // response streams with CURLE_HTTP2_STREAM (92). Text generation is a
            // non-idempotent POST, so prefer HTTP/1.1 instead of retrying blindly.
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));

            $response = curl_exec($ch);
            $curlErrno = curl_errno($ch);
            $curlError = (string) curl_error($ch);
            $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($curlErrno !== 0) {
                $errorMessage = $curlErrno === 28
                    ? "AI 请求超时：{$timeoutSeconds} 秒内未完成返回。建议稍后重试，或拆短当前工作流节点 prompt / 降低一次生成内容量。原始错误：{$curlError}"
                    : 'AI 请求失败：' . $curlError;
                abort(502, $errorMessage);
            }
            if (!is_string($response) || $response === '') {
                $errorMessage = 'AI 返回为空';
                abort(502, $errorMessage);
            }

            $rawBody = $response;
            if ($httpStatus < 200 || $httpStatus >= 300) {
                $errorMessage = 'AI 服务异常：HTTP ' . $httpStatus . '，响应：' . mb_substr($response, 0, 300);
                abort(502, $errorMessage);
            }

            $decoded = json_decode($response, true);
            if (!is_array($decoded)) {
                $errorMessage = 'AI 返回非 JSON';
                abort(502, $errorMessage);
            }

            $message = is_array($decoded['choices'][0]['message'] ?? null)
                ? $decoded['choices'][0]['message']
                : [];
            $content = $message['content'] ?? '';
            if (is_array($content)) {
                $content = $this->joinContentParts($content);
            }

            $finishReason = (string) ($decoded['choices'][0]['finish_reason'] ?? '');
            $this->lastChatFinishReason = $finishReason;
            if (isset($decoded['usage']) && is_array($decoded['usage'])) {
                $usageJson = $decoded['usage'];
            }

            $content = trim((string) $content);
            if ($content === '') {
                // DeepSeek thinking 模式下正文可能落在 reasoning_content。
                $reasoning = $message['reasoning_content'] ?? ($message['reasoning'] ?? '');
                if (is_array($reasoning)) {
                    $reasoning = $this->joinContentParts($reasoning);
                }
                $reasoning = trim((string) $reasoning);
                if ($reasoning !== '') {
                    $content = $reasoning;
                }
            }
            if ($content === '') {
                $reasoningTokens = (int) ($usageJson['completion_tokens_details']['reasoning_tokens'] ?? 0);
                $completionTokens = (int) ($usageJson['completion_tokens'] ?? 0);
                if ($reasoningTokens > 0 && $reasoningTokens >= max(1, $completionTokens - 8)) {
                    $errorMessage = 'AI 仅返回了思考过程，未生成正文（reasoning 占满 max_tokens）。请关闭 thinking 或提高输出上限后重试';
                } else {
                    $errorMessage = 'AI 未返回可解析内容';
                }
                abort(502, $errorMessage);
            }

            $assistantContent = $content;
            $this->lastChatContentBytes = strlen($content);
            $requestOk = 1;
            $contentPreview = mb_substr($content, 0, 1000);

            return $content;
        } finally {
            $logErrorMessage = $errorMessage;
            if ($finishReason !== '' && $finishReason !== 'stop') {
                $finishHint = "[finish_reason={$finishReason}]";
                $logErrorMessage = $logErrorMessage === ''
                    ? $finishHint
                    : $finishHint . ' ' . $logErrorMessage;
            }

            $workflowRunId = (int) ($context['workflow_run_id'] ?? 0);
            $workflowRunNodeId = (int) ($context['workflow_run_node_id'] ?? 0);

            $this->lastAiRequestLogId = $this->insertAiRequestLog([
                'source' => (string) ($context['source'] ?? 'chat_completions'),
                'workflow_run_id' => $workflowRunId > 0 ? $workflowRunId : null,
                'workflow_run_node_id' => $workflowRunNodeId > 0 ? $workflowRunNodeId : null,
                'model_config_id' => (int) ($model->getAttr('id') ?? 0) ?: null,
                'llm_model' => (string) ($payload['model'] ?? ''),
                'finish_reason' => $finishReason,
                'max_tokens' => (int) ($payload['max_tokens'] ?? 0),
                'usage_json' => $usageJson !== [] ? $usageJson : null,
                'endpoint' => $endpoint,
                'context_json' => $this->encodeLogJson($context),
                'request_json' => $this->encodeLogJson($payload),
                'http_status' => $httpStatus,
                'response_body' => $this->truncateLogText($rawBody, 400000),
                'curl_errno' => $curlErrno,
                'curl_error' => mb_substr($curlError, 0, 512),
                'request_ok' => $requestOk,
                'error_message' => mb_substr($logErrorMessage, 0, 2000),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'content_preview' => $contentPreview,
                'assistant_content' => $this->truncateLogText($assistantContent, 2000000),
            ]) ?? 0;
        }
    }

    private function shouldDisableDeepSeekThinking(string $endpoint, string $modelId): bool
    {
        $endpoint = strtolower($endpoint);
        $modelId = strtolower($modelId);

        return str_contains($endpoint, 'api.deepseek.com')
            || str_starts_with($modelId, 'deepseek-v4')
            || str_starts_with($modelId, 'deepseek-chat')
            || str_starts_with($modelId, 'deepseek-reasoner');
    }

    private function resolveModelTimeoutSeconds(ModelConfig $model, string $envKey, int $default, int $min, int $max): int
    {
        $options = $model->getAttr('options') ?: [];
        if (!is_array($options)) {
            $options = [];
        }

        $optionKey = strtolower($envKey);
        $optionKey = str_replace(['ai_', '_seconds'], ['', ''], $optionKey);
        $value = $options[$optionKey] ?? env($envKey, $default);

        return max($min, min($max, (int) $value));
    }

    /**
     * 合并多段 AI content。
     * 兼容模型返回 content 数组的情况。
     */
    private function joinContentParts(array $content): string
    {
        $parts = [];
        foreach ($content as $chunk) {
            if (is_array($chunk) && isset($chunk['text']) && is_string($chunk['text'])) {
                $parts[] = $chunk['text'];
            }
        }

        return implode("\n", $parts);
    }

    /**
     * 构造写入 ai_request_logs.request_json 的 HTTP 快照（不含密钥）。
     *
     * @param array<string, mixed>|string $body
     * @return array<string, mixed>
     */
    private function buildAiRequestLogSnapshot(string $method, string $url, array $headers, array|string $body): array
    {
        return [
            'method' => strtoupper($method),
            'url' => $url,
            'headers' => $this->sanitizeHttpHeadersForLog($headers),
            'body' => $this->sanitizeAiRequestBodyForLog($body),
        ];
    }

    private function sanitizeAiRequestBodyForLog(array|string $body): array|string
    {
        if (is_string($body)) {
            return $this->sanitizeDataUriForLog($body);
        }

        $sanitized = [];
        foreach ($body as $key => $value) {
            if (is_array($value) || is_string($value)) {
                $sanitized[$key] = $this->sanitizeAiRequestBodyForLog($value);
                continue;
            }
            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    private function sanitizeDataUriForLog(string $value): string
    {
        if (!str_starts_with($value, 'data:')) {
            return $value;
        }

        $comma = strpos($value, ',');
        $prefix = $comma === false ? 'data:*;base64' : substr($value, 0, $comma);

        return $prefix . ',...[base64 ' . strlen($value) . ' chars]';
    }

    /**
     * @param array<int, string> $headers
     * @return array<int, string>
     */
    private function sanitizeHttpHeadersForLog(array $headers): array
    {
        $sanitized = [];
        foreach ($headers as $header) {
            $line = trim((string) $header);
            if ($line === '') {
                continue;
            }
            if (stripos($line, 'authorization:') === 0) {
                $sanitized[] = 'Authorization: Bearer ***';
                continue;
            }
            $sanitized[] = $line;
        }

        return $sanitized;
    }

    /**
     * 写入 AI 请求日志到 ai_request_logs，并打印到应用日志。
     * 日志失败只记录错误，不影响主流程。
     */
    private function insertAiRequestLog(array $row): ?int
    {
        try {
            $row['user_id'] = $this->aiRequestLogUserId($row);
            if (isset($row['request_json']) && is_array($row['request_json'])) {
                $row['request_json'] = $this->encodeLogJson($row['request_json']);
            }
            if (isset($row['context_json']) && is_array($row['context_json'])) {
                $row['context_json'] = $this->encodeLogJson($row['context_json']);
            }

            $log = \app\model\AiRequestLog::create($row);
            $id = (int) $log->getAttr('id');
            $source = (string) ($row['source'] ?? '');
            $preview = mb_substr((string) ($row['request_json'] ?? ''), 0, 4000);
            Log::info("[AiRequestLog#{$id}] {$source} request_json={$preview}");
            return $id;
        } catch (\Throwable $e) {
            Log::error('[AiRequestLog] insert failed: ' . $e->getMessage());
            return null;
        }
    }

    private function aiRequestLogUserId(array $row): int
    {
        $userId = (int) ($row['user_id'] ?? 0);
        if ($userId > 0) {
            return $userId;
        }

        $context = $row['context_json'] ?? null;
        if (is_array($context)) {
            $userId = (int) ($context['user_id'] ?? 0);
            if ($userId > 0) {
                return $userId;
            }

            $videoJobId = (int) ($context['video_job_id'] ?? 0);
            if ($videoJobId > 0) {
                $userId = (int) VideoJob::where('id', $videoJobId)->value('user_id');
                if ($userId > 0) {
                    return $userId;
                }
            }
        }

        $workflowRunId = (int) ($row['workflow_run_id'] ?? 0);
        if ($workflowRunId > 0) {
            $userId = (int) WorkflowRun::where('id', $workflowRunId)->value('user_id');
            if ($userId > 0) {
                return $userId;
            }
        }

        return $this->effectiveUserId();
    }

    /**
     * 编码日志 JSON。
     * 用于保存请求体和上下文，超长内容会截断。
     */
    private function encodeLogJson(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return '';
        }

        return $this->truncateLogText($json, 400000);
    }

    /**
     * 截断日志文本。
     * 避免超长请求或响应撑爆日志字段。
     */
    private function truncateLogText(string $text, int $maxBytes): string
    {
        if (strlen($text) <= $maxBytes) {
            return $text;
        }

        return mb_substr($text, 0, (int) ($maxBytes / 4)) . "\n...[truncated]";
    }

    /**
     * 计算工作流节点执行顺序。
     * 根据 edges 做拓扑排序；如果图不合法，则退回原节点顺序。
     */
    private function topologicalOrder(array $graph): array
    {
        $nodes = isset($graph['nodes']) && is_array($graph['nodes']) ? $graph['nodes'] : [];
        $edges = isset($graph['edges']) && is_array($graph['edges']) ? $graph['edges'] : [];

        $map = [];
        $indeg = [];
        $adj = [];
        foreach ($nodes as $n) {
            if (!is_array($n) || !isset($n['id'])) {
                continue;
            }
            $id = (string) $n['id'];
            $map[$id] = $n;
            $indeg[$id] = 0;
            $adj[$id] = [];
        }
        foreach ($edges as $e) {
            if (!is_array($e)) {
                continue;
            }
            $s = (string) ($e['source'] ?? '');
            $t = (string) ($e['target'] ?? '');
            if ($s === '' || $t === '' || !isset($map[$s]) || !isset($map[$t])) {
                continue;
            }
            $adj[$s][] = $t;
            $indeg[$t] = ($indeg[$t] ?? 0) + 1;
        }

        $queue = [];
        foreach ($indeg as $id => $d) {
            if ($d === 0) {
                $queue[] = $id;
            }
        }

        $ordered = [];
        while ($queue) {
            $id = array_shift($queue);
            $ordered[] = $map[$id];
            foreach ($adj[$id] ?? [] as $next) {
                $indeg[$next]--;
                if ($indeg[$next] === 0) {
                    $queue[] = $next;
                }
            }
        }

        if (count($ordered) !== count($map)) {
            return array_values(array_filter($nodes, static fn ($n) => is_array($n)));
        }
        return $ordered;
    }

    /**
     * 安全解析 AI 返回 JSON。
     * 1) 去掉 Markdown 代码块包裹符；
     * 2) 先尝试直接 json_decode；
     * 3) 再尝试截取最大 `{...}` 片段；
     * 4) 最后尝试基于堆栈的截断修复（处理模型输出被 max_tokens 截断的常见场景）。
     */
    private function safeParseJson(string $content): array
    {
        $this->lastJsonWasRepaired = false;

        $raw = trim($content);
        if ($raw === '') {
            return ['__raw' => ''];
        }
        if (str_starts_with($raw, '```')) {
            $raw = preg_replace('/^```[a-zA-Z]*\s*/', '', $raw) ?? $raw;
            $raw = preg_replace('/\s*```$/', '', $raw) ?? $raw;
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $sub = substr($raw, $start, $end - $start + 1);
            $decoded = json_decode($sub, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $repaired = $this->repairTruncatedJson($raw);
        if ($repaired !== null) {
            $this->lastJsonWasRepaired = true;
            return $repaired;
        }

        return ['__raw' => $content];
    }

    /**
     * JSON 节点偶尔会被模型用未转义引号、尾随说明等破坏语法。
     * 自动做一次“只修语法、不改内容”的二次请求，避免用户反复手动重跑。
     */
    private function repairRequiredJsonWithModel(ModelConfig $model, string $label, string $raw, array $context): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        $messages = [
            [
                'role' => 'system',
                'content' => implode("\n", [
                    '你是 JSON 语法修复器。',
                    '只输出一个合法 JSON 对象或数组，不要 Markdown，不要解释。',
                    '不得新增、删除、改写业务含义；只允许修复语法问题，例如未转义双引号、缺失逗号、尾随说明、代码块包裹。',
                    '如果字段值里包含引号，必须在 JSON 字符串内部转义为 \\"。',
                ]),
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'node_label' => $label,
                    'json_last_error' => json_last_error_msg(),
                    'invalid_json' => $raw,
                ], JSON_UNESCAPED_UNICODE),
            ],
        ];

        try {
            return $this->callChatCompletions($model, $messages, $context);
        } catch (\Throwable $e) {
            Log::warning('[SeriesWorkflow] JSON repair request failed: ' . $e->getMessage(), [
                'node_label' => $label,
                'origin_ai_request_log_id' => $context['origin_ai_request_log_id'] ?? null,
            ]);
            return '';
        }
    }

    /**
     * 尝试修复被截断的 JSON。
     * 思路：从第一个 `{` 或 `[` 开始扫描，跟踪字符串/转义状态和括号栈，
     * 记录最近一次"嵌套层内闭合到上一级"的位置；
     * 一旦遇到 EOF 还没回到顶层，就回退到这个位置，
     * 去掉末尾残缺逗号，并按当时的栈状态补上对应的 `}` / `]`。
     * 大多数"模型输出被 max_tokens 截断"的 JSON 数组都能用这个方法救回前面已生成的完整元素。
     *
     * @return array<int|string, mixed>|null
     */
    private function repairTruncatedJson(string $content): ?array
    {
        $startObj = strpos($content, '{');
        $startArr = strpos($content, '[');
        if ($startObj === false && $startArr === false) {
            return null;
        }
        if ($startObj === false) {
            $start = $startArr;
        } elseif ($startArr === false) {
            $start = $startObj;
        } else {
            $start = min($startObj, $startArr);
        }

        $s = substr($content, (int) $start);
        $n = strlen($s);

        $stack = [];
        $inString = false;
        $escape = false;
        $lastSafeCut = -1;
        $lastStackSnapshot = [];

        for ($i = 0; $i < $n; $i++) {
            $c = $s[$i];
            if ($inString) {
                if ($escape) {
                    $escape = false;
                } elseif ($c === '\\') {
                    $escape = true;
                } elseif ($c === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($c === '"') {
                $inString = true;
                continue;
            }
            if ($c === '{' || $c === '[') {
                $stack[] = $c;
                continue;
            }
            if ($c === '}' || $c === ']') {
                array_pop($stack);
                if (empty($stack)) {
                    $candidate = substr($s, 0, $i + 1);
                    $decoded = json_decode($candidate, true);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                } else {
                    $lastSafeCut = $i + 1;
                    $lastStackSnapshot = $stack;
                }
            }
        }

        if ($lastSafeCut <= 0 || empty($lastStackSnapshot)) {
            return null;
        }

        $candidate = rtrim(substr($s, 0, $lastSafeCut));
        if ($candidate !== '' && substr($candidate, -1) === ',') {
            $candidate = rtrim(substr($candidate, 0, -1));
        }

        while (!empty($lastStackSnapshot)) {
            $open = array_pop($lastStackSnapshot);
            $candidate .= $open === '{' ? '}' : ']';
        }

        $decoded = json_decode($candidate, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Episode / asset extraction: tolerant to many JSON shapes
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 从工作流结果中提取剧集列表。
     * 优先读取“剧集规划/分集规划”节点，也兼容常见嵌套字段。
     */
    private function extractEpisodesFromPipeline(array $pipeline): array
    {
        $candidates = [];
        foreach ($pipeline as $step) {
            if ($step['kind'] !== 'text' || !is_array($step['output'])) {
                continue;
            }
            if (str_contains((string) $step['label'], '剧集') || str_contains((string) $step['label'], '分集')) {
                array_unshift($candidates, $step['output']);
            } else {
                $candidates[] = $step['output'];
            }
        }

        foreach ($candidates as $payload) {
            $list = $this->findEpisodeList($payload);
            if ($list !== []) {
                return $this->normalizeEpisodeItems($list);
            }
        }
        return [];
    }

    /**
     * 在任意 JSON 结构中查找 episodes 列表。
     */
    private function findEpisodeList(array $payload): array
    {
        $paths = [
            ['episodes'],
            ['episode_outline'],
            ['episodeOutline'],
            ['outlines'],
            ['episode_list'],
            ['episodeList'],
            ['data', 'episodes'],
            ['data', 'episode_outline'],
            ['data', 'episodeOutline'],
            ['result', 'episodes'],
            ['result', 'episode_outline'],
            ['result', 'episodeOutline'],
            ['series_payload', 'episodes'],
            ['series_payload', 'episode_outline'],
            ['series_payload', 'episodeOutline'],
            ['seriesPayload', 'episodes'],
            ['seriesPayload', 'episode_outline'],
            ['seriesPayload', 'episodeOutline'],
            ['payload', 'episodes'],
            ['payload', 'episode_outline'],
            ['payload', 'episodeOutline'],
            ['series', 'episodes'],
            ['series', 'episode_outline'],
            ['series', 'episodeOutline'],
            ['series_payload', 'data', 'episodes'],
            ['series_payload', 'data', 'episode_outline'],
            ['series_payload', 'data', 'episodeOutline'],
        ];

        foreach ($paths as $path) {
            $node = $payload;
            $ok = true;
            foreach ($path as $key) {
                if (is_array($node) && array_key_exists($key, $node)) {
                    $node = $node[$key];
                } else {
                    $ok = false;
                    break;
                }
            }
            if ($ok && is_array($node) && array_is_list($node)) {
                return array_values(array_filter($node, static fn ($x) => is_array($x)));
            }
        }

        return [];
    }

    /**
     * 从 AI 剧集条目中提取剧情文本，写入 episodes.plot_input。
     * 兼容 plot / plot_input / summary 等字段，以及 scenes、key_events 等列表兜底。
     */
    private function extractEpisodePlotFromItem(array $item): string
    {
        $plot = trim((string) (
            $item['plot_input']
            ?? $item['plot']
            ?? $item['brief_summary']
            ?? $item['briefSummary']
            ?? $item['summary']
            ?? $item['description']
            ?? $item['synopsis']
            ?? $item['overview']
            ?? $item['hook']
            ?? $item['content']
            ?? ''
        ));

        if ($plot === '' && isset($item['scenes']) && is_array($item['scenes'])) {
            $parts = [];
            foreach ($item['scenes'] as $s) {
                if (is_array($s)) {
                    $desc = trim((string) ($s['description'] ?? $s['desc'] ?? $s['summary'] ?? $s['content'] ?? ''));
                } else {
                    $desc = trim((string) $s);
                }
                if ($desc !== '') {
                    $parts[] = $desc;
                }
            }
            $plot = implode("\n", $parts);
        }

        if ($plot === '') {
            $parts = [];
            $listFields = [
                'scenes' => '场景',
                'key_events' => '关键事件',
                'emotional_beats' => '情绪转折',
                'shot_sequence' => '镜头序列',
                'assets_used' => '涉及资产',
            ];
            foreach ($listFields as $field => $label) {
                if (!isset($item[$field]) || !is_array($item[$field])) {
                    continue;
                }
                $values = [];
                foreach ($item[$field] as $entry) {
                    if (is_array($entry)) {
                        $value = trim((string) (
                            $entry['description']
                            ?? $entry['desc']
                            ?? $entry['summary']
                            ?? $entry['content']
                            ?? $entry['name']
                            ?? $entry['title']
                            ?? ''
                        ));
                        if ($value === '') {
                            $value = $this->stringifyAssetValue($entry);
                        }
                    } else {
                        $value = trim((string) $entry);
                    }
                    if ($value !== '') {
                        $values[] = $value;
                    }
                }
                if ($values !== []) {
                    $parts[] = $label . '：' . implode('；', $values);
                }
            }
            $plot = implode("\n", $parts);
        }

        return $plot;
    }

    /**
     * 规范化 AI 返回的剧集结构。
     * 统一转成 number、title、plot_input，兼容 scenes 兜底。
     */
    private function normalizeEpisodeItems(array $items): array
    {
        $out = [];
        foreach ($items as $i => $item) {
            if (!is_array($item)) {
                continue;
            }
            $number = (int) (
                $item['number']
                ?? $item['episode']
                ?? $item['episode_no']
                ?? $item['episode_number']
                ?? $item['episodeNumber']
                ?? ($i + 1)
            );
            $title  = trim((string) ($item['title'] ?? ''));

            $out[] = [
                'number' => $number > 0 ? $number : ($i + 1),
                'title' => $title,
                'plot_input' => $this->extractEpisodePlotFromItem($item),
            ];
        }
        return $out;
    }

    /**
     * 从工作流结果中提取资产列表。
     * 优先读取资产节点，兼容 assets_payload、characters、locations、props 等结构。
     */
    private function extractAssetsFromPipeline(array $pipeline): array
    {
        $candidates = [];
        foreach ($pipeline as $step) {
            if ($step['kind'] !== 'text' || !is_array($step['output'])) {
                continue;
            }
            if (str_contains((string) $step['label'], '资产')) {
                array_unshift($candidates, $step['output']);
            } else {
                $candidates[] = $step['output'];
            }
        }

        foreach ($candidates as $payload) {
            $assets = $this->collectAssetsFromAny($payload);
            if ($assets !== []) {
                return $assets;
            }
        }
        return [];
    }

    /**
     * 从多个可能的资产容器中收集资产。
     */
    private function collectAssetsFromAny(array $payload): array
    {
        return $this->collectAssetsFromAnyDepth($payload, 0);
    }

    /**
     * 递归收集资产，兼容超长剧本按 chunks/segments/batches 分块输出的结构。
     */
    private function collectAssetsFromAnyDepth(array $payload, int $depth): array
    {
        $sources = [];
        $sources[] = $payload;
        foreach (['assets_payload', 'assetsPayload', 'payload', 'assetsData', 'asset_payload', 'assetPayload'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $sources[] = $payload[$key];
            }
        }
        foreach (['series_payload', 'seriesPayload'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $sources[] = $payload[$key];
            }
        }
        foreach (['data', 'result'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $sources[] = $payload[$key];
                if (isset($payload[$key]['assets']) && is_array($payload[$key]['assets'])) {
                    $sources[] = $payload[$key];
                }
            }
        }

        foreach ($sources as $src) {
            $assets = $this->collectAssetsFromBlock(is_array($src) ? $src : []);
            if ($assets !== []) {
                return $assets;
            }
        }

        if ($depth >= 4) {
            return [];
        }

        $nestedKeys = [
            'chunks',
            'chunk',
            'segments',
            'segment',
            'parts',
            'part',
            'batches',
            'batch',
            'asset_chunks',
            'assetChunks',
            'asset_batches',
            'assetBatches',
            'results',
            'outputs',
            'items',
            'records',
        ];
        $merged = [];
        foreach ($nestedKeys as $key) {
            if (!isset($payload[$key]) || !is_array($payload[$key])) {
                continue;
            }
            $nested = $payload[$key];
            if (array_is_list($nested)) {
                foreach ($nested as $item) {
                    if (is_array($item)) {
                        $merged = array_merge($merged, $this->collectAssetsFromAnyDepth($item, $depth + 1));
                    }
                }
            } else {
                $merged = array_merge($merged, $this->collectAssetsFromAnyDepth($nested, $depth + 1));
            }
        }
        return $this->dedupeAssets($merged);
    }

    /**
     * 从单个 JSON 块中收集资产。
     * 兼容平铺 assets 和按角色/场景/道具分组的资产。
     */
    private function collectAssetsFromBlock(array $block): array
    {
        $lower = $this->lowerKeysShallow($block);

        // 1) flat list under `assets`
        if (isset($lower['assets']) && is_array($lower['assets']) && array_is_list($lower['assets'])) {
            return array_values(array_filter($lower['assets'], static fn ($x) => is_array($x)));
        }

        // 2) grouped under `assets`
        $groups = [];
        if (isset($lower['assets']) && is_array($lower['assets']) && !array_is_list($lower['assets'])) {
            $groups = $this->lowerKeysShallow($lower['assets']);
        } else {
            // 3) grouped at top level (user prompt uses uppercase CHARACTERS/LOCATIONS/...)
            $groups = $lower;
        }

        $groupMap = [
            'characters' => 'character',
            'character' => 'character',
            'casts' => 'character',
            'cast' => 'character',
            'persons' => 'character',
            'person' => 'character',
            'roles' => 'character',
            'role' => 'character',
            'people' => 'character',
            '人物' => 'character',
            '角色' => 'character',
            'locations' => 'scene',
            'location' => 'scene',
            'places' => 'scene',
            'place' => 'scene',
            'scenes' => 'scene',
            'scene' => 'scene',
            'settings' => 'scene',
            'setting' => 'scene',
            'environments' => 'scene',
            'environment' => 'scene',
            'sets' => 'scene',
            'set' => 'scene',
            '场景' => 'scene',
            '地点' => 'scene',
            'props' => 'prop',
            'prop' => 'prop',
            'key_props' => 'prop',
            'keyprops' => 'prop',
            'items' => 'prop',
            'item' => 'prop',
            'objects' => 'prop',
            'object' => 'prop',
            '道具' => 'prop',
            '物品' => 'prop',
        ];

        $merged = [];
        foreach ($groupMap as $groupKey => $type) {
            $items = $groups[$groupKey] ?? null;
            if (!is_array($items) || !array_is_list($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if (!isset($item['type']) || (string) $item['type'] === '') {
                    $item['type'] = $type;
                }
                $merged[] = $item;
            }
        }
        return $merged;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectCharacterLooksFromAny(array $payload): array
    {
        return $this->collectCharacterLooksFromAnyDepth($payload, 0);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectCharacterLooksFromAnyDepth(array $payload, int $depth): array
    {
        $sources = [$payload];
        foreach (['assets_payload', 'assetsPayload', 'payload', 'data', 'result'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $sources[] = $payload[$key];
            }
        }

        foreach ($sources as $src) {
            $looks = $this->collectCharacterLooksFromBlock(is_array($src) ? $src : []);
            if ($looks !== []) {
                return $looks;
            }
        }

        if ($depth >= 4) {
            return [];
        }

        $merged = [];
        foreach (['chunks', 'segments', 'parts', 'batches', 'results', 'outputs', 'items', 'records'] as $key) {
            $nested = $payload[$key] ?? null;
            if (!is_array($nested)) {
                continue;
            }
            if (array_is_list($nested)) {
                foreach ($nested as $item) {
                    if (is_array($item)) {
                        $merged = array_merge($merged, $this->collectCharacterLooksFromAnyDepth($item, $depth + 1));
                    }
                }
                continue;
            }
            $merged = array_merge($merged, $this->collectCharacterLooksFromAnyDepth($nested, $depth + 1));
        }

        return $this->dedupeCharacterLooks($merged);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectCharacterLooksFromBlock(array $block): array
    {
        $lower = $this->lowerKeysShallow($block);
        $items = $lower['character_looks'] ?? $lower['characterlooks'] ?? $lower['looks'] ?? null;
        if (!is_array($items) || !array_is_list($items)) {
            return [];
        }

        $looks = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $characterName = trim((string) ($item['character_name'] ?? $item['character'] ?? $item['name'] ?? ''));
            $lookName = trim((string) ($item['look_name'] ?? $item['variant_name'] ?? $item['variant'] ?? ''));
            if ($characterName === '' || $lookName === '') {
                continue;
            }
            $tags = $item['tags'] ?? [];
            if (!is_array($tags)) {
                $tags = [];
            }
            $looks[] = [
                'character_name' => $characterName,
                'look_name' => $lookName,
                'description' => trim((string) ($item['description'] ?? $item['desc'] ?? '')),
                'image_prompt' => trim((string) ($item['image_prompt'] ?? $item['imagePrompt'] ?? $item['prompt'] ?? '')),
                'tags' => array_values(array_filter(array_map(static fn ($tag): string => trim((string) $tag), $tags), static fn (string $tag): bool => $tag !== '')),
                'match_character' => trim((string) ($item['match_character'] ?? $item['matchCharacter'] ?? $characterName)),
            ];
        }

        return $looks;
    }

    /**
     * @param array<int, array<string, mixed>> $looks
     * @return array<int, array<string, mixed>>
     */
    private function dedupeCharacterLooks(array $looks): array
    {
        $seen = [];
        $result = [];
        foreach ($looks as $look) {
            if (!is_array($look)) {
                continue;
            }
            $characterName = trim((string) ($look['match_character'] ?? $look['character_name'] ?? ''));
            $lookName = trim((string) ($look['look_name'] ?? ''));
            if ($characterName === '' || $lookName === '') {
                continue;
            }
            $key = mb_strtolower($characterName . '|' . $lookName);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $look;
        }
        return $result;
    }

    /**
     * 合并分块资产时按 type/name 去重。
     */
    private function dedupeAssets(array $assets): array
    {
        $seen = [];
        $out = [];
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $name = $this->extractAssetName($asset);
            $type = $this->normalizeAssetType((string) ($asset['type'] ?? ''));
            $key = ($type ?? '') . '|' . mb_strtolower($name);
            if ($name !== '' && isset($seen[$key])) {
                continue;
            }
            if ($name !== '') {
                $seen[$key] = true;
            }
            $out[] = $asset;
        }
        return $out;
    }

    /**
     * 角色造型版本：每个出场人物至少保证一套可引用 look。
     *
     * @param array<int, array<string, mixed>> $assets
     * @param array<int, array<string, mixed>> $looks
     * @return array<int, array<string, mixed>>
     */
    private function ensureEpisodeCharacterLooks(array $assets, array $looks, int $episodeNumber, int &$added, string $visualStyle = 'realistic'): array
    {
        $added = 0;
        $visualStyle = $this->normalizeVisualStyle($visualStyle);
        $characters = [];
        foreach ($assets as $asset) {
            if (!is_array($asset) || $this->normalizeAssetType((string) ($asset['type'] ?? '')) !== 'character') {
                continue;
            }
            $name = $this->extractAssetName($asset);
            if ($name !== '') {
                $characters[$name] = $asset;
            }
        }

        foreach ($characters as $name => $character) {
            if (!CharacterAssetClassifier::shouldAutoGenerateLooks($character)) {
                continue;
            }
            if ($this->hasCharacterLookFor($looks, $name)) {
                continue;
            }
            $looks[] = $this->makeEpisodeDefaultCharacterLook($name, $character, $episodeNumber, $visualStyle);
            $added++;
        }

        return $this->dedupeCharacterLooks($looks);
    }

    /**
     * 模型只出造型、漏人物本体时，按 character_looks 反推补 type=character。
     *
     * @param array<int, array<string, mixed>> $assets
     * @param array<int, array<string, mixed>> $looks
     * @return array<int, array<string, mixed>>
     */
    private function ensureCharactersFromLooks(array $assets, array $looks, int &$added): array
    {
        $added = 0;
        $known = [];
        foreach ($assets as $asset) {
            if (!is_array($asset) || $this->normalizeAssetType((string) ($asset['type'] ?? '')) !== 'character') {
                continue;
            }
            $name = $this->extractAssetName($asset);
            if ($name !== '') {
                $known[mb_strtolower($name)] = true;
            }
        }

        foreach ($looks as $look) {
            if (!is_array($look)) {
                continue;
            }
            $characterName = trim((string) ($look['match_character'] ?? $look['character_name'] ?? ''));
            if ($characterName === '') {
                continue;
            }
            $key = mb_strtolower($characterName);
            if (isset($known[$key])) {
                continue;
            }
            $assets[] = $this->makeCharacterAssetFromLook($characterName, $look);
            $known[$key] = true;
            $added++;
        }

        return $this->dedupeAssets($assets);
    }

    /**
     * @param array<string, mixed> $look
     * @return array<string, mixed>
     */
    private function makeCharacterAssetFromLook(string $characterName, array $look): array
    {
        $lookName = trim((string) ($look['look_name'] ?? ''));
        $lookDesc = trim((string) ($look['description'] ?? ''));
        $identity = $lookDesc !== '' ? $lookDesc : ($lookName !== '' ? $lookName : $characterName);

        return [
            'name' => $characterName,
            'type' => 'character',
            'description' => $characterName . '的基础人物设定。身份与外貌可参考本集造型线索：' . $identity . '。本条只描述人物本体，不写入剧情服装。',
            'image_prompt' => '角色「' . $characterName . '」基础形态参考图：白底棚拍，无手持物，统一穿素色中性长袖长裤连体服与简洁平底鞋；头部五官完整可见；禁止剧情服装、盔甲、武器和场景。',
            'tags' => ['synthesized_from_look', 'episode_asset_prep'],
            'match_name' => $characterName,
        ];
    }

    private function hasCharacterLookFor(array $looks, string $characterName): bool
    {
        $characterName = trim($characterName);
        if ($characterName === '') {
            return false;
        }

        foreach ($looks as $look) {
            if (!is_array($look)) {
                continue;
            }
            $match = trim((string) ($look['match_character'] ?? $look['character_name'] ?? ''));
            if ($match !== '' && mb_strtolower($match) === mb_strtolower($characterName)) {
                return true;
            }
        }

        return false;
    }

    private function makeEpisodeDefaultCharacterLook(string $characterName, array $character, int $episodeNumber, string $visualStyle = 'realistic'): array
    {
        $episodeLabel = $episodeNumber > 0 ? '第' . $episodeNumber . '集' : '本集';
        $description = trim((string) $this->buildAssetDescription($character, 'character'));
        $identityHint = $description !== '' ? '；保持角色既有脸型、发型、体型与气质一致：' . $description : '';
        $lookBoard = $this->characterLookImagePromptRuleForStyle($visualStyle);

        return [
            'character_name' => $characterName,
            'look_name' => $episodeLabel . '默认造型',
            'description' => $episodeLabel . $characterName . '本集的基础外穿造型，服装需要符合剧情时代、身份、职业/阶层与场景，至少包含完整外穿上衣、下装和鞋履，可含外套、帽子或基础配饰；禁止裸露、内衣化或只剩贴身打底服' . $identityHint,
            'image_prompt' => $episodeLabel . $characterName . '人物造型参考图：' . $lookBoard . '白底棚拍，保持人物脸部、发型、体型稳定，穿着完整外穿服装套装，服装符合角色时代身份和本集剧情，不包含手持道具、不混入场景背景',
            'tags' => ['character_look', 'episode_asset_prep', 'inferred_look'],
            'match_character' => $characterName,
        ];
    }

    /**
     * 造型 image_prompt 构图规则：全风格统一左右分栏造型板。
     */
    private function characterLookImagePromptRuleForStyle(string $visualStyle = 'realistic'): string
    {
        return '输出左右分栏造型板：左栏正侧背三个无头全身（头从衣领处切除），右栏同一人物放大特写（头部五官完整）；禁止红笔涂脸，禁止只出一张带头正面全身。';
    }

    /**
     * 动漫/3D 造型：从 LLM/存量文案中剥离写实过人脸与红笔指令，避免混写污染生图。
     */
    private function stripCharacterLookFacePassInstructions(string $prompt): string
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            return '';
        }

        $patterns = [
            '/写实(?:风格|\/真人|\/真人风格)?[^。\n]{0,12}过人脸标准板[（(][^）)]*[）)][^。；;\n]*/u',
            '/写实(?:风格|\/真人|\/真人风格)?[^。；;\n]{0,40}过人脸标准板[^。；;\n]{0,200}/u',
            '/过人脸标准板[（(][^）)]*[）)]/u',
            '/过人脸标准板[^。；;\n]{0,200}/u',
            '/红笔穿过眼鼻嘴[^。；;\n]{0,100}/u',
            '/粗糙红笔[^。；;\n]{0,80}/u',
            '/(?:不要去头、?不要红笔|不要去头不要红笔)[^。；;\n]{0,80}/u',
            '/动漫(?:\/卡通)?(?:\/3D|\/?3D)?[^。；;\n]{0,12}(?:不要去头|不要红笔|出带头)[^。；;\n]{0,80}/u',
            '/(?:；|;)\s*动漫(?:\/卡通)?(?:\/3D)?\s*(?=。|；|;|$)/u',
            '/face-pass\s+board[^.。；;\n]{0,200}/iu',
            '/messy\s+red\s+marker[^.。；;\n]{0,160}/iu',
            '/red\s+(?:marker\s+)?scribbles?[^.。；;\n]{0,120}/iu',
            '/do\s+not\s+draw\s+red\s+marks[^.。；;\n]{0,80}/iu',
        ];

        foreach ($patterns as $pattern) {
            $prompt = (string) preg_replace($pattern, '', $prompt);
        }

        $prompt = (string) preg_replace('/[；;]{2,}/u', '；', $prompt);
        $prompt = (string) preg_replace('/[，,]{2,}/u', '，', $prompt);
        $prompt = (string) preg_replace('/\s{2,}/u', ' ', $prompt);
        $prompt = (string) preg_replace('/[：:]\s*[。．\.；;]/u', '。', $prompt);
        $prompt = (string) preg_replace('/[：:]\s*$/u', '', $prompt);
        $prompt = (string) preg_replace('/\s*[；;]\s*(?=[。．.]|$)/u', '', $prompt);
        $prompt = trim($prompt, " \t\n\r\0\x0B：:；;，,。．.");

        if ($prompt === '' || !preg_match('/[\p{L}\p{N}]/u', $prompt)) {
            return '人物造型参考图：左栏正侧背三个无头全身，右栏同一人物放大特写，白底棚拍，重点变化在服装与整体造型';
        }

        if (preg_match('/人物造型参考图\s*$/u', $prompt) === 1
            || preg_match('/参考图\s*$/u', $prompt) === 1
        ) {
            return rtrim($prompt, '：: ') . '：左栏正侧背三个无头全身，右栏同一人物放大特写，白底棚拍，重点变化在服装与整体造型';
        }

        return $prompt;
    }

    private function sanitizeCharacterLookImagePrompt(string $prompt, string $visualStyle = 'realistic'): string
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            return '';
        }

        // 只剥离旧版红笔涂脸；左无头三视 + 右特写是当前构图。
        return $this->stripCharacterLookFacePassInstructions($prompt);
    }

    private function workflowNodeSemanticKey(string $label, string $kind, int $sort): string
    {
        $label = trim($label);
        $kind = trim($kind);
        if ($label !== '' || $kind !== '') {
            return $sort . '|' . $kind . '|' . $label;
        }

        return (string) $sort;
    }

    private function isBetterWorkflowRunNode(WorkflowRunNode $candidate, WorkflowRunNode $current): bool
    {
        $candidateScore = $this->workflowRunNodeDisplayScore($candidate);
        $currentScore = $this->workflowRunNodeDisplayScore($current);
        if ($candidateScore !== $currentScore) {
            return $candidateScore > $currentScore;
        }

        return (int) $candidate->getAttr('id') > (int) $current->getAttr('id');
    }

    private function findBestWorkflowRunNodeBySemantic(int $runId, string $label, string $kind, int $sort): ?WorkflowRunNode
    {
        if ($sort <= 0) {
            return null;
        }
        $rows = WorkflowRunNode::where('run_id', $runId)
            ->where('label', $label)
            ->where('kind', $kind)
            ->where('sort', $sort)
            ->select();

        $best = null;
        foreach ($rows as $row) {
            if (!$row instanceof WorkflowRunNode) {
                continue;
            }
            if (!$best instanceof WorkflowRunNode || $this->isBetterWorkflowRunNode($row, $best)) {
                $best = $row;
            }
        }

        return $best;
    }

    private function workflowNodeSortInRun(int $runId, string $workflowNodeId): int
    {
        if ($workflowNodeId === '') {
            return 0;
        }
        $node = WorkflowRunNode::where('run_id', $runId)
            ->where('workflow_node_id', $workflowNodeId)
            ->find();

        return $node instanceof WorkflowRunNode ? (int) $node->getAttr('sort') : 0;
    }

    private function workflowRunNodeDisplayScore(WorkflowRunNode $node): int
    {
        $score = 0;
        $status = (string) $node->getAttr('status');
        if ($status === 'success') {
            $score += 100;
        } elseif ($status === 'running') {
            $score += 60;
        } elseif ($status === 'failed') {
            $score += 40;
        }

        $output = $node->getAttr('output_json') ?: [];
        if (is_array($output) && $output !== []) {
            $score += 20;
        }
        if (trim((string) $node->getAttr('raw_output')) !== '') {
            $score += 20;
        }

        return $score;
    }

    /**
     * 工作流节点 id 变更时，用运行记录的步骤语义映射回当前流程节点。
     *
     * @param array<int, array<string, mixed>> $nodes
     */
    private function findWorkflowNodeByRunNodeSemantic(array $nodes, WorkflowRunNode $runNode): ?array
    {
        $targetKey = $this->workflowNodeSemanticKey(
            (string) $runNode->getAttr('label'),
            (string) $runNode->getAttr('kind'),
            (int) $runNode->getAttr('sort'),
        );

        foreach (array_values($nodes) as $index => $node) {
            if (!is_array($node)) {
                continue;
            }
            $key = $this->workflowNodeSemanticKey(
                (string) ($node['label'] ?? $node['data']['label'] ?? ''),
                (string) ($node['data']['kind'] ?? $node['kind'] ?? ''),
                $index + 1,
            );
            if ($key === $targetKey) {
                return $node;
            }
        }

        return null;
    }

    /**
     * 兼容不同 prompt 里常见的资产名称字段。
     */
    private function extractAssetName(array $asset): string
    {
        foreach (['name', 'asset_name', 'assetName', 'title', 'label', '名称', '角色名', '场景名', '地点名', '道具名'] as $key) {
            if (isset($asset[$key])) {
                $name = trim((string) $asset[$key]);
                if ($name !== '') {
                    return $name;
                }
            }
        }
        return '';
    }

    /**
     * 归一化资产类型，避免自定义工作流使用别名时无法入库。
     */
    private function normalizeAssetType(string $type): ?string
    {
        $type = trim($type);
        $lower = strtolower($type);
        return match ($lower) {
            'character', 'characters', 'person', 'people', 'role', 'roles', 'cast', 'casts', 'character_asset' => 'character',
            'scene', 'scenes', 'location', 'locations', 'place', 'places', 'setting', 'settings', 'environment', 'environments', 'set', 'sets', 'scene_asset', 'location_asset' => 'scene',
            'prop', 'props', 'item', 'items', 'object', 'objects', 'key_prop', 'key_props', 'prop_asset', 'item_asset' => 'prop',
            '人物', '角色', '人物资产', '角色资产' => 'character',
            '场景', '地点', '位置', '环境', '场景资产', '地点资产' => 'scene',
            '道具', '物品', '道具资产', '物品资产' => 'prop',
            default => null,
        };
    }

    /**
     * 生成资产描述。
     * 兼容 AI 返回的结构化字段，避免只看 description 导致人物卡片显示为空。
     */
    private function buildAssetDescription(array $asset, string $type): string
    {
        $direct = trim((string) (
            $asset['description']
            ?? $asset['desc']
            ?? $asset['summary']
            ?? $asset['visual_description']
            ?? $asset['visualDescription']
            ?? $asset['视觉描述']
            ?? $asset['描述']
            ?? $asset['简介']
            ?? ''
        ));
        if ($direct !== '') {
            return $direct;
        }

        $fields = match ($type) {
            'character' => [
                'role' => '角色',
                'age' => '年龄',
                'gender' => '性别',
                'goal' => '目标',
                'obstacle' => '阻碍',
                'appearance_traits' => '外貌特征',
                'costume_palette' => '服装色系',
            ],
            'scene' => [
                'type' => '空间类型',
                'time_of_day' => '时间',
                'lighting_mood' => '光线氛围',
                'key_props' => '场景锚点',
                'reuse_count' => '复用次数',
            ],
            default => [
                'category' => '类别',
                'owner' => '归属角色',
                'first_appearance_episode' => '首次出现集数',
                'trigger_condition' => '触发条件',
                'visual_description' => '视觉描述',
            ],
        };

        $parts = [];
        foreach ($fields as $field => $label) {
            if (!array_key_exists($field, $asset)) {
                continue;
            }
            $value = $this->stringifyAssetValue($asset[$field]);
            if ($value !== '') {
                $parts[] = $label . '：' . $value;
            }
        }

        return implode('；', $parts);
    }

    /**
     * 生成资产生图提示词。
     * 优先使用 AI 返回的 image_prompt；没有时用名称、类型和描述拼出可用提示词。
     */
    private function buildAssetImagePrompt(array $asset, string $type, string $description): string
    {
        $prompt = trim((string) (
            $asset['image_prompt']
            ?? $asset['imagePrompt']
            ?? $asset['visual_prompt']
            ?? $asset['visualPrompt']
            ?? $asset['prompt']
            ?? $asset['生图提示词']
            ?? $asset['提示词']
            ?? ''
        ));
        if ($prompt !== '') {
            return $prompt;
        }

        $desc = trim($description);
        if ($desc !== '') {
            return $desc;
        }

        $name = $this->extractAssetName($asset);
        $prefix = match ($type) {
            'character' => '角色设定参考',
            'scene' => '场景概念图',
            default => '关键道具参考',
        };

        return trim($prefix . ($name !== '' ? "：{$name}" : ''), " ：");
    }

    /**
     * 把资产字段值转成可读文本。
     */
    private function stringifyAssetValue(mixed $value): string
    {
        if (is_array($value)) {
            $items = [];
            foreach ($value as $item) {
                if (is_array($item)) {
                    $items[] = implode('，', array_filter(array_map(
                        fn ($v): string => $this->stringifyAssetValue($v),
                        $item
                    )));
                } else {
                    $items[] = trim((string) $item);
                }
            }
            return implode('、', array_values(array_filter($items, static fn ($item): bool => $item !== '')));
        }

        return trim((string) $value);
    }

    /**
     * 浅层转换数组键名为小写。
     * 用于兼容 AI 返回的大小写不同字段。
     */
    private function lowerKeysShallow(array $arr): array
    {
        $out = [];
        foreach ($arr as $k => $v) {
            $out[is_string($k) ? strtolower($k) : $k] = $v;
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Persistence
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 原子写入剧本工作流结果。
     * AI 调用已经完成后再开启短事务，避免长时间占用数据库连接和锁。
     */
    private function persistSeriesWorkflowCheckpoint(
        int $seriesId,
        string $label,
        array $output,
        ?int $episodeWorkflowId,
    ): void {
        if ($output === []) {
            return;
        }

        try {
            $episodes = [];
            if (str_contains($label, '剧集') || str_contains($label, '分集')) {
                $episodes = $this->normalizeEpisodeItems($this->findEpisodeList($output));
            }
            $assets = [];

            if ($episodes === [] && $assets === []) {
                return;
            }

            Db::transaction(function () use ($seriesId, $episodes, $episodeWorkflowId, $assets): void {
                if ($episodes !== []) {
                    $this->upsertEpisodesFromSeriesParse($seriesId, $episodes, $episodeWorkflowId);
                }
            });
        } catch (\Throwable $e) {
            Log::warning('[SeriesWorkflowCheckpoint] failed: ' . $e->getMessage());
        }
    }

    private function persistSeriesWorkflowResult(
        int $seriesId,
        array $episodes,
        ?int $episodeWorkflowId,
        array $assets,
    ): void {
        Db::transaction(function () use ($seriesId, $episodes, $episodeWorkflowId): void {
            $this->upsertEpisodesFromSeriesParse($seriesId, $episodes, $episodeWorkflowId);
        });
    }

    /**
     * 写入或更新剧集拆解结果。
     * 按顺序覆盖已有剧集，不足则新增，多余则删除。
     */
    private function upsertEpisodesFromSeriesParse(int $seriesId, array $episodes, ?int $episodeWorkflowId = null): void
    {
        $series = Series::where('id', $seriesId)->where('user_id', $this->effectiveUserId())->find();
        if (!$series instanceof Series) {
            abort(404, '剧本不存在');
        }
        $ownerId = $this->seriesOwnerId($series);
        $region = $this->normalizeSeriesRegion((string) ($series?->getAttr('region') ?? 'china'));
        $existing = Episode::where('series_id', $seriesId)
            ->where('user_id', $ownerId)
            ->order('number', 'asc')
            ->select()
            ->all();
        $count = count($episodes);

        for ($i = 0; $i < $count; $i++) {
            $item = is_array($episodes[$i]) ? $episodes[$i] : [];
            $title = trim((string) ($item['title'] ?? ''));
            $plotInput = $this->extractEpisodePlotFromItem($item);
            $number = $i + 1;
            if ($title === '') {
                $title = $region === 'western' ? 'Episode ' . $number : '第' . $number . '集';
            }
            if (isset($existing[$i]) && $existing[$i] instanceof Episode) {
                $updateData = [
                    'user_id' => $ownerId,
                    'number' => $number,
                    'title' => $title,
                    'plot_input' => $plotInput,
                    'status' => 'draft',
                ];
                if ((int) ($existing[$i]->getAttr('workflow_id') ?? 0) <= 0 && $episodeWorkflowId !== null) {
                    $updateData['workflow_id'] = $episodeWorkflowId;
                }
                $existing[$i]->save($updateData);
            } else {
                $ep = new Episode();
                $ep->save([
                    'user_id' => $ownerId,
                    'series_id' => $seriesId,
                    'number' => $number,
                    'title' => $title,
                    'status' => 'draft',
                    'workflow_id' => $episodeWorkflowId,
                    'plot_input' => $plotInput,
                ]);
            }
        }

        if (count($existing) > $count) {
            for ($j = $count; $j < count($existing); $j++) {
                if ($existing[$j] instanceof Episode) {
                    ShotMediaVersion::where('episode_id', (int) $existing[$j]->id)->delete();
                    VideoJob::where('episode_id', (int) $existing[$j]->id)->delete();
                    StoryboardRevision::where('episode_id', (int) $existing[$j]->id)->delete();
                    Shot::where('episode_id', $existing[$j]->id)->delete();
                    $existing[$j]->delete();
                }
            }
        }

        $this->clearSeriesCache();
    }

    /**
     * 写入或更新资产提取结果。
     * 按剧本、类型、名称去重；已有资产保守补充信息，新资产新增。
     */
    private function upsertAssetsFromSeriesParse(int $seriesId, array $assets, array $meta = []): array
    {
        $this->ensureAssetLookState($seriesId);
        $series = Series::where('id', $seriesId)->where('user_id', $this->effectiveUserId())->find();
        if (!$series instanceof Series) {
            abort(404, '剧本不存在');
        }
        $ownerId = $this->seriesOwnerId($series);
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'total' => 0];

        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                $stats['skipped']++;
                continue;
            }
            $name = $this->extractAssetName($asset);
            if ($name === '') {
                $stats['skipped']++;
                continue;
            }
            $type = $this->normalizeAssetType((string) ($asset['type'] ?? ''));
            if ($type === null) {
                $stats['skipped']++;
                continue;
            }
            $stats['total']++;
            $description = $this->buildAssetDescription($asset, $type);
            $imagePrompt = $this->buildAssetImagePrompt($asset, $type, $description);
            $tags = $asset['tags'] ?? [];
            if (!is_array($tags)) {
                $tags = [];
            }
            $tags = array_values(array_filter(array_map(static fn ($t) => trim((string) $t), $tags), static fn ($t) => $t !== ''));
            if ($type === 'character') {
                $tags = array_values(array_unique(array_merge($tags, CharacterAssetClassifier::supplementalTags($asset))));
            }
            if (!in_array('auto_series_parse', $tags, true)) {
                $tags[] = 'auto_series_parse';
            }
            if (($meta['source'] ?? '') === 'episode_asset_prep') {
                $tags[] = 'episode_asset_prep';
                $episodeNumber = (int) ($meta['episode_number'] ?? 0);
                if ($episodeNumber > 0) {
                    $tags[] = 'episode_' . $episodeNumber;
                }
            }

            $exists = Asset::where('series_id', $seriesId)
                ->where('user_id', $ownerId)
                ->where('type', $type)
                ->where('name', $name)
                ->find();
            if ($exists instanceof Asset) {
                $existingTags = $exists->getAttr('tags') ?: [];
                if (!is_array($existingTags)) {
                    $existingTags = [];
                }
                $mergedTags = array_values(array_unique(array_filter(array_merge(
                    array_map(static fn ($t) => trim((string) $t), $existingTags),
                    $tags,
                ), static fn ($t) => $t !== '')));
                $existingDescription = trim((string) $exists->getAttr('description'));
                $existingPrompt = trim((string) $exists->getAttr('image_prompt'));
                $exists->save([
                    'description' => $this->chooseAssetDescription($existingDescription, $description),
                    'image_prompt' => $existingPrompt !== '' ? $existingPrompt : $imagePrompt,
                    'tags' => $mergedTags,
                ]);
                $stats['updated']++;
            } else {
                $sort = ((int) Asset::where('series_id', $seriesId)->where('user_id', $ownerId)->where('type', $type)->max('sort')) + 10;
                $new = new Asset();
                $new->save([
                    'user_id' => $ownerId,
                    'series_id' => $seriesId,
                    'type' => $type,
                    'name' => $name,
                    'description' => $description,
                    'image_prompt' => $imagePrompt,
                    'tags' => $tags,
                    'sort' => $sort,
                ]);
                $stats['created']++;
            }
        }

        $this->clearAssetCache();
        return $stats;
    }

    /**
     * @param array<int, array<string, mixed>> $looks
     */
    private function upsertCharacterLooksFromSeriesParse(int $seriesId, array $looks, array $meta = []): array
    {
        $this->ensureAssetLookState($seriesId);
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'total' => 0];
        if ($looks === []) {
            return $stats;
        }

        $series = Series::where('id', $seriesId)->where('user_id', $this->effectiveUserId())->find();
        if (!$series instanceof Series) {
            abort(404, '剧本不存在');
        }
        $ownerId = $this->seriesOwnerId($series);
        $visualStyle = $this->normalizeVisualStyle((string) ($series->getAttr('visual_style') ?? 'realistic'));

        $characters = Asset::with(['images'])
            ->where('series_id', $seriesId)
            ->where('user_id', $ownerId)
            ->where('type', 'character')
            ->where('is_hidden', 0)
            ->select();
        $charactersByName = [];
        $charactersByKey = [];
        foreach ($characters as $character) {
            if (!$character instanceof Asset) {
                continue;
            }
            $name = trim((string) $character->getAttr('name'));
            if ($name === '') {
                continue;
            }
            $charactersByName[$name] = $character;
            $charactersByKey[mb_strtolower($name)] = $character;
        }

        foreach ($this->dedupeCharacterLooks($looks) as $look) {
            $stats['total']++;
            $characterName = trim((string) ($look['match_character'] ?? $look['character_name'] ?? ''));
            $lookName = trim((string) ($look['look_name'] ?? ''));
            if ($characterName === '' || $lookName === '') {
                $stats['skipped']++;
                continue;
            }

            $character = $charactersByName[$characterName]
                ?? $charactersByKey[mb_strtolower($characterName)]
                ?? null;
            if (!$character instanceof Asset) {
                $stub = $this->makeCharacterAssetFromLook($characterName, $look);
                $created = $this->upsertAssetsFromSeriesParse($seriesId, [$stub], $meta);
                if ((int) ($created['created'] ?? 0) + (int) ($created['updated'] ?? 0) <= 0) {
                    $stats['skipped']++;
                    continue;
                }
                $character = Asset::where('series_id', $seriesId)
                    ->where('user_id', $ownerId)
                    ->where('type', 'character')
                    ->where('is_hidden', 0)
                    ->where('name', $characterName)
                    ->find();
                if (!$character instanceof Asset) {
                    $stats['skipped']++;
                    continue;
                }
                $charactersByName[$characterName] = $character;
                $charactersByKey[mb_strtolower($characterName)] = $character;
            }
            if (!CharacterAssetClassifier::shouldAutoGenerateLooks($character)) {
                $stats['skipped']++;
                continue;
            }
            $referenceKey = trim((string) ($look['reference_key'] ?? ''));
            if ($referenceKey === '') {
                $referenceKey = $this->assetLookService()->makeReferenceKey($characterName, $lookName);
            }

            $image = AssetImage::where('asset_id', (int) $character->getAttr('id'))
                ->where('reference_role', 'look')
                ->where('reference_key', $referenceKey)
                ->find();

            $prompt = $this->sanitizeCharacterLookImagePrompt(trim((string) ($look['image_prompt'] ?? '')), $visualStyle);
            $description = trim((string) ($look['description'] ?? ''));
            $url = $image instanceof AssetImage ? trim((string) $image->getAttr('url')) : '';
            $data = [
                'user_id' => $ownerId,
                'asset_id' => (int) $character->getAttr('id'),
                'view_type' => 'look',
                'url' => $url,
                'note' => '人物造型',
                'image_prompt' => $prompt !== '' ? $prompt : $this->sanitizeCharacterLookImagePrompt($description, $visualStyle),
                'sort' => $image instanceof AssetImage
                    ? (int) $image->getAttr('sort')
                    : (((int) AssetImage::where('asset_id', (int) $character->getAttr('id'))->max('sort')) + 10),
                'reference_role' => 'look',
                'variant_name' => $lookName,
                'reference_key' => $referenceKey,
            ];

            if ($image instanceof AssetImage) {
                $image->save($data);
                $stats['updated']++;
            } else {
                AssetImage::create($data);
                $stats['created']++;
            }
        }

        $this->clearAssetCache();
        return $stats;
    }

    private function chooseAssetDescription(string $existing, string $incoming): string
    {
        $existing = trim($existing);
        $incoming = trim($incoming);
        if ($incoming === '') {
            return $existing;
        }
        if ($existing === '') {
            return $incoming;
        }
        return mb_strlen($incoming) > mb_strlen($existing) ? $incoming : $existing;
    }

    /**
     * 清理剧本列表缓存。
     * 剧本、剧集或工作流写入剧集结果后调用，确保前端下一次列表读取最新数据。
     */
    private function clearSeriesCache(): void
    {
        RedisCache::bumpVersion('series');
    }

    /**
     * 规范化剧本内容地区（中国 / 欧美）。
     */
    private function normalizeVisualStyle(string $style): string
    {
        $style = strtolower(trim($style));
        return in_array($style, ['realistic', 'anime', '3d'], true) ? $style : 'realistic';
    }

    private function normalizeVisualStyleVariant(string $style, string $variant): string
    {
        $style = $this->normalizeVisualStyle($style);
        $variant = strtolower(trim($variant));
        if ($variant === '') {
            return '';
        }

        $allowed = [
            'realistic' => ['cinematic', 'short_drama', 'documentary', 'commercial', 'noir'],
            'anime' => ['showa_anime', 'classic_american_cartoon', 'heisei_classic', 'moe', 'kyoto_animation', 'shinkai', 'modern_mobile_game', 'guoman', 'cel_shaded'],
            '3d' => ['animated_feature', 'unreal', 'stylized_3d', 'clay', 'low_poly'],
        ];

        return in_array($variant, $allowed[$style] ?? [], true) ? $variant : '';
    }

    private function seriesVisualStylePromptRule(string $style, string $variant = ''): string
    {
        $style = $this->normalizeVisualStyle($style);
        $variant = $this->normalizeVisualStyleVariant($style, $variant);
        $base = match ($style) {
            'anime' => '动漫二次元风格，手绘质感，色彩鲜艳，线条清晰。Anime style, 2D hand-drawn look, vibrant colors, clean lines.',
            '3d' => '3D渲染风格，精细建模，电影级光影，高对比度。3D render style, high detail modeling, cinematic lighting, polished 3D look.',
            default => '电影感真实质感，真人拍摄效果，写实画风。Cinematic realistic style, live-action look, highly detailed textures.',
        };

        $variantRule = match ($variant) {
            'cinematic' => '细分风格：电影感写实，使用影视剧剧照质感、自然表演、真实镜头景深和克制调色。',
            'short_drama' => '细分风格：短剧写实，强调人物关系、清晰表演、现实生活场景和适合短剧平台的直接叙事。',
            'documentary' => '细分风格：纪实摄影，自然光、生活流、轻修饰、现场感强。',
            'commercial' => '细分风格：商业广告片，画面干净高级，布光精致，材质和人物状态更 polished。',
            'noir' => '细分风格：暗调悬疑，低调光、高反差、阴影层次、紧张神秘氛围。',
            'showa_anime' => '细分风格：昭和年代赛璐璐动画感，复古线条、胶片颗粒、低饱和怀旧色彩、手绘背景。',
            'classic_american_cartoon' => '细分风格：1940-1950 年代经典美式影院手绘卡通感，赛璐璐上色、清晰墨线、夸张肢体表演、弹性形变、复古胶片颗粒、温暖手绘背景和高对比舞台式灯光；借鉴黄金时代美国动画语言，但不要复刻任何既有角色、标志或受版权保护形象。',
            'heisei_classic' => '细分风格：平成经典电视动画感，清晰赛璐璐线稿、90s-00s 配色、稳定角色设定和干净分层上色。',
            'moe' => '细分风格：萌系二次元，圆润脸型、大眼睛、柔软线条、明亮可爱配色、表情细腻。',
            'kyoto_animation' => '细分风格：清爽日系青春动画感，柔和高饱和色彩、干净线条、细腻表情、日常生活光影。',
            'shinkai' => '细分风格：高透明度天空、强烈逆光、细腻城市背景、空气感光晕和电影级日系动画色彩。',
            'modern_mobile_game' => '细分风格：现代手游二次元立绘质感，精致角色设计、高饱和光效、干净数字绘和细密材质层次。',
            'guoman' => '细分风格：现代国漫风，东方审美、细腻角色造型、数字动画渲染、层次丰富背景。',
            'cel_shaded' => '细分风格：赛璐璐动画风，明确色块、硬边阴影、线稿清晰、干净平涂。',
            'animated_feature' => '细分风格：动画电影3D，友好角色比例、柔和材质、电影动画灯光和精致表情。',
            'unreal' => '细分风格：虚幻引擎写实，高精模型、真实材质、体积光、游戏过场级渲染。',
            'stylized_3d' => '细分风格：风格化3D，夸张造型、简化材质、清晰轮廓、鲜明色彩。',
            'clay' => '细分风格：黏土动画，手工塑形材质、轻微指纹纹理、定格动画质感、柔和棚拍光。',
            'low_poly' => '细分风格：低多边形，几何切面、简洁形体、清晰色块和轻量游戏美术感。',
            default => '',
        };

        return $variantRule !== '' ? $base . ' ' . $variantRule : $base;
    }

    private function normalizeSeriesRegion(string $region): string
    {
        $region = strtolower(trim($region));
        return in_array($region, ['china', 'western'], true) ? $region : 'china';
    }

    /**
     * @return array{content_region:string, content_region_rule:string}
     */
    private function seriesRegionContext(?Series $series): array
    {
        $region = $this->normalizeSeriesRegion((string) ($series?->getAttr('region') ?? 'china'));

        return [
            'content_region' => $region,
            'content_region_rule' => $this->seriesRegionPromptRule($series),
        ];
    }

    private function seriesRegionPromptRule(?Series $series): string
    {
        $region = $this->normalizeSeriesRegion((string) ($series?->getAttr('region') ?? 'china'));

        return match ($region) {
            'western' => '欧美地区语境：西方式人名、欧美都市/庄园/医院等场景，关系与对白符合欧美短剧习惯，避免中国特有地名、制度与文化梗。输出语言必须使用英文；JSON 字段名保持 schema 要求，但字段值、标题、对白、剧情摘要、分镜说明、镜头动作、资产名称、资产描述和 image_prompt 都必须写英文。人物资产必须明确为 Western / European or American casting and facial features；不得默认写成中国人、东亚人或亚洲面孔，除非原文明确要求。',
            default => '中国地区语境：中文姓名、中国本土场景与生活方式，关系与对白符合中国短剧习惯，避免明显欧美文化设定。输出语言必须使用中文；JSON 字段名保持 schema 要求。所有给用户阅读的字段值、标题、剧情摘要、分镜说明、镜头动作、旁白、人物台词和资产描述都必须写中文；即使 node_instruction 要求 English Prompt、英文画面描述或给了英文示例，也必须改写为中文输出，不得输出英文对白或整段英文分镜。image_prompt 可保留必要模型关键词，但主体描述也应以中文为主。',
        };
    }

    /**
     * 清理资产列表缓存。
     * AI 资产写入或资产增删改后调用，避免资产管理页面看到旧数据。
     */
    private function clearAssetCache(): void
    {
        RedisCache::bumpVersion('assets');
    }

    private function assetMentionCandidateKey(int $assetId, int $assetImageId = 0, string $referenceRole = 'view'): string
    {
        return $assetId . '|' . $assetImageId . '|' . strtolower(trim($referenceRole) ?: 'view');
    }

    private function ensureAssetLookState(int $seriesId = 0): void
    {
        $service = $this->assetLookService();
        $service->ensureSchema();
        if ($seriesId > 0) {
            $service->ensureSeriesMigration($this->effectiveUserId(), $seriesId);
        }
    }

    private function assetLookService(): AssetLookService
    {
        if (!$this->assetLookService instanceof AssetLookService) {
            $this->assetLookService = new AssetLookService();
        }
        return $this->assetLookService;
    }

    /**
     * 按目标集数裁剪剧集列表。
     * 只裁剪不补空集，避免生成没有内容的占位剧集。
     */
    private function normalizeEpisodeCount(array $episodes, ?int $targetEpisodeCount): array
    {
        $list = array_values(array_filter($episodes, static fn ($x) => is_array($x)));
        if ($targetEpisodeCount === null || $targetEpisodeCount <= 0) {
            return $list;
        }
        if ($list === []) {
            return [];
        }
        if (count($list) > $targetEpisodeCount) {
            return array_slice($list, 0, $targetEpisodeCount);
        }
        return $list;
    }
}
