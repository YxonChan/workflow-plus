<?php

declare(strict_types=1);

namespace app\support;

final class AssetExtractionRequestOptimizer
{
    public const DEFAULT_MAX_TOKENS = 8192;

    public static function maxTokens(array $nodeParams): int
    {
        $value = (int) ($nodeParams['maxTokens'] ?? $nodeParams['max_tokens'] ?? self::DEFAULT_MAX_TOKENS);
        return max(2048, min(32768, $value > 0 ? $value : self::DEFAULT_MAX_TOKENS));
    }

    public static function resolvePlot(string $fallback, array $upstreamOutputs): string
    {
        $outputs = array_reverse(array_values($upstreamOutputs));
        foreach ($outputs as $output) {
            if (!is_array($output)) {
                continue;
            }
            foreach (['text', 'expanded_plot', 'plot', 'content', 'script', 'plot_input'] as $key) {
                $value = trim((string) ($output[$key] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return trim($fallback);
    }

    public static function compactLibrary(array $library): array
    {
        $result = [];
        $seen = [];
        foreach ($library as $item) {
            if (!is_array($item) || (int) ($item['asset_id'] ?? $item['id'] ?? 0) <= 0) {
                continue;
            }
            if ((int) ($item['asset_image_version_id'] ?? 0) > 0) {
                continue;
            }
            $assetId = (int) ($item['asset_id'] ?? $item['id']);
            $assetImageId = (int) ($item['asset_image_id'] ?? 0);
            $role = strtolower(trim((string) ($item['reference_role'] ?? 'view'))) === 'look' ? 'look' : 'view';
            $key = $assetId . ':' . $assetImageId . ':' . $role;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $compact = [
                'asset_id' => $assetId,
                'type' => (string) ($item['type'] ?? ''),
                'name' => trim((string) ($item['name'] ?? '')),
                'description' => mb_substr(trim((string) ($item['description'] ?? '')), 0, 300),
                'tags' => self::compactTags($item['tags'] ?? []),
            ];
            if ($role === 'look') {
                $compact['asset_image_id'] = $assetImageId;
                $compact['reference_role'] = 'look';
                $compact['character_name'] = trim((string) ($item['character_name'] ?? ''));
                $compact['variant_name'] = trim((string) ($item['variant_name'] ?? ''));
            }
            $result[] = $compact;
        }
        return $result;
    }

    private static function compactTags(mixed $tags): array
    {
        if (!is_array($tags)) {
            return [];
        }
        $result = [];
        foreach ($tags as $tag) {
            $tag = mb_substr(trim((string) $tag), 0, 40);
            if ($tag !== '' && !in_array($tag, $result, true)) {
                $result[] = $tag;
            }
            if (count($result) >= 12) {
                break;
            }
        }
        return $result;
    }
}
