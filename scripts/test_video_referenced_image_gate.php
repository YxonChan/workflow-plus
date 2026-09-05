<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use app\support\VideoReferencedAssetGate;

function assertSame($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL {$label}: expected " . json_encode($expected) . ' got ' . json_encode($actual) . PHP_EOL);
        exit(1);
    }
    echo "OK {$label}" . PHP_EOL;
}

// Empty refs must not expand to a series-wide scan.
assertSame([], VideoReferencedAssetGate::assetIdsToEnforce([]), 'empty list');
assertSame([], VideoReferencedAssetGate::assetIdsToEnforce([0, -1, '']), 'invalid only');

// List and map shapes both work.
assertSame([12, 34], VideoReferencedAssetGate::assetIdsToEnforce([12, 34, 12]), 'list dedupe');
assertSame([12, 34], VideoReferencedAssetGate::assetIdsToEnforce([12 => true, 34 => true]), 'id map');

// Unrelated pending assets are outside the enforce set.
$referenced = VideoReferencedAssetGate::assetIdsToEnforce([101 => true]);
$seriesAssets = [101, 102, 103]; // 102/103 = new episode incomplete assets
$checked = array_values(array_intersect($seriesAssets, $referenced));
assertSame([101], $checked, 'only referenced asset checked');

echo "ALL PASSED" . PHP_EOL;