<?php

// +----------------------------------------------------------------------
// | 缓存设置
// +----------------------------------------------------------------------

return [
    // 默认缓存驱动
    'default' => env('CACHE_DRIVER', 'redis'),

    // 缓存连接方式配置
    'stores' => [
        'file' => [
            // 驱动方式
            'type' => 'File',
            // 缓存保存目录
            'path' => '',
            // 缓存前缀
            'prefix' => '',
            // 缓存有效期 0表示永久缓存
            'expire' => 0,
            // 缓存标签前缀
            'tag_prefix' => 'tag:',
            // 序列化机制 例如 ['serialize', 'unserialize']
            'serialize' => [],
        ],
        'redis' => [
            // 驱动方式
            'type' => 'Redis',
            // Redis 服务器地址，Docker 环境默认使用 docker-compose 中的 redis 服务名
            'host' => env('REDIS_HOST', 'redis'),
            // Redis 端口
            'port' => (int) env('REDIS_PORT', 6379),
            // Redis 密码，未设置时留空
            'password' => env('REDIS_PASSWORD', ''),
            // Redis 数据库编号
            'select' => (int) env('REDIS_SELECT', 0),
            // 连接超时时间
            'timeout' => (float) env('REDIS_TIMEOUT', 2),
            // 默认缓存有效期，业务侧会按场景传入更短 TTL
            'expire' => (int) env('CACHE_EXPIRE', 0),
            // 是否使用长连接
            'persistent' => false,
            // 缓存前缀，避免和其他项目共用 Redis 时冲突
            'prefix' => env('CACHE_PREFIX', 'malulu:'),
            // 缓存标签前缀
            'tag_prefix' => 'tag:',
            // 序列化机制
            'serialize' => [],
        ],
    ],
];
