<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = new think\App();
$app->initialize();

use app\model\AiRequestLog;
use app\model\User;
use app\support\CreditService;

echo 'quote_text=' . CreditService::quoteText(1000, 500, 'deepseek-v4-flash') . PHP_EOL;
echo 'quote_image=' . CreditService::quoteImage('high', 'OG/image2') . PHP_EOL;
echo 'quote_video15_480p=' . CreditService::quoteVideo(15, '480p', 'doubao-seedance-2-0-260128') . PHP_EOL;
echo 'quote_video15_720p=' . CreditService::quoteVideo(15, '720p', 'doubao-seedance-2-0-260128') . PHP_EOL;
echo 'quote_video15_1080p=' . CreditService::quoteVideo(15, '1080p', 'doubao-seedance-2-0-260128') . PHP_EOL;

$user = User::order('id', 'asc')->find();
if (!$user instanceof User) {
    fwrite(STDERR, "no user\n");
    exit(1);
}

$uid = (int) $user->getAttr('id');
$before = CreditService::getBalance($uid);
echo "user={$uid} before={$before}\n";

if ($before < 10) {
    CreditService::topUp($uid, 100, 0, 'probe topup');
    $before = CreditService::getBalance($uid);
    echo "topped to {$before}\n";
}

$log = AiRequestLog::create([
    'user_id' => $uid,
    'source' => 'credit_smoke_text',
    'llm_model' => 'deepseek-v4-flash',
    'endpoint' => 'https://api.deepseek.com/chat/completions',
    'usage_json' => [
        'prompt_tokens' => 1000,
        'completion_tokens' => 500,
        'total_tokens' => 1500,
    ],
    'prompt_tokens' => 1000,
    'completion_tokens' => 500,
    'total_tokens' => 1500,
    'http_status' => 200,
    'request_ok' => 1,
    'error_message' => '',
    'duration_ms' => 1,
    'content_preview' => 'ok',
]);

$fresh = AiRequestLog::find((int) $log->getAttr('id'));
$after = CreditService::getBalance($uid);
echo 'log=' . (int) $log->getAttr('id')
    . ' charged=' . (float) ($fresh ? $fresh->getAttr('credits_charged') : 0)
    . ' after=' . $after . PHP_EOL;

$expected = CreditService::quoteText(1000, 500, 'deepseek-v4-flash');
$delta = round($before - $after, 2);
echo "expected={$expected} delta={$delta}\n";
echo ($delta === $expected ? "PASS\n" : "FAIL\n");
