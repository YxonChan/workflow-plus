<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use app\support\StoryboardRequestOptimizer;

$tests = 0;

assertSame(16384, StoryboardRequestOptimizer::maxTokens([]), 'default max tokens');
assertSame(4096, StoryboardRequestOptimizer::maxTokens(['maxTokens' => 100]), 'minimum max tokens');
assertSame(24576, StoryboardRequestOptimizer::maxTokens(['max_tokens' => 24576]), 'snake case max tokens');
assertSame(32768, StoryboardRequestOptimizer::maxTokens(['maxTokens' => 65536]), 'maximum max tokens');

$plot = 'A Bao runs from the robot vacuum.';
$upstream = StoryboardRequestOptimizer::compactUpstreamOutputs([
    '剧情概要' => ['text' => $plot, 'plot_input' => $plot],
    '资产提取与合并' => ['assets' => [['name' => 'A Bao']], 'text' => str_repeat('asset json', 100)],
    '剧情扩写' => ['text' => 'Expanded story used by the storyboard.'],
], $plot);

assertSame(['剧情扩写'], array_keys($upstream), 'remove duplicate input and asset preparation output');
assertSame('Expanded story used by the storyboard.', $upstream['剧情扩写']['text'], 'keep custom narrative upstream');

$library = StoryboardRequestOptimizer::compactLibrary([[
    'id' => 1,
    'asset_id' => 1,
    'type' => 'character',
    'name' => 'A Bao',
    'description' => str_repeat('a', 400),
    'tags' => ['cat'],
    'image_prompt' => 'unused',
    'main_image_url' => 'https://example.com/a-bao.png',
    'has_image' => true,
]]);

assertSame(1, count($library), 'keep asset identity');
assertSame(false, array_key_exists('main_image_url', $library[0]), 'remove media url');
assertSame(false, array_key_exists('image_prompt', $library[0]), 'remove image prompt');
assertSame(300, mb_strlen($library[0]['description']), 'limit description');

$before = json_encode(['plot_input' => $plot, 'upstream_outputs' => [
    '剧情概要' => ['text' => $plot, 'plot_input' => $plot],
    '资产提取与合并' => ['text' => str_repeat('asset json', 100)],
]], JSON_UNESCAPED_UNICODE);
$after = json_encode(['plot_input' => $plot, 'upstream_outputs' => $upstream], JSON_UNESCAPED_UNICODE);
assertSame(true, strlen((string) $after) < strlen((string) $before), 'reduce request payload');

echo "Storyboard request optimization tests passed: {$tests}\n";

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
