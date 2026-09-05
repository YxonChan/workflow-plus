<?php

declare(strict_types=1);

/**
 * Reproduce cancel image jobs response encoding.
 *
 * Usage:
 *   docker compose run --rm --no-deps php php scripts/probe_cancel_image_jobs_api.php [series_id] [user_id]
 */

require __DIR__ . '/../vendor/autoload.php';

$app = new think\App();
$app->initialize();

use app\model\Asset;
use app\model\AssetImageJob;
use app\support\ImageJobStatus;
use app\support\MessageLocalizer;
use app\support\WorkerActions;
use think\facade\Db;

$seriesId = (int) ($argv[1] ?? 0);
$userId = (int) ($argv[2] ?? 0);

if ($userId <= 0) {
    $userId = (int) (Db::name('assets')-> wheremax? 0 : 0);
}

if ($userId <= 0) {
    $row = Db::query('SELECT user_id, series_id, COUNT(*) c FROM assets GROUP BY user_id, series_id ORDER BY c DESC LIMIT 1');
    $userId = (int) ($row[0]['user_id'] ?? 0);
    if ($seriesId <= 0) {
        $seriesId = (int) ($row[0]['series_id'] ?? 0);
    }
}

if ($seriesId <= 0) {
    $seriesId = (int) (Db::name('assets')->where('user_id', $userId)->order('id', 'desc')->value('series_id') ?: 0);
}

echo "user_id={$userId} series_id={$seriesId}\n";

$queued = AssetImageJob::where('user_id', $userId)
    ->where('status', ImageJobStatus::QUEUED)
    ->limit(5)
    ->select();
echo 'queued_sample=' . count($queued) . PHP_EOL;
foreach ($queued as $job) {
    if (!$job instanceof AssetImageJob) {
        continue;
    }
    $err = (string) $job->getAttr('error_message');
    $prompt = (string) $job->getAttr('prompt');
    $final = (string) $job->getAttr('final_prompt');
    echo 'job#' . $job->getAttr('id')
        . ' asset=' . $job->getAttr('asset_id')
        . ' err_utf8=' . (mb_check_encoding($err, 'UTF-8') ? 'yes' : 'NO')
        . ' prompt_utf8=' . (mb_check_encoding($prompt, 'UTF-8') ? 'yes' : 'NO')
        . ' final_utf8=' . (mb_check_encoding($final, 'UTF-8') ? 'yes' : 'NO')
        . PHP_EOL;
}

$assets = Asset::where('user_id', $userId)->where('series_id', $seriesId)->limit(20)->select();
foreach ($assets as $asset) {
    if (!$asset instanceof Asset) {
        continue;
    }
    $name = (string) $asset->getAttr('name');
    $desc = (string) ($asset->getAttr('description') ?? '');
    $ip = (string) ($asset->getAttr('image_prompt') ?? '');
    $bad = [];
    if (!mb_check_encoding($name, 'UTF-8')) $bad[] = 'name';
    if (!mb_check_encoding($desc, 'UTF-8')) $bad[] = 'description';
    if (!mb_check_encoding($ip, 'UTF-8')) $bad[] = 'image_prompt';
    if ($bad !== []) {
        echo 'BAD_ASSET#' . $asset->getAttr('id') . ' fields=' . implode(',', $bad) . PHP_EOL;
    }
}

$messages = [
    '任务已由用户取消',
    '已取消 3 个排队任务',
    '没有可取消的排队任务',
];
foreach ($messages as $message) {
    $translated = MessageLocalizer::translate($message);
    $payload = [
        'code' => 0,
        'message' => $translated,
        'data' => ['cancelled' => 3, 'series_id' => $seriesId, 'asset_id' => 0],
    ];
    try {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        echo 'encode_ok message=' . $message . ' bytes=' . strlen($json) . PHP_EOL;
    } catch (Throwable $e) {
        echo 'encode_FAIL message=' . $message . ' err=' . $e->getMessage() . PHP_EOL;
    }
}

// Dry-run cancel of zero jobs with the same code path values.
$count = WorkerActions::cancelQueuedImageJobs($userId, $seriesId, 0, [999999999], 0, '任务已由用户取消');
echo "dry_cancel_count={$count}\n";

$msg = $count > 0 ? "已取消 {$count} 个排队任务" : '没有可取消的排队任务';
try {
    $json = json_encode([
        'code' => 0,
        'message' => MessageLocalizer::translate($msg),
        'data' => ['cancelled' => $count, 'series_id' => $seriesId, 'asset_id' => 0],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    echo "response_ok={$json}\n";
} catch (Throwable $e) {
    echo 'response_FAIL=' . $e->getMessage() . PHP_EOL;
}
