<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = new think\App();
$app->initialize();

use app\support\AuthService;
use app\support\CreditService;
use think\facade\Db;

// AuthService owns the idempotent model migration so deploys and web workers use
// the same model selection, endpoint, options, and legacy-model cleanup rules.
AuthService::ensureSchema();

$rows = Db::name('model_configs')
    ->where('type', 'image')
    ->where('scope', 'global')
    ->where('user_id', 0)
    ->where('enabled', 1)
    ->order(['is_default' => 'desc', 'sort' => 'asc', 'id' => 'asc'])
    ->select()
    ->toArray();

foreach ($rows as $row) {
    $modelId = trim((string) ($row['model_id'] ?? ''));
    if (!in_array($modelId, ['gpt-image-2.5-flare', 'gpt-image-2.5-sunburst'], true)) {
        continue;
    }

    echo sprintf(
        "image_model id=%d model=%s default=%d endpoint=%s key=%s\n",
        (int) ($row['id'] ?? 0),
        $modelId,
        (int) ($row['is_default'] ?? 0),
        (string) ($row['endpoint'] ?? ''),
        trim((string) ($row['api_key'] ?? '')) === '' ? 'missing' : 'configured',
    );
}

echo 'quote flare standard=' . CreditService::quoteImage('standard', 'gpt-image-2.5-flare') . PHP_EOL;
echo 'quote sunburst standard=' . CreditService::quoteImage('standard', 'gpt-image-2.5-sunburst') . PHP_EOL;
echo 'pricing version=' . (CreditService::pricingCatalog()['pricing_version'] ?? '') . PHP_EOL;
