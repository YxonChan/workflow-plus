<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use app\controller\SeriesController;

$ref = new ReflectionClass(SeriesController::class);
$controller = $ref->newInstanceWithoutConstructor();

$parse = $ref->getMethod('parseVideoDurationSeconds');
$parse->setAccessible(true);
$extract = $ref->getMethod('extractDurationFromText');
$extract->setAccessible(true);
$resolve = $ref->getMethod('resolveWorkflowVideoDuration');
$resolve->setAccessible(true);

$cases = [
    ['15s', 15],
    ['8秒', 8],
    ['0-15s', 15],
    ['0-8秒', 8],
    ['5-12s', 7],
    ['auto', 0],
    ['', 0],
];
foreach ($cases as [$input, $expected]) {
    $got = $parse->invoke($controller, $input);
    if ($got !== $expected) {
        fwrite(STDERR, "parse fail [{$input}] expected {$expected} got {$got}\n");
        exit(1);
    }
}

$storyboard = <<<'TXT'
【视频节点01｜15s｜开场】
引用资产：@大福

Shot 1（0-5s｜近景）：橘猫挠门
Shot 2（5-10s｜中景）：张姨开门
Shot 3（10-15s｜特写）：定焦画面
TXT;
$extracted = $extract->invoke($controller, $storyboard);
$seconds = $parse->invoke($controller, $extracted);
if ($seconds !== 15) {
    fwrite(STDERR, "extract timeline fail got {$extracted} => {$seconds}\n");
    exit(1);
}

$shortNode = <<<'TXT'
【视频节点02｜8s｜短冲突】
Shot 1（0-4s）：对峙
Shot 2（4-8s）：转身离开
TXT;
$extractedShort = $extract->invoke($controller, $shortNode);
$secondsShort = $parse->invoke($controller, $extractedShort !== '' ? $extractedShort : '8s');
// Header already has 8s from parseShotText path; extract from body timeline should yield 8.
$fromBody = $parse->invoke($controller, $extract->invoke($controller, $shortNode));
if ($fromBody !== 8) {
    fwrite(STDERR, "short timeline fail got {$fromBody}\n");
    exit(1);
}

// Shot duration wins over node fixed duration.
$got = $resolve->invoke($controller, '8s', ['duration' => 15]);
if ($got !== 8) {
    fwrite(STDERR, "shot-priority fail expected 8 got {$got}\n");
    exit(1);
}

// Empty shot falls back to node.
$got = $resolve->invoke($controller, '', ['duration' => 15]);
if ($got !== 15) {
    fwrite(STDERR, "node-fallback fail expected 15 got {$got}\n");
    exit(1);
}

echo "ALL_PASSED\n";
