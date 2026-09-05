<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use app\controller\SeriesController;
use app\support\DefaultWorkflowGraphs;
use app\support\PromoVideoSegmentConfig;

$episodeGraph = DefaultWorkflowGraphs::episode();
$episodeVideo = $episodeGraph['nodes'][3]['data']['params'] ?? [];
assertSame(false, (bool) ($episodeVideo['chainShots'] ?? true), '短剧标准流程视频应默认关闭镜头衔接');

$chained = [
    'nodes' => [
        ['id' => 'video-gen', 'data' => ['kind' => 'video', 'params' => ['chainShots' => true]]],
        ['id' => 'text-1', 'data' => ['kind' => 'text', 'params' => []]],
    ],
];
$unchained = DefaultWorkflowGraphs::disableVideoChainShots($chained);
assertSame(false, (bool) ($unchained['nodes'][0]['data']['params']['chainShots'] ?? true), '官方模板升级应关闭视频镜头衔接');
assertSame(true, !isset($unchained['nodes'][1]['data']['params']['chainShots']), '非视频节点不应被写入 chainShots');

$graph = DefaultWorkflowGraphs::promoEpisode();
$nodes = $graph['nodes'] ?? [];
$edges = $graph['edges'] ?? [];

assertSame(5, count($nodes), '宣传片流程应包含 5 个节点');
assertSame(4, count($edges), '宣传片流程应包含 4 条连接边');
assertSame(
    ['宣传片创意', '资产提取与合并', '宣传片分镜处理', '视频生成', '输出视频'],
    array_map(static fn (array $node): string => (string) ($node['label'] ?? ''), $nodes),
    '宣传片流程节点顺序应稳定'
);

$storyboardParams = $nodes[2]['data']['params'] ?? [];
$videoParams = $nodes[3]['data']['params'] ?? [];
$outputParams = $nodes[4]['data']['params'] ?? [];
$prompt = (string) ($storyboardParams['prompt'] ?? '');

assertTrue(str_contains($prompt, 'promo_segment_count'), '分镜提示词应读取单次运行的视频段数');
assertTrue(str_contains($prompt, 'Output exactly that number of video nodes'), '分镜提示词应强制遵循动态段数');
assertTrue(str_contains($prompt, 'If promo_segment_count is 1, output only'), '1 段时应禁止扩成多段');
assertSame(15, (int) ($videoParams['duration'] ?? 0), '每个视频节点应为 15 秒');
assertSame(false, (bool) ($videoParams['chainShots'] ?? true), '宣传片视频应默认关闭镜头衔接');
assertSame(true, (bool) ($videoParams['generate_audio'] ?? false), '宣传片视频应启用模型原生音频');
assertSame('mp4', (string) ($outputParams['format'] ?? ''), '最终输出格式应为 MP4');

foreach ([1, 2, 3, 4] as $count) {
    assertSame($count, PromoVideoSegmentConfig::optional($count), "应接受 {$count} 段配置");
    assertSame($count * 15, PromoVideoSegmentConfig::totalSeconds($count), "{$count} 段总时长应正确");
    $instruction = PromoVideoSegmentConfig::applyToInstruction('原始宣传片分镜要求', $count);
    assertTrue(str_contains($instruction, "promo_segment_count = {$count}"), "{$count} 段配置应注入节点指令");
    assertTrue(str_contains($instruction, "exactly {$count} video node(s)"), "{$count} 段指令应包含确定数量");
}
assertSame(3, PromoVideoSegmentConfig::fromPayload([]), '未传段数时应默认 3 段');
assertSame(2, PromoVideoSegmentConfig::countStoryboardNodes("【视频节点01｜15s】\n内容\n【Video Node 02 | 15s】\nContent"), '应识别中英文视频节点标题');
assertThrows(
    static fn () => PromoVideoSegmentConfig::optional(5),
    '应拒绝超过 4 段的配置'
);

$controllerReflection = new ReflectionClass(SeriesController::class);
$controller = $controllerReflection->newInstanceWithoutConstructor();
$messageBuilder = $controllerReflection->getMethod('buildEpisodeNodeMessages');
$messageBuilder->setAccessible(true);
$messages = $messageBuilder->invoke($controller, '宣传片分镜处理', PromoVideoSegmentConfig::applyToInstruction('动态分镜测试', 2), [
    'series_id' => 1,
    'episode_id' => 1,
    'episode_title' => '测试宣传片',
    'episode_number' => 1,
    'plot_input' => '测试创意',
    'content_region' => 'china',
    'content_region_rule' => '',
    'upstream_outputs' => [],
    'asset_library' => [],
    'promo_segment_count' => 2,
    'promo_total_seconds' => 30,
]);
$episodePayload = json_decode((string) ($messages[1]['content'] ?? ''), true) ?: [];
assertSame(2, $episodePayload['promo_segment_count'] ?? null, '剧集级 AI 用户载荷必须携带 2 段配置');
assertSame(30, $episodePayload['promo_total_seconds'] ?? null, '剧集级 AI 用户载荷必须携带 30 秒总时长');
assertTrue(
    str_contains((string) ($episodePayload['node_instruction'] ?? ''), 'promo_segment_count = 2'),
    '剧集级节点指令必须置顶注入 2 段配置'
);

assertSame(1, PromoVideoSegmentConfig::resolve(1, []), '剧集保存的 1 段优先于默认 3 段');
assertSame(2, PromoVideoSegmentConfig::resolve(null, ['promo_segment_count' => 2]), '无剧集字段时读取 run payload');
$overproduced = "【视频节点01｜15s｜钩子】\n镜头1（0-15s）：A\n\n【视频节点02｜15s｜展示】\n镜头1（0-15s）：B\n\n【视频节点03｜15s｜收束】\n镜头1（0-15s）：C";
$trimmed = PromoVideoSegmentConfig::trimStoryboardToCount($overproduced, 1);
assertSame(1, PromoVideoSegmentConfig::countStoryboardNodes($trimmed), '超出段数时应裁掉多余视频节点');
assertTrue(str_contains($trimmed, '视频节点01'), '裁剪后应保留第 1 段');
assertTrue(!str_contains($trimmed, '视频节点02'), '裁剪后不应保留第 2 段');

echo "[PASS] 1-4 段宣传片工作流结构、时长与校验配置正确\n";

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true)
        );
    }
}

function assertThrows(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException($message);
}
