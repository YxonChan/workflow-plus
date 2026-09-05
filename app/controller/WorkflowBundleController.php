<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\model\ModelConfig;
use app\model\PromptTemplate;
use app\model\Workflow;
use app\model\WorkflowBundle;
use app\support\DefaultWorkflowGraphs;
use app\support\ModelConfigResolver;
use app\support\RedisCache;
use think\facade\Db;

class WorkflowBundleController extends BaseController
{
    /** 列表（先补齐内置套餐）。 */
    public function index()
    {
        $this->seedDefaults();

        $bundles = WorkflowBundle::where('user_id', $this->currentUserId())
            ->order(['is_system' => 'desc', 'sort' => 'asc', 'id' => 'asc'])
            ->select();

        $out = [];
        foreach ($bundles as $b) {
            $out[] = $this->serialize($b);
        }

        return successCode($out);
    }

    /** 新建套餐（可基于某套餐复制其工作流为可编辑副本）。 */
    public function save()
    {
        $payload = $this->request->param();
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            abort(422, '请填写流程名称');
        }
        $fromId = (int) ($payload['from_bundle_id'] ?? 0);
        $includeSeries = $this->boolish($payload['include_series'] ?? true);

        return successCode($this->doCreate($name, trim((string) ($payload['description'] ?? '')), $fromId, $includeSeries), 'success', 201);
    }

    /** 更新套餐元信息（名称/描述）。工作流图由 workflows/update 单独保存。 */
    public function update()
    {
        $payload = $this->request->param();
        $bundle = $this->findOrFail((int) ($payload['id'] ?? 0));
        $this->assertEditable($bundle);

        $bundle->save([
            'name' => trim((string) ($payload['name'] ?? $bundle->getAttr('name'))),
            'description' => trim((string) ($payload['description'] ?? $bundle->getAttr('description'))),
        ]);

        return successCode($this->serialize($bundle));
    }

    public function delete()
    {
        $payload = $this->request->param();
        $bundle = $this->findOrFail((int) ($payload['id'] ?? 0));
        $this->assertEditable($bundle);

        Db::transaction(function () use ($bundle): void {
            $this->deleteOwnedWorkflow((int) $bundle->getAttr('series_workflow_id'));
            $this->deleteOwnedWorkflow((int) $bundle->getAttr('episode_workflow_id'));
            $bundle->delete();
        });

        return successCode();
    }

    public function duplicate()
    {
        $payload = $this->request->param();
        $src = $this->findOrFail((int) ($payload['id'] ?? 0));
        $name = (string) $src->getAttr('name') . ' (副本)';

        return successCode($this->doCreate($name, (string) $src->getAttr('description'), (int) $src->getAttr('id'), true), 'success', 201);
    }

    // ── core ─────────────────────────────────────────────────────────────────────
    private function doCreate(string $name, string $description, int $fromBundleId, bool $includeSeries): array
    {
        return Db::transaction(function () use ($name, $description, $fromBundleId, $includeSeries): array {
            $seriesId = null;
            $episodeId = 0;

            if ($fromBundleId > 0) {
                $base = $this->findOrFail($fromBundleId);
                $episodeId = $this->copyWorkflow((int) $base->getAttr('episode_workflow_id'));
                $baseSeries = (int) $base->getAttr('series_workflow_id');
                if ($baseSeries > 0) {
                    $seriesId = $this->copyWorkflow($baseSeries);
                }
            } else {
                $episodeId = $this->createWorkflow($name . ' · 剧集段', 'episode', DefaultWorkflowGraphs::episode());
                if ($includeSeries) {
                    $seriesId = $this->createWorkflow($name . ' · 剧本段', 'series', DefaultWorkflowGraphs::seriesFull());
                }
            }

            $bundle = new WorkflowBundle();
            $bundle->save([
                'user_id' => $this->currentUserId(),
                'name' => $name,
                'description' => $description,
                'series_workflow_id' => $seriesId,
                'episode_workflow_id' => $episodeId,
                'is_system' => 0,
                'sort' => $this->nextSort(),
            ]);

            return $this->serialize($bundle);
        });
    }

    /** 复制一条工作流为当前用户的可编辑副本（is_default=0），返回新 id。 */
    private function copyWorkflow(int $sourceId): int
    {
        $src = Workflow::find($sourceId);
        $graph = ($src instanceof Workflow) ? ($src->getAttr('graph') ?: ['nodes' => [], 'edges' => []]) : ['nodes' => [], 'edges' => []];
        $scope = ($src instanceof Workflow) ? (string) $src->getAttr('scope') : 'episode';
        $name = ($src instanceof Workflow) ? (string) $src->getAttr('name') : '工作流';
        $preferOfficialModels = $src instanceof Workflow && (int) $src->getAttr('is_default') === 1;
        $graph = is_array($graph) ? $this->hydrateCopiedGraph($graph, $scope, $preferOfficialModels) : ['nodes' => [], 'edges' => []];

        return $this->createWorkflow($name, $scope, $graph);
    }

    private function createWorkflow(string $name, string $scope, array $graph): int
    {
        $wf = new Workflow();
        $wf->save([
            'user_id' => $this->currentUserId(),
            'name' => $name,
            'description' => '',
            'graph' => $this->stripInputContent($graph),
            'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1],
            'is_default' => 0,
            'scope' => ($scope === 'series') ? 'series' : 'episode',
            'sort' => 0,
        ]);

        return (int) $wf->getAttr('id');
    }

    private function deleteOwnedWorkflow(int $id): void
    {
        if ($id <= 0) {
            return;
        }
        $wf = Workflow::where('id', $id)->where('user_id', $this->currentUserId())->find();
        // 仅删用户自建副本（is_default=0），系统共享工作流不动
        if ($wf instanceof Workflow && (int) $wf->getAttr('is_default') !== 1) {
            $wf->delete();
        }
    }

    // ── seed ─────────────────────────────────────────────────────────────────────
    private function seedDefaults(): void
    {
        $e1 = $this->ensureWorkflow('短剧标准生产流程', 'episode', '剧情概要 → 扩写 → 资产提取与合并 → 分镜 → 视频生成 → 导出', DefaultWorkflowGraphs::episode());
        $promo = $this->ensureWorkflow(
            '15-60秒宣传片生产流程',
            'episode',
            '一句话创意 → 资产准备 → 1-4段分镜 → 视频生成 → 导出',
            DefaultWorkflowGraphs::promoEpisode(),
            ['45秒宣传片生产流程']
        );
        $s1 = $this->ensureWorkflow('剧本拆解与剧集规划', 'series', '小说导入 → 结构拆解 → 剧集规划 → 写入剧集', DefaultWorkflowGraphs::seriesFull(), ['剧本拆解与资产生成']);
        $this->ensureWorkflow('一句话扩写成剧本', 'series', '一句话创意 → 扩写剧本 → 结构拆解 → 剧集规划 → 写入剧集', DefaultWorkflowGraphs::seriesOneLine());

        $this->ensureBundle('完整剧本 → 批量成片', '上传完整小说/剧本，先拆集；资产在每集扩写后增量补齐', $s1, $e1, 10);
        $this->ensureBundle('一句话 → 单集成片', '一句话创意自动生成约 15-60 秒宣传片，可选 1-4 段', null, $promo, 20);
        $this->removeSystemBundle('一句话 → 完整剧本 → 批量成片');
        $this->syncSingleEpisodePromoBundle($promo);
        $this->syncFullScriptBundleStandard();
        $this->disableOfficialEpisodeChainShots();
    }

    private function ensureWorkflow(string $name, string $scope, string $description, array $graph, array $legacyNames = []): int
    {
        $existing = Workflow::where('user_id', $this->currentUserId())
            ->where('name', $name)
            ->where('scope', $scope)
            ->find();
        if (!$existing instanceof Workflow && $legacyNames !== []) {
            $existing = Workflow::where('user_id', $this->currentUserId())
                ->whereIn('name', $legacyNames)
                ->where('scope', $scope)
                ->find();
        }
        if ($existing instanceof Workflow) {
            if ((int) $existing->getAttr('is_default') === 1) {
                $existing->save([
                    'name' => $name,
                    'description' => $description,
                ]);
            }
            return (int) $existing->getAttr('id');
        }

        $wf = new Workflow();
        $wf->save([
            'user_id' => $this->currentUserId(),
            'name' => $name,
            'description' => $description,
            'graph' => $this->stripInputContent($graph),
            'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 0.8],
            'is_default' => 1, // 系统内置 → 只读锁定（沿用 WorkflowController 守卫）
            'scope' => $scope,
            'sort' => 10,
        ]);

        return (int) $wf->getAttr('id');
    }

    private function ensureBundle(string $name, string $description, ?int $seriesWorkflowId, int $episodeWorkflowId, int $sort): void
    {
        $existing = WorkflowBundle::where('user_id', $this->currentUserId())
            ->where('name', $name)
            ->where('is_system', 1)
            ->find();
        if ($existing instanceof WorkflowBundle) {
            return;
        }

        $bundle = new WorkflowBundle();
        $bundle->save([
            'user_id' => $this->currentUserId(),
            'name' => $name,
            'description' => $description,
            'series_workflow_id' => $seriesWorkflowId,
            'episode_workflow_id' => $episodeWorkflowId,
            'is_system' => 1,
            'sort' => $sort,
        ]);
    }

    private function removeSystemBundle(string $name): void
    {
        $bundle = WorkflowBundle::where('user_id', $this->currentUserId())
            ->where('name', $name)
            ->where('is_system', 1)
            ->find();
        if (!$bundle instanceof WorkflowBundle) {
            return;
        }

        $bundle->delete();
    }

    /**
     * 旧版“一句话 → 单集成片”与批量剧集共用标准流程；列表 seed 时迁移到
     * 独立 15-60 秒宣传片流程，避免缩短批量剧集并保证已有部署自动生效。
     */
    private function syncSingleEpisodePromoBundle(int $promoWorkflowId): void
    {
        $workflow = Workflow::where('id', $promoWorkflowId)
            ->where('user_id', $this->currentUserId())
            ->find();
        if ($workflow instanceof Workflow && (int) $workflow->getAttr('is_default') === 1) {
            $workflow->save([
                'name' => '15-60秒宣传片生产流程',
                'description' => '一句话创意 → 资产准备 → 1-4段分镜 → 视频生成 → 导出',
                'graph' => $this->hydrateCopiedGraph(
                    $this->stripInputContent(DefaultWorkflowGraphs::promoEpisode()),
                    'episode',
                    true
                ),
            ]);
        }

        $bundle = WorkflowBundle::where('user_id', $this->currentUserId())
            ->where('name', '一句话 → 单集成片')
            ->where('is_system', 1)
            ->find();
        if (!$bundle instanceof WorkflowBundle) {
            return;
        }

        $bundle->save([
            'description' => '一句话创意自动生成约 15-60 秒宣传片，可选 1-4 段',
            'series_workflow_id' => null,
            'episode_workflow_id' => $promoWorkflowId,
            'sort' => 20,
        ]);
    }

    /**
     * 已有库中的官方导入剧本流程可能仍指向旧图；列表 seed 时做一次自愈，
     * 让部署到线上后不需要手动执行数据库脚本。
     */
    private function syncFullScriptBundleStandard(): void
    {
        $bundle = WorkflowBundle::where('user_id', $this->currentUserId())
            ->where('name', '完整剧本 → 批量成片')
            ->where('is_system', 1)
            ->find();
        if (!$bundle instanceof WorkflowBundle) {
            return;
        }

        $seriesId = (int) $bundle->getAttr('series_workflow_id');
        if ($seriesId > 0) {
            $series = Workflow::where('id', $seriesId)->where('user_id', $this->currentUserId())->find();
            if ($series instanceof Workflow && (int) $series->getAttr('is_default') === 1) {
                $existingGraph = $series->getAttr('graph') ?: [];
                $seriesGraph = is_array($existingGraph) && $this->matchesNodeLabels($existingGraph, ['小说导入', '结构拆解', '剧集规划', '写入结果'])
                    ? $this->hydrateCopiedGraph($this->stripInputContent($existingGraph), 'series', true)
                    : $this->hydrateCopiedGraph($this->stripInputContent(DefaultWorkflowGraphs::seriesFull()), 'series', true);
                $series->save([
                    'name' => '剧本拆解与剧集规划',
                    'description' => '小说导入 → 结构拆解 → 剧集规划 → 写入剧集',
                    'graph' => $seriesGraph,
                ]);
            }
        }

        $episodeId = (int) $bundle->getAttr('episode_workflow_id');
        $sharedCount = WorkflowBundle::where('user_id', $this->currentUserId())
            ->where('episode_workflow_id', $episodeId)
            ->count();
        if ($sharedCount > 1) {
            $episodeId = $this->createSystemWorkflow(
                '导入剧本标准生产流程',
                'episode',
                '剧情概要 → 资产提取与合并 → 分镜 → 视频生成 → 导出',
                $this->hydrateCopiedGraph($this->stripInputContent(DefaultWorkflowGraphs::episode()), 'episode', true)
            );
            $bundle->save(['episode_workflow_id' => $episodeId]);
        } elseif ($episodeId > 0) {
            $episode = Workflow::where('id', $episodeId)->where('user_id', $this->currentUserId())->find();
            if ($episode instanceof Workflow && (int) $episode->getAttr('is_default') === 1) {
                $existingGraph = $episode->getAttr('graph') ?: [];
                $episodeGraph = is_array($existingGraph) && $this->matchesNodeLabels($existingGraph, ['剧情概要', '资产提取与合并', '分镜处理', '视频生成', '输出视频'])
                    ? $this->hydrateCopiedGraph($this->stripInputContent($existingGraph), 'episode', true)
                    : $this->hydrateCopiedGraph($this->stripInputContent(DefaultWorkflowGraphs::episode()), 'episode', true);
                $episode->save([
                    'name' => '导入剧本标准生产流程',
                    'description' => '剧情概要 → 资产提取与合并 → 分镜 → 视频生成 → 导出',
                    'graph' => $episodeGraph,
                ]);
            }
        }
    }

    private function createSystemWorkflow(string $name, string $scope, string $description, array $graph): int
    {
        $wf = new Workflow();
        $wf->save([
            'user_id' => $this->currentUserId(),
            'name' => $name,
            'description' => $description,
            'graph' => $graph,
            'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 0.8],
            'is_default' => 1,
            'scope' => $scope,
            'sort' => 10,
        ]);

        return (int) $wf->getAttr('id');
    }

    private function matchesNodeLabels(array $graph, array $labels): bool
    {
        $nodes = $graph['nodes'] ?? [];
        if (!is_array($nodes) || count($nodes) !== count($labels)) {
            return false;
        }
        foreach (array_values($nodes) as $idx => $node) {
            if (!is_array($node) || (string) ($node['label'] ?? '') !== $labels[$idx]) {
                return false;
            }
        }

        return true;
    }

    /**
     * 官方剧集流程关闭镜头衔接。只改 is_default=1 的内置模板。
     */
    private function disableOfficialEpisodeChainShots(): void
    {
        $defaults = Workflow::where('user_id', $this->currentUserId())
            ->where('is_default', 1)
            ->where('scope', 'episode')
            ->select();

        $changed = false;
        foreach ($defaults as $w) {
            if (!$w instanceof Workflow) {
                continue;
            }
            $graph = $w->getAttr('graph') ?: [];
            if (!is_array($graph)) {
                continue;
            }
            $nextGraph = DefaultWorkflowGraphs::disableVideoChainShots($graph);
            if (json_encode($nextGraph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                === json_encode($graph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) {
                continue;
            }
            $w->save(['graph' => $nextGraph]);
            $changed = true;
        }
        if ($changed) {
            RedisCache::bumpVersion('workflows');
        }
    }

    private function hydrateCopiedGraph(array $graph, string $scope, bool $preferOfficialModels = false): array
    {
        if (!isset($graph['nodes']) || !is_array($graph['nodes'])) {
            return $graph;
        }

        foreach ($graph['nodes'] as &$node) {
            if (!is_array($node) || !isset($node['data']) || !is_array($node['data'])) {
                continue;
            }
            $kind = (string) ($node['data']['kind'] ?? 'text');
            if (!isset($node['data']['params']) || !is_array($node['data']['params'])) {
                $node['data']['params'] = [];
            }

            if (in_array($kind, ['text', 'image', 'video', 'voice'], true)) {
                $model = $preferOfficialModels
                    ? $this->preferredModelForNode($kind, $scope, (string) ($node['label'] ?? ''))
                    : null;
                if ($model === null && (int) ($node['data']['params']['modelId'] ?? 0) <= 0) {
                    $model = ModelConfigResolver::resolve($kind, $this->currentUserId());
                }
                if ($model !== null) {
                    $node['data']['params']['modelId'] = (int) $model->getAttr('id');
                }
            }

            $template = $this->matchSystemPromptTemplate(
                $scope,
                $kind,
                (string) ($node['label'] ?? ''),
                (string) ($node['data']['params']['prompt'] ?? '')
            );
            if ($template instanceof PromptTemplate) {
                $node['data']['params']['promptTemplateId'] = (int) $template->getAttr('id');
                $node['data']['params']['prompt_template_id'] = (int) $template->getAttr('id');
                $node['data']['params']['customPromptEnabled'] = false;
                $node['data']['params']['prompt'] = (string) $template->getAttr('prompt');
            }
        }
        unset($node);

        return $graph;
    }

    private function preferredModelForNode(string $kind, string $scope, string $label): ?ModelConfig
    {
        $modelIds = [];
        if ($kind === 'video') {
            $modelIds = ['seedance-2-mini', 'seedance-2'];
        } elseif ($kind === 'text') {
            $modelIds = $scope === 'episode' && $label === '分镜处理'
                ? ['claude-sonnet-4-6', 'anthropic/claude-sonnet-4.6', 'anthropic/claude-opus-4.6']
                : ['anthropic/claude-opus-4.6', 'claude-opus-4-6', 'claude-sonnet-4-6'];
        }

        foreach ($modelIds as $modelId) {
            $model = ModelConfigResolver::visibleModelsQuery($this->currentUserId())
                ->where('type', $kind)
                ->where('model_id', $modelId)
                ->find();
            if ($model instanceof ModelConfig) {
                return $model;
            }
        }

        return null;
    }

    private function matchSystemPromptTemplate(string $scope, string $kind, string $label, string $prompt): ?PromptTemplate
    {
        if ($prompt === '') {
            return null;
        }

        $template = PromptTemplate::where('user_id', $this->currentUserId())
            ->where('is_system', 1)
            ->where('scope', $scope)
            ->where('node_kind', $kind)
            ->where('node_label', $label)
            ->where('prompt', $prompt)
            ->find();
        if ($template instanceof PromptTemplate) {
            return $template;
        }

        $template = PromptTemplate::where('user_id', $this->currentUserId())
            ->where('is_system', 1)
            ->where('scope', $scope)
            ->where('node_kind', $kind)
            ->where('node_label', $label)
            ->find();

        return $template instanceof PromptTemplate ? $template : null;
    }

    // ── helpers ──────────────────────────────────────────────────────────────────
    private function findOrFail(int $id): WorkflowBundle
    {
        if ($id <= 0) {
            abort(422, '套餐 id 不能为空');
        }
        $bundle = WorkflowBundle::where('id', $id)->where('user_id', $this->currentUserId())->find();
        if (!$bundle instanceof WorkflowBundle) {
            abort(404, '套餐不存在');
        }

        return $bundle;
    }

    private function assertEditable(WorkflowBundle $b): void
    {
        if ((int) $b->getAttr('is_system') === 1) {
            abort(403, '系统内置流程不可修改或删除，请复制后再编辑');
        }
    }

    private function serialize(WorkflowBundle $b): array
    {
        return [
            'id' => (int) $b->getAttr('id'),
            'name' => (string) $b->getAttr('name'),
            'description' => (string) $b->getAttr('description'),
            'is_system' => (int) $b->getAttr('is_system'),
            'series_workflow_id' => $b->getAttr('series_workflow_id') !== null ? (int) $b->getAttr('series_workflow_id') : null,
            'episode_workflow_id' => (int) $b->getAttr('episode_workflow_id'),
            'series_workflow' => $this->workflowBrief((int) $b->getAttr('series_workflow_id')),
            'episode_workflow' => $this->workflowBrief((int) $b->getAttr('episode_workflow_id')),
            'create_time' => $b->getAttr('create_time'),
            'update_time' => $b->getAttr('update_time'),
        ];
    }

    private function workflowBrief(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $wf = Workflow::find($id);
        if (!$wf instanceof Workflow) {
            return null;
        }
        $graph = $wf->getAttr('graph') ?: ['nodes' => [], 'edges' => []];
        $graph = $this->stripInputContent(is_array($graph) ? $graph : ['nodes' => [], 'edges' => []]);
        if ((int) $wf->getAttr('is_default') === 1) {
            $graph = $this->stripSystemPromptsFromGraph($graph);
        }

        return [
            'id' => (int) $wf->getAttr('id'),
            'name' => (string) $wf->getAttr('name'),
            'scope' => (string) $wf->getAttr('scope'),
            'is_default' => (int) $wf->getAttr('is_default'),
            'is_system' => (int) $wf->getAttr('is_default') === 1 ? 1 : 0,
            'graph' => $graph,
        ];
    }

    private function stripInputContent(array $graph): array
    {
        if (!isset($graph['nodes']) || !is_array($graph['nodes'])) {
            return $graph;
        }
        foreach ($graph['nodes'] as &$node) {
            if (is_array($node) && ($node['data']['kind'] ?? null) === 'input' && isset($node['data']['params']) && is_array($node['data']['params'])) {
                $node['data']['params']['content'] = '';
            }
        }
        unset($node);

        return $graph;
    }

    /**
     * 系统内置流程的 prompt 不对前端暴露，只展示步骤结构。
     */
    private function stripSystemPromptsFromGraph(array $graph): array
    {
        if (!isset($graph['nodes']) || !is_array($graph['nodes'])) {
            return $graph;
        }
        foreach ($graph['nodes'] as &$node) {
            if (!is_array($node)) {
                continue;
            }
            if (isset($node['data']) && is_array($node['data']) && isset($node['data']['params']) && is_array($node['data']['params'])) {
                foreach (['prompt', 'content', 'system_prompt', 'negative_prompt'] as $key) {
                    if (array_key_exists($key, $node['data']['params'])) {
                        $node['data']['params'][$key] = '';
                    }
                }
                $node['data']['params']['isBlackBoxPrompt'] = true;
            }
        }
        unset($node);

        return $graph;
    }

    private function nextSort(): int
    {
        return ((int) WorkflowBundle::where('user_id', $this->currentUserId())->max('sort')) + 10;
    }

    private function boolish($v): bool
    {
        return $v === true || $v === 1 || $v === '1' || $v === 'true';
    }
}
