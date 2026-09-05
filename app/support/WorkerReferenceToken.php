<?php

declare(strict_types=1);

namespace app\support;

final class WorkerReferenceToken
{
    private const TTL_SECONDS = 7200;

    public static function issue(int $userId, string $url, string $name = ''): string
    {
        if ($userId <= 0 || trim($url) === '') {
            throw new \InvalidArgumentException('临时参考图信息不完整');
        }

        $token = bin2hex(random_bytes(24));
        $payload = [
            'user_id' => $userId,
            'url' => trim($url),
            'name' => mb_substr(trim($name), 0, 200),
            'expires_at' => time() + self::TTL_SECONDS,
        ];
        RedisCache::set(self::key($token), $payload, self::TTL_SECONDS);
        if (RedisCache::get(self::key($token), null) === null) {
            throw new \RuntimeException('临时参考图令牌服务不可用，请稍后再试');
        }

        return $token;
    }

    /** @return array{user_id:int,url:string,name:string,expires_at:int} */
    public static function resolve(int $userId, string $token): array
    {
        $token = trim($token);
        $payload = $token !== '' ? RedisCache::get(self::key($token), null) : null;
        if (!is_array($payload)
            || (int) ($payload['user_id'] ?? 0) !== $userId
            || (int) ($payload['expires_at'] ?? 0) < time()
            || trim((string) ($payload['url'] ?? '')) === '') {
            throw new \RuntimeException('临时参考图已失效或不属于当前用户，请重新上传');
        }

        return [
            'user_id' => $userId,
            'url' => trim((string) $payload['url']),
            'name' => trim((string) ($payload['name'] ?? '')),
            'expires_at' => (int) $payload['expires_at'],
        ];
    }

    private static function key(string $token): string
    {
        return 'worker_reference:' . $token;
    }
}
