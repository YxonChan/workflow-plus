<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use app\support\StoryboardConsistencyService;

$tests = 0;
$service = new StoryboardConsistencyService();

$assetTraits = $service->extractTraits('Young woman with long black hair and clear blue eyes.');
assertSame('black', $assetTraits['hair_color']['value'] ?? null, 'extract black hair');
assertSame('long', $assetTraits['hair_length']['value'] ?? null, 'extract long hair');
assertSame('blue', $assetTraits['eye_color']['value'] ?? null, 'extract blue eyes');

$shotTraits = $service->extractTraits('@Alice appears with short red hair and brown eyes.', 'Alice');
$differences = $service->compareTraits($assetTraits, $shotTraits);
assertSame(3, count($differences), 'detect three explicit conflicts');
assertSame('发色', $differences[0]['field_label'] ?? null, 'label hair color conflict');

$unspecified = $service->extractTraits('@Alice enters the room and looks at the window.', 'Alice');
assertSame([], $service->compareTraits($assetTraits, $unspecified), 'do not flag unspecified traits');

$multi = '@Alice with long black hair faces @Bob with short red hair.';
assertSame('black', $service->extractTraits($multi, 'Alice')['hair_color']['value'] ?? null, 'scope Alice traits');
assertSame('red', $service->extractTraits($multi, 'Bob')['hair_color']['value'] ?? null, 'scope Bob traits');

$chineseAsset = $service->extractTraits('林夏留着黑色长发，眼睛是棕色。');
$chineseShot = $service->extractTraits('@林夏变成红色短发，蓝色眼睛。', '林夏');
assertSame(3, count($service->compareTraits($chineseAsset, $chineseShot)), 'detect Chinese appearance conflicts');

$extendedAsset = $service->extractTraits('Miles is an adult man with straight black hair, wearing glasses, and clean-shaven.');
$extendedShot = $service->extractTraits('@Miles appears as an elderly man with curly black hair, no glasses, and a full beard.', 'Miles');
$extendedDifferences = $service->compareTraits($extendedAsset, $extendedShot);
assertSame(4, count($extendedDifferences), 'detect extended explicit conflicts');
assertSame('发质', $extendedDifferences[0]['field_label'] ?? null, 'label hair texture conflict');
assertSame('warning', $extendedDifferences[0]['severity'] ?? null, 'mark mutable appearance as warning');
assertSame('年龄阶段', $extendedDifferences[1]['field_label'] ?? null, 'label age stage conflict');

echo "Storyboard consistency tests passed: {$tests}\n";

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
