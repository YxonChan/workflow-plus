<?php

declare(strict_types=1);

namespace app\command;

use app\model\AgentTask;
use app\support\AgentTaskService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

class AgentTaskWorker extends Command
{
    protected function configure(): void
    {
        $this->setName('agent-task:worker')
            ->setDescription('Refresh and advance Agent task state machine.')
            ->addOption('once', null, Option::VALUE_NONE, 'Process one pass and exit.')
            ->addOption('sleep', null, Option::VALUE_REQUIRED, 'Idle sleep seconds.', '3');
    }

    protected function execute(Input $input, Output $output): int
    {
        $once = (bool) $input->getOption('once');
        $sleep = max(1, (int) $input->getOption('sleep'));
        $service = new AgentTaskService();
        $service->ensureSchema();

        do {
            $tasks = AgentTask::whereNotIn('status', [
                AgentTaskService::STATUS_COMPLETED,
                AgentTaskService::STATUS_FAILED,
                AgentTaskService::STATUS_CANCELLED,
            ])
                ->order('id', 'asc')
                ->limit(20)
                ->select();

            $count = 0;
            foreach ($tasks as $task) {
                if (!$task instanceof AgentTask) {
                    continue;
                }
                $service->refresh($task, app());
                $count++;
            }

            $output->writeln("Refreshed {$count} agent task(s).");
            if ($once) {
                return 0;
            }
            sleep($sleep);
        } while (true);
    }
}
