<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use app\support\VideoVoiceAssetService;

$tests = 0;
$voices = [[
    'voice_asset_id' => 2,
    'character_asset_id' => 559,
    'character_name' => 'A Bao',
    'source_url' => 'https://video.example.com/a-bao.wav',
    'mime_type' => 'audio/wav',
    'duration_ms' => 22433,
    'sha256' => str_repeat('a', 64),
]];

$prompt = VideoVoiceAssetService::prependArkPrompt('A Bao speaks.', $voices);
assertSame(true, str_contains($prompt, '@音频1'), 'prompt audio placeholder');
assertSame(true, str_contains($prompt, 'A Bao'), 'prompt character mapping');

$items = VideoVoiceAssetService::arkContentItems($voices);
assertSame(1, count($items), 'one audio item');
assertSame('audio_url', $items[0]['type'], 'audio content type');
assertSame('reference_audio', $items[0]['role'], 'audio reference role');
assertSame('https://video.example.com/a-bao.wav', $items[0]['audio_url']['url'], 'audio source url');

$duplicated = VideoVoiceAssetService::arkContentItems([$voices[0], $voices[0]]);
assertSame(1, count($duplicated), 'deduplicate same voice');
assertSame([], VideoVoiceAssetService::arkContentItems([['voice_asset_id' => 3, 'source_url' => '']]), 'ignore empty url');

echo "Video voice asset payload tests passed: {$tests}\n";

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
