<?php

/**
 * 探测：ToAPIs 虚拟人像 asset:// 能否作为官方 Seedance 2.0（速创 / Ark）参考图。
 *
 * 用法（容器内）：
 *   php scripts/probe_official_seedance2_asset_uri.php
 *
 * 步骤：
 *   1. 读取速创默认视频模型（优先 model_configs.id=59）
 *   2. 先用 asset://<ASSET_ID> 提交官方 Ark
 *   3. 若提交或任务因 URI 被拒，再用公网 HTTPS 源图重试同一官方接口
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = new think\App();
$app->initialize();

use think\facade\Db;

$assetUri = trim((string) (getenv('PROBE_ASSET_URI') ?: 'asset://pa_01M1DF49WNX2Y4VXS7HGJ1XQBB'));
$httpsUrl = trim((string) (getenv('PROBE_IMAGE_URL') ?: 'https://xunboo.net/storage/generated/assets/u21_test1/s146_%E6%A1%83%E5%9B%AD%E4%B8%89%E7%BB%93%E4%B9%89/20260831/asset_image-asset922_%E5%88%98%E5%A4%87-main-job984-d78c3854.png'));
$modelIdHint = (int) (getenv('PROBE_MODEL_CONFIG_ID') ?: 59);
$pollSeconds = max(30, min(300, (int) (getenv('PROBE_POLL_SECONDS') ?: 180)));
$prompt = trim((string) (getenv('PROBE_PROMPT') ?: '让图片1中的角色在纯白棚拍背景前缓慢转身，镜头轻微推进，动作自然，不要其他人，不要字幕。'));

function probeHttp(string $method, string $url, string $apiKey, ?array $payload = null, int $timeout = 60): array
{
    $headers = [
        'Authorization: Bearer ' . $apiKey,
        'Accept: application/json',
    ];
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'http' => 0, 'errno' => -1, 'error' => 'curl_init failed', 'body' => '', 'json' => null];
    }
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
    ];
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = (string) curl_error($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $raw = is_string($body) ? $body : '';
    $json = json_decode($raw, true);

    return [
        'ok' => $errno === 0 && $http >= 200 && $http < 300,
        'http' => $http,
        'errno' => $errno,
        'error' => $error,
        'body' => $raw,
        'json' => is_array($json) ? $json : null,
    ];
}

function clip(string $text, int $limit = 1800): string
{
    if (strlen($text) <= $limit) {
        return $text;
    }
    return substr($text, 0, $limit) . '...';
}

function looksLikeUriReject(array $res): bool
{
    $blob = strtolower($res['body'] . ' ' . json_encode($res['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $needles = [
        'asset://',
        'invalid url',
        'invalid_image',
        'image_url',
        'unsupported url',
        'unsupported scheme',
        'scheme',
        'asset_id',
        'not found',
        'does not exist',
        'illegal',
        'malformed',
        'url format',
        'invalid parameter',
        'invalidargument',
        'content.1',
        'image url',
    ];
    foreach ($needles as $needle) {
        if (str_contains($blob, $needle)) {
            return true;
        }
    }
    return $res['http'] >= 400 && $res['http'] < 500;
}

function extractTaskId(array $decoded): string
{
    foreach (['id', 'task_id', 'request_id'] as $key) {
        $value = trim((string) ($decoded[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    foreach (['data', 'output', 'task'] as $wrap) {
        if (!is_array($decoded[$wrap] ?? null)) {
            continue;
        }
        foreach (['id', 'task_id', 'taskId', 'request_id'] as $key) {
            $value = trim((string) ($decoded[$wrap][$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
    }
    return '';
}

function extractStatus(array $decoded): string
{
    return strtolower(trim((string) (
        $decoded['status']
        ?? $decoded['task']['status']
        ?? $decoded['data']['status']
        ?? ''
    )));
}

function extractError(array $decoded): string
{
    $parts = [
        $decoded['error']['message'] ?? null,
        $decoded['error']['code'] ?? null,
        $decoded['message'] ?? null,
        $decoded['msg'] ?? null,
        $decoded['task']['error']['message'] ?? null,
        $decoded['data']['message'] ?? null,
        $decoded['data']['failReason'] ?? null,
    ];
    $out = [];
    foreach ($parts as $part) {
        $part = trim((string) $part);
        if ($part !== '' && !in_array($part, $out, true)) {
            $out[] = $part;
        }
    }
    return implode(' | ', $out);
}

function extractVideoUrl(array $decoded): string
{
    $stack = [$decoded];
    while ($stack !== []) {
        $node = array_pop($stack);
        if (!is_array($node)) {
            continue;
        }
        foreach ($node as $value) {
            if (is_string($value) && preg_match('#^https?://\S+\.(mp4|mov|webm)(\?|$)#i', $value)) {
                return trim($value);
            }
            if (is_string($value) && preg_match('#^https?://\S+#i', $value) && str_contains(strtolower($value), 'video')) {
                return trim($value);
            }
            if (is_array($value)) {
                $stack[] = $value;
            }
        }
    }
    $candidates = [
        $decoded['content']['video_url'] ?? null,
        $decoded['content']['url'] ?? null,
        $decoded['output'] ?? null,
        $decoded['url'] ?? null,
        $decoded['video_url'] ?? null,
    ];
    foreach ($candidates as $candidate) {
        if (is_string($candidate) && preg_match('#^https?://#i', $candidate)) {
            return trim($candidate);
        }
    }
    return '';
}

function buildArkPayload(string $modelId, string $prompt, string $imageUri): array
{
    return [
        'model' => $modelId,
        'content' => [
            [
                'type' => 'text',
                'text' => $prompt,
            ],
            [
                'type' => 'image_url',
                'image_url' => ['url' => $imageUri],
                'role' => 'reference_image',
            ],
        ],
        'duration' => 5,
        'resolution' => '480p',
        'ratio' => '16:9',
        'generate_audio' => false,
        'watermark' => false,
    ];
}

function submitAndPoll(string $endpoint, string $apiKey, array $payload, int $pollSeconds): array
{
    echo "SUBMIT image_uri=" . ($payload['content'][1]['image_url']['url'] ?? '') . "\n";
    $submit = probeHttp('POST', $endpoint, $apiKey, $payload, 60);
    echo 'submit_http=' . $submit['http'] . ' errno=' . $submit['errno'] . ($submit['error'] !== '' ? ' error=' . $submit['error'] : '') . "\n";
    echo clip($submit['body']) . "\n\n";
    if (!$submit['ok']) {
        return [
            'ok' => false,
            'stage' => 'submit',
            'submit' => $submit,
            'task_id' => '',
            'status' => '',
            'video_url' => '',
            'error' => extractError($submit['json'] ?? []) ?: ('HTTP ' . $submit['http']),
        ];
    }

    $taskId = extractTaskId($submit['json'] ?? []);
    $videoUrl = extractVideoUrl($submit['json'] ?? []);
    $status = extractStatus($submit['json'] ?? []);
    if ($videoUrl !== '') {
        return [
            'ok' => true,
            'stage' => 'submit',
            'submit' => $submit,
            'task_id' => $taskId,
            'status' => $status !== '' ? $status : 'succeeded',
            'video_url' => $videoUrl,
            'error' => '',
        ];
    }
    if ($taskId === '') {
        return [
            'ok' => false,
            'stage' => 'submit',
            'submit' => $submit,
            'task_id' => '',
            'status' => $status,
            'video_url' => '',
            'error' => 'no task id',
        ];
    }

    $pollUrl = rtrim($endpoint, '/') . '/' . rawurlencode($taskId);
    $deadline = time() + $pollSeconds;
    $attempt = 0;
    $last = $submit;
    while (time() <= $deadline) {
        $attempt++;
        sleep(5);
        $last = probeHttp('GET', $pollUrl, $apiKey, null, 45);
        $decoded = $last['json'] ?? [];
        $status = extractStatus($decoded);
        $videoUrl = extractVideoUrl($decoded);
        $err = extractError($decoded);
        echo "poll#{$attempt} http={$last['http']} status={$status} video=" . ($videoUrl !== '' ? 'yes' : 'no') . ($err !== '' ? " err={$err}" : '') . "\n";
        if ($videoUrl !== '') {
            echo clip($last['body']) . "\n\n";
            return [
                'ok' => true,
                'stage' => 'poll',
                'submit' => $submit,
                'task_id' => $taskId,
                'status' => $status !== '' ? $status : 'succeeded',
                'video_url' => $videoUrl,
                'error' => '',
            ];
        }
        if (in_array($status, ['failed', 'error', 'expired', 'canceled', 'cancelled'], true)) {
            echo clip($last['body']) . "\n\n";
            return [
                'ok' => false,
                'stage' => 'poll',
                'submit' => $submit,
                'task_id' => $taskId,
                'status' => $status,
                'video_url' => '',
                'error' => $err !== '' ? $err : $status,
            ];
        }
    }

    echo clip((string) $last['body']) . "\n\n";
    return [
        'ok' => false,
        'stage' => 'timeout',
        'submit' => $submit,
        'task_id' => $taskId,
        'status' => $status,
        'video_url' => '',
        'error' => 'poll timeout',
    ];
}

$row = Db::name('model_configs')->where('id', $modelIdHint)->find();
if (!$row) {
    $row = Db::name('model_configs')
        ->where('type', 'video')
        ->where('enabled', 1)
        ->order('is_default', 'desc')
        ->order('id', 'desc')
        ->find();
}
if (!$row) {
    fwrite(STDERR, "official video model missing\n");
    exit(2);
}

$endpoint = rtrim(trim((string) ($row['endpoint'] ?? '')), '/');
$modelName = trim((string) ($row['model_id'] ?? ''));
$apiKey = trim((string) ($row['api_key'] ?? ''));
$options = $row['options'] ?? [];
if (is_string($options)) {
    $options = json_decode($options, true);
}
if (!is_array($options)) {
    $options = [];
}
if ($endpoint !== '' && !str_contains($endpoint, '/contents/generations/tasks')) {
    $endpoint .= '/contents/generations/tasks';
}

echo 'model_config_id=' . ($row['id'] ?? '') . "\n";
echo 'model_name=' . ($row['name'] ?? '') . "\n";
echo 'model_id=' . $modelName . "\n";
echo 'provider=' . ($row['provider'] ?? ($options['provider'] ?? '')) . "\n";
echo 'endpoint=' . $endpoint . "\n";
echo 'key_prefix=' . substr($apiKey, 0, 8) . "\n";
echo 'key_len=' . strlen($apiKey) . "\n";
echo 'asset_uri=' . $assetUri . "\n";
echo 'https_url=' . $httpsUrl . "\n\n";

if ($endpoint === '' || $modelName === '' || $apiKey === '') {
    fwrite(STDERR, "official model endpoint/model_id/api_key missing\n");
    exit(3);
}

$first = submitAndPoll($endpoint, $apiKey, buildArkPayload($modelName, $prompt, $assetUri), $pollSeconds);
echo "RESULT_FIRST ok=" . ($first['ok'] ? 'yes' : 'no')
    . ' stage=' . $first['stage']
    . ' task_id=' . $first['task_id']
    . ' status=' . $first['status']
    . ' error=' . $first['error']
    . ' video=' . $first['video_url'] . "\n\n";

if ($first['ok']) {
    echo "PASS used_uri={$assetUri}\n";
    echo "PASS video_url={$first['video_url']}\n";
    exit(0);
}

$shouldFallback = $first['stage'] === 'submit'
    ? looksLikeUriReject($first['submit'])
    : (bool) preg_match('/asset:\/\/|url|image|scheme|illegal|invalid|not found/i', $first['error'] . ' ' . ($first['submit']['body'] ?? ''));

if (!$shouldFallback) {
    fwrite(STDERR, "official asset:// request failed without a clear URI reject; skip HTTPS fallback\n");
    exit(8);
}

echo "FALLBACK to HTTPS source image\n\n";
$second = submitAndPoll($endpoint, $apiKey, buildArkPayload($modelName, $prompt, $httpsUrl), $pollSeconds);
echo "RESULT_FALLBACK ok=" . ($second['ok'] ? 'yes' : 'no')
    . ' stage=' . $second['stage']
    . ' task_id=' . $second['task_id']
    . ' status=' . $second['status']
    . ' error=' . $second['error']
    . ' video=' . $second['video_url'] . "\n\n";

if ($second['ok']) {
    echo "PASS used_uri={$httpsUrl}\n";
    echo "PASS video_url={$second['video_url']}\n";
    echo "NOTE asset:// was rejected by official Ark; HTTPS source image succeeded.\n";
    exit(0);
}

fwrite(STDERR, "both asset:// and HTTPS official requests failed\n");
exit(9);
