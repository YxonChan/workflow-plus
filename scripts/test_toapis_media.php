<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use app\controller\AssetController;
use app\controller\QuickCreateController;
use app\controller\SeriesController;
use app\model\AiRequestLog;
use app\model\ModelConfig;
use app\model\QuickCreateMessage;
use app\support\AuthService;
use app\support\CreditService;
use app\support\ImageGenerationOptions as Images;
use app\support\ImageProviderTaskState;
use app\support\PendingImageTaskException;
use app\support\ToapisImageBilling;
use app\support\ToapisPrivateAvatarService as Media;
use think\facade\Db;

$checks = 0;
function verify(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    $checks++;
    echo 'PASS: ' . $label . PHP_EOL;
}
function rejects(callable $call, string $label): void {
    try { $call(); } catch (Throwable) { verify(true, $label); return; }
    throw new RuntimeException('FAIL: ' . $label);
}
function invoke(object|string $object, string $method, mixed ...$args): mixed {
    return (new ReflectionMethod($object, $method))->invoke(is_object($object) ? $object : null, ...$args);
}

foreach (['1K' => 13.5, '2K' => 18.0, '4K' => 22.5] as $res => $cost) {
    foreach (['gpt-image-2.5-flare', 'gpt-image-2.5-sunburst'] as $id) {
        $options = ['resolution' => $res, 'aspect_ratio' => '3:2', 'quality' => 'max', 'n' => 4];
        $body = Images::applyToPayload(['prompt' => 'cup', 'style' => 'vivid'], $options, $id);
        verify($body['size'] === '3:2' && $body['resolution'] === $res && $body['quality'] === 'high'
            && $body['n'] === 1 && !isset($body['aspect_ratio'], $body['style']), 'ordinary payload ' . $id . ' ' . $res);
        verify(CreditService::quoteImage('high', $id, 6, $options) === $cost, 'ordinary credit price ' . $res);
    }
}
foreach (['auto', 'low', 'medium', 'high', 'xhigh', 'max'] as $quality) {
    $body = Images::applyToPayload([], ['quality' => $quality, 'size' => '1536x1024', 'background' => 'transparent'], 'gpt-image-2.5-flare-official');
    verify($body['quality'] === $quality && $body['size'] === '1536x1024' && $body['background'] === 'transparent'
        && !isset($body['resolution']), 'official dynamic quality ' . $quality);
}
rejects(fn () => Images::resolve(['size' => '16:9'], 'gpt-image-2.5-flare-official'), 'official rejects ratio as pixel size');
rejects(fn () => Images::resolve(['aspect_ratio' => '19:7'], 'gpt-image-2.5-flare'), 'unsupported ratio rejected');
rejects(fn () => Images::resolve(['resolution' => '8K'], 'gpt-image-2.5-flare'), 'unsupported resolution rejected');
verify(CreditService::settledImageCredits(['status' => 'settled', 'cost_usd' => '0.004772']) === 4.29, 'final cost includes account discount');
verify(CreditService::settledImageCredits(['status' => 'pending']) === null && CreditService::settledImageCredits([]) === null, 'unsettled or missing amount is not free');
verify(CreditService::settledImageCredits(['status' => 'settled', 'cost_usd' => '0']) === 0.0, 'explicit settled zero is valid');
verify(CreditService::settledImageCredits(['status' => 'settled', 'cost_usd' => '-1']) === null, 'negative settlement rejected');
$url = 'https://api.toapis.cn/v1/videos/generations';
verify(Media::applyVideoReview(['image_urls' => ['https://example.com/a.png'], 'private_assets' => ['old']], $url)
    === ['image_urls' => ['https://example.com/a.png'], 'private_asset_review' => true], 'review attached to video with original URLs');
verify(!isset(Media::applyVideoReview(['prompt' => 'sky'], $url)['private_asset_review']), 'text only video needs no media review');
verify(!isset(Media::applyVideoReview(['image' => 'https://example.com/a.png'], 'https://other.invalid')['private_asset_review']), 'other providers unaffected');
verify(!Media::isToapisEndpoint('https://toapis.cn.attacker.invalid'), 'provider host detection is bounded');
rejects(fn () => Media::applyVideoReview(['image_urls' => ['asset://old']], $url), 'legacy asset URI rejected before submit');

if (!getenv('PROMPT_TEST_MYSQL_DSN')) { echo "Passed {$checks} policy checks.\n"; exit; }
$app = new think\App();
$app->initialize();
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
});
$app->env->set('TOAPIS_API_KEY', '');
$dsn = getenv('PROMPT_TEST_MYSQL_DSN');
$pdo = new PDO($dsn, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db = 'toapis_test_' . bin2hex(random_bytes(6));
try {
    $pdo->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4');
    parse_str(str_replace(';', '&', substr($dsn, 6)), $parts);
    $app->config->set(['default' => 'media_test', 'connections' => ['media_test' => [
        'type' => 'mysql', 'hostname' => $parts['host'], 'hostport' => $parts['port'], 'database' => $db,
        'username' => 'root', 'password' => '', 'charset' => 'utf8mb4', 'prefix' => '', 'fields_strict' => true, 'fields_cache' => false,
    ]]], 'database');
    Db::execute('CREATE TABLE model_configs (id int unsigned PRIMARY KEY AUTO_INCREMENT, user_id int unsigned, type varchar(16), name varchar(100), model_id varchar(100), endpoint varchar(255), api_key varchar(255), scope varchar(16), enabled tinyint, is_default tinyint, sort int, options json, create_time datetime, update_time datetime)');
    Db::execute('CREATE TABLE users (id int unsigned PRIMARY KEY, role varchar(20), status tinyint, credit_balance decimal(14,2))');
    Db::execute('CREATE TABLE ai_request_logs (id int unsigned PRIMARY KEY AUTO_INCREMENT, user_id int, source varchar(64), model_config_id int, llm_model varchar(120), endpoint varchar(500), context_json text, request_json mediumtext, http_status int, response_body mediumtext, usage_json json, curl_errno int, curl_error text, request_ok tinyint, error_message text, duration_ms int, max_tokens int, finish_reason varchar(32), content_preview text, prompt_tokens int, completion_tokens int, total_tokens int, credits_charged decimal(14,2) DEFAULT 0, create_time datetime)');
    Db::execute('CREATE TABLE quick_create_messages (id int unsigned PRIMARY KEY AUTO_INCREMENT, user_id int, mode varchar(16), prompt text, model_config_id int, asset_refs_json json, options_json json, status varchar(16), result_urls_json json, error_message text, ai_request_log_id int, update_time datetime, create_time datetime)');
    Db::execute('CREATE TABLE assets (id int PRIMARY KEY, user_id int, series_id int, name varchar(100), type varchar(20))');
    Db::execute('ALTER TABLE ai_request_logs ADD COLUMN workflow_run_id int, ADD COLUMN workflow_run_node_id int');
    Db::execute('CREATE TABLE asset_images (id int PRIMARY KEY, asset_id int, url text, view_type varchar(20), reference_role varchar(20), toapis_asset_url varchar(120), sort int DEFAULT 0)');
    Db::name('users')->insert(['id' => 3, 'role' => 'user', 'status' => 1, 'credit_balance' => 5000]);
    $common = ['user_id' => 0, 'type' => 'image', 'scope' => 'global', 'enabled' => 1, 'is_default' => 1, 'sort' => 0, 'name' => 'Legacy'];
    ModelConfig::create($common + ['id' => 1, 'model_id' => 'gpt-image-2.5-flare', 'endpoint' => 'https://my110.vip/v1', 'api_key' => 'old-key', 'options' => ['quality' => 'standard']]);
    ModelConfig::create(array_replace($common, ['id' => 2, 'user_id' => 99, 'scope' => 'user', 'model_id' => 'private', 'endpoint' => 'https://private.invalid', 'api_key' => 'private-key', 'options' => []]));
    ModelConfig::create(array_replace($common, ['id' => 3, 'type' => 'video', 'model_id' => 'seedance-2', 'endpoint' => $url, 'api_key' => 'toapis-fixture-key', 'options' => []]));
    invoke(AuthService::class, 'ensureDefaultImageModelConfig');
    verify(ModelConfig::where('type', 'image')->where('scope', 'global')->count() === 4, 'migration creates four image configs with image type');
    verify(ModelConfig::find(1)->getAttr('api_key') === 'toapis-fixture-key', 'migration replaces old provider credential');
    verify(ModelConfig::find(2)->getAttr('endpoint') === 'https://private.invalid', 'migration preserves private models');
    $ordinary = ModelConfig::find(1);
    $ordinary->save(['options' => Images::defaultModelOptions('gpt-image-2.5-flare') + ['toapis_image_schema' => 1]]);
    $opts = $ordinary->getAttr('options'); $opts['resolution'] = '4K';
    $ordinary->save(['options' => $opts]);
    invoke(AuthService::class, 'ensureDefaultImageModelConfig');
    verify(ModelConfig::find(1)->getAttr('options')['resolution'] === '4K', 'migration preserves later parameter edits');
    $base = getenv('PROMPT_TEST_HTTP_URL');
    file_get_contents($base . '/fixture/reset');
    $ordinary = ModelConfig::find(1);
    $ordinary->save(['endpoint' => $base . '/v1', 'options' => ['resolution' => '2K', 'aspect_ratio' => '9:16']]);
    $official = ModelConfig::where('model_id', 'gpt-image-2.5-flare-official')->find();
    $official->save(['endpoint' => $base . '/v1', 'options' => ['quality' => 'max', 'size' => '1024x1536']]);
    $controller = (new ReflectionClass(AssetController::class))->newInstanceWithoutConstructor();
    $controller->setRuntimeUserId(3);
    $image = $controller->callImageGeneration($ordinary, 'ordinary reference test', ['main_image_urls' => ['https://example.com/a.png']]);
    $log = AiRequestLog::find($controller->lastAiRequestLogId());
    verify(str_starts_with($image, '/storage/') && $log->getAttr('credits_charged') === 18.0, 'ordinary HTTP async image saved and charged for 2K');
    $image = $controller->callImageGeneration($official, 'official reference test', ['main_image_urls' => ['https://example.com/a.png']]);
    $log = AiRequestLog::find($controller->lastAiRequestLogId());
    verify($log->getAttr('credits_charged') === 4.29 && $log->getAttr('total_tokens') === 213, 'official exact settlement and tokens persisted');
    $snapshot = json_decode($log->getAttr('request_json'), true)['body'];
    verify(isset($snapshot['image_urls']) && !isset($snapshot['reference_images'], $snapshot['resolution']) && $snapshot['quality'] === 'max', 'official sends JSON references and selected quality');
    $before = CreditService::getBalance(3);
    CreditService::chargeFromAiRequestLog($log);
    $copy = $log->toArray(); unset($copy['id'], $copy['create_time']); $copy['credits_charged'] = 0;
    AiRequestLog::create($copy);
    verify(CreditService::getBalance(3) === $before, 'same provider task cannot charge again even through another log');
    $message = QuickCreateMessage::create(['user_id' => 3, 'status' => 'running', 'error_message' => '', 'result_urls_json' => []]);
    $task = '';
    try { $controller->callImageGeneration($official, 'delayed', ['quick_create_message_id' => $message->getAttr('id'), 'allow_pending_yield' => true, 'inline_poll_attempts' => 1]); }
    catch (PendingImageTaskException $error) { $task = $error->taskId(); }
    verify($task !== '' && ImageProviderTaskState::extractTaskId(QuickCreateMessage::find($message->getAttr('id'))->getAttr('error_message')) === $task, 'task persisted before yielding');
    $controller->callImageGeneration($official, 'delayed', ['provider_task_id' => $task, 'main_image_urls' => ['/storage/missing.png']]);
    $state = json_decode(file_get_contents($base . '/fixture/state'), true);
    verify(count($state['posts']) === 3 && $state['gets'][$task] === 2, 'resume queries original task without reupload or duplicate POST');
    $controller->callImageGeneration($official, 'billing-pending', []);
    $pendingLog = AiRequestLog::find($controller->lastAiRequestLogId());
    verify($pendingLog->getAttr('credits_charged') === 0.0 && json_decode($pendingLog->getAttr('context_json'), true)['image_billing_pending'], 'completed image with pending billing stays unsettled');
    $official->save(['enabled' => 0]);
    ToapisImageBilling::reconcile();
    $settledLog = AiRequestLog::find($pendingLog->getAttr('id'));
    verify($settledLog->getAttr('credits_charged') === 4.29 && !json_decode($settledLog->getAttr('context_json'), true)['image_billing_pending'], 'billing reconciles original task even after model disabled');
    $before = CreditService::getBalance(3);
    ToapisImageBilling::reconcile();
    verify(CreditService::getBalance(3) === $before, 'reconciliation cannot double charge');
    $controller->callImageGeneration($official, 'zero', []);
    $zero = AiRequestLog::find($controller->lastAiRequestLogId());
    verify($zero->getAttr('credits_charged') === 0.0 && !json_decode($zero->getAttr('context_json'), true)['image_billing_pending'], 'zero settlement finishes without fake minimum charge');
    $videoModel = ModelConfig::find(3);
    $videoModel->save(['endpoint' => $base . '/v1/videos/generations', 'options' => ['provider' => 'toapis', 'resolution' => '480p']]);
    $series = (new ReflectionClass(SeriesController::class))->newInstanceWithoutConstructor();
    $series->setRuntimeUserId(3);
    $heartbeats = 0;
    $video = $series->callWorkflowVideoGeneration($videoModel, 'Keep @图片1 identity and walk slowly.', '', '5',
        [['id' => 10, 'name' => 'Person', 'type' => 'character', 'reference_role' => 'look', 'image_url' => 'https://example.com/portrait.png']],
        ['user_id' => 3, 'idempotency_key' => 'quick-create-video-fixture-0'], function () use (&$heartbeats) { $heartbeats++; });
    $state = json_decode(file_get_contents($base . '/fixture/state'), true);
    verify($state['video_post']['private_asset_review'] === true
        && $state['video_post']['client_business_id'] === 'quick-create-video-fixture-0', 'real video adapter submits integrated review and stable business ID');
    verify($state['video_post']['image_with_roles'][0]['url'] === 'https://example.com/portrait.png'
        && !isset($state['video_post']['private_assets']), 'real video request preserves original portrait URL');
    verify($heartbeats === 2 && $state['video_gets'] === 2 && str_contains($video, '/storage/'), 'queued review polls keep quick-create heartbeat alive');
    Db::name('users')->where('id', 3)->update(['credit_balance' => 20]);
    $quick = (new ReflectionClass(QuickCreateController::class))->newInstanceWithoutConstructor();
    rejects(fn () => invoke($quick, 'assertImageBatchAffordable', $ordinary, ['count' => 2], 'cup', [], 3), 'entire multi-image batch checked before submit');
    Db::name('users')->where('id', 3)->update(['credit_balance' => 1]);
    CreditService::consume(3, 4.29, ['settle_actual_usage' => true]);
    verify(CreditService::getBalance(3) === -3.29, 'actual settlement records complete cost when estimate exceeded balance');
    CreditService::topUp(3, 10);
    verify(CreditService::getBalance(3) === 6.71, 'topup correctly covers outstanding settlement');
    Db::name('assets')->insertAll([['id' => 1, 'user_id' => 3], ['id' => 2, 'user_id' => 99]]);
    Db::name('asset_images')->insertAll([['id' => 1, 'asset_id' => 1, 'view_type' => 'look', 'reference_role' => 'look', 'url' => 'https://example.com/owned.png', 'toapis_asset_url' => 'asset://owned'],
        ['id' => 2, 'asset_id' => 2, 'view_type' => 'look', 'reference_role' => 'look', 'url' => 'https://example.com/foreign.png', 'toapis_asset_url' => 'asset://foreign']]);
    $person = app\model\Asset::with(['images'])->find(1);
    verify(invoke($series, 'realisticCharacterVideoReadinessReason', $person, [['asset_id' => 1, 'asset_image_id' => 1]]) === '', 'look without legacy face check is ready even without core view');
    $prepare = new ReflectionMethod(SeriesController::class, 'prepareVideoReferenceImagesForPayload');
    $temps = []; $uploads = [];
    $prepared = $prepare->invokeArgs($series, [['asset://owned'], $url, 'fixture', '', [], &$temps, &$uploads]);
    verify($prepared === ['https://example.com/owned.png'], 'legacy URI restored to owned source URL');
    rejects(function () use ($prepare, $series, $url) { $temps = []; $uploads = [];
        $prepare->invokeArgs($series, [['asset://foreign'], $url, 'fixture', '', [], &$temps, &$uploads]);
    }, 'foreign legacy asset cannot be resolved');
    rejects(fn () => $controller->detectLookAvatar(), 'manual review endpoint retired');
    echo "ToAPIs media checks passed: {$checks}\n";
} finally {
    $pdo->exec('DROP DATABASE IF EXISTS `' . $db . '`');
}
