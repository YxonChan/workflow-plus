<?php

declare(strict_types=1);

/**
 * 平台积分物价表（只读，第一期不支持在线修改）。
 *
 * 规则：
 * - 1 积分 = ¥0.01
 * - 平台价 = 供应商成本 × 1.25（后台 markup，可调整）
 * - USD 按 7.2 CNY/USD
 *
 * 来源：
 * - DeepSeek V4 Flash peak：api-docs.deepseek.com（cache miss $0.44/M，output $1.32/M）
 * - GPT Image 2.5：ToAPIs 普通版按分辨率；Official 按最终结算金额
 * - Seedance 标准版：ToAPIs seedance-2.0「输入不含视频」价目表（16:9 5 秒）×1.25
 */
return [
    'enabled' => true,
    'pricing_version' => '2026-10-16.toapis-2.5',
    'updated_at' => '2026-10-16',
    'credit_unit_cny' => 0.01,
    'usd_cny' => 7.2,
    'markup' => 1.25,
    'notes' => [
        '平台价 = 供应商成本上浮 25%（×1.25）；1 积分 = ¥0.01。',
        '视频按 Seedance 标准版「输入不含视频」价目：480P ¥0.462、720P ¥0.994、1080P ¥2.478 / 秒 ×1.25 → 57.75 / 124.25 / 309.75 积分/秒。当前按输出秒数预估，不以实际 token 事后调账。',
        'GPT Image 2.5 普通版按分辨率计费：1K 13.5、2K 18、4K 22.5 积分/张；参考图不另收费。',
        'GPT Image 2.5 Official 按 ToAPIs 最终结算美元金额换算积分；生成前为估算，未结算不按零费用处理。失败不扣费。',
        'Official 实际费用可能超过预估，差额计入余额；余额不足时需充值后继续生成。',
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
        'default_model_id' => 'gpt-image-2.5-flare',
        'models' => [
            'gpt-image-2.5-flare' => [
                'label' => 'GPT Image 2.5 Flare',
                'unit' => 'per_image',
                'by_resolution_usd' => ['1K' => 0.015, '2K' => 0.020, '4K' => 0.025],
                'reference_image_credits' => 0.0,
                'default_quality' => 'high',
                'basis' => '按分辨率每张计费，参考图不另收费',
            ],
            'gpt-image-2.5-sunburst' => [
                'label' => 'GPT Image 2.5 Sunburst',
                'unit' => 'per_image',
                'by_resolution_usd' => ['1K' => 0.015, '2K' => 0.020, '4K' => 0.025],
                'reference_image_credits' => 0.0,
                'default_quality' => 'high',
                'basis' => '按分辨率每张计费，参考图不另收费',
            ],
            'gpt-image-2.5-flare-official' => [
                'label' => 'GPT Image 2.5 Flare Official', 'unit' => 'actual_usage',
                'token_usd_per_million' => ['text_input' => 4, 'cached_text_input' => 1, 'image_input' => 6.4, 'cached_image_input' => 1.6, 'image_output' => 24],
                'reference_image_credits' => 0, 'default_quality' => 'high',
                'basis' => '按最终结算用量计费，预估价随画质和像素尺寸变化',
            ],
            'gpt-image-2.5-sunburst-official' => [
                'label' => 'GPT Image 2.5 Sunburst Official', 'unit' => 'actual_usage',
                'token_usd_per_million' => ['text_input' => 4, 'cached_text_input' => 1, 'image_input' => 6.4, 'cached_image_input' => 1.6, 'image_output' => 24],
                'reference_image_credits' => 0, 'default_quality' => 'high',
                'basis' => '按最终结算用量计费，预估价随画质和像素尺寸变化',
            ],
            // 保留历史日志的计费规则；新请求不会再选用腾讯模型。
            'OG/image2' => [
                'label' => 'Legacy Tencent OG image2',
                'unit' => 'per_image',
                'by_quality' => [
                    'low' => 5.63,
                    'medium' => 49.75,
                    'high' => 197.88,
                ],
                'reference_image_credits' => 12.5,
                'default_quality' => 'medium',
                'basis' => 'legacy Tencent OG/image2 rate',
            ],
        ],
        'fallback' => [
            'label' => '默认图片模型',
            'unit' => 'per_image',
            'by_quality' => [
                'standard' => 50.0,
            ],
            'reference_image_credits' => 0.0,
            'default_quality' => 'standard',
            'basis' => 'fallback to GPT Image 2.5 rates',
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
