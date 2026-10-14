<?php

declare(strict_types=1);

namespace app\support;

use app\model\Series;
use app\model\StylePreset;
use think\facade\Log;

/**
 * 按作品画风和生成类型拼风格提示词。
 * 画法进入所有图和视频；整片质感只进场景、首帧和视频；片段光影只进首帧和视频。
 */
class SeriesStylePrompt
{
    public const KINDS = ['character', 'prop', 'scene', 'frame', 'video'];

    /** @var array<int, array<string, mixed>|null> */
    private static array $presetCache = [];

    /** @return list<string> */
    public static function filmParameterKeys(): array
    {
        return ['medium', 'grain', 'lens', 'era'];
    }

    public static function forgetCache(): void
    {
        self::$presetCache = [];
    }

    /**
     * 旧数据没有适用画风时按写实处理。
     *
     * @return list<string>
     */
    public static function normalizeVisualStyles(mixed $value, bool $fallbackRealistic = true): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            } else {
                $value = preg_split('/[,，]/u', $value) ?: [];
            }
        }
        if (!is_array($value)) {
            $value = [];
        }

        $result = [];
        foreach ($value as $item) {
            $style = strtolower(trim((string) $item));
            if (in_array($style, ['realistic', 'anime', '3d'], true) && !in_array($style, $result, true)) {
                $result[] = $style;
            }
        }

        if ($result === [] && $fallbackRealistic) {
            return ['realistic'];
        }

        return $result;
    }

    public static function normalizeVisualStyle(string $style): string
    {
        $style = strtolower(trim($style));

        return in_array($style, ['realistic', 'anime', '3d'], true) ? $style : 'realistic';
    }

    public static function visualStyleLabel(string $style): string
    {
        return match (self::normalizeVisualStyle($style)) {
            'anime' => '动漫',
            '3d' => '3D',
            default => '写实',
        };
    }

    /** @param array<string, mixed>|null $preset */
    public static function applies(?array $preset, string $visualStyle): bool
    {
        if ($preset === null) {
            return false;
        }

        return in_array(
            self::normalizeVisualStyle($visualStyle),
            self::normalizeVisualStyles($preset['visual_styles'] ?? null),
            true
        );
    }

    public static function imageStyleRule(string $visualStyle): string
    {
        return match (self::normalizeVisualStyle($visualStyle)) {
            'anime' => '动漫二次元风格，手绘质感，色彩鲜艳，线条清晰；禁止写实摄影、真人实拍、照片级皮肤与真实镜头景深；禁止纯3D CGI。Anime style, 2D hand-drawn look, vibrant colors, clean lines; no photorealistic live-action; no pure 3D CGI.',
            '3d' => '3D渲染风格，精细建模，电影级光影，高对比度；禁止2D动漫平涂与赛璐璐；非手机实拍照片。3D render style, high detail modeling, cinematic lighting, polished 3D look; no 2D anime cel-shading; not a phone camera photo.',
            default => '电影感真实质感，真人拍摄效果，写实画风；禁止动漫、二次元、卡通、赛璐璐、手绘插画与漫画平涂。Cinematic realistic style, live-action look, highly detailed textures; no anime, cartoon, cel-shading, or hand-drawn illustration.',
        };
    }

    public static function videoStyleRule(string $visualStyle): string
    {
        return match (self::normalizeVisualStyle($visualStyle)) {
            'anime' => '画风最高优先级：二维动画质感，线条、上色和角色设定在各帧之间保持稳定；表演清楚、姿态易读；严禁照片级写实、真人实拍、照片皮肤质感和镜头景深，严禁 3D CGI 质感。',
            '3d' => '画风最高优先级：三维动画渲染质感，角色模型、材质在各帧之间保持一致；动作风格化但符合物理；严禁二维平涂或赛璐璐质感，严禁真人实拍。',
            default => '画风最高优先级：真人实拍质感，不要 3D 渲染、游戏引擎、动漫或卡通；真实皮肤纹理，包含毛孔、细纹和轻微瑕疵；物理电影镜头的景深表现；自然表演，视线准确，有反应前的停顿和可见的呼吸。',
        };
    }

    public static function videoUniversalRules(): string
    {
        return '物理：重力和惯性真实，身体、衣物、道具、水、火、烟、碎片有真实的重量、接触和阻力。'
            . '连续性：角色身份、服装、道具、场景空间、画面方向、光线方向在各镜头之间保持稳定。'
            . '画面质量：24fps 电影运动感，对焦处主体清晰，不抖动、不糊、无压缩瑕疵、脸不变形、手不变形。'
            . '音效：剧本没有台词时只要环境音效，声音符合场景；剧本没写就不加音乐。';
    }

    /**
     * @param array<string, mixed>|null $preset
     */
    public static function compose(?array $preset, string $visualStyle, string $kind): string
    {
        $visualStyle = self::normalizeVisualStyle($visualStyle);
        if (!in_array($kind, self::KINDS, true)) {
            $kind = 'frame';
        }
        $usable = self::applies($preset, $visualStyle) ? $preset : null;
        $parts = [$kind === 'video' ? self::videoStyleRule($visualStyle) : self::imageStyleRule($visualStyle)];
        if ($usable !== null) {
            $technique = trim((string) ($usable['technique_prompt'] ?? ''));
            $tone = trim((string) ($usable['prompt'] ?? ''));
            $lighting = trim((string) ($usable['lighting_prompt'] ?? ''));
            if ($technique !== '') {
                $parts[] = $technique;
            }
            if (in_array($kind, ['scene', 'frame', 'video'], true) && $tone !== '') {
                $parts[] = $tone;
            }
            if (in_array($kind, ['frame', 'video'], true) && $lighting !== '') {
                $parts[] = $lighting;
            }
        }

        return implode("\n", array_values(array_filter(
            $parts,
            static fn (string $value): bool => trim($value) !== ''
        )));
    }

    public static function rerunUsesCurrentStyle(string $submitted, string $previousFinal): bool
    {
        $previousFinal = trim(str_replace(["\r\n", "\r"], "\n", $previousFinal));
        if ($previousFinal === '') {
            return false;
        }

        return trim(str_replace(["\r\n", "\r"], "\n", $submitted)) === $previousFinal;
    }

    public static function assembleVideoPrompt(
        string $styleBlock,
        string $referenceText,
        string $sceneLine,
        string $timelineText,
        string $universalRules,
        string $requirements,
        string $noSubtitleRule,
    ): string {
        $parts = array_values(array_filter([
            $noSubtitleRule,
            trim($styleBlock) !== '' ? '画面风格（全片统一）：' . trim($styleBlock) : '',
            $referenceText,
            trim($sceneLine) !== '' ? '场景：' . trim($sceneLine) : '',
            $timelineText,
            trim($universalRules) !== '' ? '制作要求：' . trim($universalRules) : '',
            $requirements,
            $noSubtitleRule,
        ], static fn (string $value): bool => trim($value) !== ''));

        return implode("\n\n", $parts);
    }

    /** @return array<string, mixed>|null */
    public static function presetForSeries(?Series $series): ?array
    {
        if (!$series instanceof Series) {
            return null;
        }
        $seriesId = (int) $series->getAttr('id');
        if ($seriesId > 0 && array_key_exists($seriesId, self::$presetCache)) {
            return self::$presetCache[$seriesId];
        }

        $loaded = self::loadApplicable(
            (int) ($series->getAttr('style_preset_id') ?? 0),
            (int) $series->getAttr('user_id'),
            (string) ($series->getAttr('visual_style') ?? 'realistic'),
        );
        if ($seriesId > 0) {
            self::$presetCache[$seriesId] = $loaded;
        }

        return $loaded;
    }

    /**
     * 速创没有作品画风。只适用一种画风时带上该画风规则；多种画风只带风格正文，避免写实和动漫规则叠在一起。
     */
    public static function prepend(string $userPrompt, ?array $preset, string $kind): string
    {
        $userPrompt = trim(str_replace(["\r\n", "\r"], "\n", $userPrompt));
        if ($preset === null) {
            return $userPrompt;
        }

        $styles = self::normalizeVisualStyles($preset['visual_styles'] ?? null);
        if (count($styles) === 1) {
            $block = self::compose($preset, $styles[0], $kind);
        } else {
            $block = self::styleBody($preset, $kind);
        }
        $block = trim($block);
        if ($block === '') {
            return $userPrompt;
        }
        if ($userPrompt === '') {
            return $block;
        }

        return $block . "\n\n" . $userPrompt;
    }

    /** @param array<string, mixed> $preset */
    private static function styleBody(array $preset, string $kind): string
    {
        if (!in_array($kind, self::KINDS, true)) {
            $kind = 'scene';
        }
        $parts = [];
        $technique = trim((string) ($preset['technique_prompt'] ?? ''));
        $tone = trim((string) ($preset['prompt'] ?? ''));
        $lighting = trim((string) ($preset['lighting_prompt'] ?? ''));
        if ($technique !== '') {
            $parts[] = $technique;
        }
        if (in_array($kind, ['scene', 'frame', 'video'], true) && $tone !== '') {
            $parts[] = $tone;
        }
        if (in_array($kind, ['frame', 'video'], true) && $lighting !== '') {
            $parts[] = $lighting;
        }

        return implode("\n", $parts);
    }

    /** @return array<string, mixed>|null */
    public static function loadOwned(int $presetId, int $ownerId): ?array
    {
        if ($presetId <= 0 || $ownerId <= 0) {
            return null;
        }

        try {
            $preset = StylePreset::where('id', $presetId)->where('user_id', $ownerId)->find();
        } catch (\Throwable $error) {
            Log::warning('style preset lookup failed: ' . $error->getMessage());

            return null;
        }
        if (!$preset instanceof StylePreset) {
            Log::warning("style preset missing id={$presetId} user={$ownerId}");

            return null;
        }

        return self::fromModel($preset);
    }

    /** @return array<string, mixed>|null */
    public static function loadApplicable(int $presetId, int $ownerId, string $visualStyle): ?array
    {
        $data = self::loadOwned($presetId, $ownerId);
        if ($data === null) {
            return null;
        }
        if (!self::applies($data, $visualStyle)) {
            Log::warning("style preset {$presetId} does not apply to {$visualStyle}");

            return null;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public static function fromModel(StylePreset $preset): array
    {
        return [
            'id' => (int) $preset->getAttr('id'),
            'code' => (string) ($preset->getAttr('code') ?: ''),
            'name' => (string) $preset->getAttr('name'),
            'visual_styles' => self::normalizeVisualStyles($preset->getAttr('visual_styles')),
            'technique_prompt' => (string) $preset->getAttr('technique_prompt'),
            'prompt' => (string) $preset->getAttr('prompt'),
            'lighting_prompt' => (string) $preset->getAttr('lighting_prompt'),
        ];
    }

    public static function summaryFor(?array $preset): array
    {
        if ($preset === null) {
            return ['style_name' => '', 'style_prompt' => ''];
        }

        return [
            'style_name' => (string) ($preset['name'] ?? ''),
            'style_prompt' => trim(implode("\n", array_filter([
                (string) ($preset['technique_prompt'] ?? ''),
                (string) ($preset['prompt'] ?? ''),
                (string) ($preset['lighting_prompt'] ?? ''),
            ], static fn (string $value): bool => trim($value) !== ''))),
        ];
    }
}
