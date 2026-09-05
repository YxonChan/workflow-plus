<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use app\controller\SeriesController;
use app\support\StoryboardVisualReviewService;
use app\support\WorkerReferenceToken;
use think\App;

$tests = 0;

$service = new StoryboardVisualReviewService();
$normalizeTraits = new ReflectionMethod($service, 'normalizeVisionTraits');
$normalizeTraits->setAccessible(true);
$traits = $normalizeTraits->invoke($service, [
    'hair_color' => ['value' => 'black', 'confidence' => 0.92],
    'hair_length' => ['value' => 'long', 'confidence' => 0.55],
    'eye_color' => ['value' => 'blue', 'confidence' => 0.549],
    'eyewear' => ['value' => 'mask', 'confidence' => 0.99],
    'unknown_trait' => ['value' => 'anything', 'confidence' => 1],
]);
assertSame(['hair_color', 'hair_length'], array_keys($traits), 'keep allowed traits at or above threshold only');
assertSame('黑色', $traits['hair_color']['label'] ?? null, 'normalize allowed value label');
assertSame(0.55, $traits['hair_length']['confidence'] ?? null, 'include exact 55 percent threshold');

$decodeJson = new ReflectionMethod($service, 'decodeJsonObject');
$decodeJson->setAccessible(true);
assertSame(['person_detected' => true], $decodeJson->invoke($service, '{"person_detected":true}'), 'decode plain JSON');
assertSame(
    ['traits' => ['hair_color' => ['value' => 'black']]],
    $decodeJson->invoke($service, "```json\n{\"traits\":{\"hair_color\":{\"value\":\"black\"}}}\n```"),
    'decode fenced JSON',
);

$seriesController = (new ReflectionClass(SeriesController::class))->newInstanceWithoutConstructor();
$normalizeIndexes = new ReflectionMethod($seriesController, 'normalizeChangedShotIndexes');
$normalizeIndexes->setAccessible(true);
assertSame([4 => true], $normalizeIndexes->invoke($seriesController, 4), 'normalize one changed shot index');
assertSame(
    [2 => true, 3 => true],
    $normalizeIndexes->invoke($seriesController, [2, '3', 3, 0, -1, 'invalid']),
    'normalize and deduplicate multiple changed shot indexes',
);

$app = new App(dirname(__DIR__));
$app->initialize();
$cacheConfig = $app->config->get('cache');
$cacheConfig['default'] = 'file';
$cacheConfig['stores']['file']['path'] = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'malulu-worker-reference-tests';
$cacheConfig['stores']['file']['prefix'] = 'visual-review-test:';
$app->config->set($cacheConfig, 'cache');

$token = WorkerReferenceToken::issue(101, '/storage/uploads/worker-chat/test.png', 'test.png');
$resolved = WorkerReferenceToken::resolve(101, $token);
assertSame(101, $resolved['user_id'] ?? null, 'reference token resolves for issuing user');
assertSame('/storage/uploads/worker-chat/test.png', $resolved['url'] ?? null, 'reference token preserves URL');
assertSame(true, ($resolved['expires_at'] ?? 0) > time(), 'reference token has future expiry');

$wrongUserRejected = false;
try {
    WorkerReferenceToken::resolve(202, $token);
} catch (RuntimeException $e) {
    $wrongUserRejected = str_contains($e->getMessage(), '不属于当前用户');
}
assertSame(true, $wrongUserRejected, 'reference token rejects another user');

echo "Storyboard visual review tests passed: {$tests}\n";

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
