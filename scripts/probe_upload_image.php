<?php

declare(strict_types=1);

function httpJson(string $url, array $payload, array $headers = []): array
{
    $ch = curl_init($url);
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $hdrs = array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $hdrs,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $started = microtime(true);
    $raw = curl_exec($ch);
    $ms = (int) round((microtime(true) - $started) * 1000);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['ms' => $ms, 'status' => $status, 'err' => $err, 'raw' => is_string($raw) ? $raw : ''];
}

function httpUpload(string $url, string $filePath, string $token): array
{
    $ch = curl_init($url);
    $cfile = new CURLFile($filePath, 'image/png', 'probe.png');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => ['file' => $cfile],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $started = microtime(true);
    $raw = curl_exec($ch);
    $ms = (int) round((microtime(true) - $started) * 1000);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['ms' => $ms, 'status' => $status, 'err' => $err, 'raw' => is_string($raw) ? $raw : ''];
}

$base = 'http://web';
$png = sys_get_temp_dir() . '/probe-upload.png';
file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII='));

echo "login...\n";
$login = httpJson($base . '/api/auth/login', ['username' => 'admin', 'password' => 'Aa123456']);
echo "login status={$login['status']} ms={$login['ms']} err={$login['err']}\n";
echo substr($login['raw'], 0, 400) . "\n";
$decoded = json_decode($login['raw'], true);
$token = (string) ($decoded['data']['token'] ?? $decoded['data']['access_token'] ?? '');
if ($token === '') {
    fwrite(STDERR, "no token\n");
    exit(1);
}

echo "upload...\n";
$upload = httpUpload($base . '/api/upload/image', $png, $token);
echo "upload status={$upload['status']} ms={$upload['ms']} err={$upload['err']}\n";
echo substr($upload['raw'], 0, 800) . "\n";
@unlink($png);
