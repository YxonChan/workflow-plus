<?php

declare(strict_types=1);

namespace app\support;

use InvalidArgumentException;

final class EcommercePolicy
{
    public static function text($value, int $limit, bool $required = false): string
    {
        if (!is_string($value) || mb_strlen($value) > $limit || ($required && trim($value) === '')) {
            throw new InvalidArgumentException('内容为空或超过允许长度：' . $limit);
        }
        return trim($value);
    }

    public static function request(array $value, string $mode): array
    {
        $prompt = self::text($value['prompt'] ?? '', $mode === 'video' ? 10000 : 30000, true);
        $images = $value['images'] ?? [];
        if (!is_array($images) || !array_is_list($images) || count($images) > ($mode === 'video' ? 9 : 13)) {
            throw new InvalidArgumentException('参考图数量超过模型上限');
        }
        foreach ($images as $url) self::text($url, 4000, true);
        $options = array_intersect_key((array) ($value['options'] ?? []), array_flip([
            'size', 'aspect_ratio', 'resolution', 'quality', 'background', 'duration', 'generate_audio',
        ]));
        foreach ($options as $option) {
            if (!is_scalar($option) || (is_string($option) && strlen($option) > 100)) {
                throw new InvalidArgumentException('生成参数格式无效');
            }
        }
        if (strlen(json_encode($value['original'] ?? [])) > 150000) throw new InvalidArgumentException('原始请求过大');
        if ($mode === 'video') {
            if (!in_array((int) ($options['duration'] ?? 0), range(4, 15), true)) {
                throw new InvalidArgumentException('视频时长应为 4 至 15 秒');
            }
            if (!in_array($options['aspect_ratio'] ?? '', ['21:9', '16:9', '4:3', '1:1', '3:4', '9:16'], true)
                || !in_array($options['resolution'] ?? '', ['480p', '720p', '1080p', '4k'], true)) {
                throw new InvalidArgumentException('视频比例或分辨率无效');
            }
            $options['duration'] = (int) $options['duration'];
            $options['generate_audio'] = (bool) ($options['generate_audio'] ?? true);
        }
        $goal = self::text($value['creative_goal'] ?? '', 20);
        $videoStyle = self::text($value['video_style'] ?? 'showcase', 20);
        if (!in_array($goal, ['', 'main', 'hero', 'detail', 'full', 'poster', 'tryon', 'video'], true)
            || !in_array($videoStyle, ['showcase', 'ugc'], true)) {
            throw new InvalidArgumentException('商品创作方向无效');
        }
        return ['prompt' => $prompt, 'images' => $images, 'options' => $options, 'creative_goal' => $goal, 'video_style' => $videoStyle,
            'original' => (array) ($value['original'] ?? [])];
    }

    public static function recoveryStatus(string $taskId, string $resultUrl): string
    {
        return $resultUrl !== '' ? 'success' : ($taskId !== '' ? 'queued' : 'unknown');
    }

    public static function imageOptions(array $options, string $modelId): array
    {
        // Ecommerce requires exact composition ratios; only the current ToAPIs adapters support this contract.
        if (!ImageGenerationOptions::isToapis25($modelId)) {
            throw new InvalidArgumentException('电商图片请选择 GPT Image 2.5 或 Official 模型');
        }
        return ImageGenerationOptions::resolveToapis25($options, $modelId);
    }
}
