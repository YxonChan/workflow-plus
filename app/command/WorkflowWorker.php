<?php

declare(strict_types=1);

namespace app\command;

use app\controller\SeriesController;
use app\model\WorkflowRun;
use app\support\RedisCache;
use think\App;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;
use think\facade\Log;

class WorkflowWorker extends Command
{
    protected function configure(): void
    {
        $this->setName('workflow:worker')
            ->setDescription('Run queued series workflow jobs.')
            ->addOption('once', null, Option::VALUE_NONE, 'Process one queued job and exit.')
            ->addOption('sleep', null, Option::VALUE_REQUIRED, 'Idle sleep seconds.', '2')
            ->addOption('stale-minutes', null, Option::VALUE_REQUIRED, 'Requeue running jobs with no update after N minutes.', '30');
    }

    protected function execute(Input $input, Output $output): int
    {
        $once = (bool) $input->getOption('once');
        $sleep = max(1, (int) $input->getOption('sleep'));
        $staleMinutes = max(5, (int) $input->getOption('stale-minutes'));

        do {
            try {
                $this->recoverStaleRuns($staleMinutes);

                $run = $this->claimNextRun();
                if (!$run instanceof WorkflowRun) {
                    if ($once) {
                        $output->writeln('No queued workflow run.');
                        return 0;
                    }
                    sleep($sleep);
                    continue;
                }

                $runId = (int) $run->getAttr('id');
                $output->writeln("Running workflow run #{$runId}");

                /** @var App $app */
                $app = app();
                $controller = new SeriesController($app);
                $controller->runQueuedWorkflowRun($runId);
                $output->writeln("Workflow run #{$runId} finished.");
            } catch (\Throwable $e) {
                Log::error('[WorkflowWorker] loop failed: ' . $e->getMessage());
                $output->writeln('[WorkflowWorker] loop failed: ' . $e->getMessage());
                if ($once) {
                    return 1;
                }
                sleep($sleep);
                continue;
            }
        } while (!$once);

        return 0;
    }

    /**
     * 抢占一个 queued 任务。
     * 多个 worker 进程同时运行时，只有成功把 queued 更新成 running 的进程会继续执行。
     */
    private function claimNextRun(): ?WorkflowRun
    {
        $redisAvailable = RedisCache::isAvailable();
        $queueLockKey = 'workflow_worker:claim_lock';
        $queueLockToken = $redisAvailable ? RedisCache::acquireLock($queueLockKey, 10) : null;
        if ($redisAvailable && $queueLockToken === null) {
            return null;
        }

        try {
            return Db::transaction(function (): ?WorkflowRun {
            $run = WorkflowRun::where('status', 'queued')
                ->whereRaw("(JSON_EXTRACT(payload_json, '$.scope') IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.scope')) <> 'episode')")
                ->order(['id' => 'asc'])
                ->lock(true)
                ->find();
            if (!$run instanceof WorkflowRun) {
                return null;
            }

            $run->save([
                'status' => 'running',
                'progress' => 1,
                'started_at' => date('Y-m-d H:i:s'),
                'error_message' => '',
            ]);
            RedisCache::bumpVersion('workflow_run:' . (int) $run->getAttr('id'));

            return $run;
        });
        } finally {
            if ($queueLockToken !== null) {
                RedisCache::releaseLock($queueLockKey, $queueLockToken);
            }
        }
    }

    /**
     * 恢复异常中断的任务。
     * worker 进程被系统杀掉、容器重启或命令超时后，任务可能长期停在 running。
     * 这里仅处理超过阈值未更新的任务，并退回 queued 让后续 worker 重新执行。
     */
    private function recoverStaleRuns(int $staleMinutes): void
    {
        $expiredAt = date('Y-m-d H:i:s', time() - ($staleMinutes * 60));

        $staleIds = WorkflowRun::where('status', 'running')
            ->whereRaw("(JSON_EXTRACT(payload_json, '$.scope') IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.scope')) <> 'episode')")
            ->where('update_time', '<', $expiredAt)
            ->column('id');

        WorkflowRun::where('status', 'running')
            ->whereRaw("(JSON_EXTRACT(payload_json, '$.scope') IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.scope')) <> 'episode')")
            ->where('update_time', '<', $expiredAt)
            ->update([
                'status' => 'queued',
                'progress' => 0,
                'current_node_label' => '',
                'error_message' => "任务执行进程异常中断，已自动重新排队。",
                'started_at' => null,
            ]);

        foreach ($staleIds as $id) {
            RedisCache::bumpVersion('workflow_run:' . (int) $id);
        }
    }
}
