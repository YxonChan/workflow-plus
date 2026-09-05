<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\support\WorkerAgentService;
use app\support\StoryboardVisualReviewService;

/**
 * 数字员工对话接口。
 * 会话历史由前端维护并随请求传入，服务端无状态；
 * 职能边界由 WorkerAgentService 的工具白名单硬性保证。
 */
class WorkerAgentController extends BaseController
{
    public function chat()
    {
        $this->requireNonAdmin();
        $payload = $this->request->param();

        $worker = trim((string) ($payload['worker'] ?? ''));
        if (!in_array($worker, ['assistant', 'producer', 'asset', 'video'], true)) {
            return errorCode([], '未知的员工角色', 422);
        }

        $message = trim((string) ($payload['message'] ?? ''));
        if ($message === '') {
            return errorCode([], '请输入要对员工说的话', 422);
        }
        if (mb_strlen($message) > 2000) {
            return errorCode([], '消息太长了，请控制在 2000 字以内', 422);
        }

        $history = is_array($payload['history'] ?? null) ? $payload['history'] : [];
        $context = is_array($payload['context'] ?? null) ? $payload['context'] : [];

        $service = new WorkerAgentService();
        $result = $service->chat($this->app, $this->currentUserId(), $worker, $history, $message, $context);

        return successCode($result);
    }

    public function visualReview()
    {
        $this->requireNonAdmin();
        $payload = $this->request->param();
        $episodeId = (int) ($payload['episode_id'] ?? 0);
        if ($episodeId <= 0) {
            return errorCode([], '请先选择要检查的剧集', 422);
        }
        $references = is_array($payload['references'] ?? null) ? $payload['references'] : [];
        $result = (new StoryboardVisualReviewService())->review(
            $this->app,
            $this->currentUserId(),
            $episodeId,
            $references,
        );

        return successCode($result);
    }

    public function repairPreview()
    {
        $this->requireNonAdmin();
        $payload = $this->request->param();
        $reviewToken = trim((string) ($payload['review_token'] ?? ''));
        if ($reviewToken === '') {
            return errorCode([], '检查结果令牌不能为空', 422);
        }
        $decisions = is_array($payload['decisions'] ?? null) ? $payload['decisions'] : [];
        $result = (new StoryboardVisualReviewService())->preview(
            $this->currentUserId(),
            $reviewToken,
            $decisions,
        );

        return successCode($result);
    }

    public function repairApply()
    {
        $this->requireNonAdmin();
        $confirmationToken = trim((string) $this->request->param('confirmation_token', ''));
        if ($confirmationToken === '') {
            return errorCode([], '确认令牌不能为空', 422);
        }
        $result = (new StoryboardVisualReviewService())->apply(
            $this->app,
            $this->currentUserId(),
            $confirmationToken,
        );

        return successCode($result);
    }
}
