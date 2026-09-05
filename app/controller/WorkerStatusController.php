<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\support\WorkerActions;
use app\support\WorkerCrewStats;

/**
 * 数字员工状态聚合接口。
 * 聚合逻辑在 support\WorkerCrewStats，供本接口与员工对话 Agent 复用。
 */
class WorkerStatusController extends BaseController
{
    /** 一键动作白名单：worker => [action => 处理闭包名]。不在表内的组合一律拒绝。 */
    private const ACTION_WHITELIST = [
        'asset' => ['retry_failed', 'cancel_queued'],
        'video' => ['retry_failed', 'cancel_queued'],
    ];

    public function status()
    {
        $this->requireNonAdmin();
        return successCode([
            'workers' => WorkerCrewStats::all($this->currentUserId()),
            'generated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 数字员工一键动作（重试失败/取消排队），不经过 LLM，秒级返回。
     * 通过白名单从机制上限定每个员工可执行的动作范围。
     */
    public function action()
    {
        $this->requireNonAdmin();
        $payload = $this->request->param();
        $worker = trim((string) ($payload['worker'] ?? ''));
        $action = trim((string) ($payload['action'] ?? ''));

        $allowed = self::ACTION_WHITELIST[$worker] ?? null;
        if ($allowed === null || !in_array($action, $allowed, true)) {
            return errorCode([], '该员工不支持此操作', 422);
        }

        $userId = $this->currentUserId();
        $affected = match (true) {
            $worker === 'asset' && $action === 'retry_failed' => WorkerActions::retryFailedImageJobs($userId),
            $worker === 'asset' && $action === 'cancel_queued' => WorkerActions::cancelQueuedImageJobs($userId),
            $worker === 'video' && $action === 'retry_failed' => WorkerActions::retryFailedVideoJobs($userId),
            $worker === 'video' && $action === 'cancel_queued' => WorkerActions::cancelQueuedVideoJobs($userId),
            default => 0,
        };

        return successCode([
            'worker' => $worker,
            'action' => $action,
            'affected' => $affected,
            'workers' => WorkerCrewStats::all($userId),
        ]);
    }
}
