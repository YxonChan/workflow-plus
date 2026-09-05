<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = new think\App();
$app->initialize();

$models = think\facade\Db::name('model_configs')
    ->where('type', 'video')
    ->field('id,name,model_id,options')
    ->select()
    ->toArray();

foreach ($models as $m) {
    $o = $m['options'];
    if (is_string($o)) {
        $o = json_decode($o, true);
    }
    if (!is_array($o)) {
        $o = [];
    }
    echo 'MODEL|' . $m['id'] . '|' . $m['name'] . '|duration=' . json_encode($o['duration'] ?? null) . PHP_EOL;
}

$wfs = think\facade\Db::name('workflows')
    ->whereIn('scope', ['episode', 'series'])
    ->field('id,name,graph')
    ->select()
    ->toArray();

foreach ($wfs as $w) {
    $g = $w['graph'];
    if (is_string($g)) {
        $g = json_decode($g, true);
    }
    if (!is_array($g['nodes'] ?? null)) {
        continue;
    }
    foreach ($g['nodes'] as $n) {
        $kind = $n['data']['kind'] ?? $n['kind'] ?? '';
        $label = $n['data']['label'] ?? $n['label'] ?? '';
        if ($kind !== 'video' && !str_contains((string) $label, '视频')) {
            continue;
        }
        $d = $n['data']['params']['duration'] ?? 'MISSING';
        echo 'WF|' . $w['id'] . '|' . $w['name'] . '|' . $label . '|duration=' . json_encode($d) . PHP_EOL;
    }
}

$jobs = think\facade\Db::name('video_jobs')
    ->order('id', 'desc')
    ->limit(10)
    ->field('id,duration,status,model_config_id,shot_index,create_time')
    ->select()
    ->toArray();
echo "---jobs---\n";
foreach ($jobs as $job) {
    echo 'J|' . $job['id'] . '|dur=' . $job['duration'] . '|m=' . $job['model_config_id']
        . '|' . $job['status'] . '|shot=' . $job['shot_index'] . '|' . $job['create_time'] . PHP_EOL;
}
