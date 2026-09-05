<?php

/**
 * 探测 ToAPIs Seedance 2 虚拟人像（private-avatar）是否可通。
 * 只建组、上传、轮询状态，默认不提交视频生成。
 *
 * 用法（容器内）：
 *   TOAPIS_API_KEY=sk-xxx php scripts/probe_toapis_private_avatar.php
 *   TOAPIS_API_KEY=sk-xxx TOAPIS_BASE=https://toapis.cn php scripts/probe_toapis_private_avatar.php
 */

declare(strict_types=1);

$apiKey = trim((string) getenv('TOAPIS_API_KEY'));
$base = rtrim(trim((string) (getenv('TOAPIS_BASE') ?: 'https://toapis.cn')), '/');
$imageUrl = trim((string) (getenv('TOAPIS_IMAGE_URL') ?: 'https://xunboo.net/storage/generated/assets/u21_test1/s146_%E6%A1%83%E5%9B%AD%E4%B8%89%E7%BB%93%E4%B9%89/20260831/asset_image-asset922_%E5%88%98%E5%A4%87-main-job984-d78c3854.png'));
$pollSeconds = max(5, min(90, (int) (getenv('TOAPIS_POLL_SECONDS') ?: 45)));
$generateVideo = in_array(strtolower((string) getenv('TOAPIS_GENERATE_VIDEO')), ['1', 'true', 'yes', 'on'], true);

if ($apiKey === '') {
    fwrite(STDERR, "TOAPIS_API_KEY is empty\n");
    exit(2);
}

function probeRequest(string $method, string $url, string $apiKey, ?array $payload = null, int $timeout = 45): array
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

function printStep(string $title, array $res): void
{
    echo "===== {$title} =====\n";
    echo 'http=' . $res['http'] . ' errno=' . $res['errno'] . ($res['error'] !== '' ? ' error=' . $res['error'] : '') . "\n";
    $body = $res['body'];
    if (strlen($body) > 2000) {
        $body = substr($body, 0, 2000) . '...';
    }
    echo $body . "\n\n";
}

echo "base={$base}\n";
echo "image={$imageUrl}\n";
echo "generate_video=" . ($generateVideo ? 'yes' : 'no') . "\n\n";

$head = probeRequest('GET', $imageUrl, $apiKey, null, 20);
echo "===== source image =====\n";
echo 'http=' . $head['http'] . ' bytes=' . strlen((string) $head['body']) . "\n\n";
if ($head['http'] < 200 || $head['http'] >= 300) {
    fwrite(STDERR, "source image is not publicly reachable\n");
    exit(3);
}

$group = probeRequest('POST', $base . '/v1/videos/doubao-seedance-2-0/private-avatar/groups', $apiKey, [
    'name' => 'probe-liubei-' . date('YmdHis'),
    'description' => 'local connectivity probe for virtual character look, do not use in production',
]);
printStep('create group', $group);
$groupId = trim((string) ($group['json']['data']['group_id'] ?? $group['json']['group_id'] ?? ''));
if ($groupId === '') {
    fwrite(STDERR, "create group failed: no group_id\n");
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
$assetId = trim((string) ($asset['json']['data']['asset_id'] ?? $asset['json']['asset_id'] ?? ''));
$assetUrl = trim((string) ($asset['json']['data']['asset_url'] ?? $asset['json']['asset_url'] ?? ''));
$status = strtolower(trim((string) ($asset['json']['data']['status'] ?? $asset['json']['status'] ?? '')));
if ($assetId === '') {
    fwrite(STDERR, "upload asset failed: no asset_id\n");
    exit(5);
}
if ($assetUrl === '') {
    $assetUrl = 'asset://' . $assetId;
}

$deadline = time() + $pollSeconds;
$lastStatus = $status;
while (time() <= $deadline) {
    $query = probeRequest('GET', $base . '/v1/videos/doubao-seedance-2-0/private-avatar/assets/' . rawurlencode($assetId), $apiKey);
    printStep('poll asset ' . $assetId, $query);
    $lastStatus = strtolower(trim((string) ($query['json']['data']['status'] ?? $query['json']['status'] ?? '')));
    $polledUrl = trim((string) ($query['json']['data']['asset_url'] ?? $query['json']['asset_url'] ?? ''));
    if ($polledUrl !== '') {
        $assetUrl = $polledUrl;
    }
    if (in_array($lastStatus, ['active', 'failed', 'error', 'rejected'], true)) {
        break;
    }
    sleep(3);
}

echo "RESULT group_id={$groupId}\n";
echo "RESULT asset_id={$assetId}\n";
echo "RESULT asset_url={$assetUrl}\n";
echo "RESULT status={$lastStatus}\n";

if ($lastStatus !== 'active') {
    fwrite(STDERR, "asset not active yet; skip video generation\n");
    exit($lastStatus === 'failed' ? 6 : 7);
}

if (!$generateVideo) {
    echo "SKIP video generation (set TOAPIS_GENERATE_VIDEO=1 to submit a paid job)\n";
    exit(0);
}

$video = probeRequest('POST', $base . '/v1/videos/generations', $apiKey, [
    'model' => 'doubao-seedance-2-0',
    'prompt' => '让图片1中的角色在纯白棚拍背景前缓慢转身，镜头轻微推进，不要其他人。',
    'duration' => 5,
    'aspect_ratio' => '16:9',
    'image_with_roles' => [
        [
            'url' => $assetUrl,
            'role' => 'reference_image',
        ],
    ],
]);
printStep('submit video', $video);
exit($video['ok'] ? 0 : 8);
