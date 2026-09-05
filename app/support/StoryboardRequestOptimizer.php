<?php

declare(strict_types=1);

namespace app\support;

final class StoryboardRequestOptimizer
{
    public const DEFAULT_MAX_TOKENS = 16384;

    public static function maxTokens(array $nodeParams): int
    {
        $value = (int) ($nodeParams['maxTokens'] ?? $nodeParams['max_tokens'] ?? self::DEFAULT_MAX_TOKENS);
        return max(4096, min(32768, $value > 0 ? $value : self::DEFAULT_MAX_TOKENS));
    }

    /**
     * 分镜已有独立 plot_input 和 asset_library，不再重复发送输入节点及资产提取原始输出。
     * 其他自定义上游文本节点仍完整保留，避免破坏扩写剧情 → 分镜等自定义工作流。
     */
    public static function compactUpstreamOutputs(array $upstreamOutputs, string $plotInput): array
    {
        $result = [];
        $plotInput = trim($plotInput);

        foreach ($upstreamOutputs as $label => $output) {
            if (!is_array($output)) {
                continue;
            }

            $normalizedLabel = trim((string) $label);
            if (self::isAssetPreparationOutput($normalizedLabel)) {
                continue;
            }
            if (self::duplicatesPlotInput($output, $plotInput)) {
                continue;
            }

            $result[$label] = $output;
        }

        return $result;
    }

    public static function compactLibrary(array $library): array
    {
        return AssetExtractionRequestOptimizer::compactLibrary($library);
    }

    private static function isAssetPreparationOutput(string $label): bool
    {
        return str_contains($label, '资产提取')
            || (str_contains($label, '资产') && str_contains($label, '合并'));
    }

    private static function duplicatesPlotInput(array $output, string $plotInput): bool
    {
        if ($plotInput === '') {
            return false;
        }

        $text = trim((string) ($output['text'] ?? ''));
        $plot = trim((string) ($output['plot_input'] ?? ''));
        if ($text !== $plotInput && $plot !== $plotInput) {
            return false;
        }

        foreach ($output as $key => $value) {
            if (in_array((string) $key, ['text', 'plot_input'], true)) {
                continue;
            }
            if ($value !== null && $value !== '' && $value !== []) {
                return false;
            }
        }

        return true;
    }
}
