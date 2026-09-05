<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use app\support\ImageJobStatus;
use app\support\ImageProviderTaskState;
use app\support\PendingImageTaskException;
use app\support\ProviderJsonResponse;

$tests = 0;
$taskId = 'tsk_img_01K123ABC_xyz';

assertSame($taskId, ImageProviderTaskState::extractTaskId('AI 图片任务超时：' . $taskId), 'extract legacy timeout task id');
assertSame($taskId, ImageProviderTaskState::extractTaskId(ImageProviderTaskState::pendingMessage($taskId)), 'extract pending task id');
assertSame('cgt-20260724123000-Abc_123', ImageProviderTaskState::extractTaskId('等待 cgt-20260724123000-Abc_123'), 'extract alternate provider task id');
assertSame('', ImageProviderTaskState::extractTaskId('普通图片生成错误'), 'ignore message without provider task id');
assertSame(true, ImageProviderTaskState::isLegacyTimeout('AI 图片任务超时：' . $taskId), 'detect recoverable legacy timeout');
assertSame(false, ImageProviderTaskState::isLegacyTimeout(ImageProviderTaskState::pendingMessage($taskId)), 'do not treat pending state as legacy timeout');
assertSame('图片平台处理中，正在等待结果：' . $taskId, ImageProviderTaskState::pendingMessage($taskId), 'build user-visible pending message');

$exception = new PendingImageTaskException($taskId);
assertSame($taskId, $exception->taskId(), 'pending exception retains task id');
assertSame(ImageProviderTaskState::pendingMessage($taskId), $exception->getMessage(), 'pending exception exposes safe status');

$concatenated = '{"status":"completed","result":{"data":[{"url":"https://files.example/image.png"}]}}'
    . '{"error":{"message":"panic: invalid memory address"}}';
$providerResponse = ProviderJsonResponse::decode($concatenated);
assertSame('completed', $providerResponse['status'] ?? null, 'decode completed object from concatenated provider JSON');
assertSame('https://files.example/image.png', $providerResponse['result']['data'][0]['url'] ?? null, 'retain image URL from first provider object');
assertSame(['message' => 'brace } and escaped " quote'], ProviderJsonResponse::decode('{"message":"brace } and escaped \\" quote"}'), 'ignore braces and escaped quotes inside JSON strings');
assertSame(null, ProviderJsonResponse::decode('not-json'), 'reject response without JSON object');

assertSame(['queued', 'running', 'waiting', 'holding'], ImageJobStatus::active(), 'active image job statuses include holding');
assertSame(['running', 'waiting'], ImageJobStatus::inFlight(), 'in-flight statuses occupy provider slots');
assertSame(true, ImageJobStatus::isActive('waiting'), 'waiting is still polled by UI');
assertSame(true, ImageJobStatus::isActive('holding'), 'holding is still polled by UI');
assertSame(true, ImageJobStatus::isBusy('waiting'), 'waiting occupies a provider slot');
assertSame(false, ImageJobStatus::isBusy('holding'), 'holding does not occupy a provider slot');
assertSame(false, ImageJobStatus::isBusy('queued'), 'queued does not occupy a provider slot');

echo "Asset image task recovery tests passed: {$tests}\n";

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
