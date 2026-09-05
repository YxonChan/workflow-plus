<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = new think\App();
$app->initialize();

use app\support\CreditService;
use app\support\ImageGenerationOptions;
use think\facade\Db;

$row = Db::name('model_configs')->where('id', 68)->find();
if (!is_array($row)) {
    fwrite(STDERR, "model_configs id=68 missing\n");
    exit(1);
}

$opts = json_decode((string) ($row['options'] ?? ''), true);
if (!is_array($opts)) {
    $opts = [];
}

$defaults = ImageGenerationOptions::defaultModelOptions('OG/image2');
$changed = false;
foreach ($defaults as $key => $value) {
    if (!array_key_exists($key, $opts) || $opts[$key] === '' || $opts[$key] === null) {
        $opts[$key] = $value;
        $changed = true;
    }
}

$defaultPollAttempts = (int) ($defaults['poll_attempts'] ?? 120);
if ((int) ($opts['poll_attempts'] ?? 0) < $defaultPollAttempts) {
    $opts['poll_attempts'] = $defaultPollAttempts;
    $changed = true;
}
if ((int) ($opts['poll_interval'] ?? 0) <= 0) {
    $opts['poll_interval'] = (int) ($defaults['poll_interval'] ?? 3);
    $changed = true;
}

$defaultQuality = ImageGenerationOptions::defaultQuality();
$currentQuality = strtolower(trim((string) ($opts['quality'] ?? '')));
if ($currentQuality === '' || $currentQuality === 'high') {
    $opts['quality'] = $defaultQuality;
    $changed = true;
}
$opts['resolution'] = ImageGenerationOptions::DEFAULT_RESOLUTION;
$wantedVersion = ImageGenerationOptions::defaultModelVersion((string) $opts['quality']);
if (trim((string) ($opts['model_version'] ?? '')) !== $wantedVersion) {
    $opts['model_version'] = $wantedVersion;
    $changed = true;
}

if ($changed) {
    Db::name('model_configs')->where('id', 68)->update([
        'options' => json_encode($opts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'update_time' => date('Y-m-d H:i:s'),
    ]);
    echo 'DB options updated poll_attempts=' . $opts['poll_attempts'] . PHP_EOL;
} else {
    echo 'DB options already ok poll_attempts=' . ($opts['poll_attempts'] ?? '') . PHP_EOL;
}

echo 'quote OG/image2 high=' . CreditService::quoteImage('high', 'OG/image2') . PHP_EOL;
echo 'quote OG/image2_high high=' . CreditService::quoteImage('high', 'OG/image2_high') . PHP_EOL;
echo 'quote OG low=' . CreditService::quoteImage('low', 'OG') . PHP_EOL;

$catalog = CreditService::pricingCatalog();
echo 'pricing default_model_id=' . ($catalog['image']['default_model_id'] ?? '') . PHP_EOL;
echo 'pricing version=' . ($catalog['pricing_version'] ?? '') . PHP_EOL;
echo 'options=' . json_encode($opts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
