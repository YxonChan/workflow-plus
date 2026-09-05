<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use app\controller\SeriesController;

$ref = new ReflectionClass(SeriesController::class);
foreach ([
    'assertSeriesAssetsHaveCoreViewsForVideo',
    'listAssetsMissingCoreViewsForVideo',
    'assetHasCompletedCoreView',
] as $method) {
    if (!$ref->hasMethod($method)) {
        fwrite(STDERR, "missing method {$method}\n");
        exit(1);
    }
}

echo "OK: video core-view guard methods present\n";
