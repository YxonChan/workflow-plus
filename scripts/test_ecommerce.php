<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use app\command\EcommerceWorker;
use app\model\ModelConfig;
use app\support\EcommercePolicy;
use app\support\EcommerceService;
use app\support\CreditService;
use think\facade\Db;

$app = new think\App(); $app->initialize();
set_exception_handler(static function (Throwable $e): void { fwrite(STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n"); exit(1); });
$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $label); $checks++; echo 'PASS: ' . $label . "\n"; }
function rejects(callable $call, string $label): void { try { $call(); } catch (Throwable) { check(true, $label); return; } throw new RuntimeException('FAIL: ' . $label); }
function invoke(object $object, string $method, ...$args) { return (new ReflectionMethod($object, $method))->invoke($object, ...$args); }
check(EcommercePolicy::recoveryStatus('', '') === 'unknown', 'unknown submission cannot be automatically resubmitted');
check(EcommercePolicy::recoveryStatus('tsk_test', '') === 'queued', 'known provider task resumes polling');
check(EcommercePolicy::recoveryStatus('', '/storage/done.png') === 'success', 'persisted result survives crash');
rejects(fn () => EcommercePolicy::request(['prompt' => 'x', 'images' => array_fill(0, 14, '/x')], 'image'), 'reference limit is explicit');
rejects(fn () => EcommercePolicy::request(['prompt' => 'x', 'options' => ['size' => ['bad']]], 'image'), 'structured values cannot enter scalar model parameters');
rejects(fn () => EcommercePolicy::imageOptions(['size' => '1:1'], 'gpt-image-2.5-flare-official'), 'Official rejects ratio instead of pixels');

$templateCatalog = \app\support\EcommerceImageDesign::catalog();
foreach ([['subtitle' => '自选副标题'], ['sellingPoints' => ['手工卖点']], ['facts' => ['price' => '¥59.90']]] as $copy) {
    $manualPoster = ['creative_goal' => 'poster', 'images' => [], 'original' => [
        'design' => ['purpose' => 'poster', 'presetId' => 'poster-hero'],
        'poster' => array_replace(['headline' => '', 'subtitle' => '', 'sellingPoints' => [], 'presetId' => 'poster-hero'], $copy),
    ]];
    check(\app\support\EcommerceImageDesign::validate($manualPoster, '')['purpose'] === 'poster', 'manual copy accepts ' . array_key_first($copy) . ' without an AI headline');
    $manualPoster['original']['poster']['headline'] = str_repeat('字', 21);
    rejects(fn () => \app\support\EcommerceImageDesign::validate($manualPoster, ''), 'manual copy still enforces headline length');
}
$retiredStyles = array_filter($templateCatalog['styles'], static fn (array $style): bool => ($style['retired'] ?? false) === true);
check(count($retiredStyles) === 12, 'exactly four old main and eight old detail templates are retired');
foreach ($retiredStyles as $presetId => $style) {
    check(($style['enabled'] ?? true) === false, $presetId . ' is unavailable for new work');
    $purpose = in_array('main', $style['purposes'], true) ? 'main' : 'detail';
    $reference = '/admin/ecommerce-box/styles/' . $presetId . '.jpg';
    $request = ['creative_goal' => $purpose, 'images' => [$reference], 'prompt' => '历史成品微调', 'original' => [
        'mode' => 'compose', 'n' => 1, 'size' => $purpose === 'main' ? '2048x2048' : '1365x2048', 'styleReference' => $reference,
        'design' => ['purpose' => $purpose, 'presetId' => $presetId, 'enabled' => true, 'retired' => false],
    ]];
    if ($purpose === 'main') $request['original']['campaign'] = ['shotId' => 'M1', 'presetId' => $presetId];
    else $request['original']['design']['layout'] = 'template';
    $pageKey = $purpose === 'main' ? 'M1' : '';
    rejects(fn () => \app\support\EcommerceImageDesign::validate($request, $pageKey), $presetId . ' cannot be reactivated by client flags');
    $request['original']['mode'] = 'edit';
    $validated = \app\support\EcommerceImageDesign::validate($request, $pageKey);
    check($validated['presetId'] === $presetId, $presetId . ' keeps its historical edit identity');
    $edited = \app\support\EcommerceSkills::apply($request, 'image', 'creative', $pageKey);
    check(count($edited['skill_snapshot']) === 1, $presetId . ' edits do not force a new composition');
    if ($presetId === 'bold-color') {
        check($validated['whiteBackground'] && str_contains(implode('\n', \app\support\EcommerceImageDesign::rules($validated, $pageKey)), '不绘制人物、场景、营销文案'), 'archived pure-white main retains its original purpose rules');
    }
}
foreach ($templateCatalog['styles'] as $presetId => $style) {
    if (($style['templatePack'] ?? '') !== 'commerce-v1') continue;
    $purpose = $style['purposes'][0];
    $reference = '/admin/ecommerce-box/styles/' . $presetId . '.jpg';
    $design = ['purpose' => $purpose, 'presetId' => $presetId, 'layout' => $purpose === 'detail' ? 'template' : ''];
    $templateRequest = ['creative_goal' => $purpose, 'images' => [$reference], 'prompt' => '商品测试', 'original' => [
        'design' => $design, 'styleReference' => $reference, 'mode' => 'compose', 'n' => 1,
        'size' => $purpose === 'detail' ? '1365x2048' : '2048x2048',
    ]];
    if ($purpose === 'main') $templateRequest['original']['campaign'] = ['shotId' => 'M1', 'presetId' => $presetId];
    $pageKey = $purpose === 'main' ? 'M1' : '';
    if (($style['enabled'] ?? true) === false) {
        $templateRequest['original']['design']['enabled'] = true;
        $templateRequest['original']['design']['generationRules'] = ['untrusted-template-rule'];
        rejects(fn () => \app\support\EcommerceImageDesign::validate($templateRequest, $pageKey), $presetId . ' cannot be activated by the client before image import');
    }
    // Disabling a template for new work must not invalidate frozen historical edits.
    $templateRequest['original']['mode'] = 'edit';
    $validatedDesign = \app\support\EcommerceImageDesign::validate($templateRequest, $pageKey);
    $rules = \app\support\EcommerceImageDesign::rules($validatedDesign, $pageKey);
    check(in_array(end($style['generationRules']), $rules, true) && !in_array('untrusted-template-rule', $rules, true), $presetId . ' uses only server-maintained layout methods');
    $edited = \app\support\EcommerceSkills::apply($templateRequest, 'image', 'creative', $pageKey);
    check(!str_contains($edited['prompt'], '本模板构图：') && count($edited['skill_snapshot']) === 1, $presetId . ' edits preserve the frozen composition without reapplying a template');
}

// Only the uniquely named disposable database is mutated. Real models/queues are never used.
$originalConfig = $app->config->get('database');
$admin = Db::connect();
$db = 'ecommerce_test_' . bin2hex(random_bytes(6));
$admin->execute('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4');
$connection = $originalConfig['connections'][$originalConfig['default']];
$connection['database'] = $db; $connection['prefix'] = ''; $connection['trigger_sql'] = false;
$app->config->set(['default' => 'ecommerce_test', 'connections' => ['ecommerce_test' => $connection]], 'database');
$createdFiles = [];
$fixtureProcess = null;
try {
    Db::execute('CREATE TABLE users (id int PRIMARY KEY, role varchar(20), status tinyint, credit_balance decimal(14,2))');
    Db::execute('CREATE TABLE model_configs (id int unsigned PRIMARY KEY AUTO_INCREMENT, user_id int, type varchar(16), name varchar(100), model_id varchar(100), endpoint varchar(255), api_key varchar(255), scope varchar(16), enabled tinyint, is_default tinyint, sort int, options json, create_time datetime, update_time datetime)');
    Db::execute('CREATE TABLE ai_request_logs (id int unsigned PRIMARY KEY AUTO_INCREMENT, user_id int, source varchar(64), model_config_id int, llm_model varchar(120), endpoint varchar(500), context_json mediumtext, request_json mediumtext, http_status int, response_body mediumtext, usage_json json, curl_errno int, curl_error text, request_ok tinyint, error_message text, duration_ms int, max_tokens int, finish_reason varchar(32), content_preview text, prompt_tokens int, completion_tokens int, total_tokens int, credits_charged decimal(14,2) DEFAULT 0, create_time datetime, workflow_run_id int, workflow_run_node_id int)');
    Db::execute('CREATE TABLE quick_create_messages (id int PRIMARY KEY AUTO_INCREMENT, user_id int, mode varchar(8), status varchar(16))');
    Db::execute('CREATE TABLE asset_image_jobs (id int PRIMARY KEY AUTO_INCREMENT, user_id int, status varchar(16))');
    Db::execute('CREATE TABLE video_jobs (id int PRIMARY KEY AUTO_INCREMENT, user_id int, status varchar(16))');
    Db::execute('CREATE TABLE assets (id int PRIMARY KEY AUTO_INCREMENT, user_id int, series_id int, type varchar(20), name varchar(100), description text, image_prompt text, tags json, create_time datetime, update_time datetime)');
    Db::execute('CREATE TABLE asset_images (id int PRIMARY KEY AUTO_INCREMENT, user_id int, asset_id int, view_type varchar(20), url text, note text, image_prompt text, sort int, create_time datetime, update_time datetime)');
    Db::name('users')->insertAll([['id' => 801, 'role' => 'user', 'status' => 1, 'credit_balance' => 5000], ['id' => 802, 'role' => 'user', 'status' => 1, 'credit_balance' => 5000]]);
    $base = getenv('ECOMMERCE_TEST_HTTP_URL') ?: 'http://127.0.0.1:18089';
    if (!getenv('ECOMMERCE_TEST_HTTP_URL')) {
        $fixtureProcess = proc_open([PHP_BINARY, '-S', '127.0.0.1:18089', __DIR__ . '/fixtures/ecommerce_http.php'],
            [['pipe', 'r'], ['file', sys_get_temp_dir() . '/ecommerce-fixture-server.log', 'a'], ['file', sys_get_temp_dir() . '/ecommerce-fixture-server.log', 'a']], $pipes);
        for ($attempt = 0; $attempt < 30; $attempt++) {
            if (@file_get_contents($base . '/state') !== false) break;
            usleep(100000);
        }
    }
    $common = ['user_id' => 0, 'type' => 'image', 'scope' => 'global', 'enabled' => 1, 'is_default' => 1, 'sort' => 0, 'name' => 'Fixture', 'endpoint' => $base . '/v1', 'api_key' => 'fixture-only', 'options' => ['provider' => 'toapis', 'aspect_ratio' => '16:9', 'poll_interval' => 1]];
    ModelConfig::create($common + ['id' => 1, 'model_id' => 'gpt-image-2.5-flare']);
    ModelConfig::create(array_replace($common, ['id' => 2, 'user_id' => 802, 'scope' => 'user', 'model_id' => 'gpt-image-2.5-flare']));
    ModelConfig::create(array_replace($common, ['id' => 3, 'model_id' => 'gpt-image-2.5-flare-official']));
    ModelConfig::create(array_replace($common, ['id' => 4, 'type' => 'video', 'model_id' => 'seedance-2',
        'endpoint' => $base . '/v1/videos/generations', 'options' => ['poll_attempts' => 1, 'poll_interval' => 2]]));
    $service = new EcommerceService();
    $state = ['schema' => 1, 'name' => 'Fixture', 'model' => ['uploads' => [], 'results' => []], 'product' => ['uploads' => [], 'results' => []], 'scene' => ['uploads' => [], 'results' => []], 'creative' => ['uploads' => [], 'results' => []]];
    $project = $service->create(801, $state);
    $id = $project['id'];
    check(count($service->projects(801)) === 1 && $service->projects(802) === [], 'projects are private to their owner');
    rejects(fn () => $service->detail(802, $id), 'cross-user project reads rejected');
    rejects(fn () => $service->save(802, $id, 1, $state), 'cross-user project writes rejected');
    check($service->save(801, $id, 1, $state)['revision'] === 2, 'project saves advance revision');
    rejects(fn () => $service->save(801, $id, 1, $state), 'stale revision cannot overwrite newer project');
    rejects(fn () => $service->model(801, 'image', 2), 'foreign model cannot silently fall back to default');
    rejects(fn () => $service->model(801, 'image', 9999), 'invalid explicit model rejected');
    $foreign = $service->create(802, $state);
    $service->registerMedia(802, $foreign['id'], '/storage/foreign.png', 'foreign');
    $badState = $state; $badState['product']['uploads'] = [['src' => '/storage/foreign.png']];
    rejects(fn () => $service->save(801, $id, 2, $badState), 'foreign source rejected even with a forged client media record');
    $item = ['kind' => 'creative', 'mode' => 'image', 'title' => 'H1', 'page_key' => 'H1', 'model_config_id' => 1,
        'request' => ['prompt' => str_repeat('商品细节 ', 1200), 'images' => [], 'options' => ['size' => '1:1', 'resolution' => '2K'],
            'original' => ['prompt' => 'snapshot', 'images' => [], 'size' => '2048x2048', 'mode' => 'compose', 'n' => 1]]];
    $plan = $service->plan(801, $id, array_fill(0, 12, $item));
    check($plan['credits'] === 216.0 && $plan['count'] === 12 && !$plan['estimated'], '12-page 2K campaign quotes current 216 credits');
    check(mb_strlen($plan['items'][0]['request']['prompt']) > 4000, 'long campaign prompt is not truncated to quick-create limit');
    check(array_column($plan['items'][0]['request']['skill_snapshot'], 'id') === ['builtin:product-image', 'builtin:product-studio-image'], 'main image automatically uses shared product and studio skills');
    check(str_contains($plan['items'][0]['request']['prompt'], '自然接触阴影'), 'selected skills are compiled into the actual provider prompt');
    $requoted = $service->plan(801, $id, $plan['items']);
    check($requoted['items'][0]['request']['prompt'] === $plan['items'][0]['request']['prompt'], 're-quote and explicit retry do not duplicate skill instructions');
    $designCatalog = \app\support\EcommerceImageDesign::catalog();
    foreach ($designCatalog['purposes'] as $purpose) {
        foreach ($designCatalog['styles'] as $presetId => $style) {
            $designItem = $item;
            $designItem['page_key'] = $purpose['pageIds'][0] ?? '';
            $designItem['request']['creative_goal'] = $purpose['id'];
            $designItem['request']['original']['design'] = ['purpose' => $purpose['id'], 'presetId' => $presetId];
            if ($purpose['pageIds'] !== []) $designItem['request']['original']['campaign'] = ['shotId' => $designItem['page_key'], 'presetId' => $presetId];
            if (($style['enabled'] ?? true) !== false && in_array($purpose['id'], $style['purposes'], true)) {
                $designPlan = $service->plan(801, $id, [$designItem]);
                check($designPlan['count'] === 1, $purpose['id'] . ' accepts ' . $presetId);
            } else {
                rejects(fn () => $service->plan(801, $id, [$designItem]), $purpose['id'] . ' rejects incompatible ' . $presetId);
            }
        }
    }
    foreach ($designCatalog['styles'] as $presetId => $style) {
        if (($style['enabled'] ?? true) === false || !in_array('detail', $style['purposes'], true) || empty($style['detailLayout'])) continue;
        $template = $item;
        $template['page_key'] = '';
        $template['request']['creative_goal'] = 'detail';
        $template['request']['options']['size'] = '2:3';
        $template['request']['options']['resolution'] = '2K';
        $reference = '/admin/ecommerce-box/styles/' . $presetId . '.jpg';
        $template['request']['images'] = [$reference];
        $template['request']['original'] = ['prompt' => '完整模板详情', 'images' => [$reference], 'size' => '1365x2048', 'mode' => 'compose', 'n' => 1,
            'styleReference' => $reference, 'design' => ['purpose' => 'detail', 'presetId' => $presetId, 'layout' => 'template']];
        $templatePlan = $service->plan(801, $id, [$template]);
        check($templatePlan['count'] === 1 && $templatePlan['credits'] === 18.0, 'detail template ' . $presetId . ' stays one image at normal image pricing');
        check(str_contains($templatePlan['items'][0]['request']['prompt'], '完整商品详情图')
            && in_array('builtin:product-detail-layout', array_column($templatePlan['items'][0]['request']['skill_snapshot'], 'id'), true),
            'detail template ' . $presetId . ' receives whole-canvas layout rules');
        check($service->plan(801, $id, $templatePlan['items'])['items'][0]['request']['prompt'] === $templatePlan['items'][0]['request']['prompt'],
            'detail template ' . $presetId . ' keeps its frozen composition on re-quote');
        $wrongPage = $template; $wrongPage['page_key'] = 'D1';
        rejects(fn () => $service->plan(801, $id, [$wrongPage]), 'template detail cannot be submitted as a numbered page');
        $multiple = $template; $multiple['request']['original']['n'] = 2;
        rejects(fn () => $service->plan(801, $id, [$multiple]), 'template detail cannot request multiple images in one item');
        $missingTemplate = $template; $missingTemplate['request']['original']['images'] = [];
        $missingTemplate['request']['original']['styleReference'] = '';
        rejects(fn () => $service->plan(801, $id, [$missingTemplate]), 'template reference is required for whole-layout generation');
        $landscape = $template; $landscape['request']['original']['size'] = '2048x1365';
        rejects(fn () => $service->plan(801, $id, [$landscape]), 'template detail must use a vertical canvas');
        $wrongReference = $template; $wrongReference['request']['original']['styleReference'] = '/admin/ecommerce-box/styles/' . ($presetId === 'beauty' ? 'natural' : 'beauty') . '.jpg';
        rejects(fn () => $service->plan(801, $id, [$wrongReference]), 'template reference must match the chosen template');
        $multipleTemplates = [$template, $template];
        rejects(fn () => $service->plan(801, $id, $multipleTemplates), 'template workflow cannot be split into a multi-image batch');
    }
    $singleMain = $item; $singleMain['page_key'] = 'M1';
    $singleMain['request']['creative_goal'] = 'main';
    $singleMain['request']['original']['design'] = ['purpose' => 'main', 'presetId' => 'main-color-block'];
    $singleMain['request']['original']['campaign'] = ['shotId' => 'M1', 'presetId' => 'main-color-block'];
    $mainPlan = $service->plan(801, $id, [$singleMain]);
    check(str_contains($mainPlan['items'][0]['request']['prompt'], '一张方形商品主图'), 'new main template receives single square image rules');
    check($service->plan(801, $id, $mainPlan['items'])['items'][0]['request']['prompt'] === $mainPlan['items'][0]['request']['prompt'], 'purpose rules are stable on re-quote');
    $forgedWhite = $singleMain;
    $forgedWhite['request']['original']['design'] = ['purpose' => 'main', 'presetId' => 'main-color-block', 'whiteBackground' => true];
    $forgedWhitePlan = $service->plan(801, $id, [$forgedWhite]);
    check(in_array('builtin:product-detail-layout', array_column($forgedWhitePlan['items'][0]['request']['skill_snapshot'], 'id'), true), 'client cannot replace catalog rules by forging a white-background flag');
    $wrongPage = $singleMain; $wrongPage['page_key'] = 'D1';
    rejects(fn () => $service->plan(801, $id, [$wrongPage]), 'single main cannot submit a detail page');
    $wrongPurpose = $singleMain; $wrongPurpose['request']['creative_goal'] = 'tryon';
    rejects(fn () => $service->plan(801, $id, [$wrongPurpose]), 'frozen purpose cannot differ from the skill routing goal');
    $wrongStyle = $singleMain; $wrongStyle['request']['original']['campaign']['presetId'] = 'monochrome';
    rejects(fn () => $service->plan(801, $id, [$wrongStyle]), 'campaign snapshot must retain the selected compatible style');
    $wrongReference = $singleMain; $wrongReference['request']['original']['styleReference'] = '/admin/ecommerce-box/styles/monochrome.jpg';
    rejects(fn () => $service->plan(801, $id, [$wrongReference]), 'a template image cannot bypass style compatibility');
    foreach (['H4' => 'product-material-image', 'D4' => 'product-detail-layout', 'D6' => 'product-lifestyle-image'] as $pageKey => $method) {
        $specific = $item; $specific['page_key'] = $pageKey;
        check(in_array('builtin:' . $method, array_column($service->plan(801, $id, [$specific])['items'][0]['request']['skill_snapshot'], 'id'), true), $pageKey . ' matches its specific product skill');
    }
    $forged = $item; $forged['request']['skill_snapshot'] = [['id' => 'private:99', 'instructions' => 'foreign customer data']];
    check(!str_contains(EcommerceService::encode($service->plan(801, $id, [$forged])), 'private:99'), 'client skill snapshots cannot inject private skills into built-in commerce matching');
    $editing = $item; $editing['request']['original']['mode'] = 'edit';
    check(count($service->plan(801, $id, [$editing])['items'][0]['request']['skill_snapshot']) === 1, 'local edits do not force a new studio composition');
    $invalidDirection = $item; $invalidDirection['request']['creative_goal'] = 'run_shell';
    rejects(fn () => $service->plan(801, $id, [$invalidDirection]), 'commerce routing accepts only whitelisted creative goals');
    $badItem = $item; $badItem['request']['images'] = ['/storage/foreign.png'];
    rejects(fn () => $service->plan(801, $id, [$badItem]), 'generation references require ownership');
    $badItem = $item; $badItem['request']['original']['images'] = ['/storage/foreign.png'];
    rejects(fn () => $service->plan(801, $id, [$badItem]), 'nested edit snapshot requires ownership');
    $badItem = $item; $badItem['request']['original']['productImages'] = ['/storage/foreign.png'];
    rejects(fn () => $service->plan(801, $id, [$badItem]), 'supplemental product references in snapshots require ownership');
    $badState = $state; $badState['creative']['results'] = [['request' => ['productImages' => ['/storage/foreign.png']]]];
    rejects(fn () => $service->save(801, $id, 2, $badState), 'saved supplemental product references require ownership');
    $badItem = $item; $badItem['request']['original']['styleReference'] = '/storage/foreign.png';
    rejects(fn () => $service->plan(801, $id, [$badItem]), 'style references in queued snapshots require ownership');
    $badState = $state; $badState['creative']['results'] = [['request' => ['styleReference' => '/storage/foreign.png']]];
    rejects(fn () => $service->save(801, $id, 2, $badState), 'saved style references cannot import foreign customer images');
    $styledItem = $item; $styledItem['page_key'] = 'H2';
    $styledItem['request']['images'] = ['/admin/ecommerce-box/styles/monochrome.jpg'];
    $styledItem['request']['original']['styleReference'] = '/admin/ecommerce-box/styles/monochrome.jpg';
    $stylePlan = $service->plan(801, $id, [$styledItem]);
    check($stylePlan['count'] === 1 && $stylePlan['credits'] === 18.0, 'adding a style reference does not create extra paid images');
    $styleImages = $service->providerImages(['user_id' => 801, 'project_id' => $id], $stylePlan['items'][0]['request']);
    $createdFiles[] = $styleImages[0];
    check(count($styleImages) === 1 && str_starts_with($styleImages[0], '/storage/'), 'built-in Amazon layout is materialized as a provider-accessible reference');
    $official = $item; $official['model_config_id'] = 3; $official['request']['options'] = ['size' => '2048x2048', 'quality' => 'max'];
    check($service->plan(801, $id, [$official])['estimated'], 'Official quote indicates final metered settlement');
    $detail = $service->generate(801, $id, 'fixture-request-key-123', [$item, $item]);
    $again = $service->generate(801, $id, 'fixture-request-key-123', [$item, $item]);
    check(count($again['jobs']) === 2 && count($again['runs']) === 1, 'duplicate submit key creates only one plan');
    $runId = $detail['runs'][0]['id'];
    rejects(fn () => $service->control(802, $runId, 'resume'), 'foreign run controls rejected');
    rejects(fn () => $service->delete(801, $id), 'active project cannot be deleted');
    $service->control(801, $runId, 'pause');
    $worker = (new ReflectionClass(EcommerceWorker::class))->newInstanceWithoutConstructor();
    check(invoke($worker, 'claim') === null, 'paused plan submits no new pages');
    $service->control(801, $runId, 'resume');
    $claimed = invoke($worker, 'claim');
    check($claimed !== null && (int) $claimed['id'] === (int) $detail['jobs'][0]['id'], 'resumed plan claims the first pending page');
    EcommerceService::rememberTask((int) $claimed['id'], 'tsk_fixture');
    $service->control(801, $runId, 'pause');
    Db::name('ecommerce_jobs')->where('id', $claimed['id'])->update(['status' => 'queued']);
    check(invoke($worker, 'claim') !== null, 'pause still allows polling the already-submitted page');
    Db::name('ecommerce_jobs')->where('id', $claimed['id'])->update(['status' => 'running', 'provider_task_id' => '', 'started_at' => date('Y-m-d H:i:s', time() - 2000), 'update_time' => date('Y-m-d H:i:s', time() - 2000)]);
    invoke($worker, 'recover');
    check(Db::name('ecommerce_jobs')->where('id', $claimed['id'])->value('status') === 'unknown', 'crashed submission without task ID becomes unknown');
    $service->control(801, $runId, 'cancel');
    Db::name('ecommerce_jobs')->where('id', $claimed['id'])->update(['status' => 'cancelled']);
    // Exercise the actual image adapter against the localhost-only HTTP fixture.
    $fixtureImage = imagecreatetruecolor(4, 4); ob_start(); imagepng($fixtureImage); $fixtureBytes = ob_get_clean(); imagedestroy($fixtureImage);
    $fixtureUrl = \app\support\MediaStorage::uploadImageBinary($fixtureBytes, 'png', 'test/ecommerce', 'image/png', ['user_id' => 801]);
    $createdFiles[] = $fixtureUrl;
    file_get_contents($base . '/reset?result_url=' . rawurlencode($fixtureUrl));
    $detail = $service->generate(801, $id, 'fixture-http-request-456', [$item]);
    $run = end($detail['runs']);
    $publicJob = end($detail['jobs']);
    check($publicJob['provider_submitted'] === false && $publicJob['status'] === 'queued', 'unsubmitted task is genuinely waiting');
    $claimed = invoke($worker, 'claim');
    invoke($worker, 'processJob', $claimed);
    $pending = Db::name('ecommerce_jobs')->where('id', $claimed['id'])->find();
    check($pending['status'] === 'queued' && $pending['provider_task_id'] !== '', 'real adapter persists task ID before yielding');
    $publicDetail = $service->detail(801, $id);
    $publicJob = end($publicDetail['jobs']);
    check($publicJob['provider_submitted'] === true && $publicJob['status'] === 'queued', 'polling task exposes submission without changing scheduling state');
    check(!isset($publicJob['provider_task_id']) && !isset($publicJob['result_endpoint']), 'public task hides provider identifiers and endpoints');
    $frozen = EcommerceService::decode($pending['request_json']);
    $service->control(801, $run['id'], 'pause');
    $claimed = invoke($worker, 'claim'); invoke($worker, 'processJob', $claimed);
    $done = Db::name('ecommerce_jobs')->where('id', $claimed['id'])->find();
    check($done['status'] === 'success' && str_starts_with($done['result_url'], '/storage/'), 'original task finishes after pause without a new POST: ' . ($done['error_message'] ?? ''));
    $createdFiles[] = $done['result_url'];
    $httpState = json_decode(file_get_contents($base . '/state'), true);
    check(count($httpState['posts']) === 1 && array_sum($httpState['gets']) === 2, 'refresh/resume has exactly one paid submit and two polls');
    check(reset($httpState['posts'])['size'] === '1:1', 'frozen ratio overrides configured 16:9 default');
    check(CreditService::getBalance(801) === 4982.0, 'worker uses existing credit hook exactly once');
    $savedAsset = $service->saveAsset(801, (int) $done['id']);
    check($service->saveAsset(801, (int) $done['id']) === $savedAsset && Db::name('assets')->count() === 1, 'save-to-assets is idempotent');
    Db::name('ecommerce_jobs')->where('id', $done['id'])->update(['status' => 'unknown']);
    $service->checkOriginal(801, (int) $done['id']);
    check(Db::name('ecommerce_jobs')->where('id', $done['id'])->value('status') === 'success', 'manual check restores persisted result without querying and charging again');
    Db::name('ecommerce_jobs')->where('id', $done['id'])->update(['status' => 'running']);
    invoke($worker, 'processJob', Db::name('ecommerce_jobs')->where('id', $done['id'])->find());
    check(json_decode(file_get_contents($base . '/state'), true) === $httpState && CreditService::getBalance(801) === 4982.0, 'worker restores persisted result without any provider call or second charge');
    $dataUrl = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWQAAAABJRU5ErkJggg==';
    $legacy = $state; $legacy['product']['uploads'] = [['id' => 'old', 'src' => $dataUrl]];
    $legacy['creative']['campaign'] = ['snapshot' => ['images' => [$dataUrl]]];
    $legacy['creative']['results'] = [['request' => ['productImages' => [$dataUrl]]]];
    $legacy['creative']['video'] = ['tasks' => [['id' => 'legacy-task', 'url' => 'https://old.invalid/video.mp4']], 'activeTaskId' => 'legacy-task'];
    $imported = $service->create(801, $legacy, true);
    $importSrc = $imported['state']['product']['uploads'][0]['src']; $createdFiles[] = $importSrc;
    check(str_starts_with($importSrc, '/storage/') && $imported['state']['creative']['campaign']['snapshot']['images'][0] === $importSrc, 'import rewrites every nested reference and deduplicates embedded images');
    check($imported['state']['creative']['results'][0]['request']['productImages'][0] === $importSrc, 'import rewrites supplemental product references to the same owned media');
    check(Db::name('ecommerce_media')->where('project_id', $imported['id'])->count() === 1, 'identical embedded images are stored once');
    check($imported['state']['creative']['video']['tasks'] === [] && $imported['state']['creative']['video']['activeTaskId'] === '', 'legacy video tasks are discarded before media ownership validation');
    check(is_file(invoke($service, 'presetFile', '/admin/ecommerce-box/styles/technology.jpg')), 'preset images resolve in built or source public directories');
    $scenePresets = $service->scenePresets();
    check(count($scenePresets) === 10, 'all ten generated built-in scenes exist with provider-compatible dimensions');
    foreach ($scenePresets as $scene) {
        check(min($scene['width'], $scene['height']) >= 300 && max($scene['width'], $scene['height']) <= 6000,
            'scene dimensions are valid: ' . $scene['id']);
    }
    check($service->detail(801, $id)['available_scene_ids'] === array_column($scenePresets, 'id'), 'project response lists only available scene files');
    $sceneImages = $service->providerImages(['user_id' => 801, 'project_id' => $id], ['images' => ['/admin/ecommerce-box/scenes/street.jpg']]);
    $createdFiles[] = $sceneImages[0];
    $sceneInfo = getimagesize($app->getRootPath() . 'public' . $sceneImages[0]);
    check(min($sceneInfo[0], $sceneInfo[1]) >= 300, 'provider receives the full-size street scene rather than a gallery thumbnail');
    $errorController = new \app\controller\SeriesController($app);
    $upstreamError = 'image[2]: Width must be between 300px and 6000px.';
    check(invoke($errorController, 'extractVideoErrorFromResponse', ['status' => 'failed', 'error' => ['message' => $upstreamError]], 'failed') === $upstreamError,
        'top-level video error detail is shown instead of failed');
    check(invoke($errorController, 'extractVideoErrorFromResponse', ['task' => ['error' => ['message' => 'nested error']]], 'failed') === 'nested error', 'nested provider errors remain supported');
    rejects(fn () => $service->plan(801, $id, [array_replace_recursive($item, ['request' => ['images' => ['/admin/ecommerce-box/styles/../technology.jpg']]])]), 'preset references cannot traverse directories');
    check($frozen === EcommerceService::decode(Db::name('ecommerce_jobs')->where('id', $done['id'])->value('request_json')), 'worker never changes frozen prompts and references');
    $videoItem = ['kind' => 'creative', 'mode' => 'video', 'title' => 'Video', 'page_key' => 'video', 'model_config_id' => 4,
        'request' => ['prompt' => 'fixture ecommerce video', 'images' => [], 'options' => ['duration' => 5, 'aspect_ratio' => '16:9', 'resolution' => '720p', 'generate_audio' => true]]];
    $videoDetail = $service->generate(801, $id, 'fixture-video-789', [$videoItem]);
    $ugcItem = $videoItem; $ugcItem['request']['video_style'] = 'ugc';
    check(in_array('builtin:product-ugc-video', array_column($service->plan(801, $id, [$ugcItem])['items'][0]['request']['skill_snapshot'], 'id'), true), 'UGC direction selects the product ad method');
    $videoJob = invoke($worker, 'claim');
    $videoController = new \app\controller\SeriesController($app); $videoController->setRuntimeUserId(801);
    try {
        $videoController->callWorkflowVideoGeneration(ModelConfig::find(4), 'fixture ecommerce video', '', '5', [],
            ['user_id' => 801, 'ecommerce_job_id' => (int) $videoJob['id'], 'source' => 'ecommerce_video',
                'video_options' => $videoItem['request']['options'], 'idempotency_key' => 'fixture-video-789'],
            static function (): void { throw new RuntimeException('Simulated process interruption after submit'); });
        throw new RuntimeException('Video crash fixture was not reached');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'Simulated process interruption after submit') throw $error;
    }
    $interrupted = Db::name('ecommerce_jobs')->where('id', $videoJob['id'])->find();
    check($interrupted['provider_task_id'] === 'tsk_ecommerce_video' && str_ends_with($interrupted['result_endpoint'], '/tsk_ecommerce_video'), 'video stores task ID and poll endpoint before its first poll');
    Db::name('ecommerce_jobs')->where('id', $videoJob['id'])->update(['update_time' => date('Y-m-d H:i:s', time() - 2000)]);
    invoke($worker, 'recover');
    $resumedVideo = invoke($worker, 'claim'); invoke($worker, 'processJob', $resumedVideo);
    $videoDone = Db::name('ecommerce_jobs')->where('id', $videoJob['id'])->find();
    check($videoDone['status'] === 'success', 'video resumes its saved task after process interruption: ' . ($videoDone['error_message'] ?? ''));
    $httpState = json_decode(file_get_contents($base . '/state'), true);
    check(count($httpState['video_posts']) === 1 && $httpState['video_gets'] === 1, 'video recovery issues no second paid POST');
    check(CreditService::getBalance(801) === round(4982.0 - CreditService::quoteVideo(5, '720p', 'seedance-2'), 2), 'video is charged once on success');
    // Real text transport uses only the local fixture and a disposable model configuration.
    ModelConfig::create(array_replace($common, ['id' => 5, 'type' => 'text', 'model_id' => 'deepseek-chat', 'options' => []]));
    $storyService = new \app\support\EcommerceStoryService();
    $storyInput = ['idea' => '女儿提着油给妈妈，两人在厨房聊起价格。', 'duration' => 15];
    rejects(fn () => $storyService->expand(802, $id, 'story-foreign-001', $storyInput), 'story expansion checks project ownership before calling a model');
    rejects(fn () => $storyService->expand(801, $id, 'story-duration-001', array_replace($storyInput, ['duration' => 30])), 'story expansion cannot exceed provider duration');
    $balanceBeforeStory = CreditService::getBalance(801);
    $story = $storyService->expand(801, $id, 'story-success-001', $storyInput);
    check(count($story['characters']) === 2 && $story['hasDialogue'] && str_contains($story['script'], '妈妈说'), 'story expansion preserves distinct characters and speaker-bound dialogue');
    check(str_contains($story['script'], '0-15 秒') && str_contains($story['script'], "\n妈妈说"), 'story compiles a readable script with the exact requested duration');
    $afterStory = CreditService::getBalance(801);
    check($afterStory < $balanceBeforeStory && Db::name('ai_request_logs')->where('source', 'ecommerce_story')->count() === 1, 'story tokens use the existing request log and billing hook');
    check($storyService->expand(801, $id, 'story-success-001', $storyInput) === $story && CreditService::getBalance(801) === $afterStory, 'replaying a completed story request returns its result without another charge');
    check(count(json_decode(file_get_contents($base . '/state'), true)['text_posts']) === 1, 'completed story replay makes no second provider request');
    rejects(fn () => $storyService->expand(801, $id, 'story-success-001', array_replace($storyInput, ['idea' => 'changed'])), 'idempotency key cannot be reused for different story input');
    $running = Db::name('ecommerce_story_requests')->where('request_key', 'story-success-001')->find();
    unset($running['id']); $running['request_key'] = 'story-running-001'; $running['status'] = 'running';
    Db::name('ecommerce_story_requests')->insert($running);
    rejects(fn () => $storyService->expand(801, $id, 'story-concurrent-001', $storyInput), 'parallel story requests are rejected before another paid call');
    rejects(fn () => $service->delete(801, $id), 'project cannot be deleted while its paid story expansion is running');
    Db::name('ecommerce_story_requests')->where('request_key', 'story-running-001')->delete();
    $storyResponse = ['title' => '送油', 'characters' => [['id' => 'c1', 'name' => '女儿', 'description' => '成年女性']],
        'shots' => [['start' => 0, 'end' => 15, 'action' => '提油进厨房', 'dialogue' => []]], 'warnings' => []];
    $gap = $storyResponse; $gap['shots'][0]['start'] = 1;
    rejects(fn () => \app\support\EcommerceStoryService::validate($gap, 15), 'story timelines cannot leave gaps or change the duration');
    $speaker = $storyResponse; $speaker['shots'][0]['dialogue'] = [['character_id' => 'c9', 'text' => '你好']];
    rejects(fn () => \app\support\EcommerceStoryService::validate($speaker, 15), 'dialogue cannot reference an unknown character');
    $speech = $storyResponse; $speech['shots'][0]['dialogue'] = [['character_id' => 'c1', 'text' => str_repeat('话', 100)]];
    rejects(fn () => \app\support\EcommerceStoryService::validate($speech, 15), 'overlong dialogue is rejected instead of silently overrunning the clip');
    $fakeStoryClient = new class extends \app\support\PromptAssistantTextClient {
        public array $responses = []; public int $count = 0;
        public function complete(ModelConfig $model, int $userId, string $stage, string $system, array $input, int $maxTokens = 4096): array {
            $this->count++; $response = array_shift($this->responses); if ($response instanceof Throwable) throw $response; return $response;
        }
    };
    $fakeStoryClient->responses = [$gap, $storyResponse];
    $repairedStory = (new \app\support\EcommerceStoryService($fakeStoryClient))->expand(801, $id, 'story-repair-001', $storyInput);
    check($fakeStoryClient->count === 2 && $repairedStory['duration'] === 15, 'invalid story format receives one bounded repair');
    $fakeStoryClient->responses = [$gap, $gap]; $fakeStoryClient->count = 0;
    rejects(fn () => (new \app\support\EcommerceStoryService($fakeStoryClient))->expand(801, $id, 'story-invalid-001', $storyInput), 'repeated invalid story format fails without an unusable script');
    check($fakeStoryClient->count === 2, 'story repair cannot exceed two model calls');
    $fakeStoryClient->responses = [new RuntimeException('Fixture network failure')]; $fakeStoryClient->count = 0;
    rejects(fn () => (new \app\support\EcommerceStoryService($fakeStoryClient))->expand(801, $id, 'story-network-001', $storyInput), 'story transport failure remains recoverable');
    check($fakeStoryClient->count === 1, 'story transport failure does not automatically retry a paid call');
    $badState = $state; $badState['creative']['video']['story']['characters'] = [['image' => ['src' => '/storage/foreign.png']]];
    rejects(fn () => $service->save(801, $id, 2, $badState), 'story character images use the same per-user ownership validation');
    $copyService = new \app\support\EcommerceCopyService();
    rejects(fn () => $copyService->draft(802, $id, 'copy-foreign-001'), 'copy generation checks ownership before model calls');
    $service->registerMedia(801, $id, $fixtureUrl, 'fixture poster');
    $copyState = $state; $copyState['product']['uploads'] = [['id' => 'copy-product', 'src' => $fixtureUrl]];
    $service->save(801, $id, 2, $copyState);
    $copyBalance = CreditService::getBalance(801);
    $copy = $copyService->draft(801, $id, 'copy-success-001');
    check($copy['headline'] === '日常之选' && $copy['analyzed_images'] === 0, 'text-only fallback drafts copy without claiming to have analyzed images');
    $copyAfter = CreditService::getBalance(801);
    check($copyAfter < $copyBalance && Db::name('ai_request_logs')->where('source', 'ecommerce_copy')->count() === 1, 'copy uses the shared token billing hook');
    check($copyService->draft(801, $id, 'copy-success-001') === $copy && CreditService::getBalance(801) === $copyAfter, 'copy request replay does not incur another text charge');
    $textModel = ModelConfig::find(5); $textModel->options = ['supports_vision' => true]; $textModel->save();
    $visionCopy = $copyService->draft(801, $id, 'copy-vision-0001');
    check($visionCopy['analyzed_images'] > 0, 'multimodal copy receives actual owner-checked product images');
    $copyPosts = json_decode(file_get_contents($base . '/state'), true)['text_posts'];
    check(str_starts_with(end($copyPosts)['messages'][1]['content'][1]['image_url']['url'], 'data:image/jpeg;base64,'), 'local product image is sent as a bounded preview, not an inaccessible localhost URL');
    $textModel->options = []; $textModel->save();
    $copyRaw = ['headline' => '字', 'subtitle' => '', 'selling_points' => [], 'product_name' => '', 'observations' => []];
    rejects(fn () => \app\support\EcommerceCopyService::validate(array_replace($copyRaw, ['headline' => str_repeat('字', 21)])), 'oversized copy is rejected before layout');
    $activeCopy = Db::name('ecommerce_copy_requests')->where('request_key', 'copy-success-001')->find();
    unset($activeCopy['id']); $activeCopy['request_key'] = 'copy-running-001'; $activeCopy['status'] = 'running'; Db::name('ecommerce_copy_requests')->insert($activeCopy);
    rejects(fn () => $copyService->draft(801, $id, 'copy-concurrent-001'), 'concurrent copy cannot create another paid request');
    rejects(fn () => $service->delete(801, $id), 'project deletion waits for active copy requests');
    Db::name('ecommerce_copy_requests')->where('request_key', 'copy-running-001')->delete();
    rejects(fn () => $service->saveAsset(801, (int) $done['id'], '/storage/foreign-poster.png'), 'composed asset must belong to the owner and project');
    $asset = $service->saveAsset(801, (int) $done['id'], $fixtureUrl);
    check(\app\model\AssetImage::where('asset_id', $asset['asset_id'])->value('url') === $fixtureUrl, 'asset stores the uploaded composed poster');
    check($service->saveAsset(801, (int) $done['id'], $fixtureUrl) === $asset, 'same composed asset save is idempotent');
    $restoredAsset = $service->saveAsset(801, (int) $done['id']);
    check(\app\model\AssetImage::where('asset_id', $restoredAsset['asset_id'])->value('url') === $done['result_url'], 'removing poster text saves the original image rather than a stale composed asset');
    $textModel->options = ['supports_vision' => true]; $textModel->save();
    $portraitImage = imagecreatetruecolor(4, 4); imagefill($portraitImage, 0, 0, imagecolorallocate($portraitImage, 120, 80, 60));
    ob_start(); imagepng($portraitImage); $portraitBytes = ob_get_clean(); imagedestroy($portraitImage);
    $portraitUrl = \app\support\MediaStorage::uploadImageBinary($portraitBytes, 'png', 'test/ecommerce', 'image/png', ['user_id' => 801]); $createdFiles[] = $portraitUrl;
    $briefState = $state; $briefState['creative']['video'] = ['duration' => 15, 'aspect_ratio' => '9:16', 'resolution' => '720p', 'story' => ['idea' => '女儿送一桶油给妈妈，妈妈在厨房炒菜，两人聊价格。']];
    $briefProject = $service->create(801, $briefState); $briefId = $briefProject['id'];
    $oilMedia = $service->registerMedia(801, $briefId, $fixtureUrl, 'oil', 4, 4);
    $personMedia = $service->registerMedia(801, $briefId, $portraitUrl, 'person', 4, 4);
    $briefState['product']['uploads'] = [$oilMedia, $personMedia]; $service->save(801, $briefId, 1, $briefState);
    $briefService = fn () => new \app\support\EcommerceBriefService();
    rejects(fn () => $briefService()->detail(802, $briefId), 'video brief and conversation are private');
    $analyzed = $briefService()->turn(801, $briefId, 'brief-analyze-001', 0, 'analyze', []);
    check(array_column($analyzed['materials'], 'kind') === ['product', 'person'], 'vision distinguishes product and person before asking about the plot');
    $analyzePosts = json_decode(file_get_contents($base . '/state'), true)['text_posts'];
    check(count(end($analyzePosts)['messages'][1]['content']) === 3, 'material analysis sends both actual images to the multimodal model');
    $afterAnalysis = CreditService::getBalance(801);
    check($briefService()->turn(801, $briefId, 'brief-analyze-001', 0, 'analyze', []) === $analyzed && CreditService::getBalance(801) === $afterAnalysis, 'replaying material analysis makes no duplicate paid request');
    $questions = $briefService()->turn(801, $briefId, 'brief-consult-001', $analyzed['version'], 'consult', []);
    check($questions['phase'] === 'questions' && $questions['characters'][1]['name'] === '妈妈', 'plot consultation offers role choices and asks a focused question');
    check(count(json_decode(file_get_contents($base . '/state'), true)['text_posts']) === count($analyzePosts) + 1, 'unchanged images reuse cached analysis during conversation');
    rejects(fn () => $briefService()->turn(801, $briefId, 'brief-old-000001', $analyzed['version'], 'consult', []), 'old conversation version cannot overwrite a later turn');
    rejects(fn () => $briefService()->turn(801, $briefId, 'brief-role-00001', $questions['version'], 'consult', ['roles' => [$personMedia['id'] => 'c9']]), 'character assignments are restricted to the current plot');
    $ready = $briefService()->turn(801, $briefId, 'brief-reply-0001', $questions['version'], 'consult', ['answers' => ['tone' => '自然生活感'], 'roles' => [$personMedia['id'] => 'c2']]);
    check($ready['phase'] === 'ready' && !$ready['questions'] && $ready['proposal'] !== '', 'answered questions produce a readable proposal without repeating questions');
    check($ready['story']['characters'][1]['image']['src'] === $portraitUrl && $ready['product_ids'] === [$oilMedia['id']], 'confirmed role binding separates the mother portrait from product references');
    $replyPosts = json_decode(file_get_contents($base . '/state'), true)['text_posts'];
    $lastReply = json_decode(end($replyPosts)['messages'][1]['content'], true);
    check($lastReply['answered_questions']['tone']['answer'] === '自然生活感' && count($lastReply['messages']) > 0, 'model receives previous answers and conversation context');
    check($briefService()->detail(801, $briefId)['proposal'] === $ready['proposal'], 'proposal and conversation survive reload');
    $briefState['creative']['video']['duration'] = 10; $service->save(801, $briefId, 2, $briefState);
    check($briefService()->detail(801, $briefId)['stale'], 'duration change invalidates the previous proposal');
    rejects(fn () => $briefService()->turn(801, $briefId, 'brief-stale-0001', $ready['version'], 'confirm', []), 'changed inputs cannot confirm a stale proposal');
    $briefState['creative']['video']['duration'] = 15; $service->save(801, $briefId, 3, $briefState);
    $beforeConfirm = CreditService::getBalance(801);
    $confirmed = $briefService()->turn(801, $briefId, 'brief-confirm-01', $ready['version'], 'confirm', []);
    check($confirmed['phase'] === 'confirmed' && CreditService::getBalance(801) === $beforeConfirm, 'explicit proposal confirmation makes no model call or token charge');
    $briefVideo = $videoItem; $briefVideo['request']['options']['duration'] = 15;
    $briefVideo['request']['images'] = [$portraitUrl, $fixtureUrl]; $briefVideo['request']['prompt'] = $confirmed['story']['script'];
    rejects(fn () => $service->generate(801, $briefId, 'brief-no-confirm', [$briefVideo]), 'video generation requires the approved proposal version');
    $briefVideo['request']['original'] = ['brief_version' => $confirmed['version']];
    $wrongBriefVideo = $briefVideo; $wrongBriefVideo['request']['images'] = [$fixtureUrl];
    rejects(fn () => $service->generate(801, $briefId, 'brief-wrong-refs', [$wrongBriefVideo]), 'generation cannot lose the approved character reference');
    $briefRun = $service->generate(801, $briefId, 'brief-video-0001', [$briefVideo]);
    check(count($briefRun['jobs']) === 1 && $briefRun['jobs'][0]['status'] === 'queued', 'only an approved proposal enters the existing video queue');
    $extraMedia = $oilMedia; $extraMedia['id'] = 'extra-product'; $extraMedia['src'] = $fixtureUrl . '?extra=1';
    $service->registerMedia(801, $briefId, $extraMedia['src'], 'extra oil');
    $briefState['product']['uploads'][] = $extraMedia; $service->save(801, $briefId, 4, $briefState);
    $beforeExtra = count(json_decode(file_get_contents($base . '/state'), true)['text_posts']);
    $extra = $briefService()->turn(801, $briefId, 'brief-extra-0001', $confirmed['version'], 'analyze', []);
    $extraPosts = json_decode(file_get_contents($base . '/state'), true)['text_posts'];
    check(count($extraPosts) === $beforeExtra + 1 && count(end($extraPosts)['messages'][1]['content']) === 2, 'adding a material analyzes only the new image');
    check($extra['answers'] === $confirmed['answers'] && $extra['messages'] === $confirmed['messages'] && $extra['roles'] === $confirmed['roles'], 'adding images preserves previous answers, conversation and unchanged character assignments');
    $briefState['product']['uploads'] = [$oilMedia]; $service->save(801, $briefId, 5, $briefState);
    $afterRemoval = $briefService()->turn(801, $briefId, 'brief-remove-001', $extra['version'], 'consult', ['kinds' => [$personMedia['id'] => 'person'], 'roles' => [$personMedia['id'] => 'c2']]);
    check($afterRemoval['phase'] === 'ready' && $afterRemoval['reference_urls'] === [$fixtureUrl] && !$afterRemoval['roles'], 'removed known material selections do not break consultation or retain deleted references');
    $replacement = $service->registerMedia(801, $briefId, $portraitUrl . '?new=1', 'person replacement', 4, 4);
    $briefState['product']['uploads'] = [$oilMedia, $personMedia];
    $briefState['creative']['video']['story']['characters'] = $ready['story']['characters'];
    $briefState['creative']['video']['story']['characters'][1]['image'] = $replacement;
    $service->save(801, $briefId, 6, $briefState);
    $replaced = $briefService()->turn(801, $briefId, 'brief-replace-01', $afterRemoval['version'], 'consult', ['roles' => [$personMedia['id'] => 'c2']]);
    check($replaced['phase'] === 'ready' && $replaced['story']['characters'][1]['image']['src'] === $replacement['src']
        && !in_array($portraitUrl, $replaced['reference_urls'], true), 'explicitly replacing a character photo excludes its previous identity reference');
    echo "Passed {$checks} ecommerce checks.\n";
} finally {
    if (is_resource($fixtureProcess)) { proc_terminate($fixtureProcess); proc_close($fixtureProcess); }
    foreach ($createdFiles as $url) {
        if (str_starts_with($url, '/storage/') && !str_contains($url, '..')) @unlink($app->getRootPath() . 'public' . $url);
    }
    $admin->execute('DROP DATABASE `' . $db . '`');
    $app->config->set($originalConfig, 'database');
}
