<?php

declare(strict_types=1);

/**
 * 平台积分物价表（只读，第一期不支持在线修改）。
 *
 * 规则：
 * - 1 积分 = ¥0.01
 * - 平台价 = 供应商成本 × 1.25（原价上浮 25%）
 * - USD 按 7.2 CNY/USD
 *
 * 来源：
 * - DeepSeek V4 Flash peak：api-docs.deepseek.com（cache miss $0.44/M，output $1.32/M）
 * - Tencent OG/image2：官方 1K 价格 ×1.25；参考图输入 ¥0.10/张 ×1.25
 * - Seedance 标准版：ToAPIs seedance-2.0「输入不含视频」价目表（16:9 5 秒）×1.25
 */
return [
    'enabled' => true,
    'pricing_version' => '2026-09-01.1',
    'updated_at' => '2026-09-01',
    'credit_unit_cny' => 0.01,
    'usd_cny' => 7.2,
    'markup' => 1.25,
    'notes' => [
        '平台价 = 供应商成本上浮 25%（×1.25）；1 积分 = ¥0.01。',
        '视频按 Seedance 标准版「输入不含视频」价目：480P ¥0.462、720P ¥0.994、1080P ¥2.478 / 秒 ×1.25 → 57.75 / 124.25 / 309.75 积分/秒。当前按输出秒数预估，不以实际 token 事后调账。',
        '当前不报价 4K；若请求 4K 将直接拒绝。图片按腾讯 OG/image2 官方 1K 价格计费，参考图输入 12.5 积分/张。失败不扣费；全员计费（含管理员）。',
    ],
    'text' => [
        'default_model_id' => 'deepseek-v4-flash',
        'models' => [
            'deepseek-v4-flash' => [
                'label' => 'DeepSeek V4 Flash',
                'unit' => 'per_1k_tokens',
                // $0.44/M ×7.2×1.25 /10 = 0.396 → 0.40；$1.32/M ×7.2×1.25 /10 = 1.188 → 1.19
                'input_credits_per_1k' => 0.40,
                'output_credits_per_1k' => 1.19,
                'min_credits' => 0.01,
                'basis' => 'peak cache-miss $0.44/M in + $1.32/M out ×7.2×1.25',
            ],
        ],
        'fallback' => [
            'label' => '默认文本模型',
            'unit' => 'per_1k_tokens',
            'input_credits_per_1k' => 0.40,
            'output_credits_per_1k' => 1.19,
            'min_credits' => 0.01,
            'basis' => 'fallback to deepseek-v4-flash peak rates ×1.25',
        ],
    ],
    'image' => [
        'default_model_id' => 'OG/image2',
        'models' => [
            // 当前唯一在用图片模型；历史日志别名由 CreditService::normalizeImageModelId 归一到此。
            'OG/image2' => [
                'label' => 'Tencent OG image2',
                'unit' => 'per_image',
                'by_quality' => [
                    'low' => 5.63,
                    'medium' => 49.75,
                    'high' => 197.88,
                ],
                'reference_image_credits' => 12.5,
                'default_quality' => 'medium',
                'basis' => 'Tencent official OG/image2 1K rates ×1.25; input image ¥0.10/张 ×1.25',
            ],
        ],
        'fallback' => [
            'label' => '默认图片模型',
            'unit' => 'per_image',
            'by_quality' => [
                'low' => 5.63,
                'medium' => 49.75,
                'high' => 197.88,
            ],
            'reference_image_credits' => 12.5,
            'default_quality' => 'medium',
            'basis' => 'fallback to OG/image2 transitional rates ×1.25',
        ],
    ],
    'video' => [
        'default_model_id' => 'seedance-2',
        'models' => [
            'seedance-2' => [
                'label' => 'Seedance 标准版',
                'unit' => 'per_second',
                'by_resolution' => [
                    '480p' => 57.75,
                    '720p' => 124.25,
                    '1080p' => 309.75,
                ],
                'default_resolution' => '480p',
                'basis' => 'Seedance 标准版输入不含视频：5s 480p ¥2.31 / 720p ¥4.97 / 1080p ¥12.39 ×1.25',
            ],
            'seedance-2-fast' => [
                'label' => 'Seedance 快速版',
                'unit' => 'per_second',
                'by_resolution' => [
                    '480p' => 46.5,
                    '720p' => 100.0,
                ],
                'default_resolution' => '480p',
                'basis' => 'Seedance 快速版输入不含视频：5s 480p ¥1.86 / 720p ¥4.00 ×1.25；无 1080p',
            ],
        ],
        'fallback' => [
            'label' => '默认视频模型',
            'unit' => 'per_second',
            'by_resolution' => [
                '480p' => 57.75,
                '720p' => 124.25,
                '1080p' => 309.75,
            ],
            'default_resolution' => '480p',
            'basis' => 'fallback to Seedance 标准版 no-video-input ×1.25',
        ],
    ],
];
