<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use app\support\AssetExtractionRequestOptimizer;

$tests = 0;

$plot = AssetExtractionRequestOptimizer::resolvePlot('fallback', [
    '输入' => ['text' => 'fallback', 'plot_input' => 'fallback'],
    '剧情扩写' => ['text' => 'expanded plot'],
]);
assertSame('expanded plot', $plot, 'prefer latest upstream plot');
assertSame(8192, AssetExtractionRequestOptimizer::maxTokens([]), 'default max tokens');
assertSame(2048, AssetExtractionRequestOptimizer::maxTokens(['maxTokens' => 100]), 'minimum max tokens');
assertSame(32768, AssetExtractionRequestOptimizer::maxTokens(['maxTokens' => 65536]), 'maximum max tokens');

$library = AssetExtractionRequestOptimizer::compactLibrary([
    [
        'id' => 1,
        'asset_id' => 1,
        'type' => 'character',
        'name' => 'A Bao',
        'description' => str_repeat('a', 400),
        'tags' => array_map(static fn (int $i): string => 'tag' . $i, range(1, 20)),
        'main_image_url' => 'https://example.com/image.png',
        'has_image' => true,
        'image_prompt' => 'unused',
    ],
    [
        'asset_id' => 1,
        'asset_image_id' => 9,
        'type' => 'character',
        'name' => 'A Bao · Episode 2',
        'reference_role' => 'look',
        'character_name' => 'A Bao',
        'variant_name' => 'Episode 2',
        'description' => 'look',
        'main_image_url' => 'https://example.com/look.png',
    ],
    [
        'asset_id' => 1,
        'asset_image_id' => 9,
        'asset_image_version_id' => 99,
        'type' => 'character',
        'name' => 'A Bao · Episode 2 (version)',
        'reference_role' => 'look',
    ],
]);

assertSame(2, count($library), 'remove image versions');
assertSame(false, array_key_exists('main_image_url', $library[0]), 'remove media urls');
assertSame(false, array_key_exists('image_prompt', $library[0]), 'remove image prompt');
assertSame(300, mb_strlen($library[0]['description']), 'limit description');
assertSame(12, count($library[0]['tags']), 'limit tags');
assertSame('look', $library[1]['reference_role'], 'keep current look identity');

echo "Asset extraction optimization tests passed: {$tests}\n";

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
