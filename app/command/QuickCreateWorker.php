<?php

declare(strict_types=1);

namespace app\command;

use app\controller\QuickCreateController;
use app\model\QuickCreateMessage;
use app\support\ImageProviderTaskState;
use app\support\RedisCache;
use think\App;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;
use think\facade\Log;

class QuickCreateWorker extends Command
{
    protected function configure(): void
    {
        $this->setName('quick-create:worker')
            ->setDescription('Run queued quick-create (chat generation) messages.')
            ->addOption('once', null, Option::VALUE_NONE, 'Process one queued message and exit.')
            ->addOption('sleep', null, Option::VALUE_REQUIRED, 'Idle sleep seconds.', '2')
            ->addOption('cooldown', null, Option::VALUE_REQUIRED, 'Sleep seconds after each processed message.', '3')
            ->addOption('max-concurrency', null, Option::VALUE_REQUIRED, 'Maximum running quick-create messages.', '2')
            ->addOption('stale-minutes', null, Option::VALUE_REQUIRED, 'Recover running messages with no heartbeat after N minutes.', '20')
            ->addOption('pending-timeout-minutes', null, Option::VALUE_REQUIRED, 'Fail image provider tasks waiting after N minutes.', '20');
    }

    protected function execute(Input $input, Output $output): int
    {
        $once = (bool) $input->getOption('once');
        $sleep = max(1, (int) $input->getOption('sleep'));
        $cooldown = max(0, (int) $input->getOption('cooldown'));
        $maxConcurrency = max(1, (int) $input->getOption('max-concurrency'));
        $staleMinutes = max(10, (int) $input->getOption('stale-minutes'));
        $pendingTimeoutMinutes = max(5, (int) $input->getOption('pending-timeout-minutes'));

        do {
            try {
                $this->recoverStaleMessages($staleMinutes);
                $this->expirePendingMessages($pendingTimeoutMinutes);

                $message = $this->claimNextMessage($maxConcurrency);
                if (!$message instanceof QuickCreateMessage) {
                    if ($once) {
                        $output->writeln('No queued quick-create message.');
                        return 0;
                    }
                    sleep($sleep);
                    continue;
                }

                $messageId = (int) $message->getAttr('id');
                $output->writeln("Running quick-create message #{$messageId}");

                /** @var App $app */
                $app = app();
                $controller = new QuickCreateController($app);
                $controller->runQueuedMessage($messageId);
                $output->writeln("Quick-create message #{$messageId} finished.");
            } catch (\Throwable $e) {
                Log::error('[QuickCreateWorker] loop failed: ' . $e->getMessage());
                $output->writeln('[QuickCreateWorker] loop failed: ' . $e->getMessage());
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

    private function claimNextMessage(int $maxConcurrency): ?QuickCreateMessage
    {
        $redisAvailable = RedisCache::isAvailable();
        $queueLockKey = 'quick_create_worker:claim_lock';
        $queueLockToken = $redisAvailable ? RedisCache::acquireLock($queueLockKey, 10) : null;
        if ($redisAvailable && $queueLockToken === null) {
            return null;
        }

        try {
            $runningCount = QuickCreateMessage::where('status', 'running')->count();
            if ((int) $runningCount >= $maxConcurrency) {
                return null;
            }

            return Db::transaction(function (): ?QuickCreateMessage {
                $message = QuickCreateMessage::where('status', 'queued')
                    ->order(['update_time' => 'asc', 'id' => 'asc'])
                    ->lock(true)
                    ->find();
                if (!$message instanceof QuickCreateMessage) {
                    return null;
                }

                $providerTaskId = ImageProviderTaskState::extractTaskId((string) $message->getAttr('error_message'));
                $attempts = (int) $message->getAttr('attempts');
                if ($providerTaskId === '') {
                    $attempts++;
                }

                $message->save([
                    'status' => 'running',
                    'started_at' => (string) ($message->getAttr('started_at') ?: date('Y-m-d H:i:s')),
                    'attempts' => $attempts,
                    'error_message' => ImageProviderTaskState::pendingMessage($providerTaskId),
                ]);

                return $message;
            });
        } finally {
            if ($queueLockToken !== null) {
                RedisCache::releaseLock($queueLockKey, $queueLockToken);
            }
        }
    }

    private function recoverStaleMessages(int $staleMinutes): void
    {
        $expiredAt = date('Y-m-d H:i:s', time() - ($staleMinutes * 60));

        // 视频生成无法从 quick-create_messages 中可靠恢复上游任务号；重排队会造成重复扣费/重复出片。
        // 因此视频任务无心跳超过阈值直接失败，图片任务仍保留原有的可恢复逻辑。
        QuickCreateMessage::where('status', 'running')
            ->where('mode', 'video')
            ->where('update_time', '<', $expiredAt)
            ->update([
                'status' => 'failed',
                'error_message' => '视频生成进程无心跳超过 ' . $staleMinutes . ' 分钟，已终止，请重新提交。',
                'finished_at' => date('Y-m-d H:i:s'),
            ]);

        QuickCreateMessage::where('status', 'running')
            ->where('mode', '<>', 'video')
            ->where('update_time', '<', $expiredAt)
            ->where('attempts', '<', 3)
            ->update([
                'status' => 'queued',
                'error_message' => '生成进程异常中断，已自动重新排队。',
                'started_at' => null,
            ]);

        QuickCreateMessage::where('status', 'running')
            ->where('update_time', '<', $expiredAt)
            ->where('attempts', '>=', 3)
            ->update([
                'status' => 'failed',
                'error_message' => '多次执行失败，已停止重试，请调整描述后重新发送。',
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
    }

    private function expirePendingMessages(int $waitTimeoutMinutes): void
    {
        $deadline = time() - (max(10, $waitTimeoutMinutes) * 60);
        $rows = QuickCreateMessage::where('status', 'queued')->select();
        foreach ($rows as $message) {
            if (!$message instanceof QuickCreateMessage) {
                continue;
            }
            $taskId = ImageProviderTaskState::extractTaskId((string) $message->getAttr('error_message'));
            if ($taskId === '') {
                continue;
            }
            $started = trim((string) ($message->getAttr('started_at') ?: $message->getAttr('create_time')));
            $startedTs = $started !== '' ? strtotime($started) : false;
            if ($startedTs === false || $startedTs > $deadline) {
                continue;
            }
            $message->save([
                'status' => 'failed',
                'error_message' => '图片生成超时：上游任务超过 ' . max(10, $waitTimeoutMinutes) . ' 分钟未完成：' . $taskId,
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }
}
