<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = new think\App();
$app->initialize();

app\support\ScriptCreationService::configs(21);

$cols = think\facade\Db::query("SHOW COLUMNS FROM `script_ai_steps` LIKE 'handoff_content'");
echo 'handoff_column=' . (count($cols) > 0 ? 'yes' : 'no') . PHP_EOL;

$rows = think\facade\Db::name('script_ai_configs')->where('user_id', 21)->field('config_key,task_prompt,system_prompt')->select()->toArray();
foreach ($rows as $row) {
    $key = (string) $row['config_key'];
    $task = (string) $row['task_prompt'];
    $system = (string) $row['system_prompt'];
    $flag = 'old';
    if ($key === 'reviewer' && str_contains($task, '{{episode_body}}')) {
        $flag = 'new';
    } elseif ($key === 'default' && str_contains($task, '{{previous_handoff}}')) {
        $flag = 'new';
    } elseif (str_contains($system, '<<<DELIVERABLE>>>')) {
        $flag = 'new';
    }
    echo $key . '=' . $flag . PHP_EOL;
}
