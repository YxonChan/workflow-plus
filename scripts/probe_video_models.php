<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = new think\App();
$app->initialize();

$rows = think\facade\Db::name('model_configs')
    ->where('type', 'video')
    ->field('id,name,model_id,endpoint,is_default,enabled,options')
    ->order(['is_default' => 'desc', 'id' => 'asc'])
    ->select()
    ->toArray();

foreach ($rows as $row) {
    $opts = $row['options'];
    if (is_array($opts)) {
        $opts = json_encode($opts, JSON_UNESCAPED_UNICODE);
    }
    echo $row['id'] . '|' . $row['name'] . '|' . $row['model_id']
        . '|def=' . $row['is_default'] . '|en=' . $row['enabled']
        . '|' . $row['endpoint'] . '|' . $opts . PHP_EOL;
}

$jobs = think\facade\Db::name('video_jobs')
    ->order('id', 'desc')
    ->limit(8)
    ->field('id,duration,status,model_config_id,shot_index,create_time')
    ->select()
    ->toArray();
echo "---jobs---\n";
foreach ($jobs as $job) {
    echo 'J|' . $job['id'] . '|dur=' . $job['duration'] . '|m=' . $job['model_config_id']
        . '|' . $job['status'] . '|shot=' . $job['shot_index'] . '|' . $job['create_time'] . PHP_EOL;
}
