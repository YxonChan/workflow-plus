<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = new think\App();
$app->initialize();

$seriesId = (int) ($argv[1] ?? 0);
if ($seriesId <= 0) {
    $row = think\facade\Db::name('series')->order('id', 'desc')->find();
    $seriesId = (int) ($row['id'] ?? 0);
}

$series = think\facade\Db::name('series')->where('id', $seriesId)->find();
echo 'series|' . $seriesId . '|' . ($series['title'] ?? '') . PHP_EOL;

$assetIds = think\facade\Db::name('assets')->where('series_id', $seriesId)->column('id');
$jobs = think\facade\Db::name('asset_image_jobs')
    ->whereIn('asset_id', $assetIds ?: [0])
    ->order('id', 'asc')
    ->select()
    ->toArray();

$stats = [];
foreach ($jobs as $job) {
    $status = (string) ($job['status'] ?? '');
    $stats[$status] = ($stats[$status] ?? 0) + 1;
    echo 'J|' . $job['id']
        . '|asset=' . $job['asset_id']
        . '|img=' . ($job['asset_image_id'] ?? '')
        . '|view=' . ($job['view_type'] ?? '')
        . '|status=' . $status
        . '|result=' . mb_substr((string) ($job['result_url'] ?? ''), 0, 80)
        . '|err=' . mb_substr((string) ($job['error_message'] ?? ''), 0, 100)
        . '|desc=' . mb_substr((string) ($job['description'] ?? ''), 0, 40)
        . PHP_EOL;
}
echo 'stats|' . json_encode($stats, JSON_UNESCAPED_UNICODE) . PHP_EOL;

$withUrl = 0;
$emptyUrl = 0;
$noImg = 0;
$assets = think\facade\Db::name('assets')->where('series_id', $seriesId)->select()->toArray();
foreach ($assets as $asset) {
    $images = think\facade\Db::name('asset_images')->where('asset_id', (int) $asset['id'])->select()->toArray();
    if ($images === []) {
        $noImg++;
        continue;
    }
    foreach ($images as $image) {
        if (trim((string) ($image['url'] ?? '')) === '') {
            $emptyUrl++;
        } else {
            $withUrl++;
        }
    }
}
echo "assets=" . count($assets) . " with_url={$withUrl} empty_url={$emptyUrl} no_image_row={$noImg}\n";
