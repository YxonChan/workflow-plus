<?php

declare(strict_types=1);

namespace app\command;

use app\controller\AssetController;
use app\model\AssetImageJob;
use app\support\ImageJobStatus;
use app\support\ImageProviderTaskState;
use app\support\AssetLookService;
use app\support\ToapisPrivateAvatarService;
use app\support\RedisCache;
use think\App;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;
use think\facade\Log;

class AssetImageWorker extends Command
{
    protected function configure(): void
    {
        $this->setName('asset-image:worker')
            ->setDescription('Run queued asset image generation jobs.')
            ->addOption('once', null, Option::VALUE_NONE, 'Process one queued job and exit.')
            ->addOption('sleep', null, Option::VALUE_REQUIRED, 'Idle sleep seconds.', '1')
            ->addOption('cooldown', null, Option::VALUE_REQUIRED, 'Sleep seconds after each processed job.', '0')
            ->addOption('max-concurrency', null, Option::VALUE_REQUIRED, 'Maximum in-flight provider image tasks (capped by ASSET_IMAGE_MAX_CONCURRENCY).', '2')
            ->addOption('stale-minutes', null, Option::VALUE_REQUIRED, 'Recover running jobs with no update after N minutes.', '3')
            ->addOption('wait-timeout-minutes', null, Option::VALUE_REQUIRED, 'Fail waiting provider tasks after N minutes.', '20')
            ->addOption('poll-interval', null, Option::VALUE_REQUIRED, 'Seconds before a waiting job can be polled again.', '3');
    }

    protected function execute(Input $input, Output $output): int
    {
        $once = (bool) $input->getOption('once');
        $sleep = max(1, (int) $input->getOption('sleep'));
        $cooldown = max(0, (int) $input->getOption('cooldown'));
        $maxConcurrency = $this->resolveMaxConcurrency((int) $input->getOption('max-concurrency'));
        $staleMinutes = max(1, (int) $input->getOption('stale-minutes'));
        $waitTimeoutMinutes = max(5, (int) $input->getOption('wait-timeout-minutes'));
        $pollInterval = max(1, (int) $input->getOption('poll-interval'));

        // Worker 可能在没有任何 HTTP 请求的情况下先于应用启动；此处主动补齐队列表结构。
        (new AssetLookService())->ensureSchema();

        do {
            try {
                $this->recoverStaleRunningJobs($staleMinutes);
                $this->expireWaitingJobs($waitTimeoutMinutes);
                $this->cancelRetiredLookJobs();

                $job = $this->claimNextJob($maxConcurrency, $pollInterval);
                if (!$job instanceof AssetImageJob) {
                    if ($once) {
                        $output->writeln('No queued asset image job.');
                        return 0;
                    }
                    sleep($sleep);
                    continue;
                }

                $jobId = (int) $job->getAttr('id');
                $output->writeln("Running asset image job #{$jobId}");

                /** @var App $app */
                $app = app();
                $controller = new AssetController($app);
                $controller->runQueuedAssetImageJob($jobId);
                $output->writeln("Asset image job #{$jobId} finished.");
            } catch (\Throwable $e) {
                Log::error('[AssetImageWorker] loop failed: ' . $e->getMessage());
                $output->writeln('[AssetImageWorker] loop failed: ' . $e->getMessage());
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

    private function claimNextJob(int $maxConcurrency, int $pollInterval): ?AssetImageJob
    {
        $redisAvailable = RedisCache::isAvailable();
        $queueLockKey = 'asset_image_worker:claim_lock';
        $queueLockToken = $redisAvailable ? RedisCache::acquireLock($queueLockKey, 10) : null;
        if ($redisAvailable && $queueLockToken === null) {
            return null;
        }

        try {
            return Db::transaction(function () use ($maxConcurrency, $pollInterval): ?AssetImageJob {
                $pollDueAt = date('Y-m-d H:i:s', time() - $pollInterval);
                $waiting = AssetImageJob::where('status', ImageJobStatus::WAITING)
                    ->where('update_time', '<=', $pollDueAt)
                    ->order(['update_time' => 'asc', 'id' => 'asc'])
                    ->lock(true)
                    ->find();
                if ($waiting instanceof AssetImageJob) {
                    $waiting->save([
                        'status' => ImageJobStatus::RUNNING,
                    ]);

                    return $waiting;
                }

                $inFlight = (int) AssetImageJob::whereIn('status', ImageJobStatus::inFlight())->lock(true)->count();
                if ($inFlight >= $maxConcurrency) {
                    return null;
                }

                $queued = AssetImageJob::where('status', ImageJobStatus::QUEUED)
                    ->whereRaw('(`retry_after` IS NULL OR `retry_after` <= ?)', [date('Y-m-d H:i:s')])
                    ->order(['id' => 'asc'])
                    ->lock(true)
                    ->find();
                if (!$queued instanceof AssetImageJob) {
                    return null;
                }

                $providerTaskId = ImageProviderTaskState::extractTaskId((string) $queued->getAttr('error_message'));
                $queued->save([
                    'status' => ImageJobStatus::RUNNING,
                    'started_at' => (string) ($queued->getAttr('started_at') ?: date('Y-m-d H:i:s')),
                    'retry_after' => null,
                    'error_message' => ImageProviderTaskState::pendingMessage($providerTaskId),
                ]);

                return $queued;
            });
        } finally {
            if ($queueLockToken !== null) {
                RedisCache::releaseLock($queueLockKey, $queueLockToken);
            }
        }
    }

    /**
     * running 只应持续数秒（提交或一次短查询）。超时视为进程被杀。
     */
    private function recoverStaleRunningJobs(int $staleMinutes): void
    {
        $expiredAt = date('Y-m-d H:i:s', time() - ($staleMinutes * 60));
        $jobs = AssetImageJob::where('status', ImageJobStatus::RUNNING)
            ->where('update_time', '<', $expiredAt)
            ->select();

        foreach ($jobs as $job) {
            if (!$job instanceof AssetImageJob) {
                continue;
            }

            $providerTaskId = ImageProviderTaskState::extractTaskId((string) $job->getAttr('error_message'));
            if ($providerTaskId !== '') {
                $job->save([
                    'status' => ImageJobStatus::WAITING,
                    'retry_after' => null,
                    'error_message' => ImageProviderTaskState::pendingMessage($providerTaskId),
                ]);
                continue;
            }

            if ((int) $job->getAttr('attempts') < 3) {
                $job->save([
                    'status' => ImageJobStatus::QUEUED,
                    'retry_after' => null,
                    'error_message' => '图片生成进程异常中断，已自动重新排队。',
                    'started_at' => null,
                    'finished_at' => null,
                ]);
                continue;
            }

            $job->save([
                'status' => ImageJobStatus::FAILED,
                'retry_after' => null,
                'error_message' => '图片生成多次中断，已停止重试。',
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    private function cancelRetiredLookJobs(): void
    {
        $now = date('Y-m-d H:i:s');
        $holding = AssetImageJob::where('status', ImageJobStatus::HOLDING)->select();
        foreach ($holding as $job) {
            if (!$job instanceof AssetImageJob) {
                continue;
            }
            $job->save([
                'status' => ImageJobStatus::CANCELLED,
                'error_message' => '彩铅等待已停用，请重新生成造型',
                'finished_at' => $now,
            ]);
        }

        $pencil = AssetImageJob::whereIn('status', ImageJobStatus::active())
            ->whereLike('description', '%' . ToapisPrivateAvatarService::LEGACY_PENCIL_JOB_MARKER . '%')
            ->select();
        foreach ($pencil as $job) {
            if (!$job instanceof AssetImageJob) {
                continue;
            }
            $job->save([
                'status' => ImageJobStatus::CANCELLED,
                'error_message' => '彩铅参考已停用',
                'finished_at' => $now,
            ]);
        }
    }

    private function expireWaitingJobs(int $waitTimeoutMinutes): void
    {
        $deadline = time() - ($waitTimeoutMinutes * 60);
        $jobs = AssetImageJob::where('status', ImageJobStatus::WAITING)->select();

        foreach ($jobs as $job) {
            if (!$job instanceof AssetImageJob) {
                continue;
            }

            $started = trim((string) ($job->getAttr('started_at') ?: $job->getAttr('create_time')));
            $startedTs = $started !== '' ? strtotime($started) : false;
            if ($startedTs === false || $startedTs > $deadline) {
                continue;
            }

            $providerTaskId = ImageProviderTaskState::extractTaskId((string) $job->getAttr('error_message'));
            if ($providerTaskId === '') {
                $job->save([
                    'status' => ImageJobStatus::QUEUED,
                    'retry_after' => null,
                    'error_message' => '图片生成等待状态丢失任务号，已重新排队。',
                    'started_at' => null,
                    'finished_at' => null,
                ]);
                continue;
            }
            $job->save([
                'status' => ImageJobStatus::FAILED,
                'retry_after' => null,
                'error_message' => '图片生成超时：上游任务超过 ' . $waitTimeoutMinutes . ' 分钟未完成：' . $providerTaskId,
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * 命令行参数是单个进程的上限；环境变量是所有图片 Worker 共用的保守闸门。
     * 这样旧 unit 即使仍传入较大的参数，也不会把腾讯云并发推过账号上限。
     */
    private function resolveMaxConcurrency(int $configured): int
    {
        $configured = max(1, $configured);
        $globalLimit = (int) env('ASSET_IMAGE_MAX_CONCURRENCY', 2);
        if ($globalLimit < 1) {
            $globalLimit = 2;
        }

        return max(1, min($configured, $globalLimit));
    }
}
