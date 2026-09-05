<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use app\support\VoiceAssetService;
use app\model\VoiceAsset;
use think\facade\Db;

$tests = 0;

if (in_array('--schema', $argv, true)) {
    $app = new think\App();
    $app->initialize();
    (new VoiceAssetService())->ensureSchema();
}

if (in_array('--locking', $argv, true)) {
    $app ??= new think\App();
    $app->initialize();
    (new VoiceAssetService())->ensureSchema();
    Db::startTrans();
    try {
        $temporary = VoiceAsset::create([
            'user_id' => 0,
            'asset_id' => 0,
            'name' => '__voice_locking_test__',
            'source_url' => '/testing/voice.wav',
            'status' => 'ready',
        ]);
        $locked = VoiceAsset::where('id', (int) $temporary->getAttr('id'))->lock(true)->select();
        $ids = array_map(static fn (VoiceAsset $voice): int => (int) $voice->getAttr('id'), $locked->all());
        VoiceAsset::whereIn('id', $ids)->update(['status' => 'archived']);
        assertSame('archived', (string) VoiceAsset::find((int) $temporary->getAttr('id'))?->getAttr('status'), 'select lock then update');
    } finally {
        Db::rollback();
    }
}

$probe = VoiceAssetService::parseProbeJson(json_encode([
    'streams' => [[
        'codec_name' => 'mp3',
        'sample_rate' => '44100',
        'channels' => 1,
    ]],
    'format' => ['duration' => '12.345'],
], JSON_THROW_ON_ERROR));

assertSame(12345, $probe['duration_ms'], 'duration_ms');
assertSame('mp3', $probe['codec_name'], 'codec_name');
assertSame(44100, $probe['sample_rate'], 'sample_rate');
assertSame(1, $probe['channels'], 'channels');

$invalidFailed = false;
try {
    VoiceAssetService::parseProbeJson('{"streams":[],"format":{"duration":"0"}}');
} catch (RuntimeException) {
    $invalidFailed = true;
}
assertSame(true, $invalidFailed, 'invalid audio rejected');

if (isset($argv[1]) && is_file($argv[1])) {
    $app = new think\App();
    $app->initialize();
    $inspected = (new VoiceAssetService())->inspectAudio($argv[1]);
    assertSame(true, $inspected['duration_ms'] > 0, 'real audio duration');
    assertSame(true, $inspected['channels'] > 0, 'real audio channels');
}

echo "Voice asset validation tests passed: {$tests}\n";

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
