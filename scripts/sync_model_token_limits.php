<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = new think\App();
$app->initialize();

$rows = think\facade\Db::name('script_ai_configs')
    ->whereIn('config_key', ['default', 'writer', 'reviewer'])
    ->field('user_id,config_key,max_tokens')
    ->order(['user_id' => 'asc', 'config_key' => 'asc'])
    ->select()
    ->toArray();
foreach ($rows as $row) {
    echo 'cfg|' . $row['user_id'] . '|' . $row['config_key'] . '|' . var_export($row['max_tokens'], true) . PHP_EOL;
}

$textModels = think\facade\Db::name('model_configs')->where('type', 'text')->select()->toArray();
foreach ($textModels as $model) {
    $opts = $model['options'];
    if (is_string($opts)) {
        $opts = json_decode($opts, true);
    }
    if (!is_array($opts)) {
        $opts = [];
    }
    $opts['max_tokens'] = 65536;
    if (!isset($opts['temperature'])) {
        $opts['temperature'] = 0.7;
    }
    think\facade\Db::name('model_configs')->where('id', (int) $model['id'])->update([
        'options' => json_encode($opts, JSON_UNESCAPED_UNICODE),
        'update_time' => date('Y-m-d H:i:s'),
    ]);
    echo 'model|' . $model['id'] . '|' . $model['name'] . '|' . json_encode($opts, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

$svc = (string) file_get_contents(__DIR__ . '/../app/support/ScriptCreationService.php');
echo 'code_65536=' . (str_contains($svc, '65536') ? 'yes' : 'no') . PHP_EOL;
echo 'code_200000=' . (str_contains($svc, '200000') ? 'yes' : 'no') . PHP_EOL;
