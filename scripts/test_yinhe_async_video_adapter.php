<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use app\controller\SeriesController;

$tests = 0;
$reflection = new ReflectionClass(SeriesController::class);
$controller = $reflection->newInstanceWithoutConstructor();

$invoke = static function (string $method, mixed ...$args) use ($reflection, $controller): mixed {
    $target = $reflection->getMethod($method);
    $target->setAccessible(true);
    return $target->invoke($controller, ...$args);
};

$endpoint = 'https://api-aigc.fzyinghe.com/video/generation/tasks';
$options = $invoke('normalizeVideoRequestOptionsForModel', $endpoint, 'dreamina-seedance-2.0', [
    'provider' => 'yinhe_async',
    'aspect_ratio' => '16:9',
    'generate_audio' => true,
    'watermark' => false,
]);

assertSame('yinhe_async', $options['provider'] ?? null, 'provider normalization');
assertSame('16:9', $options['ratio'] ?? null, 'aspect ratio maps to ratio');
assertSame(9, $options['max_reference_images'] ?? null, 'reference image limit');
assertSame(true, $invoke('usesContentArrayVideoPayload', $endpoint, $options), 'content-array protocol detection');

$payload = $invoke(
    'buildArkVideoPayload',
    'dreamina-seedance-2.0',
    'A cinematic tracking shot.',
    ['https://example.com/reference.jpg'],
    15,
    $options,
    [],
);
assertSame('dreamina-seedance-2.0', $payload['model'] ?? null, 'model id');
assertSame('text', $payload['content'][0]['type'] ?? null, 'text content item');
assertSame('image_url', $payload['content'][1]['type'] ?? null, 'image content item');
assertSame('reference_image', $payload['content'][1]['role'] ?? null, 'reference image role');
assertSame(15, $payload['duration'] ?? null, 'duration');
assertSame('16:9', $payload['ratio'] ?? null, 'payload ratio');

$taskId = $invoke('extractVideoTaskIdFromResponse', [
    'code' => 200,
    'data' => ['taskId' => '0198b6b7-test'],
]);
assertSame('0198b6b7-test', $taskId, 'camelCase task id');

$taskId = $invoke('extractVideoTaskIdFromResponse', [
    'output' => ['task_id' => 'telecom-seedance-task'],
]);
assertSame('telecom-seedance-task', $taskId, 'telecom output task id');

$videoUrl = $invoke('extractVideoUrlFromResponse', [
    'code' => 200,
    'data' => [
        'status' => 'SUCCESS',
        'resultUrl' => 'https://example.com/result.mp4',
    ],
]);
assertSame('https://example.com/result.mp4', $videoUrl, 'camelCase result URL');

$resultEndpoint = $invoke('resolveVideoResultEndpoint', $endpoint, '0198b6b7-test', [
    'result_endpoint' => 'https://api-aigc.fzyinghe.com/video/generation/tasks/{id}',
]);
assertSame(
    'https://api-aigc.fzyinghe.com/video/generation/tasks/0198b6b7-test',
    $resultEndpoint,
    'result endpoint placeholder',
);

echo "Yinhe async video adapter tests passed: {$tests}\n";

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
