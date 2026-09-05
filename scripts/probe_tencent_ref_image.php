<?php

declare(strict_types=1);

/**
 * 对照官方文档直接验证 CreateAigcImageTask 参考图参数。
 *
 * 文档：https://cloud.tencent.com/document/product/266/126240
 * 输入结构：AigcImageTaskInputFileInfo = Type + Url|FileId|Base64
 * 输出结构才有 FileUrl。
 */

require __DIR__ . '/../vendor/autoload.php';

$app = new think\App();
$app->initialize();

use app\model\ModelConfig;
use app\support\TencentVodAigcImageClient;

$refUrl = $argv[1] ?? 'https://fastly.picsum.photos/id/237/640/480.jpg?hmac=WUvxmj5giHkcHhGASm19RfCwScxNCvBvlABvSbbeNYQ';
$mode = $argv[2] ?? 'url'; // url | fileurl | base64 | none

$model = ModelConfig::where('type', 'image')->where('enabled', 1)->order('is_default', 'desc')->find();
if (!$model instanceof ModelConfig) {
    fwrite(STDERR, "no enabled image model\n");
    exit(1);
}

$options = $model->getAttr('options');
if (is_string($options)) {
    $options = json_decode($options, true) ?: [];
}
if (!is_array($options)) {
    $options = [];
}

$secretId = trim((string) ($options['secret_id'] ?? ''));
$secretKey = trim((string) $model->getAttr('api_key'));
$subAppId = (int) ($options['sub_app_id'] ?? 0);

echo "model_id={$model->getAttr('id')} name={$model->getAttr('name')}\n";
echo "ModelName=" . ($options['model_name'] ?? '') . " ModelVersion=" . ($options['model_version'] ?? '') . "\n";
echo "refUrl={$refUrl}\n";
echo "mode={$mode}\n";

$client = new TencentVodAigcImageClient();
$prompt = '参考输入图片，生成同主体坐在窗台上的写实照片，柔和阳光，探活';

$payload = [
    'SubAppId' => $subAppId,
    'ModelName' => (string) ($options['model_name'] ?? 'OG'),
    'ModelVersion' => (string) ($options['model_version'] ?? 'image2_high'),
    'Prompt' => $prompt,
    'OutputConfig' => ['StorageMode' => 'Temporary'],
];

if ($mode === 'none') {
    // text-only baseline
} elseif ($mode === 'fileurl') {
    $payload['FileInfos'] = [[
        'Type' => 'Url',
        'FileUrl' => $refUrl,
    ]];
} elseif ($mode === 'base64') {
    $bin = file_get_contents($refUrl);
    if ($bin === false) {
        fwrite(STDERR, "download ref failed\n");
        exit(1);
    }
    $payload['FileInfos'] = [[
        'Type' => 'Base64',
        'Base64' => base64_encode($bin),
    ]];
} else {
    // official docs: Type=Url + Url
    $payload['FileInfos'] = [[
        'Type' => 'Url',
        'Url' => $refUrl,
    ]];
}

echo "payload=" . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

$ref = new ReflectionClass($client);
$method = $ref->getMethod('request');
$method->setAccessible(true);

try {
    $raw = $method->invoke($client, $secretId, $secretKey, 'CreateAigcImageTask', $payload);
    echo "RAW={$raw}\n";
    $decoded = json_decode($raw, true);
    if (isset($decoded['Response']['Error'])) {
        $err = $decoded['Response']['Error'];
        echo 'ERROR code=' . ($err['Code'] ?? '') . ' msg=' . ($err['Message'] ?? '') . "\n";
        exit(2);
    }
    $taskId = (string) ($decoded['Response']['TaskId'] ?? '');
    echo "TASK_OK task_id={$taskId}\n";

    for ($i = 1; $i <= 40; $i++) {
        sleep(3);
        $detail = $client->describeTask($secretId, $secretKey, $taskId, $subAppId);
        echo "poll#{$i} status={$detail['status']} finished=" . ($detail['finished'] ? '1' : '0')
            . ' url=' . ($detail['image_url'] !== '' ? 'yes' : 'no')
            . ' msg=' . $detail['message'] . "\n";
        if ($detail['finished']) {
            if ($detail['image_url'] !== '') {
                echo "PASS image_url={$detail['image_url']}\n";
                exit(0);
            }
            echo "FAIL finished without url\n";
            exit(3);
        }
    }
    echo "TIMEOUT\n";
    exit(4);
} catch (Throwable $e) {
    echo 'EXCEPTION ' . $e->getMessage() . "\n";
    exit(5);
}
