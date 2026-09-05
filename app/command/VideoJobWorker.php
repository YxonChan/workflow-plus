<?php

declare(strict_types=1);

namespace app\command;

use app\controller\SeriesController;
use app\support\VideoWorkflowProgress;
use app\model\VideoJob;
use app\support\RedisCache;
use think\App;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;
use think\facade\Log;

/**
 * 视频任务 worker。
 *
 * 关键点：runQueuedVideoJob 会同步阻塞到上游返回，单个 PHP 进程同一时刻只能真正执行 1 个 job。
 * 所以“每用户 2 路并行”必须同时满足：
 * 1) claim 时按 user_id 限流（per-user-concurrency）
 * 2) 部署多个 worker 进程（进程数 ≈ 期望全站同时 running 数）
 */
class VideoJobWorker extends Command
{
    protected function configure(): void
    {
        $this->setName('video-job:worker')
            ->setDescription('Run queued episode video generation jobs.')
            ->addOption('once', null, Option::VALUE_NONE, 'Process one queued job and exit.')
            ->addOption('sleep', null, Option::VALUE_REQUIRED, 'Idle sleep seconds.', '2')
            ->addOption('cooldown', null, Option::VALUE_REQUIRED, 'Sleep seconds after each processed job.', '3')
            // 兼容旧参数：等价于 max-global-concurrency
            ->addOption('max-concurrency', null, Option::VALUE_REQUIRED, 'Alias of max-global-concurrency.', null)
            ->addOption('per-user-concurrency', null, Option::VALUE_REQUIRED, 'Maximum running video jobs per user.', '2')
            ->addOption('max-global-concurrency', null, Option::VALUE_REQUIRED, 'Maximum running video jobs across all users (0 = unlimited).', '20')
            ->addOption('stale-minutes', null, Option::VALUE_REQUIRED, 'Requeue running jobs with no heartbeat after N minutes.', '20');
    }

    protected function execute(Input $input, Output $output): int
    {
        $once = (bool) $input->getOption('once');
        $sleep = max(1, (int) $input->getOption('sleep'));
        $cooldown = max(0, (int) $input->getOption('cooldown'));
        $perUserConcurrency = max(1, (int) $input->getOption('per-user-concurrency'));
        $maxGlobalRaw = $input->getOption('max-global-concurrency');
        $legacyMax = $input->getOption('max-concurrency');
        if ($legacyMax !== null && $legacyMax !== '' && ($maxGlobalRaw === null || $maxGlobalRaw === '' || (string) $maxGlobalRaw === '20')) {
            // 旧启动命令只传了 --max-concurrency=1 时，沿用为全站上限
            $maxGlobalConcurrency = max(0, (int) $legacyMax);
        } else {
            $maxGlobalConcurrency = max(0, (int) ($maxGlobalRaw ?? 20));
        }
        $staleMinutes = max(10, (int) $input->getOption('stale-minutes'));

        $output->writeln(sprintf(
            'VideoJobWorker started (per-user=%d, global=%s)',
            $perUserConcurrency,
            $maxGlobalConcurrency > 0 ? (string) $maxGlobalConcurrency : 'unlimited'
        ));

        do {
            try {
                $this->recoverStaleJobs($staleMinutes);
                $this->releaseUnblockedJobs();

                $job = $this->claimNextJob($perUserConcurrency, $maxGlobalConcurrency);
                if (!$job instanceof VideoJob) {
                    if ($once) {
                        $output->writeln('No queued video job.');
                        return 0;
                    }
                    sleep($sleep);
                    continue;
                }

                $jobId = (int) $job->getAttr('id');
                $userId = (int) $job->getAttr('user_id');
                $output->writeln("Running video job #{$jobId} (user={$userId})");

                /** @var App $app */
                $app = app();
                $controller = new SeriesController($app);
                $controller->runQueuedVideoJob($jobId);
                $output->writeln("Video job #{$jobId} finished.");
            } catch (\Throwable $e) {
                Log::error('[VideoJobWorker] loop failed: ' . $e->getMessage());
                $output->writeln('[VideoJobWorker] loop failed: ' . $e->getMessage());
                if ($once) {
                    return 1;
                }
                sleep($sleep);
                continue;
            }

            if (!$once && $cooldown > 0) {
                sleep($cooldown);
            }
        } while (!$once);

        return 0;
    }

    private function claimNextJob(int $perUserConcurrency, int $maxGlobalConcurrency): ?VideoJob
    {
        $redisAvailable = RedisCache::isAvailable();
        $queueLockKey = 'video_job_worker:claim_lock';
        $queueLockToken = $redisAvailable ? RedisCache::acquireLock($queueLockKey, 10) : null;
        if ($redisAvailable && $queueLockToken === null) {
            return null;
        }

        try {
            if ($maxGlobalConcurrency > 0) {
                $runningCount = (int) VideoJob::where('status', 'running')->count();
                if ($runningCount >= $maxGlobalConcurrency) {
                    return null;
                }
            }

            return Db::transaction(function () use ($perUserConcurrency, $maxGlobalConcurrency): ?VideoJob {
                if ($maxGlobalConcurrency > 0) {
                    $runningCount = (int) VideoJob::where('status', 'running')->lock(true)->count();
                    if ($runningCount >= $maxGlobalConcurrency) {
                        return null;
                    }
                }

                // 先找出“已跑满 per-user 上限”的用户，再从其余 queued 里 FIFO 取一个
                $saturatedUserIds = $this->saturatedUserIds($perUserConcurrency);
                $query = VideoJob::where('status', 'queued');
                if ($saturatedUserIds !== []) {
                    $query->whereNotIn('user_id', $saturatedUserIds);
                }

                $job = $query->order(['id' => 'asc'])->lock(true)->find();
                if (!$job instanceof VideoJob) {
                    return null;
                }

                $userId = (int) $job->getAttr('user_id');
                $userRunning = (int) VideoJob::where('status', 'running')
                    ->where('user_id', $userId)
                    ->lock(true)
                    ->count();
                if ($userRunning >= $perUserConcurrency) {
                    return null;
                }

                $job->save([
                    'status' => 'running',
                    'started_at' => date('Y-m-d H:i:s'),
                    'error_message' => '',
                ]);

                return $job;
            });
        } finally {
            if ($queueLockToken !== null) {
                RedisCache::releaseLock($queueLockKey, $queueLockToken);
            }
        }
    }

    /**
     * @return list<int>
     */
    private function saturatedUserIds(int $perUserConcurrency): array
    {
        $rows = VideoJob::where('status', 'running')
            ->field('user_id, COUNT(*) AS cnt')
            ->group('user_id')
            ->select()
            ->toArray();

        $ids = [];
        foreach ($rows as $row) {
            $uid = (int) ($row['user_id'] ?? 0);
            $cnt = (int) ($row['cnt'] ?? 0);
            if ($uid > 0 && $cnt >= $perUserConcurrency) {
                $ids[] = $uid;
            }
        }

        return $ids;
    }

    private function releaseUnblockedJobs(): void
    {
        $blockedJobs = VideoJob::where('status', 'blocked')->select();
        $touchedRunNodeIds = [];
        foreach ($blockedJobs as $job) {
            if (!$job instanceof VideoJob) {
                continue;
            }
            $dependsOn = (int) ($job->getAttr('depends_on_job_id') ?? 0);
            if ($dependsOn <= 0) {
                $job->save(['status' => 'queued', 'error_message' => '']);
                $runNodeId = (int) ($job->getAttr('workflow_run_node_id') ?? 0);
                if ($runNodeId > 0) {
                    $touchedRunNodeIds[$runNodeId] = true;
                }
                continue;
            }
            $previous = VideoJob::find($dependsOn);
            if (!$previous instanceof VideoJob) {
                continue;
            }

            $resolution = VideoWorkflowProgress::resolveBlockedDependent((string) $previous->getAttr('status'));
            $action = (string) ($resolution['action'] ?? 'wait');
            if ($action === 'queue') {
                $job->save(['status' => 'queued', 'error_message' => '']);
            } elseif ($action === 'cancel') {
                $job->save([
                    'status' => 'cancelled',
                    'error_message' => (string) ($resolution['error_message'] ?? '前序视频任务已取消'),
                    'finished_at' => date('Y-m-d H:i:s'),
                ]);
            } elseif ($action === 'fail') {
                $job->save([
                    'status' => 'failed',
                    'error_message' => (string) ($resolution['error_message'] ?? '前序视频任务失败，后续镜头无法继续'),
                    'finished_at' => date('Y-m-d H:i:s'),
                ]);
            } else {
                continue;
            }

            $runNodeId = (int) ($job->getAttr('workflow_run_node_id') ?? 0);
            if ($runNodeId > 0) {
                $touchedRunNodeIds[$runNodeId] = true;
            }
        }

        if ($touchedRunNodeIds === []) {
            return;
        }

        /** @var App $app */
        $app = app();
        $controller = new SeriesController($app);
        foreach (array_keys($touchedRunNodeIds) as $runNodeId) {
            try {
                $controller->syncVideoWorkflowProgress((int) $runNodeId);
            } catch (\Throwable $e) {
                Log::error('[VideoJobWorker] refresh progress failed for run_node #' . $runNodeId . ': ' . $e->getMessage());
            }
        }
    }

    private function recoverStaleJobs(int $staleMinutes): void
    {
        $expiredAt = date('Y-m-d H:i:s', time() - ($staleMinutes * 60));

        VideoJob::where('status', 'running')
            ->where('update_time', '<', $expiredAt)
            ->where('attempts', '<', 3)
            ->update([
                'status' => 'queued',
                'error_message' => '视频生成进程异常中断，已自动重新排队。',
                'started_at' => null,
            ]);
    }
}
