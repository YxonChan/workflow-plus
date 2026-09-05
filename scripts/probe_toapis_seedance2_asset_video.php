<?php

/**
 * 用 ToAPIs Seedance 2 生成接口验证 asset:// 人像 URI。
 *
 * 用法（容器内，key 走环境变量，不要写进仓库）：
 *   TOAPIS_API_KEY=sk-xxx php scripts/probe_toapis_seedance2_asset_video.php
 */

declare(strict_types=1);

$apiKey = trim((string) getenv('TOAPIS_API_KEY'));
$base = rtrim(trim((string) (getenv('TOAPIS_BASE') ?: 'https://toapis.cn')), '/');
$assetId = trim((string) (getenv('TOAPIS_ASSET_ID') ?: 'pa_01M1DF49WNX2Y4VXS7HGJ1XQBB'));
$imageUrl = trim((string) (getenv('TOAPIS_IMAGE_URL') ?: 'https://xunboo.net/storage/generated/assets/u21_test1/s146_%E6%A1%83%E5%9B%AD%E4%B8%89%E7%BB%93%E4%B9%89/20260831/asset_image-asset922_%E5%88%98%E5%A4%87-main-job984-d78c3854.png'));
$model = trim((string) (getenv('TOAPIS_VIDEO_MODEL') ?: 'seedance-2'));
$pollSeconds = max(60, min(600, (int) (getenv('TOAPIS_POLL_SECONDS') ?: 360)));
$outDir = trim((string) (getenv('TOAPIS_OUT_DIR') ?: dirname(__DIR__) . DIRECTORY_SEPARATOR . 'generated-videos'));

if ($apiKey === '') {
    fwrite(STDERR, "TOAPIS_API_KEY is empty\n");
    exit(2);
}

function probeRequest(string $method, string $url, string $apiKey, ?array $payload = null, int $timeout = 60): array
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
    return strlen($text) > $limit ? substr($text, 0, $limit) . '...' : $text;
}

function printStep(string $title, array $res): void
{
    echo "===== {$title} =====\n";
    echo 'http=' . $res['http'] . ' errno=' . $res['errno'] . ($res['error'] !== '' ? ' error=' . $res['error'] : '') . "\n";
    echo clip($res['body']) . "\n\n";
}

function pick(array $json, string $key, string $fallback = ''): string
{
    $direct = trim((string) ($json[$key] ?? ''));
    if ($direct !== '') {
        return $direct;
    }
    $nested = trim((string) ($json['data'][$key] ?? ''));
    return $nested !== '' ? $nested : $fallback;
}

function ensureActiveAsset(string $base, string $apiKey, string $assetId, string $imageUrl): array
{
    if ($assetId !== '') {
        $query = probeRequest('GET', $base . '/v1/videos/doubao-seedance-2-0/private-avatar/assets/' . rawurlencode($assetId), $apiKey);
        printStep('get asset ' . $assetId, $query);
        $status = strtolower(pick($query['json'] ?? [], 'status'));
        $assetUrl = pick($query['json'] ?? [], 'asset_url', 'asset://' . $assetId);
        $groupId = pick($query['json'] ?? [], 'group_id');
        if ($query['ok'] && $status === 'active') {
            return [
                'asset_id' => pick($query['json'] ?? [], 'asset_id', $assetId),
                'asset_url' => $assetUrl,
                'group_id' => $groupId,
                'status' => $status,
            ];
        }
        echo "existing asset not active, re-upload\n\n";
    }

    $group = probeRequest('POST', $base . '/v1/videos/doubao-seedance-2-0/private-avatar/groups', $apiKey, [
        'name' => 'probe-liubei-video-' . date('YmdHis'),
        'description' => 'ToAPIs seedance-2 asset:// video probe',
    ]);
    printStep('create group', $group);
    $groupId = pick($group['json'] ?? [], 'group_id');
    if ($groupId === '') {
        fwrite(STDERR, "create group failed\n");
        exit(4);
    }

    $asset = probeRequest('POST', $base . '/v1/videos/doubao-seedance-2-0/private-avatar/assets', $apiKey, [
        'group_id' => $groupId,
        'asset_type' => 'image',
        'source_url' => $imageUrl,
        'name' => 'liubei-core-main',
        'description' => 'Liu Bei character core view probe',
    ]);
    printStep('upload asset', $asset);
    $newAssetId = pick($asset['json'] ?? [], 'asset_id');
    $assetUrl = pick($asset['json'] ?? [], 'asset_url', $newAssetId !== '' ? 'asset://' . $newAssetId : '');
    $status = strtolower(pick($asset['json'] ?? [], 'status'));
    if ($newAssetId === '') {
        fwrite(STDERR, "upload asset failed\n");
        exit(5);
    }

    $deadline = time() + 60;
    while (time() <= $deadline) {
        if (in_array($status, ['active', 'failed', 'error', 'rejected'], true)) {
            break;
        }
        sleep(3);
        $query = probeRequest('GET', $base . '/v1/videos/doubao-seedance-2-0/private-avatar/assets/' . rawurlencode($newAssetId), $apiKey);
        printStep('poll asset ' . $newAssetId, $query);
        $status = strtolower(pick($query['json'] ?? [], 'status'));
        $polledUrl = pick($query['json'] ?? [], 'asset_url');
        if ($polledUrl !== '') {
            $assetUrl = $polledUrl;
        }
    }
    if ($status !== 'active') {
        fwrite(STDERR, "asset not active: {$status}\n");
        exit(6);
    }

    return [
        'asset_id' => $newAssetId,
        'asset_url' => $assetUrl,
        'group_id' => $groupId,
        'status' => $status,
    ];
}

function extractTaskId(array $decoded): string
{
    foreach (['id', 'task_id'] as $key) {
        $value = trim((string) ($decoded[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    foreach (['data', 'task'] as $wrap) {
        if (!is_array($decoded[$wrap] ?? null)) {
            continue;
        }
        foreach (['id', 'task_id'] as $key) {
            $value = trim((string) ($decoded[$wrap][$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
    }
    return '';
}

function extractVideoUrl(array $decoded): string
{
    $url = trim((string) ($decoded['result']['data'][0]['url'] ?? ''));
    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }
    $stack = [$decoded];
    while ($stack !== []) {
        $node = array_pop($stack);
        if (!is_array($node)) {
            continue;
        }
        foreach ($node as $value) {
            if (is_string($value) && preg_match('#^https?://\S+\.mp4(\?|$)#i', $value)) {
                return trim($value);
            }
            if (is_array($value)) {
                $stack[] = $value;
            }
        }
    }
    return '';
}

function downloadVideo(string $url, string $outDir, string $taskId): string
{
    if (!is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
        return '';
    }
    $safeId = preg_replace('/[^A-Za-z0-9_-]+/', '_', $taskId) ?: 'video';
    $path = rtrim($outDir, '\\/') . DIRECTORY_SEPARATOR . 'toapis-seedance2-' . $safeId . '.mp4';
    $ch = curl_init($url);
    if ($ch === false) {
        return '';
    }
    $fp = fopen($path, 'wb');
    if ($fp === false) {
        curl_close($ch);
        return '';
    }
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_CONNECTTIMEOUT => 20,
    ]);
    $ok = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);
    if ($ok === false || $http < 200 || $http >= 300 || !is_file($path) || filesize($path) < 1024) {
        @unlink($path);
        return '';
    }
    return $path;
}

echo "base={$base}\n";
echo "model={$model}\n";
echo "asset_id={$assetId}\n";
echo "key_prefix=" . substr($apiKey, 0, 6) . "\n";
echo "key_len=" . strlen($apiKey) . "\n\n";

$asset = ensureActiveAsset($base, $apiKey, $assetId, $imageUrl);
$assetUrl = $asset['asset_url'] !== '' ? $asset['asset_url'] : ('asset://' . $asset['asset_id']);
echo "READY group_id={$asset['group_id']}\n";
echo "READY asset_id={$asset['asset_id']}\n";
echo "READY asset_url={$assetUrl}\n";
echo "READY status={$asset['status']}\n\n";

$payload = [
    'model' => $model,
    'prompt' => '让图片1中的角色在纯白棚拍背景前缓慢转身，镜头轻微推进，动作自然，不要其他人，不要字幕。',
    'duration' => 5,
    'aspect_ratio' => '16:9',
    'resolution' => '480p',
    'generate_audio' => false,
    'image_with_roles' => [
        [
            'url' => $assetUrl,
            'role' => 'reference_image',
        ],
    ],
];

$submit = probeRequest('POST', $base . '/v1/videos/generations', $apiKey, $payload, 60);
printStep('submit video model=' . $model, $submit);

if (!$submit['ok'] && in_array($submit['http'], [400, 404, 422], true) && $model !== 'doubao-seedance-2-0') {
    echo "retry with model=doubao-seedance-2-0\n\n";
    $payload['model'] = 'doubao-seedance-2-0';
    $submit = probeRequest('POST', $base . '/v1/videos/generations', $apiKey, $payload, 60);
    printStep('submit video model=doubao-seedance-2-0', $submit);
}

if (!$submit['ok']) {
    fwrite(STDERR, "submit video failed\n");
    exit(8);
}

$taskId = extractTaskId($submit['json'] ?? []);
$videoUrl = extractVideoUrl($submit['json'] ?? []);
$status = strtolower(pick($submit['json'] ?? [], 'status'));
if ($taskId === '' && $videoUrl === '') {
    fwrite(STDERR, "submit returned neither task id nor video url\n");
    exit(8);
}

if ($videoUrl === '' && $taskId !== '') {
    $deadline = time() + $pollSeconds;
    $attempt = 0;
    while (time() <= $deadline) {
        $attempt++;
        sleep($attempt === 1 ? 5 : 10);
        $poll = probeRequest('GET', $base . '/v1/videos/generations/' . rawurlencode($taskId), $apiKey);
        $status = strtolower(pick($poll['json'] ?? [], 'status'));
        $progress = pick($poll['json'] ?? [], 'progress');
        $videoUrl = extractVideoUrl($poll['json'] ?? []);
        $err = trim((string) (($poll['json']['error']['message'] ?? '') ?: ($poll['json']['message'] ?? '')));
        echo "poll#{$attempt} http={$poll['http']} status={$status} progress={$progress} video=" . ($videoUrl !== '' ? 'yes' : 'no') . ($err !== '' ? " err={$err}" : '') . "\n";
        if ($videoUrl !== '' || $status === 'completed') {
            printStep('poll completed', $poll);
            break;
        }
        if (in_array($status, ['failed', 'error', 'canceled', 'cancelled'], true)) {
            printStep('poll failed', $poll);
            fwrite(STDERR, "video task failed: {$err}\n");
            exit(9);
        }
    }
}

echo "\nRESULT asset_url={$assetUrl}\n";
echo "RESULT model=" . ($payload['model'] ?? $model) . "\n";
echo "RESULT task_id={$taskId}\n";
echo "RESULT status={$status}\n";
echo "RESULT video_url={$videoUrl}\n";

if ($videoUrl === '') {
    fwrite(STDERR, "no video url after polling\n");
    exit(10);
}

$saved = downloadVideo($videoUrl, $outDir, $taskId !== '' ? $taskId : 'asset');
if ($saved !== '') {
    echo 'RESULT saved=' . $saved . ' bytes=' . filesize($saved) . "\n";
} else {
    echo "RESULT saved=\n";
}

echo "PASS asset:// accepted by ToAPIs generation\n";
exit(0);
