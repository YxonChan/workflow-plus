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

$endpoint = 'https://video-api.example.com/api/v1/videos';
$options = $invoke('normalizeVideoRequestOptionsForModel', $endpoint, 'mini23-h3', [
    'provider' => 'mini23',
    'width' => 864,
    'height' => 480,
]);

assertSame('mini23', $options['provider'] ?? null, 'provider normalization');
assertSame(864, $options['width'] ?? null, 'default width');
assertSame(480, $options['height'] ?? null, 'default height');
assertSame(180, $options['poll_attempts'] ?? null, 'queue-tolerant polling');

[$width, $height] = $invoke('mini23DimensionsForOptions', array_merge($options, [
    'resolution' => '480p',
    'aspect_ratio' => '16:9',
]));
assertSame(864, $width, '16:9 width aligns to 32');
assertSame(480, $height, '480p height');

$tempFiles = [];
$payloadMethod = $reflection->getMethod('buildMini23VideoPayload');
$payloadMethod->setAccessible(true);
[$payload, $files] = $payloadMethod->invokeArgs($controller, [
    'A cinematic ocean at sunset.',
    [],
    5,
    $options,
    &$tempFiles,
]);
assertSame('t2v', $payload['mode'] ?? null, 'text-to-video mode');
assertSame(5, $payload['seconds'] ?? null, 'duration');
assertSame([], $files, 'no image refs for T2V');

$resultEndpoint = $invoke('resolveVideoResultEndpoint', $endpoint, '4e6e54668db56d537e568e87', $options);
assertSame($endpoint . '/4e6e54668db56d537e568e87', $resultEndpoint, 'query endpoint');

$contentUrl = $invoke('extractMini23ContentUrl', [
    'status' => 'succeeded',
    'content_url' => '/api/v1/videos/4e6e54668db56d537e568e87/content',
], $endpoint);
assertSame(
    'https://video-api.example.com/api/v1/videos/4e6e54668db56d537e568e87/content',
    $contentUrl,
    'relative content URL becomes authenticated backend URL'
);

echo "Mini23 video adapter tests passed: {$tests}\n";

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
