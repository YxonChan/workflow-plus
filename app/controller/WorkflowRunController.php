<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\model\WorkflowRun;
use app\model\WorkflowRunNode;
use app\support\RedisCache;
use app\support\WorkflowRuntime;
use think\facade\Db;

class WorkflowRunController extends BaseController
{
    /**
     * 查询最近的工作流执行记录。
     * 返回当前用户最近的任务列表（含剧名），供制作台总览展示流程健康状态。
     */
    public function index()
    {
        $payload = $this->request->param();
        $limit = max(1, min(50, (int) ($payload['limit'] ?? 10)));

        $runs = WorkflowRun::where('user_id', $this->currentUserId())
            ->order('id', 'desc')
            ->limit($limit)
            ->select();

        $seriesIds = [];
        foreach ($runs as $run) {
            $seriesIds[] = (int) $run->getAttr('series_id');
        }
        $seriesTitles = $seriesIds === []
            ? []
            : Db::name('series')->whereIn('id', array_values(array_unique($seriesIds)))->column('title', 'id');

        $items = [];
        foreach ($runs as $run) {
            $items[] = [
                'id' => (int) $run->getAttr('id'),
                'series_id' => (int) $run->getAttr('series_id'),
                'series_title' => (string) ($seriesTitles[(int) $run->getAttr('series_id')] ?? ''),
                'workflow_id' => (int) $run->getAttr('workflow_id'),
                'status' => (string) $run->getAttr('status'),
                'progress' => (int) $run->getAttr('progress'),
                'current_node_label' => (string) $run->getAttr('current_node_label'),
                'error_message' => (string) $run->getAttr('error_message'),
                'started_at' => $run->getAttr('started_at'),
                'finished_at' => $run->getAttr('finished_at'),
                'create_time' => $run->getAttr('create_time'),
            ];
        }

        return successCode($items);
    }

    /**
     * 查询异步工作流任务详情。
     * 从 JSON 请求体读取 id，返回任务主状态和每个节点的执行状态，供前端轮询进度。
     */
    public function detail()
    {
        $payload = $this->request->param();
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            return errorCode([], '任务 id 不能为空', 422);
        }

        $run = WorkflowRun::with(['nodes'])->where('id', $id)->where('user_id', $this->currentUserId())->find();
        if (!$run instanceof WorkflowRun) {
            return errorCode([], '任务不存在', 404);
        }

        return successCode($this->serializeRun($run));
    }

    /**
     * 失败任务继续执行。
     * 已成功节点保留，失败/运行中的节点重新排队，worker 会从失败处往后继续。
     */
    public function resume()
    {
        $payload = $this->request->param();
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            return errorCode([], '任务 id 不能为空', 422);
        }

        $run = Db::transaction(function () use ($id): WorkflowRun {
            $run = WorkflowRun::where('id', $id)->where('user_id', $this->currentUserId())->lock(true)->find();
            if (!$run instanceof WorkflowRun) {
                abort(404, '任务不存在');
            }
            if ((string) $run->getAttr('status') !== 'failed') {
                abort(422, '只有失败的任务可以继续执行');
            }

            WorkflowRunNode::where('run_id', $id)
                ->whereIn('status', ['failed', 'running'])
                ->update([
                    'status' => 'queued',
                    'error_message' => '',
                    'started_at' => null,
                    'finished_at' => null,
                ]);

            $success = WorkflowRunNode::where('run_id', $id)->where('status', 'success')->count();
            $total = max(1, WorkflowRunNode::where('run_id', $id)->count());
            $run->save([
                'status' => 'queued',
                'progress' => max(1, min(99, (int) floor(($success / $total) * 90))),
                'current_node_label' => '',
                'error_message' => '',
                'finished_at' => null,
            ]);

            return $run;
        });

        WorkflowRuntime::rememberSeriesRun($run);
        RedisCache::bumpVersion('series');
        RedisCache::bumpVersion("workflow_run:{$id}");

        return successCode($this->serializeRun(WorkflowRun::with(['nodes'])->where('id', $id)->where('user_id', $this->currentUserId())->findOrFail()));
    }

    /**
     * 取消当前剧本工作流任务。
     * 用于失败/卡住/不想继续时解锁剧本；已写入的剧集和资产不会回滚。
     */
    public function cancel()
    {
        $payload = $this->request->param();
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            return errorCode([], '任务 id 不能为空', 422);
        }

        $run = Db::transaction(function () use ($id): WorkflowRun {
            $run = WorkflowRun::where('id', $id)->where('user_id', $this->currentUserId())->lock(true)->find();
            if (!$run instanceof WorkflowRun) {
                abort(404, '任务不存在');
            }
            if (in_array((string) $run->getAttr('status'), ['success', 'cancelled'], true)) {
                return $run;
            }

            WorkflowRunNode::where('run_id', $id)
                ->whereIn('status', ['queued', 'running'])
                ->update([
                    'status' => 'skipped',
                    'error_message' => '任务已取消',
                    'finished_at' => date('Y-m-d H:i:s'),
                ]);

            $run->save([
                'status' => 'cancelled',
                'current_node_label' => '',
                'error_message' => '任务已取消',
                'finished_at' => date('Y-m-d H:i:s'),
            ]);

            return $run;
        });

        WorkflowRuntime::clearSeriesRun((int) $run->getAttr('series_id'), (int) $run->getAttr('id'));
        RedisCache::bumpVersion('series');
        RedisCache::bumpVersion("workflow_run:{$id}");

        return successCode($this->serializeRun(WorkflowRun::with(['nodes'])->where('id', $id)->where('user_id', $this->currentUserId())->findOrFail()));
    }

    /**
     * 通过 SSE 推送异步工作流任务详情。
     * 连接会在任务成功/失败/取消后关闭；前端断线可自动重连。
     */
    public function stream()
    {
        $id = (int) ($this->request->param('id') ?? 0);
        if ($id <= 0) {
            return response('任务 id 不能为空', 422);
        }

        if (!WorkflowRun::where('id', $id)->where('user_id', $this->currentUserId())->find()) {
            return response('任务不存在', 404);
        }

        @set_time_limit(0);
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', '0');
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }

        if (!headers_sent()) {
            header('Content-Type: text/event-stream; charset=utf-8');
            header('Cache-Control: no-cache, no-transform');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');
        }

        $startedAt = time();
        $versionKey = "workflow_run:{$id}";
        $lastVersion = -1;

        echo "retry: 2000\n\n";
        @ob_flush();
        @flush();

        while (true) {
            if (connection_aborted()) {
                break;
            }

            $version = RedisCache::version($versionKey);
            if ($version !== $lastVersion) {
                $run = WorkflowRun::with(['nodes'])->where('id', $id)->where('user_id', $this->currentUserId())->find();
                if (!$run instanceof WorkflowRun) {
                    echo "event: error\n";
                    echo 'data: ' . json_encode(['message' => '任务不存在'], JSON_UNESCAPED_UNICODE) . "\n\n";
                    @ob_flush();
                    @flush();
                    break;
                }

                $payload = $this->serializeRun($run);
                echo "event: workflow_run\n";
                echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
                $lastVersion = $version;

                if (in_array((string) $run->getAttr('status'), ['success', 'failed', 'cancelled'], true)) {
                    @ob_flush();
                    @flush();
                    break;
                }
            } else {
                echo ": heartbeat\n\n";
            }
            @ob_flush();
            @flush();

            if (time() - $startedAt >= 120) {
                break;
            }

            sleep(1);
        }

        exit;
    }

    /**
     * 序列化任务主记录。
     */
    private function serializeRun(WorkflowRun $run): array
    {
        $nodes = [];
        foreach ($run->nodes as $node) {
            if ($node instanceof WorkflowRunNode) {
                $nodes[] = $this->serializeNode($node);
            }
        }

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
            'started_at' => $run->getAttr('started_at'),
            'finished_at' => $run->getAttr('finished_at'),
            'create_time' => $run->getAttr('create_time'),
            'update_time' => $run->getAttr('update_time'),
            'nodes' => $nodes,
        ];
    }

    /**
     * 序列化任务节点记录。
     */
    private function serializeNode(WorkflowRunNode $node): array
    {
        $requestPayload = $node->getAttr('request_payload_json');
        $aiMeta = $node->getAttr('ai_meta_json');
        $rawOutput = (string) $node->getAttr('raw_output');

        return [
            'id' => (int) $node->getAttr('id'),
            'workflow_node_id' => (string) $node->getAttr('workflow_node_id'),
            'label' => (string) $node->getAttr('label'),
            'kind' => (string) $node->getAttr('kind'),
            'sort' => (int) $node->getAttr('sort'),
            'status' => (string) $node->getAttr('status'),
            'error_message' => (string) $node->getAttr('error_message'),
            'duration_ms' => (int) $node->getAttr('duration_ms'),
            'started_at' => $node->getAttr('started_at'),
            'finished_at' => $node->getAttr('finished_at'),
            'ai_request_log_id' => $node->getAttr('ai_request_log_id') === null
                ? null
                : (int) $node->getAttr('ai_request_log_id'),
            'request_payload_json' => is_array($requestPayload) ? $requestPayload : null,
            'ai_meta_json' => is_array($aiMeta) ? $aiMeta : null,
            'raw_output_preview' => $rawOutput === ''
                ? ''
                : (mb_strlen($rawOutput) > 4000
                    ? mb_substr($rawOutput, 0, 2000) . "\n...[truncated]...\n" . mb_substr($rawOutput, -2000)
                    : $rawOutput),
        ];
    }
}
