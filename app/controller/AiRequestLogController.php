<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\model\AiRequestLog;
use app\model\User;

class AiRequestLogController extends BaseController
{
    /**
     * 分页查询 AI 请求日志。
     * 支持 page、limit、source、workflow_run_id、workflow_run_node_id。
     */
    public function index()
    {
        $this->requireAdmin();

        $page = max(1, (int) $this->request->param('page', 1));
        $limit = min(100, max(1, (int) $this->request->param('limit', 20)));
        $source = trim((string) $this->request->param('source', ''));
        $model = trim((string) $this->request->param('model', ''));
        $status = trim((string) $this->request->param('status', ''));
        $startDate = trim((string) $this->request->param('start_date', ''));
        $endDate = trim((string) $this->request->param('end_date', ''));
        $userId = (int) $this->request->param('user_id', 0);
        $workflowRunId = (int) $this->request->param('workflow_run_id', 0);
        $workflowRunNodeId = (int) $this->request->param('workflow_run_node_id', 0);

        $query = AiRequestLog::order('id', 'desc');
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        if ($source !== '') {
            $query->where('source', $source);
        }
        if ($model !== '') {
            $query->whereLike('llm_model', '%' . $model . '%');
        }
        if ($status === 'success') {
            $query->where('request_ok', 1);
        } elseif ($status === 'failed') {
            $query->where('request_ok', 0);
        }
        if ($startDate !== '') {
            $query->where('create_time', '>=', $startDate . ' 00:00:00');
        }
        if ($endDate !== '') {
            $query->where('create_time', '<=', $endDate . ' 23:59:59');
        }
        if ($workflowRunId > 0) {
            $query->where('workflow_run_id', $workflowRunId);
        }
        if ($workflowRunNodeId > 0) {
            $query->where('workflow_run_node_id', $workflowRunNodeId);
        }

        $total = (int) $query->count();
        $list = $query->page($page, $limit)->select()->toArray();

        return successCode([
            'list' => array_map([$this, 'serializeListItem'], $list),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    /**
     * 单条日志详情（含完整 request_json、assistant_content、response_body）。
     */
    public function detail()
    {
        $this->requireAdmin();

        $id = (int) $this->request->param('id', 0);
        if ($id <= 0) {
            return errorCode([], '日志 id 不能为空', 422);
        }

        $log = AiRequestLog::where('id', $id)->find();
        if (!$log instanceof AiRequestLog) {
            return errorCode([], '日志不存在', 404);
        }

        return successCode($this->serializeDetail($log->toArray()));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function serializeListItem(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'source' => (string) ($row['source'] ?? ''),
            'workflow_run_id' => isset($row['workflow_run_id']) ? (int) $row['workflow_run_id'] : null,
            'workflow_run_node_id' => isset($row['workflow_run_node_id']) ? (int) $row['workflow_run_node_id'] : null,
            'model_config_id' => isset($row['model_config_id']) ? (int) $row['model_config_id'] : null,
            'user_id' => (int) ($row['user_id'] ?? 0),
            'user' => $this->serializeUserBrief((int) ($row['user_id'] ?? 0)),
            'llm_model' => (string) ($row['llm_model'] ?? ''),
            'finish_reason' => (string) ($row['finish_reason'] ?? ''),
            'max_tokens' => (int) ($row['max_tokens'] ?? 0),
            'prompt_tokens' => (int) ($row['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) ($row['completion_tokens'] ?? 0),
            'total_tokens' => (int) ($row['total_tokens'] ?? 0),
            'http_status' => (int) ($row['http_status'] ?? 0),
            'request_ok' => (int) ($row['request_ok'] ?? 0),
            'error_message' => (string) ($row['error_message'] ?? ''),
            'duration_ms' => (int) ($row['duration_ms'] ?? 0),
            'content_preview' => (string) ($row['content_preview'] ?? ''),
            'create_time' => $row['create_time'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function serializeDetail(array $row): array
    {
        $item = $this->serializeListItem($row);
        $item['endpoint'] = (string) ($row['endpoint'] ?? '');
        $item['context_json'] = $this->decodeJsonField($row['context_json'] ?? '');
        $item['request_json'] = $this->decodeJsonField($row['request_json'] ?? '');
        $item['usage_json'] = is_array($row['usage_json'] ?? null) ? $row['usage_json'] : $this->decodeJsonField($row['usage_json'] ?? '');
        $item['response_body'] = (string) ($row['response_body'] ?? '');
        $item['assistant_content'] = (string) ($row['assistant_content'] ?? '');
        $item['curl_errno'] = (int) ($row['curl_errno'] ?? 0);
        $item['curl_error'] = (string) ($row['curl_error'] ?? '');

        return $item;
    }

    /**
     * @return array<string, mixed>|string
     */
    private function decodeJsonField(mixed $value): array|string
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : $value;
    }

    private function serializeUserBrief(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        $user = User::where('id', $userId)->find();
        if (!$user instanceof User) {
            return null;
        }

        return [
            'id' => (int) $user->getAttr('id'),
            'username' => (string) $user->getAttr('username'),
            'display_name' => (string) $user->getAttr('display_name'),
        ];
    }
}
