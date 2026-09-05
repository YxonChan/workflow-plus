<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use app\model\Series;
use app\support\WorkerAgentService;
use think\App;

$tests = 0;
$service = new WorkerAgentService();

$personasMethod = new ReflectionMethod($service, 'personas');
$personasMethod->setAccessible(true);
$personas = $personasMethod->invoke($service);
$assistant = $personas['assistant'] ?? [];
assertSame('制作助手', $assistant['name'] ?? null, 'unified assistant persona');
assertSame(true, in_array('regenerate_storyboard_image', $assistant['tools'] ?? [], true), 'assistant can generate shot image');
assertSame(true, in_array('regenerate_storyboard_shot', $assistant['tools'] ?? [], true), 'assistant can revise storyboard shot');
assertSame(true, in_array('validate_storyboard_consistency', $assistant['tools'] ?? [], true), 'assistant can validate consistency');
assertSame(true, in_array('inspect_series_readiness', $assistant['tools'] ?? [], true), 'assistant can run read-only readiness check');

$systemPromptMethod = new ReflectionMethod($service, 'systemPrompt');
$systemPromptMethod->setAccessible(true);
$systemPrompt = $systemPromptMethod->invoke($service, $assistant);
assertSame(true, str_contains($systemPrompt, '功能清单'), 'assistant prompt answers capability questions');
assertSame(true, str_contains($systemPrompt, '不得调用工具'), 'capability answer does not query production data');
assertSame(true, str_contains($systemPrompt, '必须支持，不得归类为“闲聊创作”'), 'assistant prompt allows platform creative drafting');
assertSame(true, str_contains($systemPrompt, '直接用 reply 输出可复制的完整草稿'), 'creative drafting does not require a tool');
assertSame(true, str_contains($systemPrompt, '不写数据库，不启动工作流或付费任务'), 'creative drafting remains read only');
$contextPrompt = $systemPromptMethod->invoke($service, $assistant, [
    'page' => 'series',
    'series_id' => 42,
    'series_title' => '测试作品',
    'episode_id' => 88,
    'episode_number' => 3,
    'episode_title' => '测试剧集',
]);
assertSame(true, str_contains($contextPrompt, '当前作品：测试作品（series_id=42）'), 'prompt includes trusted series context');
assertSame(true, str_contains($contextPrompt, '当前剧集：第3集 测试剧集（episode_id=88）'), 'prompt includes trusted episode context');
assertSame(true, str_contains($contextPrompt, '只读制作体检'), 'prompt limits readiness check to read only');

$capabilityQuestionMethod = new ReflectionMethod($service, 'isCapabilityQuestion');
$capabilityQuestionMethod->setAccessible(true);
assertSame(true, $capabilityQuestionMethod->invoke($service, '你能做什么？'), 'detect direct capability question');
assertSame(true, $capabilityQuestionMethod->invoke($service, '给我看看功能清单'), 'detect capability list request');
assertSame(false, $capabilityQuestionMethod->invoke($service, '现在有哪些任务正在生产？'), 'do not intercept production query');

$capabilityReplyMethod = new ReflectionMethod($service, 'capabilityReply');
$capabilityReplyMethod->setAccessible(true);
$capabilityReply = $capabilityReplyMethod->invoke($service);
assertSame(true, str_contains($capabilityReply, '1. 运行制作体检'), 'capability reply starts with readiness check');
assertSame(true, str_contains($capabilityReply, '2. 查询生产进度'), 'capability reply lists production status');
assertSame(true, str_contains($capabilityReply, '单集图片核验'), 'capability reply includes actual-image episode review');
assertSame(true, str_contains($capabilityReply, '所有问题默认不修改'), 'capability reply states confirmation-first safety');
assertSame(true, str_contains($capabilityReply, '8. 创作生产文案'), 'capability reply includes platform creative drafting');

$capabilityResponse = $service->chat(new App(), 1, 'assistant', [], '你能做什么？');
assertSame([], $capabilityResponse['actions'] ?? null, 'capability question returns without tools');
assertSame(true, str_contains((string) ($capabilityResponse['reply'] ?? ''), '8. 创作生产文案'), 'capability question returns full list');

$statusCountsMethod = new ReflectionMethod($service, 'statusCounts');
$statusCountsMethod->setAccessible(true);
$statusCounts = $statusCountsMethod->invoke($service, ['queued', 'failed', 'failed', 'unknown'], ['queued', 'running', 'failed']);
assertSame(['queued' => 1, 'running' => 0, 'failed' => 2], $statusCounts, 'count only allowed readiness statuses');

$cancelImagesMethod = new ReflectionMethod($service, 'toolCancelQueuedImageJobs');
$cancelImagesMethod->setAccessible(true);
$cancelImages = $cancelImagesMethod->invoke($service, 1, []);
assertSame(true, isset($cancelImages['error']), 'reject unscoped image cancellation');

$cancelVideosMethod = new ReflectionMethod($service, 'toolCancelQueuedVideoJobs');
$cancelVideosMethod->setAccessible(true);
$cancelVideos = $cancelVideosMethod->invoke($service, 1, []);
assertSame(true, isset($cancelVideos['error']), 'reject unscoped video cancellation');

if (in_array('--readiness-db', $argv, true)) {
    $app = new App();
    $app->initialize();
    $series = Series::where('user_id', '>', 0)->order('id', 'desc')->find();
    if ($series instanceof Series) {
        $contextMethod = new ReflectionMethod($service, 'resolvePageContext');
        $contextMethod->setAccessible(true);
        $trustedContext = $contextMethod->invoke($service, (int) $series->getAttr('user_id'), [
            'page' => 'series',
            'series_id' => (int) $series->getAttr('id'),
            'series_title' => '伪造标题',
        ]);
        assertSame((string) $series->getAttr('title'), $trustedContext['series_title'] ?? null, 'context title comes from database');
        $deniedContext = $contextMethod->invoke($service, 2147483647, [
            'series_id' => (int) $series->getAttr('id'),
        ]);
        assertSame(false, isset($deniedContext['series_id']), 'context rejects series from another user');

        $readinessMethod = new ReflectionMethod($service, 'toolInspectSeriesReadiness');
        $readinessMethod->setAccessible(true);
        $readiness = $readinessMethod->invoke($service, (int) $series->getAttr('user_id'), [
            'series_id' => (int) $series->getAttr('id'),
        ]);
        assertSame(true, $readiness['read_only'] ?? null, 'database readiness check is read only');
        assertSame((int) $series->getAttr('id'), $readiness['scope']['series_id'] ?? null, 'database readiness scope');
        assertSame(true, str_contains((string) ($readiness['safety'] ?? ''), '不会自动'), 'database readiness safety note');
    } else {
        echo "Database readiness check skipped: no series\n";
    }
}

echo "Unified worker agent tests passed: {$tests}\n";

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    global $tests;
    $tests++;
    if ($expected !== $actual) {
        fwrite(STDERR, "Assertion failed [{$label}]: expected " . var_export($expected, true)
            . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}
