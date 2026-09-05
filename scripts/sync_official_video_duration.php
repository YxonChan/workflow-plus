<?php

declare(strict_types=1);

/**
 * Bump official/Seedance video model default duration from 5 → 15,
 * and set episode workflow video nodes to duration=auto (0) so storyboard wins.
 */

require __DIR__ . '/../vendor/autoload.php';

$app = new think\App();
$app->initialize();

$models = think\facade\Db::name('model_configs')
    ->where('type', 'video')
    ->select()
    ->toArray();

foreach ($models as $model) {
    $opts = $model['options'] ?? [];
    if (is_string($opts)) {
        $opts = json_decode($opts, true);
    }
    if (!is_array($opts)) {
        $opts = [];
    }
    $before = $opts['duration'] ?? null;
    $modelId = strtolower((string) ($model['model_id'] ?? ''));
    $endpoint = strtolower((string) ($model['endpoint'] ?? ''));
    $isOfficialSeedance = str_contains($modelId, 'seedance')
        || str_contains($endpoint, 'volces.com')
        || str_contains($endpoint, 'telecomjs.com')
        || (($opts['provider'] ?? '') === 'ark')
        || (($opts['provider'] ?? '') === 'yinhe_async');

    if ($isOfficialSeedance && (int) ($opts['duration'] ?? 0) > 0 && (int) $opts['duration'] < 15) {
        $opts['duration'] = 15;
        think\facade\Db::name('model_configs')->where('id', (int) $model['id'])->update([
            'options' => json_encode($opts, JSON_UNESCAPED_UNICODE),
            'update_time' => date('Y-m-d H:i:s'),
        ]);
        echo 'model|' . $model['id'] . '|' . $model['name'] . "|duration {$before} -> 15\n";
    } else {
        echo 'model|' . $model['id'] . '|' . $model['name'] . '|skip duration=' . json_encode($before) . PHP_EOL;
    }
}

$workflows = think\facade\Db::name('workflows')
    ->whereIn('scope', ['episode', 'series'])
    ->select()
    ->toArray();

$updatedWorkflows = 0;
foreach ($workflows as $workflow) {
    $graph = $workflow['graph'] ?? null;
    if (is_string($graph)) {
        $graph = json_decode($graph, true);
    }
    if (!is_array($graph) || !isset($graph['nodes']) || !is_array($graph['nodes'])) {
        continue;
    }
    $changed = false;
    foreach ($graph['nodes'] as &$node) {
        if (!is_array($node)) {
            continue;
        }
        $kind = (string) ($node['data']['kind'] ?? $node['kind'] ?? '');
        $label = (string) ($node['data']['label'] ?? $node['label'] ?? '');
        if ($kind !== 'video' && !str_contains($label, '视频生成')) {
            continue;
        }
        $params = $node['data']['params'] ?? null;
        if (!is_array($params)) {
            continue;
        }
        // Promo fixed 15s nodes keep duration=15; standard episode nodes become auto.
        $isPromo = str_contains($label, '宣传片') || str_contains((string) ($workflow['name'] ?? ''), '宣传');
        if ($isPromo) {
            if ((int) ($params['duration'] ?? 0) !== 15) {
                $params['duration'] = 15;
                $changed = true;
            }
        } else {
            $raw = $params['duration'] ?? null;
            if ($raw !== 0 && $raw !== '0' && $raw !== '' && strtolower((string) $raw) !== 'auto') {
                $params['duration'] = 0;
                $changed = true;
            }
        }
        $node['data']['params'] = $params;
    }
    unset($node);
    if ($changed) {
        think\facade\Db::name('workflows')->where('id', (int) $workflow['id'])->update([
            'graph' => json_encode($graph, JSON_UNESCAPED_UNICODE),
            'update_time' => date('Y-m-d H:i:s'),
        ]);
        $updatedWorkflows++;
        echo 'workflow|' . $workflow['id'] . '|' . ($workflow['name'] ?? '') . "|video duration -> auto\n";
    }
}

echo "updated_workflows={$updatedWorkflows}\n";
echo "DONE\n";
