<?php

declare(strict_types=1);

/**
 * 作品风格拼装：画法 / 整片质感 / 片段光影的适用范围，以及官方目录约束。
 *
 * 运行：php scripts/test_series_style_prompt_rules.php
 */

require __DIR__ . '/../vendor/autoload.php';

use app\support\SeriesStylePrompt;
use app\support\StylePresetDefaults;

function assertTrue(bool $cond, string $message): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$message}\n");
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

$preset = [
    'name' => '测试风格',
    'visual_styles' => ['anime', 'realistic'],
    'technique_prompt' => '画法：圆润线条',
    'prompt' => '整片质感：蜂蜜暖光',
    'lighting_prompt' => '片段光影：窗边逆光',
];

$character = SeriesStylePrompt::compose($preset, 'anime', 'character');
assertContains('画法：圆润线条', $character, 'character includes technique');
assertNotContains('整片质感：蜂蜜暖光', $character, 'character excludes tone');
assertNotContains('片段光影：窗边逆光', $character, 'character excludes lighting');

$prop = SeriesStylePrompt::compose($preset, 'anime', 'prop');
assertContains('画法：圆润线条', $prop, 'prop includes technique');
assertNotContains('整片质感：蜂蜜暖光', $prop, 'prop excludes tone');

$scene = SeriesStylePrompt::compose($preset, 'realistic', 'scene');
assertContains('画法：圆润线条', $scene, 'scene includes technique');
assertContains('整片质感：蜂蜜暖光', $scene, 'scene includes tone');
assertNotContains('片段光影：窗边逆光', $scene, 'scene excludes lighting');

$frame = SeriesStylePrompt::compose($preset, 'realistic', 'frame');
assertContains('画法：圆润线条', $frame, 'frame includes technique');
assertContains('整片质感：蜂蜜暖光', $frame, 'frame includes tone');
assertContains('片段光影：窗边逆光', $frame, 'frame includes lighting');

$video = SeriesStylePrompt::compose($preset, 'anime', 'video');
assertContains('二维动画', $video, 'video uses anime style rule');
assertContains('画法：圆润线条', $video, 'video includes technique');
assertContains('整片质感：蜂蜜暖光', $video, 'video includes tone');
assertContains('片段光影：窗边逆光', $video, 'video includes lighting');
assertNotContains('8K IMAX', $video, 'anime video rule has no 8K IMAX');
assertNotContains('Photorealistic', $video, 'anime video rule has no Photorealistic');

$animeDefault = SeriesStylePrompt::compose(null, 'anime', 'video');
assertNotContains('8K IMAX', $animeDefault, 'anime without style has no 8K IMAX');
assertNotContains('Photorealistic', $animeDefault, 'anime without style has no Photorealistic');

$wrongStyle = SeriesStylePrompt::compose($preset, '3d', 'video');
assertNotContains('画法：圆润线条', $wrongStyle, 'inapplicable style is ignored');
assertContains('三维动画', $wrongStyle, 'inapplicable style still keeps the visual-style rule');

$assembled = SeriesStylePrompt::assembleVideoPrompt(
    '风格段',
    '参考图说明',
    '雨夜街道',
    '0-4秒：人物转身',
    SeriesStylePrompt::videoUniversalRules(),
    '要求：画面稳定',
    '硬性禁止字幕',
);
$stylePos = strpos($assembled, '画面风格（全片统一）：风格段');
$timelinePos = strpos($assembled, '0-4秒：人物转身');
$rulesPos = strpos($assembled, '制作要求：');
assertTrue($stylePos !== false && $timelinePos !== false && $stylePos < $timelinePos, 'style block comes before shot timeline');
assertTrue($rulesPos !== false && $timelinePos < $rulesPos, 'universal rules come after shot timeline');
assertContains('重力和惯性真实', $assembled, 'universal rules include physics');

$quickImage = SeriesStylePrompt::prepend('一只猫', [
    'visual_styles' => ['anime'],
    'technique_prompt' => '画法：圆润线条',
    'prompt' => '整片质感：蜂蜜暖光',
    'lighting_prompt' => '片段光影：窗边逆光',
], 'scene');
assertContains('动漫二次元', $quickImage, 'quick image uses the single visual style');
assertContains('画法：圆润线条', $quickImage, 'quick image keeps technique');
assertContains('整片质感：蜂蜜暖光', $quickImage, 'quick image keeps tone');
assertNotContains('片段光影：窗边逆光', $quickImage, 'quick image skips lighting');
assertTrue(strpos($quickImage, '一只猫') > strpos($quickImage, '画法：圆润线条'), 'user prompt follows the style block');

$quickVideo = SeriesStylePrompt::prepend('一只猫', $preset, 'video');
assertContains('画法：圆润线条', $quickVideo, 'multi-style quick video keeps technique');
assertContains('片段光影：窗边逆光', $quickVideo, 'quick video keeps lighting');
assertNotContains('二维动画', $quickVideo, 'multi-style quick video does not force one visual-style rule');
assertNotContains('真人实拍', $quickVideo, 'multi-style quick video does not force the realistic rule');
assertTrue(SeriesStylePrompt::prepend('一只猫', null, 'scene') === '一只猫', 'empty style leaves the prompt unchanged');

assertTrue(SeriesStylePrompt::rerunUsesCurrentStyle("  原提示词 \n", "原提示词"), 'unchanged prompt rebuilds with current style');
assertTrue(!SeriesStylePrompt::rerunUsesCurrentStyle('手写提示词', '原提示词'), 'edited prompt stays manual');
assertTrue(!SeriesStylePrompt::rerunUsesCurrentStyle('原提示词', ''), 'missing previous prompt stays manual');

$official = StylePresetDefaults::official();
assertTrue(count($official) === 22, 'official catalog has 22 styles');
$codes = array_column($official, 'code');
assertTrue(count($codes) === count(array_unique($codes)), 'official codes are unique');
$legacy = [
    'cinematic', 'short_drama', 'documentary', 'commercial', 'noir',
    'showa_anime', 'classic_american_cartoon', 'heisei_classic', 'moe', 'kyoto_animation',
    'shinkai', 'modern_mobile_game', 'guoman', 'cel_shaded',
    'animated_feature', 'unreal', 'stylized_3d', 'clay', 'low_poly',
];
foreach ($legacy as $code) {
    assertTrue(in_array($code, $codes, true), "official catalog covers legacy variant {$code}");
}

foreach ($official as $item) {
    $styles = $item['visual_styles'] ?? [];
    assertTrue(is_array($styles) && $styles !== [], "{$item['code']} has visual styles");
    $film = !in_array('realistic', $styles, true);
    if ($film) {
        assertTrue(trim((string) ($item['technique_prompt'] ?? '')) !== '', "{$item['code']} has a technique prompt");
        $tone = (string) ($item['prompt'] ?? '');
        foreach (['Kodak', '35mm', '85mm', 'Anamorphic', 'Portra', 'Vision3'] as $term) {
            assertNotContains($term, $tone, "{$item['code']} tone avoids {$term}");
            assertNotContains($term, (string) ($item['technique_prompt'] ?? ''), "{$item['code']} technique avoids {$term}");
        }
    }
}

echo "\nAll series style prompt checks passed.\n";
