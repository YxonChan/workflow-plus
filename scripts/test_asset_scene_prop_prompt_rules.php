<?php

declare(strict_types=1);

/**
 * 场景/道具生图拼装规则：image_prompt 优先、营销文案降权、风格冲突清洗、无人脸约束。
 *
 * 运行：php scripts/test_asset_scene_prop_prompt_rules.php
 */

require __DIR__ . '/../vendor/autoload.php';

function assertTrue(bool $cond, string $message): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\n expected=" . var_export($expected, true) . "\n actual=" . var_export($actual, true) . "\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

function assertContains(string $needle, string $haystack, string $message): void
{
    assertTrue(str_contains($haystack, $needle), $message . " (missing: {$needle})");
}

function assertNotContains(string $needle, string $haystack, string $message): void
{
    assertTrue(!str_contains($haystack, $needle), $message . " (unexpected: {$needle})");
}

$controller = new ReflectionClass(\app\controller\AssetController::class);

$buildPrompt = $controller->getMethod('buildAssetImagePrompt');
$buildPrompt->setAccessible(true);
$refine = $controller->getMethod('refinePromptForView');
$refine->setAccessible(true);
$sanitize = $controller->getMethod('sanitizeSubjectPromptForVisualStyle');
$sanitize->setAccessible(true);
$isMeta = $controller->getMethod('isMetaOrMarketingAssetBlurb');
$isMeta->setAccessible(true);

$instance = $controller->newInstanceWithoutConstructor();

// 1) 生图优先 image_prompt，并带上资产名
$built = $buildPrompt->invoke($instance, [
    'name' => '豪华酒店套房',
    'description' => '海外短剧剧情片段画面，明亮有电影质感，用于展示短剧场景落地',
    'image_prompt' => '现代豪华酒店套房，落地窗城市夜景，米色沙发与胡桃木茶几，暖色壁灯',
], 'scene', '海外短剧剧情片段画面，明亮有电影质感，用于展示短剧场景落地');
assertContains('豪华酒店套房', $built, 'scene prompt includes asset name');
assertContains('现代豪华酒店套房', $built, 'scene prompt prefers concrete image_prompt');
assertNotContains('用于展示短剧场景落地', $built, 'scene prompt ignores marketing description when image_prompt exists');

// 2) 只有营销 description、无 image_prompt 时，落到名称兜底
$metaOnly = $buildPrompt->invoke($instance, [
    'name' => '机场出发厅',
    'description' => '海外短剧剧情片段画面，用于展示短剧场景落地',
    'image_prompt' => '',
], 'scene', '海外短剧剧情片段画面，用于展示短剧场景落地');
assertContains('机场出发厅', $metaOnly, 'meta-only description falls back to name');
assertTrue(
    !str_contains($metaOnly, '用于展示') || str_contains($metaOnly, '机场出发厅'),
    'meta-only still anchored by name'
);

// 3) 道具同样优先 image_prompt
$propBuilt = $buildPrompt->invoke($instance, [
    'name' => '红色行李箱',
    'description' => '关键剧情道具',
    'image_prompt' => '哑光红色硬壳行李箱，银色拉杆，四轮，白底棚拍',
], 'prop', '关键剧情道具');
assertContains('红色行李箱', $propBuilt, 'prop prompt includes name');
assertContains('哑光红色硬壳行李箱', $propBuilt, 'prop prompt prefers image_prompt');

// 4) 营销文案识别
assertTrue((bool) $isMeta->invoke($instance, '海外短剧剧情片段画面，用于展示短剧场景落地'), 'detects marketing blurb');
assertTrue(!(bool) $isMeta->invoke($instance, '现代豪华酒店套房，落地窗城市夜景'), 'concrete scene is not marketing');

// 5) 三向风格清洗
$cleanedAnime = $sanitize->invoke($instance, '写实摄影，真人实拍，photorealistic hotel lobby，暖色灯光', 'anime');
assertNotContains('写实', $cleanedAnime, 'anime sanitize strips 写实');
assertNotContains('真人实拍', $cleanedAnime, 'anime sanitize strips 真人实拍');
assertNotContains('photorealistic', $cleanedAnime, 'anime sanitize strips photorealistic');
assertContains('暖色灯光', $cleanedAnime, 'anime sanitize keeps concrete content');

$cleanedRealistic = $sanitize->invoke($instance, '动漫二次元风格，赛璐璐，cartoon living room，暖色灯光', 'realistic');
assertNotContains('动漫', $cleanedRealistic, 'realistic sanitize strips 动漫');
assertNotContains('二次元', $cleanedRealistic, 'realistic sanitize strips 二次元');
assertNotContains('赛璐璐', $cleanedRealistic, 'realistic sanitize strips 赛璐璐');
assertNotContains('cartoon', strtolower($cleanedRealistic), 'realistic sanitize strips cartoon');
assertContains('暖色灯光', $cleanedRealistic, 'realistic sanitize keeps concrete content');

$cleaned3d = $sanitize->invoke($instance, '赛璐璐平涂，手绘插画，手机实拍客厅，体积光', '3d', 'stylized_3d');
assertNotContains('赛璐璐', $cleaned3d, '3d sanitize strips 赛璐璐');
assertNotContains('手绘插画', $cleaned3d, '3d sanitize strips 手绘插画');
assertNotContains('手机实拍', $cleaned3d, '3d sanitize strips 手机实拍');
assertContains('体积光', $cleaned3d, '3d sanitize keeps concrete content');

// 6) 场景最终提示词：风格前置 + 无人脸
$sceneFinal = $refine->invoke(
    $instance,
    '现代豪华酒店套房，落地窗城市夜景，米色沙发',
    'main',
    '',
    'scene',
    'anime',
    'china',
    'shinkai',
    null
);
assertContains('画风最高优先级', $sceneFinal, 'scene final has style priority prefix');
assertContains('动漫', $sceneFinal, 'scene final mentions anime style');
assertContains('无人空场景硬性禁止', $sceneFinal, 'scene final has no-people/face rule');
assertContains('no faces', $sceneFinal, 'scene final has English no-faces constraint');
assertNotContains('摄影作品风格', $sceneFinal, 'anime scene must not hardcode photoreal core style');

// 7) 道具最终提示词：无人脸 + 风格
$propFinal = $refine->invoke(
    $instance,
    '哑光红色硬壳行李箱，银色拉杆，四轮',
    'main',
    '',
    'prop',
    'anime',
    'china',
    '',
    null
);
assertContains('道具硬性禁止', $propFinal, 'prop final has no-people/face rule');
assertContains('画风最高优先级', $propFinal, 'prop final has style priority prefix');
assertContains('严禁写实摄影', $propFinal, 'prop anime forbids photoreal');

// 8) 写实最终提示词：禁止串成动漫
$realisticFinal = $refine->invoke(
    $instance,
    '现代豪华酒店套房，落地窗城市夜景，米色沙发',
    'main',
    '',
    'scene',
    'realistic',
    'china',
    'cinematic',
    null
);
assertContains('严禁动漫', $realisticFinal, 'realistic final forbids anime');
assertContains('真人实拍/写实摄影', $realisticFinal, 'realistic final requires live-action look');
assertNotContains('必须是动漫/二次元', $realisticFinal, 'realistic final must not require anime');

echo "\nAll asset scene/prop prompt rule checks passed.\n";
