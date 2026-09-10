<?php

declare(strict_types=1);

namespace app\command;

use app\controller\QuickCreateController;
use app\model\QuickCreateFaceVerification;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;
use think\facade\Log;

class QuickCreateFaceVerificationWorker extends Command
{
    protected function configure(): void
    {
        $this->setName('quick-create:face-worker')
            ->setDescription('Run queued quick-create face verification tasks.')
            ->addOption('once', null, Option::VALUE_NONE, 'Process one queued task and exit.')
            ->addOption('sleep', null, Option::VALUE_REQUIRED, 'Idle sleep seconds.', '2')
            ->addOption('stale-minutes', null, Option::VALUE_REQUIRED, 'Recover running tasks with no heartbeat after N minutes.', '20');
    }

    protected function execute(Input $input, Output $output): int
    {
        $once = (bool) $input->getOption('once');
        $sleep = max(1, (int) $input->getOption('sleep'));
        $staleMinutes = max(5, (int) $input->getOption('stale-minutes'));
        do {
            try {
                $this->recoverStaleTasks($staleMinutes);
                $task = Db::transaction(function (): ?QuickCreateFaceVerification {
                    $row = QuickCreateFaceVerification::where('status', 'queued')->order(['id' => 'asc'])->lock(true)->find();
                    if (!$row instanceof QuickCreateFaceVerification) return null;
                    $row->save(['status' => 'running', 'attempts' => (int) $row->getAttr('attempts') + 1, 'started_at' => date('Y-m-d H:i:s')]);
                    return $row;
                });
                if ($task instanceof QuickCreateFaceVerification) {
                    $id = (int) $task->getAttr('id');
                    (new QuickCreateController(app()))->runQueuedFaceVerification($id);
                    $output->writeln("Face verification #{$id} finished.");
                    if ($once) return 0;
                    continue;
                }
                if ($once) return 0;
                sleep($sleep);
            } catch (\Throwable $e) {
                Log::error('[QuickCreateFaceVerificationWorker] loop failed: ' . $e->getMessage());
                if ($once) return 1;
                sleep($sleep);
            }
        } while (!$once);
        return 0;
    }

    private function recoverStaleTasks(int $staleMinutes): void
    {
        $expiredAt = date('Y-m-d H:i:s', time() - ($staleMinutes * 60));
        QuickCreateFaceVerification::where('status', 'running')
            ->where('update_time', '<', $expiredAt)
            ->where('attempts', '<', 3)
            ->update([
                'status' => 'queued',
                'error_message' => '人脸检测进程异常中断，已自动重新排队。',
                'started_at' => null,
                'finished_at' => null,
            ]);
        QuickCreateFaceVerification::where('status', 'running')
            ->where('update_time', '<', $expiredAt)
            ->where('attempts', '>=', 3)
            ->update([
                'status' => 'failed',
                'error_message' => '人脸检测进程无心跳超过 ' . $staleMinutes . ' 分钟，请重试。',
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
    }
}
