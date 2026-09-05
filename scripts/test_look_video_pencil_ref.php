<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use app\controller\AssetController;
use app\controller\SeriesController;
use app\support\AssetLookService;
use app\support\CreditService;
use app\support\ModelConfigResolver;
use app\support\ToapisPrivateAvatarService;
use app\support\VideoAssetImageRefreshService;

$tests = 0;

$look = new AssetLookService();
assertTrue($look->needsLookToapisAvatar('realistic'), 'realistic needs toapis avatar');
assertTrue(!$look->needsLookToapisAvatar('anime'), 'anime does not need toapis avatar');
assertTrue(!$look->needsLookToapisAvatar('3d'), '3d does not need toapis avatar');

$withAvatar = $look->resolveVideoLookImageUrl(
    'https://example.com/look-display.png',
    'asset://pa_look_1',
    'realistic',
    'active',
);
assertSame('asset://pa_look_1', $withAvatar['url'], 'realistic active avatar uses asset://');
assertSame(true, $withAvatar['used_toapis'], 'realistic active marks used_toapis');
assertSame('active', $withAvatar['status'], 'realistic active keeps status');

$animeIgnore = $look->resolveVideoLookImageUrl(
    'https://example.com/look-display.png',
    'asset://pa_look_1',
    'anime',
    'active',
);
assertSame('https://example.com/look-display.png', $animeIgnore['url'], 'anime ignores avatar');
assertSame(false, $animeIgnore['used_toapis'], 'anime not used_toapis');

$processing = $look->resolveVideoLookImageUrl(
    'https://example.com/look-display.png',
    'asset://pa_look_1',
    'realistic',
    'processing',
);
assertSame('https://example.com/look-display.png', $processing['url'], 'processing avatar does not use asset://');
assertSame(false, $processing['used_toapis'], 'processing not used_toapis');

$httpsAvatar = $look->resolveVideoLookImageUrl(
    'https://example.com/look-display.png',
    'https://example.com/not-asset.png',
    'realistic',
    'active',
);
assertSame('https://example.com/look-display.png', $httpsAvatar['url'], 'https avatar url is not accepted');
assertSame(false, $httpsAvatar['used_toapis'], 'https avatar not used_toapis');

$assetsById = [
    101 => [
        'id' => 101,
        'type' => 'character',
        'name' => '杰克',
        'description' => '',
        'image_prompt' => '',
        'series_id' => 9,
        'images' => [
            [
                'id' => 55,
                'view_type' => 'look',
                'reference_role' => 'look',
                'variant_name' => '第1集默认造型',
                'reference_key' => 'jack-ep1',
                'url' => 'https://example.com/look-display.png',
                'toapis_asset_url' => 'asset://pa_look_1',
                'toapis_status' => 'active',
            ],
        ],
    ],
];

$item = [
    'id' => 101,
    'asset_image_id' => 55,
    'reference_role' => 'look',
    'variant_name' => '第1集默认造型',
    'image_url' => 'https://example.com/old-frozen.png',
];

$resolvedRealistic = VideoAssetImageRefreshService::resolveAssetItem($item, $assetsById, [], 'realistic');
assertSame('asset://pa_look_1', $resolvedRealistic['image_url'] ?? null, 'refresh realistic uses asset://');
assertSame(true, $resolvedRealistic['used_toapis'] ?? null, 'refresh realistic used_toapis');
assertSame('https://example.com/look-display.png', $resolvedRealistic['display_image_url'] ?? null, 'refresh keeps display url');

$pinnedVersion = [
    77 => [
        'id' => 77,
        'asset_id' => 101,
        'asset_image_id' => 55,
        'url' => 'https://example.com/pinned-look.png',
    ],
];
$pinnedItem = $item + ['asset_image_version_id' => 77];
$resolvedPinned = VideoAssetImageRefreshService::resolveAssetItem($pinnedItem, $assetsById, $pinnedVersion, 'realistic');
assertSame('asset://pa_look_1', $resolvedPinned['image_url'] ?? null, 'refresh realistic pinned version still uses current avatar');
assertSame(true, $resolvedPinned['used_toapis'] ?? null, 'refresh realistic pinned used_toapis');

$resolvedAnime = VideoAssetImageRefreshService::resolveAssetItem($item, $assetsById, [], 'anime');
assertSame('https://example.com/look-display.png', $resolvedAnime['image_url'] ?? null, 'refresh anime uses display');
assertSame(false, $resolvedAnime['used_toapis'] ?? null, 'refresh anime not used_toapis');

$reflection = new ReflectionClass(SeriesController::class);
$controller = $reflection->newInstanceWithoutConstructor();
$invoke = static function (string $method, mixed ...$args) use ($reflection, $controller): mixed {
    $target = $reflection->getMethod($method);
    $target->setAccessible(true);
    return $target->invoke($controller, ...$args);
};

$refs = $invoke('buildVideoReferenceImages', '', [
    [
        'id' => 101,
        'name' => '杰克·第1集默认造型',
        'type' => 'character',
        'reference_role' => 'look',
        'image_url' => 'asset://pa_look_1',
        'used_toapis' => true,
        'description' => '青年男性短发',
    ],
], false);
assertSame('asset://pa_look_1', $refs['image_1']['url'] ?? null, 'builder keeps asset:// look');
assertSame(true, $refs['image_1']['used_toapis'] ?? null, 'builder keeps used_toapis');
$prompt = $invoke('buildChineseVideoReferencePrompt', $refs, false);
assertTrue(str_contains($prompt, '@图片1 作为人物「杰克·第1集默认造型」'), 'prompt keeps character label');
assertTrue(!str_contains($prompt, '彩铅'), 'prompt does not mention pencil');
assertTrue(str_contains($prompt, '保持面部、发型、体型、年龄感和服装一致'), 'prompt asks identity lock');

$skippedUnready = $invoke('buildVideoReferenceImages', '', [
    [
        'id' => 101,
        'name' => '杰克·第1集默认造型',
        'type' => 'character',
        'reference_role' => 'look',
        'image_url' => 'https://example.com/look-display.png',
        'used_toapis' => false,
    ],
    [
        'id' => 101,
        'name' => '杰克',
        'type' => 'character',
        'reference_role' => 'view',
        'image_url' => 'https://example.com/core-main.png',
    ],
    [
        'id' => 202,
        'name' => '客栈',
        'type' => 'scene',
        'reference_role' => 'view',
        'image_url' => 'https://example.com/scene.png',
    ],
], false);
assertTrue(!isset($skippedUnready['image_1']) || ($skippedUnready['image_1']['type'] ?? '') !== 'character', 'unready realistic look skipped');
assertSame('https://example.com/scene.png', $skippedUnready['image_1']['url'] ?? null, 'scene https still used');
assertSame('scene', $skippedUnready['image_1']['type'] ?? null, 'only scene remains after skipping character core/look');

$looksOnly = [
    ['character_name' => '刘备', 'look_name' => '第1集常服', 'description' => '汉室宗亲'],
    ['character_name' => '关羽', 'look_name' => '第1集常服'],
];
$ensureLooks = $reflection->getMethod('ensureCharactersFromLooks');
$ensureLooks->setAccessible(true);
$synthesizedCount = 0;
$merged = $ensureLooks->invokeArgs($controller, [[
    ['name' => '桃园', 'type' => 'scene'],
    ['name' => '青龙偃月刀', 'type' => 'prop'],
], $looksOnly, &$synthesizedCount]);
$mergedNames = array_map(static fn (array $row): string => (string) ($row['name'] ?? ''), $merged);
assertSame(2, $synthesizedCount, 'synthesizes two missing characters');
assertTrue(in_array('刘备', $mergedNames, true), 'adds 刘备 from look');
assertTrue(in_array('关羽', $mergedNames, true), 'adds 关羽 from look');
assertTrue(in_array('桃园', $mergedNames, true), 'keeps scene');

$alreadyHas = 0;
$mergedKeep = $ensureLooks->invokeArgs($controller, [[
    ['name' => '刘备', 'type' => 'character'],
], $looksOnly, &$alreadyHas]);
assertSame(1, $alreadyHas, 'only synthesizes missing 关羽');
$keepNames = array_map(static fn (array $row): string => (string) ($row['name'] ?? ''), $mergedKeep);
assertTrue(in_array('刘备', $keepNames, true), 'keeps existing 刘备');
assertTrue(in_array('关羽', $keepNames, true), 'adds missing 关羽');

$tempFiles = [];
$uploadMap = [];
$prepare = $reflection->getMethod('prepareVideoReferenceImagesForPayload');
$prepare->setAccessible(true);
$prepared = $prepare->invokeArgs($controller, [
    ['asset://pa_look_1', 'asset://pa_look_1'],
    'https://toapis.cn/v1/videos/generations',
    'seedance-2',
    'sk-test',
    [],
    &$tempFiles,
    &$uploadMap,
]);
assertSame(['asset://pa_look_1'], $prepared, 'asset:// is not re-uploaded and is de-duplicated');
assertSame([], $uploadMap, 'asset:// does not enter upload map');

assertSame('asset://pa_look_1', $invoke('normalizeImageUrlForToapis', 'asset://pa_look_1'), 'normalize keeps asset://');
assertSame('https://example.com/a.png', $invoke('normalizeImageUrlForToapis', 'https://example.com/a.png'), 'normalize keeps https');

assertTrue(ToapisPrivateAvatarService::isAssetUri('asset://pa_look_1'), 'isAssetUri accepts asset://');
assertTrue(!ToapisPrivateAvatarService::isAssetUri('https://example.com/a.png'), 'isAssetUri rejects https');
assertTrue(ToapisPrivateAvatarService::isToapisEndpoint('https://toapis.cn/v1/videos/generations'), 'cn is toapis');
assertTrue(ToapisPrivateAvatarService::isToapisEndpoint('https://toapis.xyz/v1/videos/generations'), 'xyz is toapis');
assertTrue(ToapisPrivateAvatarService::isToapisEndpoint('https://toapis.com/v1/videos/generations'), 'com is toapis');
assertTrue(!ToapisPrivateAvatarService::isToapisEndpoint('https://ark.cn-beijing.volces.com/api/v3'), 'ark is not toapis');
assertSame('https://toapis.cn', ToapisPrivateAvatarService::normalizeBaseUrl('https://toapis.cn/v1/videos/generations'), 'cn base stays');
assertSame('https://toapis.cn', ToapisPrivateAvatarService::normalizeBaseUrl('https://toapis.xyz/v1/videos/generations'), 'xyz base migrates');
assertSame('https://toapis.cn', ToapisPrivateAvatarService::normalizeBaseUrl('https://toapis.com/v1/videos/generations'), 'com base migrates');
assertSame('https://toapis.cn/v1/videos/generations', ToapisPrivateAvatarService::rewriteLegacyEndpoint('https://toapis.xyz/v1/videos/generations'), 'xyz endpoint migrates');
assertSame('https://toapis.cn/v1/videos/generations', ToapisPrivateAvatarService::rewriteLegacyEndpoint('https://toapis.com/v1/videos/generations'), 'com endpoint migrates');
assertSame('https://toapis.cn/v1/videos/generations', ToapisPrivateAvatarService::rewriteLegacyEndpoint('https://toapis.cn/v1/videos/generations'), 'cn endpoint stays');

$arkModel = [
    'type' => 'video',
    'endpoint' => 'https://ark.cn-beijing.volces.com/api/v3/contents/generations/tasks',
    'options' => ['provider' => 'ark'],
];
$seedanceModel = [
    'type' => 'video',
    'endpoint' => 'https://toapis.cn/v1/videos/generations',
    'options' => ['provider' => 'toapis'],
];
$telecomModel = [
    'type' => 'video',
    'name' => '电信 Doubao Seedance 2.0',
    'model_id' => 'doubao-seedance-2-0-260128',
    'endpoint' => 'https://aigw.telecomjs.com/v1/videos/generations',
    'options' => ['provider' => 'yinhe_async', 'result_endpoint' => 'https://aigw.telecomjs.com/v1/videos/generations/task/{id}'],
];
$yinheModel = [
    'type' => 'video',
    'name' => '银河视频',
    'model_id' => 'other-video',
    'endpoint' => 'https://api-aigc.fzyinghe.com/v1/videos/generations',
    'options' => ['provider' => 'yinhe_async'],
];
assertTrue(ToapisPrivateAvatarService::isOfficialArkVideo($arkModel), 'official ark detected');
assertTrue(ModelConfigResolver::isHiddenFromUsers($arkModel), 'official ark hidden from users');
assertTrue(!ToapisPrivateAvatarService::isOfficialArkVideo($seedanceModel), 'seedance standard is not ark');
assertTrue(!ModelConfigResolver::isHiddenFromUsers($seedanceModel), 'seedance standard remains visible');
$telecomShort = [
    'type' => 'video',
    'name' => '电信sd2',
    'model_id' => 'doubao-seedance-2-0-260128',
    'endpoint' => 'https://example.invalid/v1/videos',
    'options' => ['provider' => 'yinhe_async'],
];
assertTrue(ToapisPrivateAvatarService::isTelecomSeedanceVideo($telecomModel), 'telecom seedance detected');
assertTrue(ModelConfigResolver::isHiddenFromUsers($telecomModel), 'telecom seedance hidden from users');
assertTrue(ToapisPrivateAvatarService::isTelecomSeedanceVideo($telecomShort), 'telecom short name detected');
assertTrue(ModelConfigResolver::isHiddenFromUsers($telecomShort), 'telecom short name hidden from users');
assertTrue(!ToapisPrivateAvatarService::isTelecomSeedanceVideo($yinheModel), 'generic yinhe is not telecom');
assertTrue(!ModelConfigResolver::isHiddenFromUsers($yinheModel), 'generic yinhe remains visible');

assertSame(288.75, CreditService::quoteVideo(5, '480p', 'seedance-2'), 'seedance-2 5s 480p');
assertSame(621.25, CreditService::quoteVideo(5, '720p', 'seedance-2'), 'seedance-2 5s 720p');
assertSame(1548.75, CreditService::quoteVideo(5, '1080p', 'seedance-2'), 'seedance-2 5s 1080p');
assertSame(288.75, CreditService::quoteVideo(5, '480p', 'doubao-seedance-2-0'), 'alias doubao-seedance-2-0 uses seedance-2 rate');
assertSame(288.75, CreditService::quoteVideo(5, '480p', 'doubao-seedance-2-0-260128'), 'alias official id uses seedance-2 rate');
assertSame(232.5, CreditService::quoteVideo(5, '480p', 'seedance-2-fast'), 'seedance-2-fast 5s 480p');
assertSame(500.0, CreditService::quoteVideo(5, '720p', 'seedance-2-fast'), 'seedance-2-fast 5s 720p');

$assetReflection = new ReflectionClass(AssetController::class);
$assetController = $assetReflection->newInstanceWithoutConstructor();
$invokeAsset = static function (string $method, mixed ...$args) use ($assetReflection, $assetController): mixed {
    $target = $assetReflection->getMethod($method);
    $target->setAccessible(true);
    return $target->invoke($assetController, ...$args);
};

assertTrue(!$assetReflection->hasMethod('refineLookVideoPencilPrompt'), 'pencil prompt helper removed');
assertTrue(!$assetReflection->hasMethod('needsLookVideoPencilRef'), 'pencil need helper removed');

$lookPrompt = $invokeAsset(
    'refinePromptForView',
    '刘备第1集常服',
    'look',
    'https://example.com/core.png',
    'character',
    'realistic',
    'china',
    'cinematic',
);
assertTrue(str_contains($lookPrompt, '左栏'), 'look prompt has left column');
assertTrue(str_contains($lookPrompt, '无头全身'), 'look prompt asks headless three-view');
assertTrue(str_contains($lookPrompt, '右栏'), 'look prompt has right column');
assertTrue(str_contains($lookPrompt, '放大特写'), 'look prompt asks close-up');
assertTrue(!str_contains($lookPrompt, '不要三宫格'), 'look prompt does not ban split board');
assertTrue(!str_contains($lookPrompt, '禁止去头'), 'look prompt does not forbid headless left column');
assertTrue(!str_contains($lookPrompt, '彩铅'), 'look prompt does not ask pencil');

assertTrue(ToapisPrivateAvatarService::isLookAvatarActive('active', 'asset://pa_look_1'), 'active asset uri is look avatar');
assertTrue(ToapisPrivateAvatarService::isLookAvatarActive('ACTIVE', 'ASSET://pa_look_1'), 'look avatar check is case insensitive');
assertTrue(!ToapisPrivateAvatarService::isLookAvatarActive('processing', 'asset://pa_look_1'), 'processing is not look avatar');
assertTrue(!ToapisPrivateAvatarService::isLookAvatarActive('active', 'https://example.com/x.png'), 'https is not look avatar');
assertTrue(!ToapisPrivateAvatarService::isLookAvatarActive('', ''), 'empty is not look avatar');
assertTrue(ToapisPrivateAvatarService::isLookAvatarProcessing('processing'), 'processing status is in-flight');
assertTrue(!ToapisPrivateAvatarService::isLookAvatarProcessing('failed'), 'failed is not processing');
assertTrue(ToapisPrivateAvatarService::isLookAvatarPending(false, true, 'failed'), 'active ingest job stays pending even if leftover failed');
assertTrue(ToapisPrivateAvatarService::isLookAvatarPending(false, false, 'processing'), 'processing without job still pending');
assertTrue(!ToapisPrivateAvatarService::isLookAvatarPending(false, false, 'failed'), 'failed without job is not pending');
assertTrue(!ToapisPrivateAvatarService::isLookAvatarPending(true, true, 'processing'), 'passed avatar is not pending');

$seriesReflection = new ReflectionClass(SeriesController::class);
$seriesController = $seriesReflection->newInstanceWithoutConstructor();
$invokeSeries = static function (string $method, mixed ...$args) use ($seriesReflection, $seriesController): mixed {
    $target = $seriesReflection->getMethod($method);
    $target->setAccessible(true);
    return $target->invoke($seriesController, ...$args);
};
$labelAsset = new class extends \app\model\Asset {
    public function __construct()
    {
    }

    public function getAttr(string $name, $default = null)
    {
        return $name === 'name' ? '刘备' : $default;
    }
};
assertSame('刘备（第1集戎装）', $invokeSeries('lookVideoGateLabel', $labelAsset, ['variant_name' => '第1集戎装']), 'gate label uses character and variant');
assertSame('刘备的人物造型', $invokeSeries('lookVideoGateLabel', $labelAsset, []), 'gate label fallback without variant');

echo "Look toapis avatar tests passed: {$tests}\n";

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
