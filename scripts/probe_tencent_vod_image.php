<?php

declare(strict_types=1);

/**
 * 腾讯云点播 AIGC 生图探活：创建任务 → 轮询 →（可选）写入/切换 model_configs。
 *
 * 用法：
 *   php scripts/probe_tencent_vod_image.php
 *   php scripts/probe_tencent_vod_image.php --apply-model
 */

require __DIR__ . '/../vendor/autoload.php';

$app = new think\App();
$app->initialize();

use app\model\ModelConfig;
use app\support\TencentVodAigcImageClient;
use think\facade\Db;

$applyModel = in_array('--apply-model', $argv ?? [], true);

$txtPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '腾讯.txt';
if (!is_file($txtPath)) {
    fwrite(STDERR, "missing 腾讯.txt\n");
    exit(1);
}
$raw = (string) file_get_contents($txtPath);
$subAppId = 0;
$secretId = '';
$secretKey = '';
if (preg_match('/subAppId\s*:\s*(\d+)/i', $raw, $m)) {
    $subAppId = (int) $m[1];
}
if (preg_match('/SecretId\s*\n\s*([A-Z0-9]+)/i', $raw, $m)) {
    $secretId = trim($m[1]);
}
if (preg_match('/SecretKey\s*\n\s*([A-Za-z0-9]+)/i', $raw, $m)) {
    $secretKey = trim($m[1]);
}

echo "subAppId={$subAppId}\n";
echo 'secretId=' . substr($secretId, 0, 8) . "...\n";
echo 'secretKey=' . (strlen($secretKey) > 0 ? 'set(' . strlen($secretKey) . ')' : 'missing') . "\n";

if ($subAppId <= 0 || $secretId === '' || $secretKey === '') {
    fwrite(STDERR, "failed to parse credentials from 腾讯.txt\n");
    exit(1);
}

$client = new TencentVodAigcImageClient();
$options = [
    'sub_app_id' => $subAppId,
    'model_name' => 'OG',
    'model_version' => 'image2_low',
    'quality' => 'low',
    'aspect_ratio' => '1:1',
    'storage_mode' => 'Temporary',
    'poll_interval' => 3,
    'poll_attempts' => 40,
];

$prompt = '一只橙色小猫坐在窗台上，阳光柔和，写实摄影，测试图';
echo "creating task...\n";
$created = $client->createTask($secretId, $secretKey, $prompt, $options);
$taskId = $created['task_id'];
echo "task_id={$taskId}\n";
echo "request_id=" . ($created['request_id'] ?? '') . "\n";

$imageUrl = '';
$message = '';
for ($i = 1; $i <= 40; $i++) {
    sleep(3);
    $detail = $client->describeTask($secretId, $secretKey, $taskId, $subAppId);
    $status = $detail['status'];
    echo "poll #{$i} status={$status} url=" . ($detail['image_url'] !== '' ? 'yes' : 'no') . "\n";
    if ($detail['finished'] && $detail['image_url'] !== '') {
        $imageUrl = $detail['image_url'];
        break;
    }
    if ($detail['finished']) {
        $message = $detail['message'];
        break;
    }
}

if ($imageUrl === '') {
    fwrite(STDERR, "PROBE FAIL: " . ($message !== '' ? $message : 'timeout/no url') . "\n");
    if (!empty($detail['raw'])) {
        echo substr((string) $detail['raw'], 0, 1200) . "\n";
    }
    exit(2);
}

echo "PROBE PASS\n";
echo "image_url={$imageUrl}\n";

if (!$applyModel) {
    echo "skip model apply (pass --apply-model to switch default image model)\n";
    exit(0);
}

$optionsJson = [
    'provider' => 'tencent_vod',
    'sub_app_id' => $subAppId,
    'secret_id' => $secretId,
    'model_name' => 'OG',
    'model_version' => 'image2_high',
    'quality' => 'high',
    'n' => 1,
    'size' => '16:9',
    'aspect_ratio' => '16:9',
    'resolution' => '1K',
    'storage_mode' => 'Temporary',
    'poll_interval' => 3,
    'poll_attempts' => 80,
];

Db::transaction(function () use ($secretKey, $optionsJson): void {
    Db::name('model_configs')->where('type', 'image')->update(['is_default' => 0]);

    $existing = Db::name('model_configs')
        ->where('type', 'image')
        ->where('model_id', 'OG/image2')
        ->find();

    $row = [
        'user_id' => 1,
        'scope' => 'global',
        'type' => 'image',
        'name' => 'Tencent OG image2',
        'model_id' => 'OG/image2',
        'endpoint' => 'https://vod.tencentcloudapi.com',
        'api_key' => $secretKey,
        'is_default' => 1,
        'enabled' => 1,
        'options' => json_encode($optionsJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'sort' => 0,
        'update_time' => date('Y-m-d H:i:s'),
    ];

    if ($existing) {
        Db::name('model_configs')->where('id', (int) $existing['id'])->update($row);
        echo 'updated model_configs id=' . (int) $existing['id'] . "\n";
    } else {
        $row['create_time'] = date('Y-m-d H:i:s');
        $id = Db::name('model_configs')->insertGetId($row);
        echo "inserted model_configs id={$id}\n";
    }
});

echo "default image model switched to Tencent OG image2\n";
