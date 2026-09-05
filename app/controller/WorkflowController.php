<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\model\Workflow;
use app\support\DefaultWorkflowGraphs;
use app\support\RedisCache;
use app\validate\WorkflowValidate;
use think\facade\Db;

class WorkflowController extends BaseController
{
    /**
     * 查询工作流列表。
     * 查询前会补齐默认工作流并升级旧模板；返回按默认、排序、id 排列的工作流。
     */
    public function index()
    {
        return successCode($this->doList());
    }

    /**
     * 查询工作流详情。
     * 从 JSON 请求体读取 id，返回单个工作流的画布、视口和基础信息。
     */
    public function read()
    {
        $payload = $this->request->param();
        $id = $this->requireId($payload, '工作流 id 不能为空');

        return successCode($this->doFind($id));
    }

    /**
     * 新建工作流。
     * 解析 graph、viewport 后校验请求体，写入工作流模板。
     */
    public function save()
    {
        $payload = $this->payload();
        $error = $this->validatePayload($payload);
        if ($error !== null) {
            return $error;
        }

        return successCode($this->doCreate($payload), 'success', 201);
    }

    /**
     * 更新工作流。
     * 从 JSON 请求体读取 id，更新名称、描述、画布、视口、默认状态和适用范围。
     */
    public function update()
    {
        $payload = $this->payload();
        $id = $this->requireId($payload, '工作流 id 不能为空');
        $error = $this->validatePayload($payload);
        if ($error !== null) {
            return $error;
        }

        return successCode($this->doUpdate($id, $payload));
    }

    /**
     * 删除工作流。
     * 从 JSON 请求体读取 id 后删除模板。
     */
    public function delete()
    {
        $payload = $this->request->param();
        $id = $this->requireId($payload, '工作流 id 不能为空');
        $this->doDelete($id);

        return successCode();
    }

    /**
     * 复制工作流。
     * 从 JSON 请求体读取 id，复制指定工作流的画布和配置，清理输入节点运行时内容后生成副本。
     */
    public function duplicate()
    {
        $payload = $this->payload();
        $id = $this->requireId($payload, '工作流 id 不能为空');

        return successCode($this->doDuplicate($id), 'success', 201);
    }

    /**
     * 从请求体读取必填 id。
     * 所有接口参数统一走 JSON body，不再从 URL path 接收 id。
     */
    private function requireId(array $payload, string $message): int
    {
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            abort(422, $message);
        }

        return $id;
    }

    /**
     * 读取并整理工作流请求体。
     * 把 graph 和 viewport 的 JSON 字符串解析为数组，便于后续校验和保存。
     */
    private function payload(): array
    {
        $payload = $this->request->param();

        foreach (['graph', 'viewport'] as $field) {
            if (isset($payload[$field]) && is_string($payload[$field]) && $payload[$field] !== '') {
                $decoded = json_decode($payload[$field], true);
                if (is_array($decoded)) {
                    $payload[$field] = $decoded;
                }
            }
        }

        return $payload;
    }

    /**
     * 校验工作流请求体。
     * 复用 WorkflowValidate，失败时返回统一 422 响应。
     */
    private function validatePayload(array $payload)
    {
        $validator = new WorkflowValidate();
        if ($validator->check($payload)) {
            return null;
        }

        return errorCode([], $validator->getError(), 422);
    }

    /**
     * 执行工作流列表查询。
     * 包含默认模板初始化、空模板升级和旧剧本模板升级。
     */
    private function doList(): array
    {
        $this->seedDefaultsIfEmpty();
        $this->seedSeriesDefaultIfMissing();
        $this->upgradeEmptyDefault();
        $this->upgradeLegacySeriesTemplate();
        $this->upgradeSeriesDefaultWithoutAssetExtraction();
        $this->upgradeEpisodeDefaultWithAssetNode();
        $this->upgradeEpisodeDefaultWithoutFrameNode();
        $this->upgradeEpisodeDefaultDisableChainShots();

        $version = RedisCache::version('workflows');
        $userId = $this->currentUserId();
        return RedisCache::remember("workflows:list:{$this->currentUserCacheSuffix()}:v{$version}", 120, function () use ($userId): array {
            return Workflow::where('user_id', $userId)
                ->order(['is_default' => 'desc', 'sort' => 'asc', 'id' => 'asc'])
                ->select()
                ->map(fn (Workflow $w): array => $this->serialize($w))
                ->toArray();
        });
    }

    /**
     * 升级旧的空默认工作流。
     * 当默认工作流没有节点时，替换为标准短剧生产流程。
     */
    private function upgradeEmptyDefault(): void
    {
        $defaults = Workflow::where('user_id', $this->currentUserId())->where('is_default', 1)->select();
        foreach ($defaults as $w) {
            $graph = $w->getAttr('graph') ?: [];
            $nodes = $graph['nodes'] ?? [];
            if (is_array($nodes) && count($nodes) === 0) {
                $w->save([
                    'name' => '短剧标准生产流程',
                    'description' => '剧情概要 → 扩写 → 分镜 → 视频生成 → 导出',
                    'graph' => $this->buildDefaultGraph(),
                    'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 0.75],
                    'scope' => $this->normalizeScope((string) ($w->getAttr('scope') ?? 'episode')),
                ]);
            }
        }
    }

    /**
     * 执行工作流详情查询。
     */
    private function doFind(int $id): array
    {
        return $this->serialize($this->findOrFail($id));
    }

    /**
     * 执行工作流创建事务。
     * 规范化画布数据，清理输入节点内容，写入 workflow。
     */
    private function doCreate(array $payload): array
    {
        return Db::transaction(function () use ($payload): array {
            $payload = $this->normalizePayload($payload, 'episode');
            $payload['sort'] = $this->nextSort();
            $payload['user_id'] = $this->currentUserId();

            $model = new Workflow();
            $model->save($payload);
            $this->clearWorkflowCache();

            return $this->serialize($model);
        });
    }

    /**
     * 执行工作流更新事务。
     * 沿用原 scope 作为默认值，保存后重新读取返回。
     */
    private function doUpdate(int $id, array $payload): array
    {
        return Db::transaction(function () use ($id, $payload): array {
            $model = $this->findOrFail($id);
            $this->assertEditableWorkflow($model);
            $payload = $this->normalizePayload($payload, (string) ($model->getAttr('scope') ?? 'episode'));

            $model->save($payload);
            $this->clearWorkflowCache();

            return $this->serialize($this->findOrFail($id));
        });
    }

    /**
     * 执行工作流删除事务。
     */
    private function doDelete(int $id): void
    {
        Db::transaction(function () use ($id): void {
            $model = $this->findOrFail($id);
            $this->assertEditableWorkflow($model);
            $model->delete();
        });
        $this->clearWorkflowCache();
    }

    /**
     * 执行工作流复制事务。
     * 复制时不保留输入节点的用户运行时内容。
     */
    private function doDuplicate(int $id): array
    {
        return Db::transaction(function () use ($id): array {
            $source = $this->findOrFail($id);

            $graph = $source->getAttr('graph') ?: ['nodes' => [], 'edges' => []];
            $graph = is_array($graph) ? $this->stripRuntimeInputsFromGraph($graph) : ['nodes' => [], 'edges' => []];

            $copy = new Workflow();
            $copy->save([
                'name' => $source->getAttr('name') . ' (副本)',
                'user_id' => $this->currentUserId(),
                'description' => (string) $source->getAttr('description'),
                'graph' => $graph,
                'viewport' => $source->getAttr('viewport') ?: ['x' => 0, 'y' => 0, 'zoom' => 1],
                'is_default' => 0,
                'scope' => $this->normalizeScope((string) ($source->getAttr('scope') ?? 'episode')),
                'sort' => $this->nextSort(),
            ]);
            $this->clearWorkflowCache();

            return $this->serialize($copy);
        });
    }

    /**
     * 初始化默认剧集工作流。
     * 数据库没有任何工作流时创建标准短剧生产流程。
     */
    private function seedDefaultsIfEmpty(): void
    {
        if ((int) Workflow::where('user_id', $this->currentUserId())->count() > 0) {
            return;
        }

        Workflow::create([
            'user_id' => $this->currentUserId(),
            'name' => '短剧标准生产流程',
            'description' => '剧情概要 → 扩写 → 分镜 → 视频生成 → 导出',
            'graph' => $this->buildDefaultGraph(),
            'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 0.75],
            'is_default' => 1,
            'scope' => 'episode',
            'sort' => 10,
        ]);
        $this->clearWorkflowCache();
    }

    /**
     * 初始化默认剧本工作流。
     * 当用户没有任何剧本(series)工作流时创建标准剧本拆解流程，
     * 保证简单模式下「剧集 + 剧本」各有一个默认黑盒流程。
     */
    private function seedSeriesDefaultIfMissing(): void
    {
        $hasSeries = (int) Workflow::where('user_id', $this->currentUserId())
            ->where('scope', 'series')
            ->count();
        if ($hasSeries > 0) {
            return;
        }

        Workflow::create([
            'user_id' => $this->currentUserId(),
            'name' => '剧本拆解与剧集规划',
            'description' => '小说/剧本导入 → 结构拆解 → 剧集规划 → 写入剧集；资产按集生产时增量补齐',
            'graph' => $this->buildDefaultSeriesGraph(),
            'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 0.85],
            'is_default' => 1,
            'scope' => 'series',
            'sort' => $this->nextSort(),
        ]);
        $this->clearWorkflowCache();
    }

    /**
     * 构建默认剧本工作流画布。
     * 委托 DefaultWorkflowGraphs，保证与 WorkflowBundleController seed 的画布一致。
     */
    private function buildDefaultSeriesGraph(): array
    {
        return DefaultWorkflowGraphs::seriesFull();
    }

    /**
     * 构建默认剧集工作流画布。
     * 委托 DefaultWorkflowGraphs（含「资产提取与合并」节点）。
     */
    private function buildDefaultGraph(): array
    {
        return DefaultWorkflowGraphs::episode();
    }

    /**
     * 规范化工作流保存入参。
     * 统一处理 graph、viewport、is_default、scope，并清空输入节点运行时内容。
     */
    private function normalizePayload(array $payload, string $defaultScope = 'episode'): array
    {
        $graph = $payload['graph'] ?? null;
        if (is_string($graph) && $graph !== '') {
            $decoded = json_decode($graph, true);
            $graph = is_array($decoded) ? $decoded : null;
        }

        $viewport = $payload['viewport'] ?? null;
        if (is_string($viewport) && $viewport !== '') {
            $decoded = json_decode($viewport, true);
            $viewport = is_array($decoded) ? $decoded : null;
        }

        $graph = is_array($graph) ? $graph : ['nodes' => [], 'edges' => []];
        $graph = $this->stripRuntimeInputsFromGraph($graph);

        return [
            'name' => trim((string) $payload['name']),
            'description' => trim((string) ($payload['description'] ?? '')),
            'graph' => $graph,
            'viewport' => is_array($viewport) ? $viewport : ['x' => 0, 'y' => 0, 'zoom' => 1],
            'is_default' => isset($payload['is_default']) ? (int) $payload['is_default'] : 0,
            'scope' => $this->normalizeScope((string) ($payload['scope'] ?? $defaultScope)),
        ];
    }

    /**
     * 清理画布中的运行时输入内容。
     * 工作流模板只保存结构和 prompt，不保存用户在输入节点粘贴的正文。
     */
    private function stripRuntimeInputsFromGraph(array $graph): array
    {
        if (!isset($graph['nodes']) || !is_array($graph['nodes'])) {
            return $graph;
        }

        foreach ($graph['nodes'] as &$node) {
            if (!is_array($node)) {
                continue;
            }
            $kind = $node['data']['kind'] ?? null;
            if ($kind === 'input' && isset($node['data']['params']) && is_array($node['data']['params'])) {
                $node['data']['params']['content'] = '';
            }
        }
        unset($node);

        return $graph;
    }

    /**
     * 序列化工作流模型。
     * 返回前端需要的稳定字段，并再次清理输入节点内容。
     */
    private function serialize(Workflow $w): array
    {
        $graph = $w->getAttr('graph') ?: ['nodes' => [], 'edges' => []];
        $graph = $this->stripRuntimeInputsFromGraph(is_array($graph) ? $graph : ['nodes' => [], 'edges' => []]);
        if ((int) $w->getAttr('is_default') === 1) {
            $graph = $this->stripSystemPromptsFromGraph($graph);
        }

        return [
            'id' => (int) $w->getAttr('id'),
            'name' => (string) $w->getAttr('name'),
            'description' => (string) $w->getAttr('description'),
            'graph' => $graph,
            'viewport' => $w->getAttr('viewport') ?: ['x' => 0, 'y' => 0, 'zoom' => 1],
            'is_default' => (int) $w->getAttr('is_default'),
            'is_system' => ((int) $w->getAttr('is_default') === 1) ? 1 : 0,
            'scope' => $this->normalizeScope((string) ($w->getAttr('scope') ?? 'episode')),
            'create_time' => $w->getAttr('create_time'),
            'update_time' => $w->getAttr('update_time'),
        ];
    }

    /**
     * 守卫：系统默认工作流（is_default=1）不可修改或删除。
     * 用户如需自定义，应先复制为可编辑副本（is_default=0）。
     */
    private function assertEditableWorkflow(Workflow $w): void
    {
        if ((int) $w->getAttr('is_default') === 1) {
            abort(403, '系统默认工作流不可修改或删除，请复制后再编辑');
        }
    }

    /**
     * 规范化工作流适用范围。
     * 只允许 series，其他值都按 episode 处理。
     */
    private function normalizeScope(string $scope): string
    {
        return $scope === 'series' ? 'series' : 'episode';
    }

    /**
     * 升级旧版剧本工作流模板。
     * 把旧标签和 scope 调整为当前“小说导入/结构拆解/写入结果”的剧本工作流语义。
     */
    private function upgradeLegacySeriesTemplate(): void
    {
        $target = Workflow::where('user_id', $this->currentUserId())
            ->where('name', '剧集级：小说拆解与资产生成')
            ->find();
        if (!$target instanceof Workflow) {
            return;
        }

        $graph = $target->getAttr('graph') ?: [];
        $nodes = is_array($graph) ? ($graph['nodes'] ?? []) : [];
        if (!is_array($nodes)) {
            $nodes = [];
        }

        $labelMap = [
            '整剧原文输入' => ['label' => '小说导入', 'kind' => 'input', 'icon' => 'EditPen', 'desc' => '输入完整小说/剧本正文'],
            '剧集拆解' => ['label' => '结构拆解', 'kind' => 'text', 'icon' => 'MagicStick', 'desc' => '提取主线/人物线/冲突线'],
            '输出剧集与资产' => ['label' => '写入结果', 'kind' => 'output', 'icon' => 'Download', 'desc' => '写入剧集管理；资产按集生产时增量补齐'],
        ];

        $changed = false;
        foreach ($nodes as &$node) {
            if (!is_array($node)) {
                continue;
            }
            $label = isset($node['label']) ? trim((string) $node['label']) : '';
            if (!isset($labelMap[$label])) {
                continue;
            }
            $next = $labelMap[$label];
            $node['label'] = $next['label'];
            $data = isset($node['data']) && is_array($node['data']) ? $node['data'] : [];
            $data['kind'] = $next['kind'];
            $data['icon'] = $next['icon'];
            $data['desc'] = $next['desc'];
            $node['data'] = $data;
            $changed = true;
        }
        unset($node);

        $scope = $this->normalizeScope((string) ($target->getAttr('scope') ?? 'episode'));
        if ($scope !== 'series') {
            $scope = 'series';
            $changed = true;
        }

        if ($changed) {
            $graph['nodes'] = $nodes;
            $target->save([
                'scope' => $scope,
                'graph' => $graph,
            ]);
            $this->clearWorkflowCache();
        }
    }

    /**
     * 升级存量默认剧本工作流：官方剧本段只拆集，不再提前提取资产。
     */
    private function upgradeSeriesDefaultWithoutAssetExtraction(): void
    {
        $defaults = Workflow::where('user_id', $this->currentUserId())
            ->where('is_default', 1)
            ->where('scope', 'series')
            ->select();

        foreach ($defaults as $w) {
            if (!$w instanceof Workflow) {
                continue;
            }
            $name = trim((string) $w->getAttr('name'));
            $graph = $w->getAttr('graph') ?: [];
            $nodes = is_array($graph) ? ($graph['nodes'] ?? []) : [];
            $hasSeriesAssetNode = false;
            if (is_array($nodes)) {
                foreach ($nodes as $node) {
                    $label = is_array($node) ? trim((string) ($node['label'] ?? '')) : '';
                    if (str_contains($label, '资产提取')) {
                        $hasSeriesAssetNode = true;
                        break;
                    }
                }
            }

            if (!in_array($name, ['剧本拆解与资产生成', '剧本拆解与剧集规划', '一句话扩写成剧本'], true) && !$hasSeriesAssetNode) {
                continue;
            }

            $graph = $name === '一句话扩写成剧本'
                ? DefaultWorkflowGraphs::seriesOneLine()
                : DefaultWorkflowGraphs::seriesFull();
            $description = $name === '一句话扩写成剧本'
                ? '一句话创意 → 扩写剧本 → 结构拆解 → 剧集规划 → 写入剧集；资产按集生产时增量补齐'
                : '小说/剧本导入 → 结构拆解 → 剧集规划 → 写入剧集；资产按集生产时增量补齐';
            $w->save([
                'name' => $name === '剧本拆解与资产生成' ? '剧本拆解与剧集规划' : $name,
                'description' => $description,
                'graph' => $graph,
            ]);
            $this->clearWorkflowCache();
        }
    }

    /**
     * 升级存量默认剧集工作流：缺少「资产提取与合并」节点时自动插入。
     * 只处理 is_default=1 且 scope=episode 的内置工作流，用户自建流程不动。
     */
    private function upgradeEpisodeDefaultWithAssetNode(): void
    {
        $defaults = Workflow::where('user_id', $this->currentUserId())
            ->where('is_default', 1)
            ->where('scope', 'episode')
            ->select();

        foreach ($defaults as $w) {
            if (!$w instanceof Workflow) {
                continue;
            }
            $graph = $w->getAttr('graph') ?: [];
            $nodes = is_array($graph) ? ($graph['nodes'] ?? []) : [];
            if (!is_array($nodes) || $nodes === []) {
                continue;
            }

            $hasAssetNode = false;
            $changedExistingAssetNode = false;
            foreach ($nodes as &$node) {
                $label = is_array($node) ? trim((string) ($node['label'] ?? '')) : '';
                if (str_contains($label, '资产提取')) {
                    $hasAssetNode = true;
                    $node['label'] = '资产提取与合并';
                    $data = isset($node['data']) && is_array($node['data']) ? $node['data'] : [];
                    $data['desc'] = '按本集扩写剧情增量提取人物/场景/道具；已有资产复用，新资产写入并排队参考图';
                    $params = isset($data['params']) && is_array($data['params']) ? $data['params'] : [];
                    $existingPrompt = trim((string) ($params['prompt'] ?? ''));
                    if ($existingPrompt === '' || str_contains($existingPrompt, '资产库为空') || str_contains($existingPrompt, '已有资产则跳过')) {
                        $params['prompt'] = DefaultWorkflowGraphs::episodeAssetPrompt();
                    }
                    $data['params'] = $params;
                    $node['data'] = $data;
                    $changedExistingAssetNode = true;
                    break;
                }
            }
            unset($node);
            if ($hasAssetNode) {
                if ($changedExistingAssetNode) {
                    $graph['nodes'] = $nodes;
                    $w->save(['graph' => $graph]);
                    $this->clearWorkflowCache();
                }
                continue;
            }

            // 线性排序（默认图都是单链），找到首个 image/video 节点，资产节点插在它之前。
            $ordered = $this->linearizeGraphNodes($graph);
            if ($ordered === []) {
                continue;
            }
            $insertAt = count($ordered);
            foreach ($ordered as $i => $node) {
                $kind = (string) ($node['data']['kind'] ?? '');
                $nodeLabel = trim((string) ($node['label'] ?? ''));
                // 与新默认图保持一致：资产节点插在「分镜」之前；兜底插在首个 image/video/output 之前。
                if (str_contains($nodeLabel, '分镜') || in_array($kind, ['image', 'video', 'output'], true)) {
                    $insertAt = $i;
                    break;
                }
            }

            $assetNode = DefaultWorkflowGraphs::episodeAssetNode('asset-prep-ep', 0);
            array_splice($ordered, $insertAt, 0, [$assetNode]);
            $ordered = array_values($ordered);

            // 重排坐标并重连顺序边。
            $edges = [];
            foreach ($ordered as $i => &$node) {
                $node['position'] = ['x' => $i * 280, 'y' => 200];
                if ($i > 0) {
                    $s = (string) $ordered[$i - 1]['id'];
                    $t = (string) $node['id'];
                    $edges[] = ['id' => "e-{$s}-{$t}", 'source' => $s, 'target' => $t, 'animated' => true, 'markerEnd' => 'arrowclosed'];
                }
            }
            unset($node);

            $w->save(['graph' => ['nodes' => $ordered, 'edges' => $edges]]);
            $this->clearWorkflowCache();
        }
    }

    /**
     * 系统默认流程对用户是黑盒：列表/详情接口只暴露结构，不暴露内置 prompt。
     * 执行流程仍读取数据库原始 graph，不受这里的序列化裁剪影响。
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

    /**
     * 升级存量默认剧集工作流：官方流程不再包含「帧图片生成」节点。
     * 只处理 is_default=1 且 scope=episode 的内置工作流，用户自建流程不动。
     */
    private function upgradeEpisodeDefaultWithoutFrameNode(): void
    {
        $defaults = Workflow::where('user_id', $this->currentUserId())
            ->where('is_default', 1)
            ->where('scope', 'episode')
            ->select();

        foreach ($defaults as $w) {
            if (!$w instanceof Workflow) {
                continue;
            }

            $graph = $w->getAttr('graph') ?: [];
            $ordered = is_array($graph) ? $this->linearizeGraphNodes($graph) : [];
            if ($ordered === []) {
                continue;
            }

            $changed = false;
            $filtered = [];
            foreach ($ordered as $node) {
                if (!is_array($node)) {
                    continue;
                }
                if ($this->isFrameImageGenerationNode($node)) {
                    $changed = true;
                    continue;
                }

                $label = trim((string) ($node['label'] ?? ''));
                if ($label === '视频生成') {
                    $data = isset($node['data']) && is_array($node['data']) ? $node['data'] : [];
                    $params = isset($data['params']) && is_array($data['params']) ? $data['params'] : [];
                    $data['desc'] = '基于分镜描述和资产参考生成视频片段';
                    $params['prompt'] = '基于当前分镜描述生成视频片段；如涉及人物、场景或道具，优先参考资产库保持一致性。画面不要字幕。';
                    $params['duration'] = 0;
                    $data['params'] = $params;
                    $node['data'] = $data;
                    $changed = true;
                }

                $filtered[] = $node;
            }

            $description = (string) $w->getAttr('description');
            $nextDescription = str_replace([' → 帧图片', '帧图片 → '], ['', ''], $description);
            if ($nextDescription !== $description) {
                $changed = true;
            }

            if (!$changed) {
                continue;
            }

            $edges = [];
            foreach ($filtered as $i => &$node) {
                $node['position'] = ['x' => $i * 280, 'y' => 200];
                if ($i > 0) {
                    $s = (string) $filtered[$i - 1]['id'];
                    $t = (string) $node['id'];
                    $edges[] = ['id' => "e-{$s}-{$t}", 'source' => $s, 'target' => $t, 'animated' => true, 'markerEnd' => 'arrowclosed'];
                }
            }
            unset($node);

            $w->save([
                'description' => $nextDescription,
                'graph' => ['nodes' => $filtered, 'edges' => $edges],
            ]);
            $this->clearWorkflowCache();
        }
    }

    /**
     * 官方剧集流程关闭镜头衔接。只改 is_default=1 的内置模板，用户副本不动。
     */
    private function upgradeEpisodeDefaultDisableChainShots(): void
    {
        $defaults = Workflow::where('user_id', $this->currentUserId())
            ->where('is_default', 1)
            ->where('scope', 'episode')
            ->select();

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
            $this->clearWorkflowCache();
        }
    }

    private function isFrameImageGenerationNode(array $node): bool
    {
        $id = strtolower((string) ($node['id'] ?? ''));
        $label = trim((string) ($node['label'] ?? ''));
        $kind = (string) ($node['data']['kind'] ?? '');

        return str_contains($id, 'frame-gen')
            || str_contains($label, '帧图片生成')
            || ($kind === 'image' && str_contains($label, '帧'));
    }

    /**
     * 把 graph 按边链线性化；断链节点按 position.x 兜底追加（与前端 graphToSteps 一致）。
     */
    private function linearizeGraphNodes(array $graph): array
    {
        $nodes = is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [];
        $edges = is_array($graph['edges'] ?? null) ? $graph['edges'] : [];
        if ($nodes === []) {
            return [];
        }

        $byId = [];
        foreach ($nodes as $node) {
            if (is_array($node) && isset($node['id'])) {
                $byId[(string) $node['id']] = $node;
            }
        }

        $next = [];
        $hasIncoming = [];
        foreach ($edges as $edge) {
            $s = (string) ($edge['source'] ?? '');
            $t = (string) ($edge['target'] ?? '');
            if (!isset($byId[$s], $byId[$t])) {
                continue;
            }
            $next[$s] ??= $t;
            $hasIncoming[$t] = true;
        }

        $start = null;
        foreach ($byId as $id => $node) {
            if (!isset($hasIncoming[$id])) {
                $start = $id;
                break;
            }
        }
        $start ??= (string) array_key_first($byId);

        $ordered = [];
        $visited = [];
        $cur = $start;
        while ($cur !== null && isset($byId[$cur]) && !isset($visited[$cur])) {
            $visited[$cur] = true;
            $ordered[] = $byId[$cur];
            $cur = $next[$cur] ?? null;
        }

        $rest = array_filter($byId, static fn ($n, $id) => !isset($visited[$id]), ARRAY_FILTER_USE_BOTH);
        usort($rest, static fn ($a, $b) => ((int) ($a['position']['x'] ?? 0)) <=> ((int) ($b['position']['x'] ?? 0)));

        return array_merge($ordered, array_values($rest));
    }

    /**
     * 查找工作流。
     * 找不到时直接返回 404。
     */
    private function findOrFail(int $id): Workflow
    {
        $model = Workflow::where('id', $id)
            ->where('user_id', $this->currentUserId())
            ->find();
        if (!$model instanceof Workflow) {
            abort(404, '工作流不存在');
        }

        return $model;
    }

    /**
     * 计算下一个工作流排序值。
     */
    private function nextSort(): int
    {
        return ((int) Workflow::where('user_id', $this->currentUserId())->max('sort')) + 10;
    }

    /**
     * 清理工作流列表缓存。
     * 工作流模板变化后提升版本号，使旧缓存立即失效。
     */
    private function clearWorkflowCache(): void
    {
        RedisCache::bumpVersion('workflows');
    }
}
