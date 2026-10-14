<?php

return [
    // WeChat Pay v3 Native payment configuration. Keep credentials in .env.
    'wechat' => [
        'enabled' => (bool) env('WECHAT_PAY_ENABLED', false),
        'app_id' => (string) env('WECHAT_PAY_APP_ID', ''),
        'merchant_id' => (string) env('WECHAT_PAY_MERCHANT_ID', ''),
        'merchant_serial_no' => (string) env('WECHAT_PAY_MERCHANT_SERIAL_NO', ''),
        'api_v3_key' => (string) env('WECHAT_PAY_API_V3_KEY', ''),
        'merchant_private_key_path' => (string) env('WECHAT_PAY_MERCHANT_PRIVATE_KEY_PATH', ''),
        'platform_public_key_id' => (string) env('WECHAT_PAY_PLATFORM_PUBLIC_KEY_ID', ''),
        'platform_public_key_path' => (string) env('WECHAT_PAY_PLATFORM_PUBLIC_KEY_PATH', ''),
        'platform_certificate_path' => (string) env('WECHAT_PAY_PLATFORM_CERTIFICATE_PATH', ''),
        'notify_url' => (string) env('WECHAT_PAY_NOTIFY_URL', ''),
        'description_prefix' => (string) env('WECHAT_PAY_DESCRIPTION_PREFIX', 'AI 工作流积分'),
    ],
    // 1 credit = CNY 0.01. Package ids are intentionally server-defined.
    'packages' => [
        'credits_10' => ['amount_cny' => 10.00, 'credits' => 1000.00, 'name' => '10 元积分包'],
        'credits_100' => ['amount_cny' => 100.00, 'credits' => 10000.00, 'name' => '100 元积分包'],
        'credits_500' => ['amount_cny' => 500.00, 'credits' => 50000.00, 'name' => '500 元积分包'],
    ],
];
