<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = new think\App();
$app->initialize();

echo 'db=' . think\facade\Db::query('select database() d')[0]['d'] . PHP_EOL;
echo 'series_count=' . think\facade\Db::name('series')->count() . PHP_EOL;

$rows = think\facade\Db::name('series')
    ->order('id', 'desc')
    ->field('id,user_id,title,create_time,update_time,source_text')
    ->select()
    ->toArray();

foreach ($rows as $row) {
    $id = (int) $row['id'];
    $ep = think\facade\Db::name('episodes')->where('series_id', $id)->count();
    $as = think\facade\Db::name('assets')->where('series_id', $id)->count();
    echo 'S|' . $id
        . '|u' . $row['user_id']
        . '|' . $row['title']
        . '|ep=' . $ep
        . '|assets=' . $as
        . '|src_len=' . mb_strlen((string) ($row['source_text'] ?? ''))
        . '|' . $row['create_time']
        . '|' . $row['update_time']
        . PHP_EOL;
}

$catLike = think\facade\Db::name('series')
    ->whereLike('title', '%猫%')
    ->field('id,title,user_id')
    ->select()
    ->toArray();
echo 'cat_title_matches=' . count($catLike) . PHP_EOL;
foreach ($catLike as $row) {
    echo 'CAT|' . $row['id'] . '|' . $row['title'] . '|u' . $row['user_id'] . PHP_EOL;
}

// Check orphaned assets/episodes pointing to missing series.
$assetSeries = think\facade\Db::query(
    'SELECT series_id, COUNT(*) c FROM assets GROUP BY series_id ORDER BY series_id DESC'
);
foreach ($assetSeries as $row) {
    $sid = (int) $row['series_id'];
    $exists = think\facade\Db::name('series')->where('id', $sid)->count();
    echo 'ASSET_SERIES|' . $sid . '|count=' . $row['c'] . '|series_exists=' . ($exists ? 'yes' : 'no') . PHP_EOL;
}

$epSeries = think\facade\Db::query(
    'SELECT series_id, COUNT(*) c FROM episodes GROUP BY series_id ORDER BY series_id DESC'
);
foreach ($epSeries as $row) {
    $sid = (int) $row['series_id'];
    $exists = think\facade\Db::name('series')->where('id', $sid)->count();
    echo 'EP_SERIES|' . $sid . '|count=' . $row['c'] . '|series_exists=' . ($exists ? 'yes' : 'no') . PHP_EOL;
}

echo 'assets_total=' . think\facade\Db::name('assets')->count() . PHP_EOL;
echo 'episodes_total=' . think\facade\Db::name('episodes')->count() . PHP_EOL;
echo 'jobs_recent=' . think\facade\Db::name('asset_image_jobs')->where('id', '>=', 830)->count() . PHP_EOL;

try {
    echo 'series_cache_bump=' . app\support\RedisCache::bumpVersion('series') . PHP_EOL;
    echo 'assets_cache_bump=' . app\support\RedisCache::bumpVersion('assets') . PHP_EOL;
} catch (Throwable $e) {
    echo 'cache_bump_error=' . $e->getMessage() . PHP_EOL;
}
