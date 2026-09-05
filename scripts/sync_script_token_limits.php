<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = new think\App();
$app->initialize();

$uids = think\facade\Db::name('script_ai_configs')->distinct(true)->column('user_id');
if ($uids === []) {
    $uids = [1];
}
foreach ($uids as $uid) {
    app\support\ScriptCreationService::configs((int) $uid);
    echo "synced_user={$uid}\n";
}

$rows = think\facade\Db::name('script_ai_configs')
    ->whereIn('config_key', ['default', 'writer', 'reviewer'])
    ->field('user_id,config_key,max_tokens')
    ->order(['user_id' => 'asc', 'config_key' => 'asc'])
    ->select()
    ->toArray();
foreach ($rows as $row) {
    echo $row['user_id'] . '|' . $row['config_key'] . '|' . var_export($row['max_tokens'], true) . PHP_EOL;
}
