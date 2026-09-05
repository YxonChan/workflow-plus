<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\model\AdminOperationLog;
use app\model\User;

class AdminOperationLogController extends BaseController
{
    public function index()
    {
        $this->requireAdmin();

        $page = max(1, (int) $this->request->param('page', 1));
        $limit = min(100, max(1, (int) $this->request->param('limit', 20)));
        $userId = (int) $this->request->param('operator_user_id', 0);
        $action = trim((string) $this->request->param('action', ''));
        $targetType = trim((string) $this->request->param('target_type', ''));
        $targetId = (int) $this->request->param('target_id', 0);
        $result = trim((string) $this->request->param('result', ''));
        $keyword = trim((string) $this->request->param('keyword', ''));
        $startDate = trim((string) $this->request->param('start_date', ''));
        $endDate = trim((string) $this->request->param('end_date', ''));

        $query = AdminOperationLog::order('id', 'desc');
        if ($userId > 0) {
            $query->where('operator_user_id', $userId);
        }
        if ($action !== '') {
            $query->where('action', $action);
        }
        if ($targetType !== '') {
            $query->where('target_type', $targetType);
        }
        if ($targetId > 0) {
            $query->where('target_id', $targetId);
        }
        if (in_array($result, ['success', 'failed'], true)) {
            $query->where('result', $result);
        }
        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $query->where(function ($q) use ($like) {
                $q->whereLike('target_name_snapshot', $like)
                    ->whereOr('operator_name_snapshot', 'like', $like)
                    ->whereOr('error_message', 'like', $like);
            });
        }
        if ($startDate !== '') {
            $query->where('create_time', '>=', $startDate . ' 00:00:00');
        }
        if ($endDate !== '') {
            $query->where('create_time', '<=', $endDate . ' 23:59:59');
        }

        $total = (int) $query->count();
        $rows = $query->page($page, $limit)->select()->toArray();

        return successCode([
            'list' => array_map([$this, 'serializeListItem'], $rows),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    public function detail()
    {
        $this->requireAdmin();

        $id = (int) $this->request->param('id', 0);
        if ($id <= 0) {
            return errorCode([], '日志 id 不能为空', 422);
        }

        $log = AdminOperationLog::where('id', $id)->find();
        if (!$log instanceof AdminOperationLog) {
            return errorCode([], '日志不存在', 404);
        }

        return successCode($this->serializeDetail($log->toArray()));
    }

    private function serializeListItem(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'operator_user_id' => (int) ($row['operator_user_id'] ?? 0),
            'operator' => $this->serializeUserBrief((int) ($row['operator_user_id'] ?? 0), (string) ($row['operator_name_snapshot'] ?? '')),
            'action' => (string) ($row['action'] ?? ''),
            'target_type' => (string) ($row['target_type'] ?? ''),
            'target_id' => (int) ($row['target_id'] ?? 0),
            'target_name_snapshot' => (string) ($row['target_name_snapshot'] ?? ''),
            'series_id' => isset($row['series_id']) ? (int) $row['series_id'] : null,
            'episode_id' => isset($row['episode_id']) ? (int) $row['episode_id'] : null,
            'workflow_run_id' => isset($row['workflow_run_id']) ? (int) $row['workflow_run_id'] : null,
            'result' => (string) ($row['result'] ?? 'success'),
            'error_message' => (string) ($row['error_message'] ?? ''),
            'create_time' => $row['create_time'] ?? null,
        ];
    }

    private function serializeDetail(array $row): array
    {
        $item = $this->serializeListItem($row);
        $item['operator_name_snapshot'] = (string) ($row['operator_name_snapshot'] ?? '');
        $item['ip'] = (string) ($row['ip'] ?? '');
        $item['user_agent'] = (string) ($row['user_agent'] ?? '');
        $item['request_id'] = (string) ($row['request_id'] ?? '');
        $item['meta_json'] = $this->decodeJsonField($row['meta_json'] ?? null);
        $item['before_json'] = $this->decodeJsonField($row['before_json'] ?? null);
        $item['after_json'] = $this->decodeJsonField($row['after_json'] ?? null);

        return $item;
    }

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

    private function serializeUserBrief(int $userId, string $fallbackName): ?array
    {
        if ($userId <= 0 && $fallbackName === '') {
            return null;
        }

        $user = $userId > 0 ? User::where('id', $userId)->find() : null;
        if ($user instanceof User) {
            return [
                'id' => (int) $user->getAttr('id'),
                'username' => (string) $user->getAttr('username'),
                'display_name' => (string) $user->getAttr('display_name'),
            ];
        }

        return [
            'id' => $userId,
            'username' => '',
            'display_name' => $fallbackName,
        ];
    }
}
