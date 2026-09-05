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

class EpisodeWorkflowWorker extends Command
{
    protected function configure(): void
    {
        $this->setName('episode-workflow:worker')
            ->setDescription('Run queued episode workflow jobs.')
            ->addOption('once', null, Option::VALUE_NONE, 'Process one queued job and exit.')
            ->addOption('sleep', null, Option::VALUE_REQUIRED, 'Idle sleep seconds.', '2')
            ->addOption('stale-minutes', null, Option::VALUE_REQUIRED, 'Requeue stale episode runs after N minutes.', '30');
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
                        $output->writeln('No queued episode workflow run.');
                        return 0;
                    }
                    sleep($sleep);
                    continue;
                }

                $runId = (int) $run->getAttr('id');
                $output->writeln("Running episode workflow run #{$runId}");

                /** @var App $app */
                $app = app();
                $controller = new SeriesController($app);
                $controller->runQueuedEpisodeWorkflowRun($runId);
                $output->writeln("Episode workflow run #{$runId} finished.");
            } catch (\Throwable $e) {
                Log::error('[EpisodeWorkflowWorker] loop failed: ' . $e->getMessage());
                $output->writeln('[EpisodeWorkflowWorker] loop failed: ' . $e->getMessage());
                if ($once) {
                    return 1;
                }
                sleep($sleep);
                continue;
            }
        } while (!$once);

        return 0;
    }

    private function claimNextRun(): ?WorkflowRun
    {
        $redisAvailable = RedisCache::isAvailable();
        $queueLockKey = 'episode_workflow_worker:claim_lock';
        $queueLockToken = $redisAvailable ? RedisCache::acquireLock($queueLockKey, 10) : null;
        if ($redisAvailable && $queueLockToken === null) {
            return null;
        }

        try {
            return Db::transaction(function (): ?WorkflowRun {
                $run = WorkflowRun::where('status', 'queued')
                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.scope')) = 'episode'")
                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.execution_mode')) = 'auto'")
                    ->order(['id' => 'asc'])
                    ->lock(true)
                    ->find();
                if (!$run instanceof WorkflowRun) {
                    return null;
                }

                $run->save([
                    'status' => 'running',
                    'progress' => max(1, (int) $run->getAttr('progress')),
                    'started_at' => $run->getAttr('started_at') ?: date('Y-m-d H:i:s'),
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

    private function recoverStaleRuns(int $staleMinutes): void
    {
        $expiredAt = date('Y-m-d H:i:s', time() - ($staleMinutes * 60));

        // 整集 auto 已停用：过期 running/waiting_async 直接 retire，不再重新排队推进。
        $staleIds = WorkflowRun::whereIn('status', ['running', 'waiting_async'])
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.scope')) = 'episode'")
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.execution_mode')) = 'auto'")
            ->where('update_time', '<', $expiredAt)
            ->column('id');
        if ($staleIds === []) {
            return;
        }

        /** @var App $app */
        $app = app();
        $controller = new SeriesController($app);
        foreach ($staleIds as $id) {
            try {
                $controller->runQueuedEpisodeWorkflowRun((int) $id);
            } catch (\Throwable $e) {
                Log::error('[EpisodeWorkflowWorker] retire stale auto run #' . (int) $id . ': ' . $e->getMessage());
            }
        }
    }
}
