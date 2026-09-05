<?php

declare(strict_types=1);

namespace app\support;

use app\model\User;
use think\Request;

class LocaleContext
{
    public const DEFAULT_LOCALE = 'zh-CN';
    private const USER_LOCALE_CACHE_TTL = 86400;

    public static function current(?Request $request = null, ?User $user = null): string
    {
        return self::DEFAULT_LOCALE;
    }

    public static function normalize(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = str_replace('_', '-', strtolower($value));
        if (str_contains($value, ',')) {
            $value = trim(explode(',', $value)[0]);
        }
        if (str_contains($value, ';')) {
            $value = trim(explode(';', $value)[0]);
        }

        if (in_array($value, ['zh', 'zh-cn', 'zh-hans', 'zh-hans-cn', 'zh_cn'], true)) {
            return self::DEFAULT_LOCALE;
        }

        return '';
    }

    public static function normalizeForStorage(string $value): string
    {
        return self::normalize($value) ?: self::DEFAULT_LOCALE;
    }

    public static function cachedUserPreferredLocale(int $userId): string
    {
        if ($userId <= 0) {
            return '';
        }

        $key = self::userLocaleCacheKey($userId);
        $cached = self::normalize((string) RedisCache::get($key, ''));
        if ($cached !== '') {
            return $cached;
        }

        try {
            $user = User::where('id', $userId)->find();
        } catch (\Throwable) {
            return '';
        }
        if (!$user instanceof User) {
            return '';
        }

        $locale = self::normalize((string) $user->getAttr('preferred_locale'));
        if ($locale !== '') {
            RedisCache::set($key, $locale, self::USER_LOCALE_CACHE_TTL);
        }

        return $locale;
    }

    public static function putUserPreferredLocale(int $userId, string $locale): void
    {
        $normalized = self::normalize($locale);
        if ($userId <= 0 || $normalized === '') {
            return;
        }

        RedisCache::set(self::userLocaleCacheKey($userId), $normalized, self::USER_LOCALE_CACHE_TTL);
    }

    public static function forgetUserPreferredLocale(int $userId): void
    {
        if ($userId > 0) {
            RedisCache::delete(self::userLocaleCacheKey($userId));
        }
    }

    private static function userPreferredLocale(Request $request, ?User $user): string
    {
        if ($user instanceof User) {
            $locale = self::normalize((string) $user->getAttr('preferred_locale'));
            if ($locale !== '') {
                self::putUserPreferredLocale((int) $user->getAttr('id'), $locale);
                return $locale;
            }
        }

        try {
            $userId = AuthService::userIdFromRequest($request);
        } catch (\Throwable) {
            $userId = null;
        }

        return $userId ? self::cachedUserPreferredLocale($userId) : '';
    }

    private static function userLocaleCacheKey(int $userId): string
    {
        return "user:{$userId}:preferred_locale";
    }
}
