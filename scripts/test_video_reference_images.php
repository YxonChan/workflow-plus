<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use app\controller\SeriesController;

$tests = 0;
$reflection = new ReflectionClass(SeriesController::class);
$controller = $reflection->newInstanceWithoutConstructor();

$invoke = static function (string $method, mixed ...$args) use ($reflection, $controller): mixed {
    $target = $reflection->getMethod($method);
    $target->setAccessible(true);
    return $target->invoke($controller, ...$args);
};

$assets = [
    [
        'id' => 808,
        'name' => '导演·造型',
        'type' => 'character',
        'reference_role' => 'look',
        'image_url' => 'asset://pa_director',
        'used_toapis' => true,
        'description' => '中年男性，约45岁，短发微乱，面容疲惫',
    ],
    [
        'id' => 810,
        'name' => '排期表',
        'type' => 'prop',
        'image_url' => 'https://example.com/schedule.png',
        'description' => '纸质排期表，上面有红圈标注延期3天',
    ],
    [
        'id' => 811,
        'name' => '财务报表',
        'type' => 'prop',
        'image_url' => 'https://example.com/finance.png',
        'description' => '财务报表，上面有红笔圈出2万/小时',
    ],
];

$shotFrameUrl = 'https://example.com/upload-first-frame.jpg';

$refs = $invoke('buildVideoReferenceImages', $shotFrameUrl, $assets, false);
assertSame(['image_1', 'image_2', 'image_3'], array_keys($refs), 'dense aliases start at image_1');
assertSame('导演·造型', $refs['image_1']['name'] ?? null, 'character is 图片1');
assertSame('排期表', $refs['image_2']['name'] ?? null, 'first prop is 图片2');
assertSame('财务报表', $refs['image_3']['name'] ?? null, 'second prop is 图片3');
assertSame(false, in_array($shotFrameUrl, array_column($refs, 'url'), true), 'do not append unlabeled first frame');

$urls = $invoke('videoReferenceImageUrls', $refs);
assertSame([
    'asset://pa_director',
    'https://example.com/schedule.png',
    'https://example.com/finance.png',
], $urls, 'payload order matches @图片1/2/3');

$prompt = $invoke('buildChineseVideoReferencePrompt', $refs, false);
assertTrue(str_contains($prompt, '@图片1 作为人物「导演·造型」'), 'prompt starts at 图片1');
assertTrue(str_contains($prompt, '@图片2 作为道具「排期表」'), 'schedule is 图片2');
assertTrue(str_contains($prompt, '@图片3 作为道具「财务报表」'), 'finance is 图片3');
assertTrue(!str_contains($prompt, '@图片4'), 'no fourth image mention');

$sceneAssets = array_merge([
    [
        'id' => 900,
        'name' => '昏暗办公室',
        'type' => 'scene',
        'image_url' => 'https://example.com/office.png',
        'description' => '灰调冷光办公室',
    ],
], $assets);
$sceneRefs = $invoke('buildVideoReferenceImages', $shotFrameUrl, $sceneAssets, false);
assertSame('导演·造型', $sceneRefs['image_1']['name'] ?? null, 'character/look stays 图片1 even with scene');
assertSame('昏暗办公室', $sceneRefs['image_4']['name'] ?? null, 'scene is numbered after character and props');
assertSame('scene', $sceneRefs['image_4']['type'] ?? null, 'scene type preserved');

$sameUrlAssets = $assets;
$sameUrlAssets[1]['image_url'] = $shotFrameUrl;
$deduped = $invoke('buildVideoReferenceImages', $shotFrameUrl, $sameUrlAssets, false);
assertSame(3, count($deduped), 'first frame matching an asset is not duplicated');

$chained = $invoke('buildVideoReferenceImages', $shotFrameUrl, $assets, true);
assertSame(4, count($chained), 'continuity frame is appended when chaining');
assertSame('continuity_reference', $chained['image_4']['type'] ?? null, 'continuity is last image');
assertSame($shotFrameUrl, $chained['image_4']['url'] ?? null, 'continuity url');
$chainedPrompt = $invoke('buildChineseVideoReferencePrompt', $chained, true);
assertTrue(str_contains($chainedPrompt, '@图片4 作为上一镜头尾帧衔接参考'), 'continuity mentioned as 图片4 not 视频1');

$fallback = $invoke('buildVideoReferenceImages', $shotFrameUrl, [], false);
assertSame('shot_frame', $fallback['image_1']['type'] ?? null, 'empty assets fall back to shot frame');

$eightAssets = [
    ['id' => 1, 'name' => '刘备', 'type' => 'character', 'reference_role' => 'look', 'image_url' => 'asset://pa_1', 'used_toapis' => true],
    ['id' => 2, 'name' => '关羽', 'type' => 'character', 'reference_role' => 'look', 'image_url' => 'asset://pa_2', 'used_toapis' => true],
    ['id' => 3, 'name' => '张飞', 'type' => 'character', 'reference_role' => 'look', 'image_url' => 'asset://pa_3', 'used_toapis' => true],
    ['id' => 4, 'name' => '剑', 'type' => 'prop', 'image_url' => 'https://example.com/p4.png'],
    ['id' => 5, 'name' => '盔', 'type' => 'prop', 'image_url' => 'https://example.com/p5.png'],
    ['id' => 6, 'name' => '马', 'type' => 'prop', 'image_url' => 'https://example.com/p6.png'],
    ['id' => 7, 'name' => '营帐', 'type' => 'scene', 'image_url' => 'https://example.com/p7.png'],
    ['id' => 8, 'name' => '令旗', 'type' => 'prop', 'image_url' => 'https://example.com/p8.png'],
];
$eightRefs = $invoke('buildVideoReferenceImages', '', $eightAssets, false);
assertSame(['刘备', '关羽', '张飞', '剑', '盔', '马', '令旗', '营帐'], array_column($eightRefs, 'name'), 'eight-image rank order');
$eightPrompt = $invoke('buildChineseVideoReferencePrompt', $eightRefs, false) . "\n刘备持剑，关羽在侧。";
$dropLastPrompt = preg_replace('/^@图片8.*(?:\n|$)/mu', '', $eightPrompt) ?? $eightPrompt;
$dropLast = $invoke('applyDirectVideoPromptReferenceFilter', $dropLastPrompt, $eightAssets, '', false);
assertSame(7, count($dropLast['assets']), 'drop last @图片8 keeps 7 assets');
assertSame(['image_1', 'image_2', 'image_3', 'image_4', 'image_5', 'image_6', 'image_7'], array_keys($dropLast['reference_meta']), 'drop last keeps dense 1-7');
assertTrue(!str_contains($dropLast['prompt'], '@图片8'), 'drop last removes @图片8 from prompt');
assertTrue(str_contains($dropLast['prompt'], '@图片7 作为道具「令旗」'), 'drop last keeps @图片7 as flag');
assertSame('https://example.com/p8.png', $dropLast['reference_meta']['image_7']['url'] ?? null, 'payload image 7 is flag');
assertSame(false, $dropLast['include_continuity_frame'], 'drop last does not invent continuity');

$dropMiddlePrompt = preg_replace('/^@图片3.*(?:\n|$)/mu', '', $eightPrompt) ?? $eightPrompt;
$dropMiddle = $invoke('applyDirectVideoPromptReferenceFilter', $dropMiddlePrompt, $eightAssets, '', false);
assertSame(7, count($dropMiddle['assets']), 'drop middle keeps 7 assets');
assertSame('关羽', $dropMiddle['reference_meta']['image_2']['name'] ?? null, 'image 2 stays Guan Yu');
assertSame('剑', $dropMiddle['reference_meta']['image_3']['name'] ?? null, 'old image 4 becomes image 3');
assertSame('营帐', $dropMiddle['reference_meta']['image_7']['name'] ?? null, 'old image 8 becomes image 7');
assertTrue(str_contains($dropMiddle['prompt'], '@图片3 作为道具「剑」'), 'prompt remaps old 图片4 to 图片3 so payload stays aligned');
assertTrue(!str_contains($dropMiddle['prompt'], '@图片8'), 'prompt no longer has 图片8 after remap');
assertTrue(str_contains($dropMiddle['prompt'], '@图片7 作为场景「营帐」'), 'prompt remaps old 图片8 to 图片7');

$noMention = $invoke('applyDirectVideoPromptReferenceFilter', '只写动作，不要参考图。', $eightAssets, $shotFrameUrl, true);
assertSame([], $noMention['assets'], 'edited prompt without @图片N sends no frozen assets');
assertSame([], $noMention['reference_meta'], 'edited prompt without @图片N sends no rebuilt images');
assertSame(false, $noMention['include_continuity_frame'], 'edited prompt does not re-inject continuity');
assertSame('只写动作，不要参考图。', $noMention['prompt'], 'edited prompt is sent as-is');

$dropContinuityPrompt = $invoke('buildChineseVideoReferencePrompt', $chained, true);
$dropContinuityPrompt = preg_replace('/^@图片4.*(?:\n|$)/mu', '', $dropContinuityPrompt) ?? $dropContinuityPrompt;
$dropContinuity = $invoke('applyDirectVideoPromptReferenceFilter', $dropContinuityPrompt, $assets, $shotFrameUrl, true);
assertSame(3, count($dropContinuity['assets']), 'removing continuity line keeps original assets');
assertSame(false, $dropContinuity['include_continuity_frame'], 'removing continuity line does not re-append end frame');
assertTrue(!str_contains($dropContinuity['prompt'], '@图片4'), 'continuity mention stays removed');

$unchanged = $invoke('applyDirectVideoPromptReferenceFilter', $eightPrompt, $eightAssets, '', false);
assertSame(8, count($unchanged['assets']), 'full prompt keeps all 8');
assertSame($eightPrompt, $unchanged['prompt'], 'full prompt is not rewritten');

echo "Video reference image tests passed: {$tests}\n";

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    global $tests;
    $tests++;
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL {$label}\n expected=" . var_export($expected, true) . "\n actual=" . var_export($actual, true) . "\n");
        exit(1);
    }
}

function assertTrue(bool $ok, string $label): void
{
    assertSame(true, $ok, $label);
}
