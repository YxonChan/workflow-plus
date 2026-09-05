<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = new think\App();
$app->initialize();

$cols = think\facade\Db::query('SHOW COLUMNS FROM series');
echo "=== series columns ===\n";
foreach ($cols as $c) {
    echo $c['Field'] . '|' . $c['Type'] . PHP_EOL;
}

echo "=== recent series region/style ===\n";
$seriesRows = think\facade\Db::name('series')
    ->order('id', 'desc')
    ->limit(25)
    ->select()
    ->toArray();
foreach ($seriesRows as $row) {
    $epCount = think\facade\Db::name('episodes')->where('series_id', (int) $row['id'])->count();
    echo 'series|' . $row['id']
        . '|' . mb_substr((string) ($row['title'] ?? ''), 0, 40)
        . '|region=' . ($row['region'] ?? '')
        . '|style=' . ($row['visual_style'] ?? '')
        . '|variant=' . ($row['visual_style_variant'] ?? '')
        . '|eps=' . $epCount
        . '|src_len=' . mb_strlen((string) ($row['source_text'] ?? ''))
        . '|' . ($row['create_time'] ?? '') . PHP_EOL;
}

echo "=== recent runs ===\n";
$runs = think\facade\Db::name('workflow_runs')
    ->order('id', 'desc')
    ->limit(20)
    ->select()
    ->toArray();
foreach ($runs as $run) {
    $payload = $run['payload_json'] ?? null;
    if (is_string($payload)) {
        $payload = json_decode($payload, true);
    }
    $srcLen = 0;
    $markers = 0;
    if (is_array($payload)) {
        $src = (string) ($payload['source_text'] ?? '');
        $srcLen = mb_strlen($src);
        preg_match_all('/^[ \t]*(?:(?:EPISODE|Episode|episode)[ \t]*([0-9一二三四五六七八九十百]+)|第[ \t]*([0-9一二三四五六七八九十百]+)[ \t]*集)/mu', $src, $m);
        $markers = count($m[0] ?? []);
    }
    $epCount = think\facade\Db::name('episodes')->where('series_id', (int) $run['series_id'])->count();
    echo 'run|' . $run['id']
        . '|series=' . $run['series_id']
        . '|status=' . ($run['status'] ?? '')
        . '|target=' . ($run['target_episode_count'] ?? '')
        . '|label=' . ($run['current_node_label'] ?? '')
        . '|payload_src_len=' . $srcLen
        . '|payload_markers=' . $markers
        . '|eps=' . $epCount
        . '|err=' . mb_substr((string) ($run['error_message'] ?? ''), 0, 100)
        . '|' . ($run['update_time'] ?? '') . PHP_EOL;
}

echo "=== storyboard language samples ===\n";
$logs = think\facade\Db::name('ai_request_logs')
    ->where('source', 'episode_workflow')
    ->order('id', 'desc')
    ->limit(40)
    ->select()
    ->toArray();
foreach ($logs as $log) {
    $ctx = $log['context_json'] ?? null;
    if (is_string($ctx)) {
        $ctx = json_decode($ctx, true);
    }
    if (!is_array($ctx)) {
        $ctx = [];
    }
    $label = (string) ($ctx['node_label'] ?? '');
    if ($label !== '' && !str_contains($label, '分镜') && !str_contains(strtolower($label), 'storyboard') && !str_contains($label, '宣传片')) {
        continue;
    }
    $req = $log['request_json'] ?? null;
    if (is_string($req)) {
        $req = json_decode($req, true);
    }
    $system = '';
    if (is_array($req) && isset($req['messages'][0]['content'])) {
        $system = (string) $req['messages'][0]['content'];
    }
    $preview = (string) ($log['content_preview'] ?? '');
    $hasCjk = (bool) preg_match('/[\x{4e00}-\x{9fff}]/u', $preview);
    $hasLatin = (bool) preg_match('/[A-Za-z]{12,}/', $preview);
    $sysHasWesternDefault = str_contains($system, '默认使用英文') || str_contains($system, 'must use English') || str_contains($system, '输出语言必须使用英文');
    $sysHasChineseDefault = str_contains($system, '默认使用中文') || str_contains($system, '输出语言必须使用中文');
    $regionInReq = '';
    if (preg_match('/"content_region"\s*:\s*"([^"]+)"/', json_encode($req, JSON_UNESCAPED_UNICODE) ?: '', $mm)) {
        $regionInReq = $mm[1];
    }
    echo 'sb|' . $log['id']
        . '|run=' . ($log['workflow_run_id'] ?? '')
        . '|series=' . ($ctx['series_id'] ?? '')
        . '|ep=' . ($ctx['episode_id'] ?? '')
        . '|label=' . $label
        . '|ok=' . ($log['request_ok'] ?? '')
        . '|cjk=' . ($hasCjk ? '1' : '0')
        . '|latin=' . ($hasLatin ? '1' : '0')
        . '|sys_en=' . ($sysHasWesternDefault ? '1' : '0')
        . '|sys_zh=' . ($sysHasChineseDefault ? '1' : '0')
        . '|region_hint=' . $regionInReq
        . '|prev=' . mb_substr(preg_replace('/\s+/', ' ', $preview) ?? '', 0, 90)
        . '|' . ($log['create_time'] ?? '') . PHP_EOL;
}

echo "=== series source_text marker audit ===\n";
foreach ($seriesRows as $row) {
    $text = trim((string) ($row['source_text'] ?? ''));
    if ($text === '') {
        continue;
    }
    preg_match_all(
        '/^[ \t]*(?:(?:EPISODE|Episode|episode)[ \t]*([0-9一二三四五六七八九十百]+)|第[ \t]*([0-9一二三四五六七八九十百]+)[ \t]*集)/mu',
        $text,
        $m
    );
    $markers = $m[0] ?? [];
    $epReal = think\facade\Db::name('episodes')->where('series_id', (int) $row['id'])->count();
    echo 'src|' . $row['id']
        . '|' . mb_substr((string) ($row['title'] ?? ''), 0, 30)
        . '|region=' . ($row['region'] ?? '')
        . '|markers=' . count($markers)
        . '|sample_markers=' . mb_substr(implode(' || ', array_slice($markers, 0, 5)), 0, 120)
        . '|eps=' . $epReal
        . '|len=' . mb_strlen($text)
        . PHP_EOL;
}
