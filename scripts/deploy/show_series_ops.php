<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

$app = new think\App();
$app->initialize();

$cols = think\facade\Db::query('SHOW COLUMNS FROM admin_operation_logs');
echo "cols=";
foreach ($cols as $c) {
    echo $c['Field'] . ',';
}
echo PHP_EOL;

$rows = think\facade\Db::name('admin_operation_logs')
    ->whereLike('action', '%series%')
    ->order('id', 'desc')
    ->limit(40)
    ->select()
    ->toArray();

foreach ($rows as $row) {
    echo json_encode([
        'id' => $row['id'] ?? null,
        'action' => $row['action'] ?? null,
        'target_id' => $row['target_id'] ?? null,
        'target_name_snapshot' => $row['target_name_snapshot'] ?? null,
        'ip' => $row['ip'] ?? null,
        'create_time' => $row['create_time'] ?? null,
        'user' => $row['actor_name'] ?? ($row['user_name'] ?? ($row['operator_name'] ?? null)),
        'uid' => $row['actor_user_id'] ?? ($row['user_id'] ?? ($row['operator_user_id'] ?? null)),
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

echo "series_now=\n";
foreach (think\facade\Db::name('series')->order('id', 'asc')->select()->toArray() as $s) {
    echo json_encode($s, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
