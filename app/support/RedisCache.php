<?php

declare(strict_types=1);

namespace app\support;

use think\facade\Cache;
use think\facade\Log;

class RedisCache
{
    /**
     * 安全读取缓存。
     * Redis 不可用时返回默认值，避免缓存故障影响业务接口。
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            return Cache::get($key, $default);
        } catch (\Throwable $e) {
            self::logFailure('get', $key, $e);
            return $default;
        }
    }

    /**
     * 安全写入缓存。
     * Redis 不可用时静默失败，业务继续走数据库结果。
     */
    public static function set(string $key, mixed $value, int $ttl = 300): void
    {
        try {
            Cache::set($key, $value, $ttl);
        } catch (\Throwable $e) {
            self::logFailure('set', $key, $e);
        }
    }

    /**
     * 安全删除缓存。
     * 用于更新、删除后清理指定缓存键。
     */
    public static function delete(string $key): void
    {
        try {
            Cache::delete($key);
        } catch (\Throwable $e) {
            self::logFailure('delete', $key, $e);
        }
    }

    /**
     * 使用版本号批量失效缓存。
     * 列表类接口把版本号拼进缓存 key，写操作只需要 bump 对应版本。
     */
    public static function bumpVersion(string $name): void
    {
        $key = self::versionKey($name);
        try {
            $version = (int) Cache::inc($key);
            if ($version <= 1) {
                self::set($key, 2, 86400 * 30);
            }
        } catch (\Throwable $e) {
            self::logFailure('inc', $key, $e);
            $version = (int) self::get($key, 1);
            self::set($key, $version + 1, 86400 * 30);
        }
    }

    /**
     * 返回指定缓存域的当前版本号。
     */
    public static function version(string $name): int
    {
        $key = self::versionKey($name);
        $version = (int) self::get($key, 1);
        if ($version <= 0) {
            $version = 1;
            self::set($key, $version, 86400 * 30);
        }

        return $version;
    }

    /**
     * 读取或生成缓存。
     * 常用于列表接口，避免控制器重复写 get/set 逻辑。
     */
    public static function remember(string $key, int $ttl, callable $callback): mixed
    {
        $cached = self::get($key, null);
        if ($cached !== null) {
            return $cached;
        }

        $value = $callback();
        self::set($key, $value, $ttl);

        return $value;
    }

    /**
     * 获取 Redis 原生句柄。
     * 只有需要原子锁这类 Redis 特性时才直接使用。
     */
    public static function handler(): mixed
    {
        try {
            return Cache::store('redis')->handler();
        } catch (\Throwable $e) {
            self::logFailure('handler', 'redis', $e);
            return null;
        }
    }

    /**
     * 尝试获取分布式锁。
     * 成功返回锁 token；失败返回 null。
     */
    public static function acquireLock(string $key, int $ttl = 900): ?string
    {
        $handler = self::handler();
        if (!$handler) {
            return null;
        }

        $token = bin2hex(random_bytes(16));
        try {
            $redisKey = self::redisKey($key);
            if ($handler instanceof \Redis) {
                $ok = $handler->set($redisKey, $token, ['nx', 'ex' => $ttl]);
                return $ok ? $token : null;
            }

            if (method_exists($handler, 'set')) {
                $ok = $handler->set($redisKey, $token, 'EX', $ttl, 'NX');
                return $ok ? $token : null;
            }
        } catch (\Throwable $e) {
            self::logFailure('acquireLock', $key, $e);
        }

        return null;
    }

    /**
     * 判断 Redis 当前是否可用。
     * worker 会用它决定 Redis 锁是否作为强约束参与抢任务。
     */
    public static function isAvailable(): bool
    {
        $handler = self::handler();
        if (!$handler || !method_exists($handler, 'ping')) {
            return false;
        }

        try {
            $result = $handler->ping();
            return $result === true || strtoupper((string) $result) === '+PONG' || strtoupper((string) $result) === 'PONG';
        } catch (\Throwable $e) {
            self::logFailure('ping', 'redis', $e);
            return false;
        }
    }

    /**
     * 释放分布式锁。
     * 只删除 token 匹配的锁，避免误删其他 worker 刚抢到的新锁。
     */
    public static function releaseLock(string $key, string $token): void
    {
        $handler = self::handler();
        if (!$handler) {
            return;
        }

        try {
            $redisKey = self::redisKey($key);
            $script = "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end";
            if ($handler instanceof \Redis) {
                $handler->eval($script, [$redisKey, $token], 1);
                return;
            }

            if (method_exists($handler, 'eval')) {
                $handler->eval($script, 1, $redisKey, $token);
            }
        } catch (\Throwable $e) {
            self::logFailure('releaseLock', $key, $e);
        }
    }

    private static function versionKey(string $name): string
    {
        return 'cache_version:' . $name;
    }

    private static function redisKey(string $key): string
    {
        try {
            return Cache::store('redis')->getCacheKey($key);
        } catch (\Throwable $e) {
            self::logFailure('key', $key, $e);
            return $key;
        }
    }

    private static function logFailure(string $operation, string $key, \Throwable $e): void
    {
        try {
            Log::warning("[RedisCache] {$operation} {$key} failed: " . $e->getMessage());
        } catch (\Throwable) {
        }
    }
}
