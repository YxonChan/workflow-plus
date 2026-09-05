<?php

declare(strict_types=1);

/**
 * Recover asset image jobs whose provider task already completed but app DB was not updated.
 *
 * Usage:
 *   php scripts/recover_completed_image_jobs.php [series_id]
 *   php scripts/recover_completed_image_jobs.php 121
 */

require __DIR__ . '/../vendor/autoload.php';

use app\controller\AssetController;
use app\model\AssetImageJob;
use app\support\ImageProviderTaskState;
use think\facade\Db;

$app = new think\App();
$app->initialize();

$seriesId = (int) ($argv[1] ?? 0);
$assetIds = [];
if ($seriesId > 0) {
    $assetIds = Db::name('assets')->where('series_id', $seriesId)->column('id');
}

$query = Db::name('asset_image_jobs')->order('id', 'asc');
if ($assetIds !== []) {
    $query->whereIn('asset_id', $assetIds);
} else {
    $query->where('id', '>=', max(1, (int) Db::name('asset_image_jobs')->max('id') - 50));
}
$jobs = $query->select()->toArray();

$model = Db::name('model_configs')->where('type', 'image')->where('enabled', 1)->order('is_default', 'desc')->find();
if (!$model) {
    fwrite(STDERR, "no image model\n");
    exit(1);
}
$apiKey = trim((string) ($model['api_key'] ?? ''));
$endpoint = trim((string) ($model['endpoint'] ?? ''));
if ($apiKey === '' || $endpoint === '') {
    fwrite(STDERR, "image model missing key/endpoint\n");
    exit(1);
}

/** @var AssetController $controller */
$controller = app()->make(AssetController::class);
$ref = new ReflectionClass($controller);
$persist = $ref->getMethod('persistGeneratedImageUrl');
$persist->setAccessible(true);
$upsert = $ref->getMethod('upsertGeneratedAssetImage');
$upsert->setAccessible(true);
$runtime = $ref->getProperty('runtimeUserId');
$runtime->setAccessible(true);

function resolveTaskStatusUrl(string $submitEndpoint, string $taskId): string
{
    if (preg_match('#^(.*/v1)/images/(?:generations|edits)(?:/.*)?$#', $submitEndpoint, $m) === 1) {
        return rtrim($m[1], '/') . '/images/generations/' . rawurlencode($taskId);
    }

    return rtrim($submitEndpoint, '/') . '/' . rawurlencode($taskId);
}

function findTaskId(array $job): string
{
    $fromJob = ImageProviderTaskState::extractTaskId((string) ($job['error_message'] ?? ''));
    if ($fromJob !== '') {
        return $fromJob;
    }
    $logs = Db::name('ai_request_logs')
        ->where('source', 'image_generation')
        ->whereLike('context_json', '%"job_id":' . (int) $job['id'] . '%')
        ->order('id', 'desc')
        ->limit(10)
        ->column('error_message');
    foreach ($logs as $message) {
        $taskId = ImageProviderTaskState::extractTaskId((string) $message);
        if ($taskId !== '') {
            return $taskId;
        }
    }

    return '';
}

$recovered = 0;
$pending = 0;
$failed = 0;
$skipped = 0;

foreach ($jobs as $job) {
    $jobId = (int) $job['id'];
    $status = (string) ($job['status'] ?? '');
    if ($status === 'success' && trim((string) ($job['result_url'] ?? '')) !== '') {
        $skipped++;
        continue;
    }

    $taskId = findTaskId($job);
    if ($taskId === '') {
        echo "skip|{$jobId}|no_task|status={$status}\n";
        $skipped++;
        continue;
    }

    $url = resolveTaskStatusUrl($endpoint, $taskId);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 45,
    ]);
    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = is_string($body) ? json_decode($body, true) : null;
    $provStatus = is_array($decoded) ? strtolower((string) ($decoded['status'] ?? '')) : '';
    $remoteUrl = '';
    if (is_array($decoded)) {
        $remoteUrl = trim((string) ($decoded['result']['data'][0]['url'] ?? $decoded['data'][0]['url'] ?? $decoded['url'] ?? ''));
    }

    echo "probe|{$jobId}|db={$status}|task={$taskId}|http={$http}|prov={$provStatus}|has_url=" . ($remoteUrl !== '' ? '1' : '0') . PHP_EOL;

    if ($provStatus === 'completed' && $remoteUrl !== '') {
        $userId = (int) ($job['user_id'] ?? 0) ?: 1;
        $runtime->setValue($controller, $userId);
        $assetId = (int) ($job['asset_id'] ?? 0);
        $assetImageId = (int) ($job['asset_image_id'] ?? 0);
        $localUrl = (string) $persist->invoke($controller, $remoteUrl, [
            'asset_id' => $assetId,
            'asset_image_id' => $assetImageId,
            'view_type' => (string) ($job['view_type'] ?? 'main'),
            'job_id' => $jobId,
            'user_id' => $userId,
            'source' => 'generated',
        ]);
        $upsert->invoke(
            $controller,
            $assetId,
            $assetImageId > 0 ? $assetImageId : null,
            (string) ($job['view_type'] ?? 'main'),
            $localUrl,
            (string) ($job['prompt'] ?? ''),
            [
                'model_config_id' => (int) ($job['model_config_id'] ?? 0),
                'job_id' => $jobId,
                'source' => 'generated',
                'is_selected' => 1,
                'meta_json' => ['recovered_from_provider' => 1, 'provider_task_id' => $taskId],
            ]
        );
        AssetImageJob::where('id', $jobId)->update([
            'status' => 'success',
            'result_url' => $localUrl,
            'error_message' => '',
            'finished_at' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s'),
        ]);
        echo "recovered|{$jobId}|{$localUrl}\n";
        $recovered++;
        continue;
    }

    if (in_array($provStatus, ['queued', 'in_progress'], true)) {
        AssetImageJob::where('id', $jobId)->update([
            'status' => 'queued',
            'error_message' => ImageProviderTaskState::pendingMessage($taskId),
            'started_at' => null,
            'finished_at' => null,
            'update_time' => date('Y-m-d H:i:s'),
        ]);
        echo "requeue_pending|{$jobId}|{$taskId}\n";
        $pending++;
        continue;
    }

    if ($provStatus !== '' || $http > 0) {
        echo "leave|{$jobId}|prov={$provStatus}\n";
        $failed++;
    }
}

echo "done|recovered={$recovered}|requeued_pending={$pending}|left={$failed}|skipped={$skipped}\n";
