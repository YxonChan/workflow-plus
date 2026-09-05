<?php

$ch = curl_init('https://api.deepseek.com/chat/completions');
$payload = json_encode([
    'model' => 'deepseek-v4-flash',
    'messages' => [['role' => 'user', 'content' => '用一句话写场景标题：外景 桃园 日']],
    'max_tokens' => 128,
    'thinking' => ['type' => 'disabled'],
], JSON_UNESCAPED_UNICODE);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer sk-9b25c89b52a24669b2a44931d06db71a',
    ],
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_TIMEOUT => 60,
]);
$body = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$j = json_decode((string) $body, true);
$msg = is_array($j['choices'][0]['message'] ?? null) ? $j['choices'][0]['message'] : [];
echo 'HTTP=' . $code . PHP_EOL;
echo 'content=' . substr((string) ($msg['content'] ?? ''), 0, 120) . PHP_EOL;
echo 'reasoning_len=' . strlen((string) ($msg['reasoning_content'] ?? '')) . PHP_EOL;
echo 'usage=' . json_encode($j['usage'] ?? [], JSON_UNESCAPED_UNICODE) . PHP_EOL;
