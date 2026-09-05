<?php

declare(strict_types=1);

namespace app\support;

/**
 * 统一图片生成规格。
 * 默认通道：腾讯云点播 AIGC OG/image2（异步任务）。
 * 从 model_configs.options 读取，未配置时使用下列默认值。
 */
final class ImageGenerationOptions
{
    public const DEFAULT_ENDPOINT = 'https://vod.tencentcloudapi.com';

    public const DEFAULT_MODEL_ID = 'OG/image2';

    public const DEFAULT_PROVIDER = 'tencent_vod';

    /** @var array<int, string> */
    public const PREFERRED_MODEL_IDS = [
        'OG/image2',
        'OG',
    ];

    public const DEFAULT_MODEL_NAME = 'Tencent OG image2';

    /** ToAPIs 使用比例尺寸，不使用旧的像素尺寸。 */
    public const SIZE_LANDSCAPE_16_9 = '16:9';

    /** 1:1 方图 */
    public const SIZE_SQUARE_1_1 = '1:1';

    public const DEFAULT_ASPECT_RATIO = '16:9';

    public const DEFAULT_RESOLUTION = '1K';

    public const DEFAULT_N = 1;

    /**
     * 默认中清 medium（腾讯云对应 image2_medium），分辨率固定 1K。
     */
    public static function defaultQuality(string $modelId = ''): string
    {
        return 'medium';
    }

    public static function defaultModelVersion(string $quality = ''): string
    {
        $quality = strtolower(trim($quality));
        if ($quality === '') {
            $quality = self::defaultQuality();
        }

        return match ($quality) {
            'low' => 'image2_low',
            'high' => 'image2_high',
            default => 'image2_medium',
        };
    }

    /**
     * 默认横屏 16:9。
     */
    public static function defaultSize(string $modelId = ''): string
    {
        return self::SIZE_LANDSCAPE_16_9;
    }

    /**
     * 合并模型 options，得到发往上游的 size / quality / n 及可选 aspect_ratio、resolution。
     *
     * @return array{size: string, quality: string, n: int, aspect_ratio?: string, resolution?: string}
     */
    public static function resolve(array $options, string $modelId = ''): array
    {
        if (!is_array($options)) {
            $options = [];
        }

        $size = self::normalizeSize((string) ($options['size'] ?? ''), $modelId);
        $quality = self::normalizeQuality((string) ($options['quality'] ?? ''), $modelId);
        $n = max(1, (int) ($options['n'] ?? self::DEFAULT_N));

        if (self::isStrictToapisGptImageModel($modelId)) {
            $size = self::normalizeStrictToapisSize($size);
            // ToAPIs channel 107（gpt-image-2）目前仅接受 quality="medium"，其余取值会被上游拒绝：
            // ValidateRequestAndSetAction for channel 107: gpt-image-2 only supports quality="medium"
            $quality = 'medium';
        }

        $out = [
            'size' => $size,
            'quality' => $quality,
            'n' => $n,
        ];

        $aspectRatio = trim((string) ($options['aspect_ratio'] ?? ''));
        if ($aspectRatio === '') {
            $aspectRatio = self::inferAspectRatioFromSize($size) ?? self::DEFAULT_ASPECT_RATIO;
        }
        $out['aspect_ratio'] = $aspectRatio;

        $out['resolution'] = self::normalizeResolution((string) ($options['resolution'] ?? ''), $modelId);

        return $out;
    }

    /**
     * 写入 model_configs.options 的推荐默认值（新建图片模型时用）。
     *
     * @return array<string, mixed>
     */
    public static function defaultModelOptions(string $modelId = ''): array
    {
        $options = [
            'provider' => self::DEFAULT_PROVIDER,
            'model_name' => 'OG',
            'model_version' => self::defaultModelVersion(self::defaultQuality($modelId)),
            'quality' => self::defaultQuality($modelId),
            'n' => self::DEFAULT_N,
            'size' => self::defaultSize($modelId),
            'aspect_ratio' => self::DEFAULT_ASPECT_RATIO,
            'resolution' => self::DEFAULT_RESOLUTION,
            'storage_mode' => 'Temporary',
            // 参考图路径实测可达 4–5 分钟；120×3s ≈ 6 分钟，避免假超时。
            'poll_interval' => 3,
            'poll_attempts' => 120,
        ];

        return $options;
    }

    /**
     * 把 size / quality / n 及扩展字段合并进已有 payload（保留 prompt、model 等）。
     */
    public static function applyToPayload(array $payload, array $options, string $modelId = ''): array
    {
        $resolved = self::resolve($options, $modelId);
        $payload['size'] = $resolved['size'];
        $payload['quality'] = $resolved['quality'];
        $payload['n'] = $resolved['n'];

        foreach (['aspect_ratio', 'resolution'] as $key) {
            if (!empty($options[$key]) || !empty($resolved[$key])) {
                $payload[$key] = $resolved[$key];
            }
        }

        unset($payload['aspect_ratio']);

        return $payload;
    }

    private static function normalizeSize(string $size, string $modelId): string
    {
        $size = trim($size);
        if ($size === '') {
            return self::defaultSize($modelId);
        }

        $lower = strtolower($size);
        if (in_array($lower, ['16:9', '16x9', 'landscape', '横屏', 'widescreen'], true)) {
            return self::SIZE_LANDSCAPE_16_9;
        }
        if (in_array($lower, ['1:1', '1x1', 'square', '方图'], true)) {
            return self::SIZE_SQUARE_1_1;
        }
        if (preg_match('/^(\d+)x(\d+)$/i', $lower, $m) === 1) {
            $w = max(1, (int) $m[1]);
            $h = max(1, (int) $m[2]);
            if (self::isStrictToapisGptImageModel($modelId)) {
                if (abs(($w / $h) - 1) < 0.05) {
                    return self::SIZE_SQUARE_1_1;
                }

                return self::SIZE_LANDSCAPE_16_9;
            }
            if (abs(($w / $h) - 1) < 0.05) {
                return self::SIZE_SQUARE_1_1;
            }
            return $w >= $h ? self::SIZE_LANDSCAPE_16_9 : '9:16';
        }

        if (self::isStrictToapisGptImageModel($modelId)) {
            return self::normalizeStrictToapisSize($size);
        }

        return $size;
    }

    private static function normalizeQuality(string $quality, string $modelId): string
    {
        $quality = trim($quality);
        if ($quality === '') {
            return self::defaultQuality($modelId);
        }

        $map = [
            '标准' => 'standard',
            '高清' => 'high',
            '超清' => 'high',
            'standard' => 'standard',
            'hd' => 'hd',
            'high' => 'high',
            'medium' => 'medium',
            'low' => 'low',
            'auto' => 'auto',
        ];

        $lower = strtolower($quality);
        if (isset($map[$quality]) || isset($map[$lower])) {
            $normalized = $map[$quality] ?? $map[$lower];
            if ($normalized === 'hd') {
                return 'high';
            }

            return $normalized;
        }

        return $quality;
    }

    /**
     * 图片生成统一固定为 1K，避免旧配置或手工配置回退到高分辨率导致任务失败或资产图加载过慢。
     */
    private static function normalizeResolution(string $resolution, string $modelId = ''): string
    {
        $resolution = strtolower(trim($resolution));
        if (self::isStrictToapisGptImageModel($modelId)) {
            return self::DEFAULT_RESOLUTION;
        }
        if ($resolution === '') {
            return self::DEFAULT_RESOLUTION;
        }

        $map = [
            '1k' => self::DEFAULT_RESOLUTION,
            '1024' => self::DEFAULT_RESOLUTION,
            '2k' => self::DEFAULT_RESOLUTION,
            '1440p' => self::DEFAULT_RESOLUTION,
            '2560x1440' => self::DEFAULT_RESOLUTION,
            '4k' => self::DEFAULT_RESOLUTION,
            '2160p' => self::DEFAULT_RESOLUTION,
            '3840x2160' => self::DEFAULT_RESOLUTION,
            'uhd' => self::DEFAULT_RESOLUTION,
        ];

        return $map[$resolution] ?? self::DEFAULT_RESOLUTION;
    }

    private static function isStrictToapisGptImageModel(string $modelId): bool
    {
        $modelId = strtolower(trim($modelId));
        return $modelId === 'gpt-image-2' || $modelId === 'gpt-image-2-official';
    }

    private static function normalizeStrictToapisSize(string $size): string
    {
        $size = strtolower(trim($size));
        if (in_array($size, [self::SIZE_SQUARE_1_1, '1x1', 'square', '方图'], true)) {
            return self::SIZE_SQUARE_1_1;
        }

        return self::SIZE_LANDSCAPE_16_9;
    }

    private static function inferAspectRatioFromSize(string $size): ?string
    {
        if (preg_match('/^(\d+)x(\d+)$/i', $size, $m)) {
            $w = (int) $m[1];
            $h = (int) $m[2];
            if ($w > $h) {
                return '16:9';
            }
            if ($w < $h) {
                return '9:16';
            }

            return '1:1';
        }

        return null;
    }
}
