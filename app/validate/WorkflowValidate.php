<?php

declare(strict_types=1);

namespace app\validate;

use think\Validate;

class WorkflowValidate extends Validate
{
    protected $rule = [
        'name' => 'require|max:120',
        'description' => 'max:255',
        'graph' => 'require|array|checkGraphTopology',
        'viewport' => 'array',
        'is_default' => 'in:0,1',
        'scope' => 'in:episode,series',
    ];

    protected $message = [
        'name.require' => '请输入工作流名称',
        'name.max' => '工作流名称不能超过 120 个字符',
        'description.max' => '描述不能超过 255 个字符',
        'graph.require' => '请先搭建工作流节点后再保存',
        'graph.array' => '画布数据必须是 JSON 对象',
        'viewport.array' => '视口数据必须是 JSON 对象',
        'scope.in' => '工作流类型必须是 episode 或 series',
    ];

    /** 各 scope 允许的节点类型（与前端 allowedKindsByScope 保持一致）。 */
    private const KINDS_BY_SCOPE = [
        'episode' => ['input', 'text', 'image', 'video', 'voice', 'output'],
        'series' => ['input', 'text', 'output'],
    ];

    /** 能向下游提供分镜内容的节点类型；图片/视频节点的直接上游必须是其中之一。 */
    private const SHOT_PRODUCER_KINDS = ['text', 'image', 'video'];

    protected function checkGraphTopology($value, $rule = '', array $data = []): bool|string
    {
        if (!is_array($value)) {
            return '画布数据必须是 JSON 对象';
        }

        $nodes = $value['nodes'] ?? [];
        $edges = $value['edges'] ?? [];
        if (!is_array($nodes) || !is_array($edges)) {
            return '画布数据格式错误：必须包含 nodes / edges 数组';
        }

        // 工作流必须可执行：空模板会在剧集页造成“还没有流程节点”的死胡同。
        if (count($nodes) === 0) {
            return '工作流不能为空：请至少添加输入、处理和输出节点';
        }

        $nodeIds = [];
        $labels = [];
        $kinds = [];
        foreach ($nodes as $node) {
            if (!is_array($node) || !isset($node['id'])) {
                continue;
            }
            $id = trim((string) $node['id']);
            if ($id === '') {
                continue;
            }
            $nodeIds[$id] = true;
            $labels[$id] = isset($node['label']) && is_scalar($node['label']) && trim((string) $node['label']) !== ''
                ? trim((string) $node['label'])
                : $id;
            $kinds[$id] = (isset($node['data']) && is_array($node['data']) && isset($node['data']['kind']) && is_scalar($node['data']['kind']))
                ? (string) $node['data']['kind']
                : '';
        }

        if (count($nodeIds) === 0) {
            return '请至少添加一个有效节点后再保存';
        }

        $inDegree = [];
        $outDegree = [];
        $adjacency = [];
        $predecessors = [];
        foreach ($nodeIds as $id => $_) {
            $inDegree[$id] = 0;
            $outDegree[$id] = 0;
            $adjacency[$id] = [];
            $predecessors[$id] = [];
        }

        foreach ($edges as $edge) {
            if (!is_array($edge)) {
                continue;
            }
            $source = isset($edge['source']) ? trim((string) $edge['source']) : '';
            $target = isset($edge['target']) ? trim((string) $edge['target']) : '';
            if ($source === '' || $target === '') {
                continue;
            }
            if (!isset($nodeIds[$source]) || !isset($nodeIds[$target])) {
                continue;
            }
            $outDegree[$source]++;
            $inDegree[$target]++;
            $adjacency[$source][] = $target;
            $predecessors[$target][] = $source;
        }

        $isolated = [];
        foreach ($nodeIds as $id => $_) {
            if (($inDegree[$id] + $outDegree[$id]) === 0) {
                $isolated[] = $labels[$id] ?? $id;
            }
        }
        if ($isolated !== []) {
            return '存在未连接节点：' . implode('、', array_slice($isolated, 0, 3));
        }

        $inputIds = [];
        $outputIds = [];
        foreach ($kinds as $id => $kind) {
            if ($kind === 'input') {
                $inputIds[] = $id;
            } elseif ($kind === 'output') {
                $outputIds[] = $id;
            }
        }

        if (count($inputIds) !== 1 || count($outputIds) !== 1) {
            return '请确保工作流且仅有 1 个输入节点和 1 个输出节点';
        }

        // 只有输入和输出的流程跑不出任何产物，必须至少有一个处理节点。
        if (count($nodeIds) < 3) {
            return '输入和输出之间至少需要一个处理节点（文本/图片/视频）';
        }

        $notClosed = [];
        foreach ($nodeIds as $id => $_) {
            $kind = $kinds[$id] ?? '';
            if ($kind === 'input') {
                if ($outDegree[$id] === 0) {
                    $notClosed[] = $labels[$id] ?? $id;
                }
                continue;
            }
            if ($kind === 'output') {
                if ($inDegree[$id] === 0) {
                    $notClosed[] = $labels[$id] ?? $id;
                }
                continue;
            }
            if ($inDegree[$id] === 0 || $outDegree[$id] === 0) {
                $notClosed[] = $labels[$id] ?? $id;
            }
        }

        if ($notClosed !== []) {
            return '存在未闭环节点：' . implode('、', array_slice($notClosed, 0, 3));
        }

        $start = $inputIds[0];
        $end = $outputIds[0];
        $visited = [$start => true];
        $queue = [$start];
        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($adjacency[$current] as $next) {
                if (!isset($visited[$next])) {
                    $visited[$next] = true;
                    $queue[] = $next;
                }
            }
        }

        if (!isset($visited[$end])) {
            return '当前链路未闭环：无法从输入节点到达输出节点';
        }

        // 每个节点都必须在输入到输出的链路上：既能被输入节点到达，也能到达输出节点。
        // 否则会出现游离子图（例如两个节点互相连成环但不在主链上）。
        $reachesEnd = [$end => true];
        $queue = [$end];
        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($predecessors[$current] as $prev) {
                if (!isset($reachesEnd[$prev])) {
                    $reachesEnd[$prev] = true;
                    $queue[] = $prev;
                }
            }
        }

        $offChain = [];
        foreach ($nodeIds as $id => $_) {
            if (!isset($visited[$id]) || !isset($reachesEnd[$id])) {
                $offChain[] = $labels[$id] ?? $id;
            }
        }
        if ($offChain !== []) {
            return '以下节点不在输入到输出的链路上：' . implode('、', array_slice($offChain, 0, 3));
        }

        // 拓扑排序必须能覆盖全部节点，否则说明存在循环连线，执行顺序无法确定。
        $degree = $inDegree;
        $queue = [];
        foreach ($degree as $id => $d) {
            if ($d === 0) {
                $queue[] = $id;
            }
        }
        $sorted = 0;
        while ($queue !== []) {
            $current = array_shift($queue);
            $sorted++;
            foreach ($adjacency[$current] as $next) {
                $degree[$next]--;
                if ($degree[$next] === 0) {
                    $queue[] = $next;
                }
            }
        }
        if ($sorted !== count($nodeIds)) {
            return '工作流存在循环连线，无法按顺序执行，请检查节点连接方向';
        }

        // scope 限制：剧本工作流只允许文本类节点。
        $scope = isset($data['scope']) && is_scalar($data['scope']) ? (string) $data['scope'] : 'episode';
        $allowedKinds = self::KINDS_BY_SCOPE[$scope] ?? self::KINDS_BY_SCOPE['episode'];
        foreach ($kinds as $id => $kind) {
            if ($kind !== '' && !in_array($kind, $allowedKinds, true)) {
                return '剧本工作流只支持输入、文本、输出节点，请移除节点：' . ($labels[$id] ?? $id);
            }
        }

        // 语义闭环：图片/视频节点的直接上游必须能产出分镜内容（文本/图片/视频），
        // 直接接在输入或语音节点后面在执行时必然失败。
        foreach ($kinds as $id => $kind) {
            if ($kind !== 'image' && $kind !== 'video') {
                continue;
            }
            $hasShotProducer = false;
            foreach ($predecessors[$id] as $prev) {
                if (in_array($kinds[$prev] ?? '', self::SHOT_PRODUCER_KINDS, true)) {
                    $hasShotProducer = true;
                    break;
                }
            }
            if (!$hasShotProducer) {
                $kindLabel = $kind === 'image' ? '图片' : '视频';
                return "{$kindLabel}节点「" . ($labels[$id] ?? $id) . '」前面必须有分镜（文本）或图片节点提供分镜内容，不能直接接在输入/语音节点后面';
            }
        }

        return true;
    }
}
