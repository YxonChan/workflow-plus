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

class EpisodeNodeWorker extends Command
{
    protected function configure(): void
    {
        $this->setName('episode-node:worker')
            ->setDescription('Run queued manual episode node jobs.')
            ->addOption('once', null, Option::VALUE_NONE, 'Process one queued node job and exit.')
            ->addOption('sleep', null, Option::VALUE_REQUIRED, 'Idle sleep seconds.', '2')
            ->addOption('stale-minutes', null, Option::VALUE_REQUIRED, 'Requeue stale node runs after N minutes.', '15');
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
                        $output->writeln('No queued episode node run.');
                        return 0;
                    }
                    sleep($sleep);
                    continue;
                }

                $runId = (int) $run->getAttr('id');
                $output->writeln("Running episode node run #{$runId}");

                /** @var App $app */
                $app = app();
                $controller = new SeriesController($app);
                $controller->runQueuedEpisodeNodeRun($runId);
                $output->writeln("Episode node run #{$runId} finished.");
            } catch (\Throwable $e) {
                Log::error('[EpisodeNodeWorker] loop failed: ' . $e->getMessage());
                $output->writeln('[EpisodeNodeWorker] loop failed: ' . $e->getMessage());
                if ($once) {
                    return 1;
                }
                sleep($sleep);
            }
        } while (!$once);

        return 0;
    }

    private function claimNextRun(): ?WorkflowRun
    {
        $redisAvailable = RedisCache::isAvailable();
        $queueLockKey = 'episode_node_worker:claim_lock';
        $queueLockToken = $redisAvailable ? RedisCache::acquireLock($queueLockKey, 10) : null;
        if ($redisAvailable && $queueLockToken === null) {
            return null;
        }

        try {
            return Db::transaction(function (): ?WorkflowRun {
                $run = WorkflowRun::where('status', 'queued')
                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.scope')) = 'episode'")
                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.execution_mode')) = 'node'")
                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.target_node_id')) <> ''")
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

        $staleIds = WorkflowRun::where('status', 'running')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.scope')) = 'episode'")
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.execution_mode')) = 'node'")
            ->where('update_time', '<', $expiredAt)
            ->column('id');

        WorkflowRun::where('status', 'running')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.scope')) = 'episode'")
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.execution_mode')) = 'node'")
            ->where('update_time', '<', $expiredAt)
            ->update([
                'status' => 'queued',
                'error_message' => '剧集节点执行进程异常中断，已自动重新排队。',
                'finished_at' => null,
            ]);

        foreach ($staleIds as $id) {
            RedisCache::bumpVersion('workflow_run:' . (int) $id);
        }
    }
}
