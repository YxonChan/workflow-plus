<?php

declare(strict_types=1);

namespace app\support;

/**
 * Pure helpers for video-node progress / chain-release decisions.
 */
final class VideoWorkflowProgress
{
    /**
     * Decide workflow run-node status + episode-state status from job counters.
     * Cancelled is a terminal status and must never leave the node stuck in running.
     *
     * Outcomes:
     * - any queued/running → running
     * - failed/stale/blocked with no active work → failed (state may be stale)
     * - some success + remainder cancelled → success
     * - all cancelled (no success) → failed
     * - all success → success
     *
     * @return array{node: string, state: string}
     */
    public static function decideStatus(
        int $total,
        int $success,
        int $failed,
        int $stale,
        int $active,
        int $blocked,
        int $cancelled = 0
    ): array {
        if ($total <= 0) {
            return ['node' => 'running', 'state' => 'queued'];
        }

        if ($active > 0) {
            return ['node' => 'running', 'state' => 'running'];
        }

        // 链式后续停在 blocked、或已有 failed/stale：整节点应结束，避免一直 running。
        if ($failed > 0 || $stale > 0 || ($blocked > 0 && ($success + $cancelled) < $total)) {
            return [
                'node' => 'failed',
                'state' => $failed > 0 ? 'failed' : ($stale > 0 ? 'stale' : 'failed'),
            ];
        }

        if ($success > 0 && ($success + $cancelled) >= $total) {
            return ['node' => 'success', 'state' => 'success'];
        }

        if ($cancelled >= $total) {
            return ['node' => 'failed', 'state' => 'failed'];
        }

        if ($success >= $total) {
            return ['node' => 'success', 'state' => 'success'];
        }

        return ['node' => 'running', 'state' => 'queued'];
    }

    /**
     * How a blocked dependent should react to its upstream terminal/active status.
     *
     * @return array{action: 'queue'|'cancel'|'fail'|'wait', error_message?: string}
     */
    public static function resolveBlockedDependent(string $upstreamStatus): array
    {
        return match ($upstreamStatus) {
            'success' => ['action' => 'queue'],
            'cancelled' => [
                'action' => 'cancel',
                'error_message' => '前序视频任务已取消',
            ],
            'failed' => [
                'action' => 'fail',
                'error_message' => '前序视频任务失败，后续镜头无法继续',
            ],
            'stale' => [
                'action' => 'fail',
                'error_message' => '前序视频任务已失效，后续镜头无法继续',
            ],
            default => ['action' => 'wait'],
        };
    }
}
