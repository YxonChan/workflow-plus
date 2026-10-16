<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use app\controller\QuickCreateController;
use app\model\QuickCreateMessage;
use app\support\ImageProviderTaskState;

$app = new think\App(dirname(__DIR__));
$app->initialize();
$controller = (new ReflectionClass(QuickCreateController::class))->newInstanceWithoutConstructor();
$serialize = new ReflectionMethod($controller, 'serialize');
$pending = ImageProviderTaskState::pendingMessage('tsk_status_fixture');
$cases = [
    ['image', 'queued', '', 'queued'],
    ['image', 'queued', $pending, 'running'],
    ['image', 'running', $pending, 'running'],
    ['image', 'success', '', 'success'],
    ['image', 'failed', $pending, 'failed'],
    ['video', 'queued', $pending, 'queued'],
];
foreach ($cases as [$mode, $status, $error, $expected]) {
    $message = new QuickCreateMessage([
        'id' => 1, 'user_id' => 1, 'mode' => $mode, 'status' => $status,
        'error_message' => $error, 'asset_refs_json' => [], 'options_json' => [], 'result_urls_json' => [],
    ]);
    $result = $serialize->invoke($controller, $message);
    if ($result['status'] !== $expected || $message->getAttr('status') !== $status) {
        throw new RuntimeException("Incorrect public status for {$mode}:{$status}");
    }
}
echo 'Quick-create public status tests passed: ' . count($cases) . PHP_EOL;
