<?php

// +----------------------------------------------------------------------
// | 控制台配置
// +----------------------------------------------------------------------
return [
    // 指令定义
    'commands' => [
        'workflow:worker' => \app\command\WorkflowWorker::class,
        'episode-workflow:worker' => \app\command\EpisodeWorkflowWorker::class,
        'episode-node:worker' => \app\command\EpisodeNodeWorker::class,
        'agent-task:worker' => \app\command\AgentTaskWorker::class,
        'asset-image:worker' => \app\command\AssetImageWorker::class,
        'video-job:worker' => \app\command\VideoJobWorker::class,
        'quick-create:worker' => \app\command\QuickCreateWorker::class,
        'quick-create:face-worker' => \app\command\QuickCreateFaceVerificationWorker::class,
        'script-creation:worker' => \app\command\ScriptCreationWorker::class,
        'migrate:ai-debug' => \app\command\MigrateAiDebug::class,
        'series:backfill-plots' => \app\command\BackfillEpisodePlots::class,
    ],
];
