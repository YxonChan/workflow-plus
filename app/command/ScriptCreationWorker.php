<?php

declare(strict_types=1);

namespace app\command;

use app\support\ScriptCreationService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Log;

class ScriptCreationWorker extends Command
{
    protected function configure(): void
    {
        $this->setName('script-creation:worker')
            ->setDescription('Run queued AI-driven script creation steps.')
            ->addOption('once', null, Option::VALUE_NONE, 'Process one queued step and exit.')
            ->addOption('sleep', null, Option::VALUE_REQUIRED, 'Idle sleep seconds.', '2')
            ->addOption('stale-minutes', null, Option::VALUE_REQUIRED, 'Fail running steps with no update after N minutes.', '30');
    }

    protected function execute(Input $input, Output $output): int
    {
        $once = (bool) $input->getOption('once');
        $sleep = max(1, (int) $input->getOption('sleep'));
        $staleMinutes = max(5, (int) $input->getOption('stale-minutes'));
        ScriptCreationService::ensureSchema();

        do {
            try {
                $recovered = ScriptCreationService::recoverStaleSteps($staleMinutes);
                if ($recovered > 0) {
                    $output->writeln("Recovered {$recovered} stale script creation step(s).");
                }
                $processed = ScriptCreationService::runNextQueuedStep();
                if (!$processed) {
                    if ($once) {
                        $output->writeln('No queued script creation step.');
                        return 0;
                    }
                    sleep($sleep);
                    continue;
                }
                $output->writeln('Processed one script creation step.');
            } catch (\Throwable $e) {
                Log::error('[ScriptCreationWorker] loop failed: ' . $e->getMessage());
                $output->writeln('[ScriptCreationWorker] loop failed: ' . $e->getMessage());
                if ($once) {
                    return 1;
                }
                sleep($sleep);
            }
        } while (!$once);

        return 0;
    }
}
