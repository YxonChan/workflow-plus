<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/vendor/topthink/framework/src/helper.php';

use app\controller\QuickCreateController;
use app\model\ModelConfig;
use think\exception\HttpException;

$reflection = new ReflectionClass(QuickCreateController::class);
$controller = $reflection->newInstanceWithoutConstructor();
$sanitize = $reflection->getMethod('sanitizeOptions');
$sanitize->setAccessible(true);
$tests = 0;
$check = static function (mixed $requested, array $config, bool $valid, ?int $expected = null) use ($sanitize, $controller, &$tests): void {
    $model = new class(['endpoint' => '', 'options' => $config]) extends ModelConfig {
        protected $schema = ['endpoint' => 'string', 'options' => 'json'];
    };
    try {
        $result = $sanitize->invoke($controller, ['duration' => $requested], 'video', $model);
        if (!$valid || $result['duration'] !== ($expected ?? $requested)) {
            throw new RuntimeException('Unexpected accepted duration: ' . json_encode([$requested, $config, $result]));
        }
    } catch (HttpException $error) {
        if ($valid || $error->getStatusCode() !== 422) {
            throw $error;
        }
    }
    $tests++;
};

foreach ([2, 3, 4, 0, -1, 16, 5.5, 'invalid'] as $duration) {
    $check($duration, [], false);
}
foreach ([5, 8, 10, 15] as $duration) {
    $check($duration, [], true);
}
$check('5', [], true, 5);
$check(4, ['provider' => 'minimax_v2'], true);
$check(3, ['provider' => 'minimax_v2'], false);
$check(6, ['duration_options' => [6, 8]], true);
$check(5, ['duration_options' => [6, 8]], false);
$check(8, ['duration_options' => ['6', '8']], true);
$check(5, ['min_duration' => 6, 'max_duration' => 8], false);
$check(8, ['min_duration' => 6, 'max_duration' => 8], true);
$check(10, ['min_duration' => 6, 'max_duration' => 8], false);
// Explicit configuration must not bypass adapter limits and silently change timing later.
$check(3, ['duration_options' => [3, 5], 'min_duration' => 2], false);
$image = $sanitize->invoke($controller, ['duration' => 3], 'image');
if (isset($image['duration'])) {
    throw new RuntimeException('Video duration leaked into image options');
}
echo 'Quick Create duration checks passed: ' . ($tests + 1) . PHP_EOL;
