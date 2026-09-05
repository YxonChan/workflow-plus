<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = new think\App();
$app->initialize();

echo "=== tables like %run% / %node% / %episode% ===\n";
$tables = think\facade\Db::query('SHOW TABLES');
foreach ($tables as $row) {
    $name = (string) array_values($row)[0];
    if (preg_match('/run|node|episode|asset|ai_request/i', $name)) {
        echo $name . PHP_EOL;
    }
}

echo "=== recent workflow_runs ===\n";
$runs = think\facade\Db::name('workflow_runs')
    ->order('id', 'desc')
    ->limit(15)
    ->select()
    ->toArray();
foreach ($runs as $r) {
    echo 'run|' . $r['id']
        . '|status=' . ($r['status'] ?? '')
        . '|label=' . ($r['current_node_label'] ?? '')
        . '|err=' . mb_substr((string) ($r['error_message'] ?? ''), 0, 220)
        . '|' . ($r['update_time'] ?? '') . PHP_EOL;
}

echo "=== recent ai_request_logs episode_workflow ===\n";
$logs = think\facade\Db::name('ai_request_logs')
    ->where('source', 'episode_workflow')
    ->order('id', 'desc')
    ->limit(12)
    ->select()
    ->toArray();
foreach ($logs as $l) {
    $req = $l['request_json'] ?? null;
    if (is_array($req)) {
        $req = json_encode($req, JSON_UNESCAPED_UNICODE);
    }
    $usage = $l['usage_json'] ?? null;
    if (is_array($usage)) {
        $usage = json_encode($usage, JSON_UNESCAPED_UNICODE);
    }
    $hasThinking = is_string($req) && str_contains($req, 'thinking');
    $mt = 0;
    if (is_string($req)) {
        $decoded = json_decode($req, true);
        $mt = (int) ($decoded['max_tokens'] ?? $l['max_tokens'] ?? 0);
    }
    echo 'log|' . $l['id']
        . '|ok=' . ($l['request_ok'] ?? '')
        . '|http=' . ($l['http_status'] ?? '')
        . '|mt=' . ($l['max_tokens'] ?? $mt)
        . '|ms=' . ($l['duration_ms'] ?? '')
        . '|thinking_in_req=' . ($hasThinking ? 'yes' : 'no')
        . '|err=' . mb_substr((string) ($l['error_message'] ?? ''), 0, 160)
        . '|prev=' . mb_substr((string) ($l['content_preview'] ?? ''), 0, 80)
        . '|usage=' . mb_substr((string) $usage, 0, 220)
        . '|' . ($l['create_time'] ?? '') . PHP_EOL;
}

echo "=== model options ===\n";
$models = think\facade\Db::name('model_configs')->where('type', 'text')->select()->toArray();
foreach ($models as $m) {
    $opts = $m['options'];
    if (is_array($opts)) {
        $opts = json_encode($opts, JSON_UNESCAPED_UNICODE);
    }
    echo 'model|' . $m['id'] . '|' . $m['name'] . '|' . $opts . PHP_EOL;
}
