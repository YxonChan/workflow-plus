<?php

declare(strict_types=1);

namespace app\support;

use app\model\WorkflowRun;
use app\model\Workflow;

class WorkflowRuntime
{
    private const SERIES_LOCK_PREFIX = 'series_workflow_lock:';

    /**
     * 未真正完成的剧本任务都会锁住剧本编辑。
     * failed 也保留锁，让用户只能继续执行或删除重来。
     */
    public static function isBlockingStatus(string $status): bool
    {
        return in_array($status, ['queued', 'running', 'failed'], true);
    }

    public static function rememberSeriesRun(WorkflowRun $run, int $ttl = 86400): void
    {
        $seriesId = (int) $run->getAttr('series_id');
        $runId = (int) $run->getAttr('id');
        if ($seriesId <= 0 || $runId <= 0) {
            return;
        }

        $status = (string) $run->getAttr('status');
        if (!self::isBlockingStatus($status)) {
            self::clearSeriesRun($seriesId, $runId);
            return;
        }

        RedisCache::set(self::seriesKey($seriesId), [
            'run_id' => $runId,
            'series_id' => $seriesId,
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ], $ttl);
    }

    public static function findBlockingSeriesRun(int $seriesId): ?WorkflowRun
    {
        if ($seriesId <= 0) {
            return null;
        }

        $cached = RedisCache::get(self::seriesKey($seriesId), []);
        $runId = is_array($cached) ? (int) ($cached['run_id'] ?? 0) : 0;
        if ($runId > 0) {
            $run = WorkflowRun::find($runId);
            if (
                $run instanceof WorkflowRun
                && self::isSeriesScopeRun($run)
                && self::isBlockingStatus((string) $run->getAttr('status'))
            ) {
                return $run;
            }
            self::clearSeriesRun($seriesId, $runId);
        }

        $latestSeriesRun = self::latestSeriesScopeRun($seriesId);
        if ($latestSeriesRun instanceof WorkflowRun && self::isBlockingStatus((string) $latestSeriesRun->getAttr('status'))) {
            self::rememberSeriesRun($latestSeriesRun);
            return $latestSeriesRun;
        }

        return null;
    }

    private static function latestSeriesScopeRun(int $seriesId): ?WorkflowRun
    {
        $runs = WorkflowRun::where('series_id', $seriesId)
            ->order('id', 'desc')
            ->limit(20)
            ->select();

        foreach ($runs as $run) {
            if ($run instanceof WorkflowRun && self::isSeriesScopeRun($run)) {
                return $run;
            }
        }

        return null;
    }

    private static function isSeriesScopeRun(WorkflowRun $run): bool
    {
        $payload = $run->getAttr('payload_json');
        if (is_array($payload) && (string) ($payload['scope'] ?? '') === 'episode') {
            return false;
        }

        $workflowId = (int) $run->getAttr('workflow_id');
        if ($workflowId <= 0) {
            return false;
        }

        $workflow = Workflow::find($workflowId);
        return $workflow instanceof Workflow && (string) $workflow->getAttr('scope') === 'series';
    }

    public static function clearSeriesRun(int $seriesId, ?int $runId = null): void
    {
        if ($seriesId <= 0) {
            return;
        }

        $cached = RedisCache::get(self::seriesKey($seriesId), []);
        $cachedRunId = is_array($cached) ? (int) ($cached['run_id'] ?? 0) : 0;
        if ($runId === null || $cachedRunId === 0 || $cachedRunId === $runId) {
            RedisCache::delete(self::seriesKey($seriesId));
        }
    }

    private static function seriesKey(int $seriesId): string
    {
        return self::SERIES_LOCK_PREFIX . $seriesId;
    }
}
