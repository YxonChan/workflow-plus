<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use app\model\ModelConfig;
use app\support\PromptAssistantPolicy;
use app\support\PromptAssistantResponseException;
use app\support\PromptAssistantReferences;
use app\support\PromptAssistantService;
use app\support\PromptAssistantTextClient;
use think\facade\Db;

$count = 0;
function check(bool $condition, string $name): void
{
    global $count;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $name);
    }
    echo 'PASS: ' . $name . PHP_EOL;
    $count++;
}
function rejects(callable $operation, string $name): void
{
    try { $operation(); } catch (Throwable) { check(true, $name); return; }
    throw new RuntimeException('FAIL: ' . $name);
}

class FixturePromptClient extends PromptAssistantTextClient
{
    public array $responses = [];
    public array $calls = [];
    public function completeWithImages(ModelConfig $model, int $userId, string $stage, string $system, array $input, array $images, int $maxTokens = 4096): array
    {
        $response = $this->complete($model, $userId, $stage, $system, $input, $maxTokens);
        $this->calls[array_key_last($this->calls)]['images'] = $images;
        return $response;
    }
    public function complete(ModelConfig $model, int $userId, string $stage, string $system, array $input, int $maxTokens = 4096): array
    {
        $this->calls[] = ['user_id' => $userId, 'stage' => $stage, 'input' => $input];
        if ($this->responses === []) throw new RuntimeException('No fixture response for ' . $stage);
        $response = array_shift($this->responses);
        if ($response instanceof Throwable) throw $response;
        return $response;
    }
}

function promptRoute(think\App $app, string $action, array $payload = [], array $files = []): think\Response
{
    $path = 'api/prompt-assistant/' . $action;
    $files = array_map(static fn (think\file\UploadedFile $file): array => ['name' => $file->getOriginalName(),
        'type' => $file->getOriginalMime(), 'tmp_name' => $file->getPathname(), 'error' => UPLOAD_ERR_OK, 'size' => $file->getSize()], $files);
    $request = (new app\Request())->setPathinfo($path)->setMethod('POST')->withPost($payload)
        ->withHeader(['accept' => 'application/json'])->withFiles($files);
    $app->instance('request', $request);
    $route = 'PromptAssistantController/' . match ($action) { 'list' => 'index', 'upload' => 'uploadReference', default => $action };
    $rule = $app->route->rule($path, $route, 'POST');
    $dispatch = new think\route\dispatch\Controller($request, $rule, $route);
    $dispatch->init($app);
    return $dispatch->run();
}

$settings = PromptAssistantPolicy::settings(['mode' => 'image', 'text_model_id' => 1]);
check($settings['visual_style'] === 'realistic', 'settings normalize with a single primary style');
check($settings['duration_confirmed'] === false, 'default duration is not treated as a user confirmation');
rejects(fn () => PromptAssistantPolicy::settings(['duration_confirmed' => 'true']), 'duration confirmation must be a boolean');
rejects(fn () => PromptAssistantPolicy::settings(['user_id' => 2]), 'authority fields cannot enter settings');
rejects(fn () => PromptAssistantPolicy::settings(['mode' => 'shell']), 'unsupported modes rejected');
rejects(fn () => PromptAssistantPolicy::settings(['visual_style' => ['anime', 'realistic']]), 'multiple primary styles rejected');
rejects(fn () => PromptAssistantPolicy::settings(['asset_ids' => ['../secret']]), 'asset paths cannot be used as IDs');
rejects(fn () => PromptAssistantPolicy::settings(['asset_ids' => range(1, 7)]), 'reference count is bounded');
rejects(fn () => PromptAssistantPolicy::settings(['asset_ids' => [1], 'reference_ids' => range(1, 6)]), 'uploads and assets share a combined six-reference limit');
rejects(fn () => PromptAssistantPolicy::settings(['reference_ids' => ['https://example.com/a.png']]), 'arbitrary image URLs cannot enter reference settings');
rejects(fn () => PromptAssistantPolicy::plan(['action' => 'run_series_workflow'], [], 'image'), 'production tools outside the decision whitelist rejected');
rejects(fn () => PromptAssistantPolicy::plan(['action' => 'generate_prompt', 'reply' => 'ready', 'brief' => ['subject' => 'cup', 'goal' => 'display'], 'skill_ids' => ['private:99']], [], 'image'), 'hallucinated or foreign skill IDs rejected');

$video = PromptAssistantPolicy::settings(['mode' => 'video', 'duration' => 10]);
$confirmedDuration = PromptAssistantPolicy::confirmDuration($video, ['duration' => 15, 'evidence' => '15秒'],
    [['role' => 'assistant', 'text' => '5秒？'], ['role' => 'user', 'text' => '我要15秒的视频']]);
check($confirmedDuration['duration'] === 15 && $confirmedDuration['duration_confirmed'], 'explicit seconds in user conversation update the actual settings');
rejects(fn () => PromptAssistantPolicy::confirmDuration($video, ['duration' => 5, 'evidence' => '5秒'],
    [['role' => 'assistant', 'text' => '5秒？'], ['role' => 'user', 'text' => '我还没决定']]), 'assistant suggestions cannot confirm a default duration');
rejects(fn () => PromptAssistantPolicy::confirmDuration($confirmedDuration, ['duration' => 5, 'evidence' => '5秒'],
    [['role' => 'user', 'text' => '5秒'], ['role' => 'user', 'text' => '继续使用15秒']]), 'an older answer cannot replace the current confirmed duration');
$result = ['title' => 'Cup', 'prompt_body' => 'Display the cup.', 'shots' => [
    ['title' => 'First', 'duration' => 5, 'prompt_body' => 'Rotate slowly.'],
    ['title' => 'Second', 'duration' => 5, 'prompt_body' => 'Hold still.'],
], 'assumptions' => [], 'asset_requirements' => []];
check(count(PromptAssistantPolicy::result($result, $video, [])['shots']) === 2, 'valid storyboard accepted');
$invalid = $result;
$invalid['shots'][1]['duration'] = 3;
rejects(fn () => PromptAssistantPolicy::result($invalid, $video, []), 'storyboard durations must sum to the requested total');
rejects(fn () => PromptAssistantPolicy::result($result, $settings, []), 'image requests cannot return video shots');
$assembled = PromptAssistantService::composeResult(['style_block' => 'STYLE', 'prompt_body' => 'BODY', 'shots' => $result['shots']]);
check(substr_count($assembled['shots'][0]['full_prompt'], 'STYLE') === 1, 'style composed once for each shot');

$referenceFixtures = [['id' => 10, 'kind' => 'upload', 'name' => 'scene.png', 'url' => '/storage/scene.png'],
    ['id' => 20, 'kind' => 'upload', 'name' => 'product.png', 'url' => '/storage/product.png']];
$referenceResult = ['prompt_body' => 'Display the product in @图片2 on a plain background.', 'shots' => [],
    'reference_usage' => [['reference_index' => 2, 'instruction' => 'Preserve the cup shape and ceramic material; replace the original background.']]];
$bound = PromptAssistantReferences::applyUsage($referenceResult, $referenceFixtures);
check($bound['references'] === [$referenceFixtures[1]] && str_contains($bound['prompt_body'], '@图片1：Preserve the cup shape')
    && !str_contains($bound['prompt_body'], '@图片2'), 'AI-selected images, instructions and prompt tokens are compacted together');
rejects(fn () => PromptAssistantReferences::applyUsage(['prompt_body' => 'Product', 'shots' => []], $referenceFixtures),
    'image-backed output must explicitly describe how references are used');
rejects(fn () => PromptAssistantReferences::applyUsage(array_replace($referenceResult,
    ['reference_usage' => [['reference_index' => 3, 'instruction' => 'Unknown image']]]), $referenceFixtures), 'unknown reference indices rejected');
rejects(fn () => PromptAssistantReferences::applyUsage(array_replace($referenceResult,
    ['reference_usage' => [['reference_index' => 2, 'instruction' => 'First'], ['reference_index' => 2, 'instruction' => 'Duplicate']]]), $referenceFixtures),
    'duplicate reference usage rejected');
rejects(fn () => PromptAssistantReferences::applyUsage(array_replace($referenceResult,
    ['reference_usage' => [['reference_index' => 2, 'instruction' => '']]]), $referenceFixtures), 'empty reference instructions rejected');
rejects(fn () => PromptAssistantReferences::applyUsage(array_replace($referenceResult,
    ['prompt_body' => 'Use @图片1 and @图片2']), $referenceFixtures), 'prompt cannot refer to an image excluded by AI selection');
rejects(fn () => PromptAssistantReferences::applyUsage($referenceResult, []), 'text-only output cannot invent image attachments');
$noReference = PromptAssistantReferences::applyUsage(['prompt_body' => 'Freely design a cup.', 'shots' => [], 'reference_usage' => []], $referenceFixtures);
check($noReference['references'] === [] && $noReference['prompt_body'] === 'Freely design a cup.', 'AI can explicitly choose text-only generation with uploaded images available');
$reordered = $referenceResult;
$reordered['reference_usage'] = [['reference_index' => 2, 'instruction' => 'Product from @图片2, background from @图片1.'],
    ['reference_index' => 1, 'instruction' => 'Use only the lighting.']];
$reordered['shots'] = [['prompt_body' => 'Move toward @图片2 in the setting from @图片1.']];
$bound = PromptAssistantReferences::applyUsage($reordered, $referenceFixtures);
check($bound['references'] === array_reverse($referenceFixtures) && $bound['reference_usage'][0]['instruction'] === 'Product from @图片1, background from @图片2.'
    && $bound['shots'][0]['prompt_body'] === 'Move toward @图片1 in the setting from @图片2.',
    'multiple reference roles and video shots retain the same attachment order');
$serviceReflection = new ReflectionClass(PromptAssistantService::class);
$reviewInputMethod = $serviceReflection->getMethod('reviewInput');
$reviewInputMethod->setAccessible(true);
$numberedReview = $reviewInputMethod->invoke(new PromptAssistantService(), [], $settings, [['role' => 'user', 'text' => 'Use the product in image 2.']],
    ['references' => $referenceFixtures, 'style_block' => 'STYLE', 'target_model' => null], $bound);
check(array_column($numberedReview['context']['reference_numbering'], 'source_index') === [2, 1]
    && array_column($numberedReview['context']['reference_numbering'], 'final_index') === [1, 2]
    && !isset($numberedReview['context']['references']), 'review gets an explicit source-to-final image map without competing attachment arrays');

if (!getenv('PROMPT_TEST_MYSQL_DSN')) {
    echo "Policy checks passed: {$count}. Set PROMPT_TEST_MYSQL_DSN for isolated persistence tests.\n";
    exit(0);
}

$app = new think\App();
$app->initialize();
$dsn = getenv('PROMPT_TEST_MYSQL_DSN');
$dbUser = getenv('PROMPT_TEST_MYSQL_USER') ?: 'root';
$dbPassword = getenv('PROMPT_TEST_MYSQL_PASSWORD') ?: '';
$pdo = new PDO($dsn, $dbUser, $dbPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$dbName = 'prompt_test_' . bin2hex(random_bytes(6));
$failure = null;
try {
    $pdo->exec('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4');
    parse_str(str_replace(';', '&', substr($dsn, 6)), $dsnParts);
    $app->config->set(['default' => 'prompt_test', 'connections' => ['prompt_test' => [
        'type' => 'mysql', 'hostname' => $dsnParts['host'] ?? '127.0.0.1', 'hostport' => $dsnParts['port'] ?? '3306',
        'database' => $dbName, 'username' => $dbUser, 'password' => $dbPassword, 'charset' => 'utf8mb4',
        'prefix' => '', 'fields_strict' => true, 'fields_cache' => false,
    ]]], 'database');
    Db::execute('CREATE TABLE model_configs (id int unsigned PRIMARY KEY AUTO_INCREMENT, user_id int unsigned,
        type varchar(16), name varchar(100), model_id varchar(100), endpoint varchar(255), api_key varchar(255),
        scope varchar(16), enabled tinyint, is_default tinyint, sort int, options json, create_time datetime, update_time datetime)');
    Db::execute('CREATE TABLE users (id int unsigned PRIMARY KEY, role varchar(20), status tinyint)');
    ModelConfig::create(['id' => 1, 'user_id' => 0, 'type' => 'text', 'name' => 'Fixture', 'model_id' => 'fixture',
        'endpoint' => 'http://unreachable.invalid', 'api_key' => '', 'scope' => 'global', 'enabled' => 1, 'is_default' => 1, 'sort' => 0, 'options' => []]);
    ModelConfig::create(['id' => 2, 'user_id' => 2, 'type' => 'text', 'name' => 'Private', 'model_id' => 'private',
        'endpoint' => 'http://unreachable.invalid', 'api_key' => '', 'scope' => 'user', 'enabled' => 1, 'is_default' => 1, 'sort' => 0, 'options' => []]);
    $client = new FixturePromptClient();
    $service = new PromptAssistantService($client);
    $session = $service->create(1, $settings);
    $other = $service->create(2, $settings);
    $fixtureRole = 'user';
    $app->bind(app\controller\PromptAssistantController::class, static function () use ($app, &$fixtureRole) {
        // CLI skips login; inject a fixture user while keeping real controller initialization and route dispatch.
        $controller = new app\controller\PromptAssistantController($app);
        $authUser = new ReflectionProperty(app\BaseController::class, 'authUser');
        $authUser->setAccessible(true);
        $authUser->setValue($controller, new app\model\User(['id' => 1, 'role' => $fixtureRole]));
        return $controller;
    });
    $routedList = promptRoute($app, 'list');
    check($routedList->getCode() === 200 && count($routedList->getData()['data']) === 1,
        'framework dispatch loads authenticated prompt history without Closure middleware errors');
    check(promptRoute($app, 'skills')->getCode() === 200, 'framework dispatch loads the Skill library');
    check(promptRoute($app, 'create', ['settings' => ['mode' => 'shell']])->getCode() === 422,
        'invalid prompt settings render HTTP 422 through the real exception handler');
    $fixtureRole = 'admin';
    check(promptRoute($app, 'list')->getCode() === 403, 'route dispatch preserves the non-admin access restriction');
    $fixtureRole = 'user';
    check(count($service->sessions(1)) === 1, 'session list isolated by current user');
    rejects(fn () => $service->detail(1, $other['id']), 'foreign session cannot be read');
    rejects(fn () => $service->deleteSession(1, $other['id']), 'foreign session cannot be deleted');
    rejects(fn () => $service->turn(1, $session['id'], 'cup', 'model-check-00000001', $settings + ['ignored' => true]), 'unknown request settings rejected');
    $foreignModel = $settings;
    $foreignModel['text_model_id'] = 2;
    rejects(fn () => $service->turn(1, $session['id'], 'cup', 'model-check-00000002', $foreignModel), 'explicit foreign model cannot silently fall back');
    $client->responses = [['action' => 'ask_user', 'reply' => 'Where will it be used?', 'brief' => ['subject' => 'cup'],
        'questions' => [['text' => 'Placement?', 'options' => ['Store', 'Social']]], 'skill_ids' => [], 'reason' => '']];
    $asked = $service->turn(1, $session['id'], 'Make a cup image', 'request-ask-00000001', $settings);
    check($asked['status'] === 'awaiting_input' && count($asked['messages']) === 2, 'clarification persisted with questions');
    $plan = ['action' => 'generate_prompt', 'reply' => 'Prompt ready', 'brief' => ['subject' => 'cup', 'goal' => 'e-commerce product display'],
        'questions' => [], 'skill_ids' => ['builtin:product-image'], 'reason' => 'Product photography'];
    $imageResult = ['title' => 'Cup image', 'prompt_body' => 'A cup on a clean background.', 'shots' => [], 'assumptions' => [], 'asset_requirements' => []];
    $client->responses = [$plan, $imageResult, ['issues' => [], 'notes' => []]];
    $generated = $service->turn(1, $session['id'], 'Store listing', 'request-generate-0001', $settings);
    check($generated['status'] === 'completed' && count($generated['results']) === 1, 'prompt generated with a persisted result version');
    check($generated['results'][0]['content']['skills'][0]['id'] === 'builtin:product-image', 'selected method recorded on result');
    $calls = count($client->calls);
    $repeated = $service->turn(1, $session['id'], 'Store listing', 'request-generate-0001', $settings);
    check(count($client->calls) === $calls && count($repeated['results']) === 1, 'completed request replay does not call or charge the model again');
    $edited = $service->saveResult(1, $session['id'], $generated['results'][0]['id'], ['prompt_body' => 'Edited cup scene.', 'shots' => []]);
    check(count($edited['results']) === 2 && $edited['results'][1]['content']['prompt_body'] === $imageResult['prompt_body'], 'editing preserves the original version');
    rejects(fn () => $service->saveResult(2, $session['id'], $generated['results'][0]['id'], ['prompt_body' => 'bad']), 'foreign prompt cannot be edited');
    $client->responses = [['action' => 'run_shell'], ['action' => 'run_shell']];
    $failed = $service->turn(1, $session['id'], 'New request', 'request-failed-000001', $settings);
    check($failed['status'] === 'failed' && $failed['error_message'] !== '', 'invalid decisions fail with recoverable state');
    $messages = count($failed['messages']);
    $client->responses = [$plan, $imageResult, ['issues' => [], 'notes' => []]];
    $retried = $service->turn(1, $session['id'], 'New request', 'request-failed-000001', $settings);
    check($retried['status'] === 'completed' && count($retried['messages']) === $messages + 1, 'failed request retry does not duplicate its user message');
    $client->responses = [new PDOException('Private SQL diagnostics')];
    $dbFailure = $service->turn(1, $session['id'], 'Database failure fixture', 'request-db-failed001', $settings);
    check($dbFailure['status'] === 'failed' && !str_contains($dbFailure['error_message'], 'Private SQL diagnostics'),
        'database error internals are excluded from user-facing session errors');
    Db::name('prompt_assistant_sessions')->where('id', $session['id'])->update(['status' => 'running']);
    rejects(fn () => $service->turn(1, $session['id'], 'Concurrent', 'request-concurrent01', $settings), 'concurrent generation rejected');
    Db::name('prompt_assistant_sessions')->where('id', $session['id'])->update(['status' => 'completed']);
    $instructions = str_repeat('Collect essential inputs, ask only missing questions, preserve source facts, describe concrete visual actions and validate output constraints. ', 3);
    $candidate = ['name' => 'specialized-cup-scene', 'description' => 'Specialized scene prompts', 'instructions' => $instructions,
        'test_cases' => ['Make an image showing the special scene.', 'I need something unusual, but have no product details.']];
    $candidatePlan = $plan;
    $candidatePlan['action'] = 'create_skill';
    $candidatePlan['skill_ids'] = [];
    $candidatePlan['candidate'] = $candidate;
    $client->responses = [$candidatePlan, $imageResult, ['issues' => [], 'notes' => []]];
    $created = $service->turn(1, $session['id'], 'Special scene', 'request-candidate001', $settings);
    $candidateId = $created['results'][0]['content']['candidate_skill_id'];
    check($service->skill(1, $candidateId)['status'] === 'draft', 'new method saved as a private candidate');
    check(count($service->skills(2)) === 6, 'candidate not visible to another user');
    check(count($service->skills(1, false)) === 6, 'unvalidated candidates excluded from automatic matching');
    rejects(fn () => $service->skill(2, $candidateId), 'foreign skill cannot be opened');
    $client->responses = [['output' => 'Baseline one'], ['output' => 'Improved one'], ['output' => 'Baseline two'], ['output' => 'Improved two'],
        ['passed' => true, 'evidence' => ['The method preserves the factual constraints in both cases.'], 'issues' => []]];
    $validated = $service->validateSkill(1, $candidateId, 1);
    check($validated['status'] === 'ready' && count($validated['evaluations']['outputs']) === 2, 'comparative evaluation enables the private method');
    check(count($service->skills(1, false)) === 7, 'validated method available for future matching');
    check(count($service->skills(2, false)) === 6, 'validated private methods stay outside another user matching catalog');
    rejects(fn () => PromptAssistantPolicy::plan(array_replace($plan, ['skill_ids' => [$candidateId]]), $service->skills(2, false), 'image'),
        'another user cannot select a validated private method by its ID');
    rejects(fn () => $service->skill(2, $candidateId), 'validated private methods cannot be opened by another user');
    rejects(fn () => $service->deleteSkill(2, $candidateId), 'validated private methods cannot be deleted by another user');
    $client->responses = [new RuntimeException('Fixture provider unavailable')];
    rejects(fn () => $service->validateSkill(1, $candidateId, 1), 'provider failure interrupts skill evaluation');
    check($service->skill(1, $candidateId)['status'] === 'ready', 'failed evaluation restores the original skill status');
    $skillRowId = (int) substr($candidateId, 8);
    Db::name('prompt_assistant_skills')->where('id', $skillRowId)->update(['status' => 'validating']);
    rejects(fn () => $service->validateSkill(1, $candidateId, 1), 'concurrent skill evaluation rejected');
    rejects(fn () => $service->deleteSkill(1, $candidateId), 'a validating skill cannot be deleted');
    Db::name('prompt_assistant_skills')->where('id', $skillRowId)->update(['update_time' => date('Y-m-d H:i:s', time() - 700)]);
    check($service->skill(1, $candidateId)['status'] === 'draft', 'interrupted evaluation recovers to an unapproved candidate');
    $client->responses = [['output' => 'Baseline one'], ['output' => 'Same one'], ['output' => 'Baseline two'], ['output' => 'Same two'],
        ['passed' => true, 'evidence' => [], 'issues' => []]];
    check($service->validateSkill(1, $candidateId, 1)['status'] === 'draft', 'evaluation without improvement evidence cannot enable a method');
    ModelConfig::create(['id' => 3, 'user_id' => 0, 'type' => 'video', 'name' => 'Fixture video', 'model_id' => 'fixture-video',
        'endpoint' => 'http://unreachable.invalid', 'api_key' => 'fixture-only-key', 'scope' => 'global', 'enabled' => 1,
        'is_default' => 1, 'sort' => 0, 'options' => ['duration_options' => [5], 'max_duration' => 5]]);
    $videoSettings = PromptAssistantPolicy::settings(['mode' => 'video', 'text_model_id' => 1, 'target_model_id' => 3, 'duration' => 10, 'duration_confirmed' => true]);
    $videoSession = $service->create(1, $videoSettings);
    $videoPlan = $plan;
    $videoPlan['skill_ids'] = ['builtin:storyboard-video'];
    $badVideo = $result;
    $badVideo['shots'][0]['duration'] = 2;
    $badVideo['shots'][1]['duration'] = 8;
    $client->responses = [$videoPlan, $badVideo, $badVideo];
    check($service->turn(1, $videoSession['id'], 'Cup video', 'video-invalid-000001', $videoSettings)['status'] === 'failed',
        'model clip duration constraints enforced after bounded repair');
    $client->responses = [$videoPlan, $result, ['issues' => [], 'notes' => []]];
    $videoGenerated = $service->turn(1, $videoSession['id'], 'Cup video', 'video-invalid-000001', $videoSettings);
    check($videoGenerated['status'] === 'completed' && count($videoGenerated['results'][0]['content']['shots']) === 2,
        'valid video storyboard persists after retry');
    $decisionCall = array_values(array_filter($client->calls, static fn (array $call): bool => $call['stage'] === 'decision'));
    check(!isset(end($decisionCall)['input']['context']['target_model']['api_key']), 'model credentials are excluded from agent context');
    $service->deleteSession(1, $videoSession['id']);
    $unconfirmedSettings = PromptAssistantPolicy::settings(['mode' => 'video', 'text_model_id' => 1]);
    $durationSession = $service->create(1, $unconfirmedSettings);
    $startCalls = count($client->calls);
    $client->responses = [$videoPlan];
    $durationAsked = $service->turn(1, $durationSession['id'], '产品视频，不要人物，只用产品图。', 'duration-ask-0000001', $unconfirmedSettings);
    check($durationAsked['status'] === 'awaiting_input' && $durationAsked['results'] === []
        && str_contains(end($durationAsked['messages'])['questions'][0]['text'], '多少秒') && count($client->calls) === $startCalls + 1,
        'video delivery cannot bypass explicit duration confirmation even when the model tries to generate');
    $durationPlan = $videoPlan;
    $durationPlan['specifications'] = ['duration' => 15, 'evidence' => '15秒'];
    $durationResult = $imageResult;
    $durationResult['shots'] = [['title' => '展示', 'duration' => 15, 'prompt_body' => '只展示产品，不要人物。']];
    $client->responses = [$durationPlan, $durationResult, ['issues' => [], 'notes' => []]];
    $durationReady = $service->turn(1, $durationSession['id'], '15秒，沿用刚才确认的要求。', 'duration-ready-00001', $unconfirmedSettings);
    check($durationReady['status'] === 'completed' && $durationReady['settings']['duration'] === 15
        && $durationReady['settings']['duration_confirmed'] && $durationReady['results'][0]['content']['settings']['duration'] === 15,
        'a conversational duration answer persists into session and result settings');
    $durationCalls = array_slice($client->calls, -3);
    check(count($durationCalls[1]['input']['messages']) === 3 && count($durationCalls[2]['input']['messages']) === 3
        && $durationCalls[1]['input']['messages'][0]['text'] === '产品视频，不要人物，只用产品图。',
        'generation and review both receive the original user answers and earlier constraints');
    $client->responses = [$videoPlan, $durationResult, ['issues' => [], 'notes' => []]];
    $durationContinued = $service->turn(1, $durationSession['id'], '换一个背景即可。', 'duration-next-000001', $durationReady['settings']);
    check($durationContinued['status'] === 'completed' && end($durationContinued['messages'])['questions'] === [],
        'a confirmed duration does not trigger another question on the next turn');
    $service->deleteSession(1, $durationSession['id']);
    $longSession = $service->create(1, $settings);
    $olderMessages = [['role' => 'user', 'text' => '只用产品图，不要人物，也不要品牌文案。']];
    for ($index = 0; $index < 18; $index++) {
        $olderMessages[] = ['role' => $index % 2 ? 'user' : 'assistant', 'text' => 'Earlier conversation ' . $index];
    }
    Db::name('prompt_assistant_sessions')->where('id', $longSession['id'])->update(['messages_json' => json_encode($olderMessages, JSON_UNESCAPED_UNICODE)]);
    $client->responses = [$plan, $imageResult, ['issues' => [], 'notes' => []]];
    $longResult = $service->turn(1, $longSession['id'], '按刚才的要求继续。', 'context-long-0000001', $settings);
    $longCalls = array_slice($client->calls, -3);
    check($longResult['status'] === 'completed' && count($longCalls[0]['input']['messages']) === 20
        && $longCalls[0]['input']['messages'][0]['text'] === $olderMessages[0]['text']
        && $longCalls[1]['input']['messages'] === $longCalls[0]['input']['messages']
        && $longCalls[2]['input']['messages'] === $longCalls[0]['input']['messages'],
        'all stages retain original constraints beyond the old sixteen-message history cutoff');
    $service->deleteSession(1, $longSession['id']);
    $repairSession = $service->create(1, $settings);
    $startCalls = count($client->calls);
    $client->responses = [$plan, $imageResult,
        ['issues' => ['A previously confirmed constraint was omitted.'], 'notes' => [], 'questions' => [], 'repair_instructions' => ['Restore the confirmed product-only constraint.']],
        array_replace($imageResult, ['prompt_body' => 'Only the cup on a clean background, no person.']), ['issues' => [], 'notes' => []]];
    $autoRepaired = $service->turn(1, $repairSession['id'], '只要杯子，不要人物。', 'review-auto-00000001', $settings);
    check($autoRepaired['status'] === 'completed' && end($autoRepaired['messages'])['questions'] === []
        && str_contains($autoRepaired['results'][0]['content']['prompt_body'], 'no person')
        && array_column(array_slice($client->calls, $startCalls), 'stage') === ['decision', 'generate', 'review', 'revise', 'review'],
        'technical review corrections are repaired automatically instead of becoming repeated user questions');
    $service->deleteSession(1, $repairSession['id']);
    $recoverySession = $service->create(1, $settings);
    $startCalls = count($client->calls);
    $badOutput = '{"issues":["Product facts need verification."]},"notes":["Text review only."]}';
    $client->responses = [new PromptAssistantResponseException('{"action":'), $plan,
        new PromptAssistantResponseException('{"title":'), $imageResult,
        new PromptAssistantResponseException($badOutput), ['issues' => [], 'notes' => ['Text review only.']]];
    $recovered = $service->turn(1, $recoverySession['id'], 'Repair all stages', 'request-json-six-0001', $settings);
    $recoveryCalls = array_slice($client->calls, $startCalls);
    check($recovered['status'] === 'completed' && count($recovered['results']) === 1
        && array_column($recoveryCalls, 'stage') === ['decision', 'decision_repair', 'generate', 'generate_repair', 'review', 'review_repair'],
        'malformed JSON at all three stages recovers within six calls');
    check($recoveryCalls[5]['input']['previous_response'] === $badOutput
        && $recovered['results'][0]['content']['checks']['notes'] === ['Text review only.'],
        'review repair receives the invalid output as data and preserves recovered notes');
    $client->responses = [new PromptAssistantResponseException('Private malformed output'), new PromptAssistantResponseException('Private malformed output')];
    $calls = count($client->calls);
    $failedJson = $service->turn(1, $recoverySession['id'], 'Bad decision', 'request-json-fail001', $settings);
    check($failedJson['status'] === 'failed' && count($client->calls) === $calls + 2 && count($failedJson['results']) === 1
        && str_contains($failedJson['error_message'], 'JSON') && !str_contains($failedJson['error_message'], 'Private'),
        'repeated malformed decisions stop after one repair with a safe retry error');
    $client->responses = [new PromptAssistantResponseException('{'), ['action' => 'run_shell']];
    $unsafeRepair = $service->turn(1, $recoverySession['id'], 'Unsafe repair', 'request-json-unsafe1', $settings);
    check($unsafeRepair['status'] === 'failed' && count($unsafeRepair['results']) === 1,
        'format repair cannot bypass the decision action whitelist');
    $client->responses = [$plan, new PromptAssistantResponseException('{'), new PromptAssistantResponseException('{')];
    $failedGeneration = $service->turn(1, $recoverySession['id'], 'Bad generation', 'request-json-genfail', $settings);
    check($failedGeneration['status'] === 'failed' && count($failedGeneration['results']) === 1,
        'unrecoverable generation does not save an invalid prompt');
    $client->responses = [$plan, $imageResult, ['notes' => []], ['issues' => [], 'notes' => []]];
    $schemaRepair = $service->turn(1, $recoverySession['id'], 'Missing review fields', 'request-json-schema1', $settings);
    check($schemaRepair['status'] === 'completed' && $schemaRepair['results'][0]['content']['checks']['status'] === 'checked',
        'review missing required fields is repaired before receiving checked status');
    $client->responses = [$plan, $imageResult, ['issues' => 'invalid', 'notes' => []], ['issues' => [], 'unexpected' => true]];
    $unreviewed = $service->turn(1, $recoverySession['id'], 'Review schema fails', 'request-json-schema2', $settings);
    check($unreviewed['status'] === 'completed' && $unreviewed['error_message'] === ''
        && $unreviewed['results'][0]['content']['prompt_body'] === $imageResult['prompt_body']
        && $unreviewed['results'][0]['content']['checks']['status'] === 'needs_review'
        && $unreviewed['results'][0]['content']['checks']['issues'] !== [],
        'repeated invalid review schema preserves the prompt with a visible manual review requirement');
    $calls = count($client->calls);
    $replayed = $service->turn(1, $recoverySession['id'], 'Review schema fails', 'request-json-schema2', $settings);
    check(count($client->calls) === $calls && count($replayed['results']) === count($unreviewed['results'])
        && count($replayed['messages']) === count($unreviewed['messages']),
        'unreviewed completed request replay does not duplicate output, messages or calls');
    $client->responses = [$plan, $imageResult, new RuntimeException('Model service unavailable')];
    $calls = count($client->calls);
    $unavailableReview = $service->turn(1, $recoverySession['id'], 'Review service fails', 'request-review-down1', $settings);
    check($unavailableReview['status'] === 'completed'
        && $unavailableReview['results'][0]['content']['checks']['status'] === 'needs_review'
        && count($client->calls) === $calls + 3,
        'review transport errors preserve output without automatic transport retries');
    $service->deleteSession(1, $recoverySession['id']);
    $clarificationSession = $service->create(1, $settings);
    $question = ['text' => 'Show water droplets instead of unverified internal structures?', 'options' => ['Yes', 'No']];
    $client->responses = [$plan, ['questions' => [$question]]];
    $clarification = $service->turn(1, $clarificationSession['id'], 'Waterproof earphones', 'request-open-facts01', $settings);
    check($clarification['status'] === 'awaiting_input' && $clarification['results'] === []
        && end($clarification['messages'])['questions'] === [$question], 'generation returns blocking questions in the conversation without saving a partial prompt');
    $client->responses = [$plan, $imageResult, ['issues' => ['Unverified product internals.'], 'notes' => [], 'questions' => [$question]]];
    $reviewQuestions = $service->turn(1, $clarificationSession['id'], 'Review facts', 'request-open-facts02', $settings);
    check($reviewQuestions['status'] === 'awaiting_input' && $reviewQuestions['results'] === []
        && end($reviewQuestions['messages'])['action'] === 'ask_user', 'reviewed factual gaps become questions before final delivery');
    $client->responses = [$plan, $imageResult, ['issues' => [], 'notes' => []]];
    $confirmed = $service->turn(1, $clarificationSession['id'], 'Yes, droplets only.', 'request-open-facts03', $settings);
    check($confirmed['status'] === 'completed' && count($confirmed['results']) === 1, 'confirmed facts produce a single clean final result');
    $unconfirmed = $imageResult;
    $unconfirmed['asset_requirements'] = ['Product exterior needs confirmation'];
    $client->responses = [$plan, $unconfirmed, ['questions' => [$question]]];
    $gated = $service->turn(1, $clarificationSession['id'], 'Incomplete result', 'request-open-facts04', $settings);
    check($gated['status'] === 'awaiting_input' && count($gated['results']) === 1, 'unresolved requirements cannot silently enter a final result');
    $service->deleteSession(1, $clarificationSession['id']);
    check(promptRoute($app, 'upload')->getCode() === 422, 'upload route reports a missing file through the framework');
    $routedUpload = promptRoute($app, 'upload', [], ['file' => new think\file\UploadedFile(__DIR__ . '/../public/logo.png', 'route.png', 'image/png', UPLOAD_ERR_OK, true)]);
    check($routedUpload->getCode() === 201 && $routedUpload->getData()['data']['id'] > 0,
        'authenticated multipart upload route stores a usable persistent reference (HTTP ' . $routedUpload->getCode() . ')');
    $uploaded = PromptAssistantReferences::upload(1, new think\file\UploadedFile(__DIR__ . '/../public/logo.png', 'product.png', 'image/png', UPLOAD_ERR_OK, true));
    check($uploaded['id'] > 0 && str_contains($uploaded['url'], '/storage/'), 'image upload stores an owner-scoped persistent reference');
    $imageSettings = array_replace($settings, ['reference_ids' => [$uploaded['id']]]);
    rejects(fn () => $service->create(2, $imageSettings), 'foreign uploaded reference cannot be attached');
    $imageSession = $service->create(1, $imageSettings);
    rejects(fn () => $service->turn(1, $imageSession['id'], 'Analyze', 'request-no-vision01', $imageSettings), 'text-only model cannot silently process attached images');
    $visionModel = ModelConfig::create(['user_id' => 0, 'type' => 'text', 'name' => 'Vision fixture', 'model_id' => 'vision-fixture',
        'endpoint' => 'http://unreachable.invalid', 'api_key' => '', 'scope' => 'global', 'enabled' => 1, 'is_default' => 0,
        'sort' => 0, 'options' => ['supports_vision' => true]]);
    $imageSettings['vision_model_id'] = (int) $visionModel->id;
    $analysis = ['observations' => ['A purple circular product.'], 'uncertain' => ['Internal structure is not visible.']];
    $referencedResult = $imageResult + ['reference_usage' => [['reference_index' => 1, 'instruction' => 'Preserve the visible product shape and color; replace the original background.']]];
    $client->responses = [$analysis, $plan, $referencedResult, ['issues' => [], 'notes' => []]];
    $calls = count($client->calls);
    $withImage = $service->turn(1, $imageSession['id'], 'Use the product image', 'request-with-vision1', $imageSettings);
    check($withImage['status'] === 'completed' && $withImage['results'][0]['content']['references'] == [$uploaded]
        && $withImage['messages'][0]['references'] == [$uploaded], 'uploaded reference survives messages, persistence and result handoff');
    check(str_contains($withImage['results'][0]['content']['prompt_body'], '@图片1：Preserve the visible product shape')
        && str_contains($withImage['results'][0]['content']['full_prompt'], 'replace the original background'),
        'reference identity and usage instructions are part of the persisted usable prompt');
    check(str_starts_with($client->calls[$calls]['images'][0], 'data:image/jpeg;base64,')
        && $client->calls[$calls + 1]['input']['context']['image_analysis'] === $analysis,
        'local image bytes reach the vision stage and verified observations reach the decision stage');
    $client->responses = [$plan, $referencedResult, ['issues' => [], 'notes' => []]];
    $calls = count($client->calls);
    $service->turn(1, $imageSession['id'], 'Use a darker background', 'request-with-vision2', $imageSettings);
    check(count($client->calls) === $calls + 3 && $client->calls[$calls]['stage'] === 'decision', 'unchanged reference images reuse prior analysis across conversation turns');
    $otherUpload = PromptAssistantReferences::upload(1, new think\file\UploadedFile(__DIR__ . '/../public/logo.png', 'new.png', 'image/png', UPLOAD_ERR_OK, true));
    $imageSettings['reference_ids'] = [$otherUpload['id']];
    $client->responses = [$analysis, $plan, $referencedResult, ['issues' => [], 'notes' => []]];
    $calls = count($client->calls);
    $changedImage = $service->turn(1, $imageSession['id'], 'Different reference', 'request-with-vision3', $imageSettings);
    check($client->calls[$calls]['stage'] === 'image_analysis' && $changedImage['results'][0]['content']['references'][0]['id'] === $otherUpload['id'],
        'changing images invalidates cached analysis and preserves per-version references');
    $imageSettings['reference_ids'] = [$uploaded['id'], $otherUpload['id']];
    $selectedResult = $imageResult + ['reference_usage' => [['reference_index' => 2, 'instruction' => 'Use only the second product exterior, with a new plain background.']]];
    $client->responses = [$analysis, $plan, $selectedResult, ['issues' => [], 'notes' => []]];
    $selectedImages = $service->turn(1, $imageSession['id'], 'Use only the second product image', 'request-image-select1', $imageSettings);
    $selectedContent = $selectedImages['results'][0]['content'];
    check($selectedImages['status'] === 'completed' && $selectedContent['references'] == [$otherUpload]
        && str_contains($selectedContent['prompt_body'], '@图片1：Use only the second product exterior')
        && count($selectedImages['references']) === 2, 'AI selects generation attachments while all source images remain in the conversation');
    $selectedEdited = $service->saveResult(1, $imageSession['id'], $selectedImages['results'][0]['id'], ['prompt_body' => $selectedContent['prompt_body'] . ' Soft light.']);
    check($selectedEdited['results'][0]['content']['references'] == [$otherUpload]
        && substr_count($selectedEdited['results'][0]['content']['prompt_body'], '@图片1：') === 1,
        'manual edits preserve selected images without duplicating reference instructions');
    $client->responses = [$plan, $imageResult, $selectedResult, ['issues' => [], 'notes' => []]];
    $usageRepair = $service->turn(1, $imageSession['id'], 'Repair missing image usage', 'request-image-select2', $imageSettings);
    check($usageRepair['status'] === 'completed' && $usageRepair['results'][0]['content']['references'] == [$otherUpload],
        'missing usage instructions trigger bounded generation repair instead of silent attachment fallback');
    rejects(fn () => PromptAssistantReferences::modelImages([['url' => '/storage/../../.env']]), 'local reference reading cannot escape the storage directory');
    $preview = PromptAssistantReferences::modelImages([$uploaded])[0];
    $previewSize = getimagesizefromstring(base64_decode(substr($preview, strpos($preview, ',') + 1)));
    check(max($previewSize[0], $previewSize[1]) <= 1600 && strlen($preview) < 4 * 1024 * 1024,
        'analysis preview bounds image resolution and encoded size without changing the original reference');
    check(PromptAssistantReferences::modelImages([['kind' => 'asset', 'url' => 'https://example.com/storage/v1/object/product.png']])
        === ['https://example.com/storage/v1/object/product.png'], 'owner-checked remote asset storage paths are not mistaken for local files');
    $service->deleteSession(1, $imageSession['id']);
    rejects(fn () => $service->deleteSkill(1, 'builtin:general-image'), 'built-in methods cannot be deleted');
    $service->deleteSkill(1, $candidateId);
    rejects(fn () => $service->skill(1, $candidateId), 'owned candidate can be removed');
    $service->deleteSession(1, $session['id']);
    check(count($service->sessions(1)) === 0 && count($service->sessions(2)) === 1, 'session deletion leaves other users intact');
    if (getenv('PROMPT_TEST_HTTP_URL')) {
        $pricing = new ReflectionProperty(app\support\CreditService::class, 'pricingCache');
        $pricing->setAccessible(true);
        $pricing->setValue(null, ['enabled' => false]);
        Db::execute('CREATE TABLE ai_request_logs (id int unsigned PRIMARY KEY AUTO_INCREMENT, user_id int, source varchar(64),
            model_config_id int, llm_model varchar(120), endpoint varchar(500), context_json text, request_json mediumtext,
            http_status int, response_body mediumtext, usage_json json, curl_errno int, curl_error text, request_ok tinyint,
            error_message text, duration_ms int, max_tokens int, finish_reason varchar(32), content_preview text,
            prompt_tokens int, completion_tokens int, total_tokens int, create_time datetime)');
        $httpModel = new ModelConfig(['id' => 1, 'model_id' => 'deepseek-chat', 'api_key' => 'fixture-only-key',
            'endpoint' => getenv('PROMPT_TEST_HTTP_URL'), 'options' => []]);
        $httpClient = new PromptAssistantTextClient();
        $httpResult = $httpClient->complete($httpModel, 1, 'transport_fixture', 'Return JSON.', ['fixture_case' => 'normal']);
        check($httpResult === ['thinking_disabled' => true, 'json_mode' => true, 'no_tools' => true, 'authorized' => true, 'system_role' => true],
            'real HTTP adapter sends bounded DeepSeek-compatible JSON requests');
        $httpClient->complete($httpModel, 1, 'transport_fixture', 'Return JSON.', ['fixture_case' => 'fenced']);
        check((int) Db::name('ai_request_logs')->where('request_ok', 1)->count() === 2
            && (int) Db::name('ai_request_logs')->value('total_tokens') === 30, 'provider token usage stored through the existing request log');
        rejects(fn () => $httpClient->complete($httpModel, 1, 'transport_fixture', 'Return JSON.', ['fixture_case' => 'malformed']),
            'malformed provider JSON rejected');
        try {
            $httpClient->complete($httpModel, 1, 'transport_fixture', 'Return JSON.', ['fixture_case' => 'upstream_error']);
            throw new LogicException('Fixture should have failed');
        } catch (RuntimeException $error) {
            check(str_contains($error->getMessage(), 'HTTP 401') && str_contains($error->getMessage(), 'API Key')
                && !str_contains($error->getMessage(), 'Private upstream diagnostic'), 'invalid API keys produce a specific safe authentication error');
        }
        foreach (['insufficient_balance' => 402, 'forbidden' => 403, 'missing_model' => 404,
            'invalid_parameters' => 400, 'rate_limited' => 429, 'unavailable' => 503] as $case => $status) {
            try {
                (new PromptAssistantTextClient())->complete($httpModel, 1, 'transport_fixture', 'Return JSON.', ['fixture_case' => $case]);
                throw new LogicException('Fixture should have failed');
            } catch (RuntimeException $error) {
                check(str_contains($error->getMessage(), 'HTTP ' . $status)
                    && !str_contains($error->getMessage(), 'Private upstream diagnostic'), 'provider HTTP ' . $status . ' has a specific safe error');
            }
        }
        for ($index = 0; $index < 2; $index++) {
            $httpClient->complete($httpModel, 1, 'transport_fixture', 'Return JSON.', ['fixture_case' => 'normal']);
        }
        rejects(fn () => $httpClient->complete($httpModel, 1, 'transport_fixture', 'Return JSON.', ['fixture_case' => 'normal']),
            'per-request model call limit enforced by the HTTP adapter');
        foreach (['review_extra_brace', 'array_root', 'truncated'] as $case) {
            try {
                (new PromptAssistantTextClient())->complete($httpModel, 1, 'transport_fixture', 'Return JSON.', ['fixture_case' => $case]);
                throw new LogicException('Fixture should have failed');
            } catch (PromptAssistantResponseException $error) {
                check($error->output !== '' && ($case !== 'truncated' || str_contains($error->getMessage(), '长度上限')),
                    'HTTP ' . $case . ' returns a typed format error carrying repair data');
            }
        }
        foreach (['repair', 'invalid', 'unavailable', 'repair_unavailable', 'all_repairs', 'image_budget'] as $scenario) {
            $scenarioModel = ModelConfig::create(['user_id' => 0, 'type' => 'text', 'name' => 'Recovery HTTP fixture',
                'model_id' => 'deepseek-fixture-' . $scenario, 'endpoint' => getenv('PROMPT_TEST_HTTP_URL'),
                'api_key' => 'fixture-only-key', 'scope' => 'global', 'enabled' => 1, 'is_default' => 0, 'sort' => 0,
                'options' => $scenario === 'image_budget' ? ['supports_vision' => true] : []]);
            $scenarioSettings = array_replace($settings, ['text_model_id' => (int) $scenarioModel->id]);
            if ($scenario === 'image_budget') $scenarioSettings['reference_ids'] = [$uploaded['id']];
            $httpService = new PromptAssistantService(new PromptAssistantTextClient());
            $httpSession = $httpService->create(1, $scenarioSettings);
            $beforeLogs = (int) Db::name('ai_request_logs')->count();
            $httpGenerated = $httpService->turn(1, $httpSession['id'], 'Waterproof earphones', 'http-recovery-000001', $scenarioSettings);
            $expectedStatus = in_array($scenario, ['repair', 'all_repairs'], true) ? 'checked' : 'needs_review';
            $expectedCalls = in_array($scenario, ['all_repairs', 'image_budget'], true) ? 6 : ($scenario === 'unavailable' ? 3 : 4);
            check($httpGenerated['status'] === 'completed' && count($httpGenerated['results']) === 1
                && $httpGenerated['results'][0]['content']['checks']['status'] === $expectedStatus
                && (int) Db::name('ai_request_logs')->count() === $beforeLogs + $expectedCalls,
                'real HTTP service ' . $scenario . ' persists one result and logs every bounded model call');
            $httpService->deleteSession(1, $httpSession['id']);
        }
        $httpModel->setAttr('options', ['supports_vision' => true]);
        $visionResponse = (new PromptAssistantTextClient())->completeWithImages($httpModel, 1, 'vision_transport', 'Return JSON.',
            ['fixture_case' => 'vision'], PromptAssistantReferences::modelImages([$uploaded]), 512);
        check($visionResponse === ['image_count' => 1, 'inline_image' => true], 'real HTTP vision adapter sends image content parts with local image bytes');
        $visionLog = Db::name('ai_request_logs')->order('id', 'desc')->find();
        check(!str_contains($visionLog['request_json'], 'base64,') && str_contains($visionLog['request_json'], 'Attached images: 1'),
            'vision request logging excludes large base64 image bodies while retaining provider usage');
    }
    echo "All {$count} prompt assistant checks passed.\n";
} catch (Throwable $error) {
    $failure = $error;
} finally {
    $pdo->exec('DROP DATABASE `' . $dbName . '`');
}
if ($failure !== null) {
    fwrite(STDERR, $failure->getMessage() . PHP_EOL . $failure->getTraceAsString() . PHP_EOL);
    exit(1);
}
