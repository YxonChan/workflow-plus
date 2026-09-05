<?php

declare(strict_types=1);

namespace app\support;

final class SupabaseStorage
{
    private const DEFAULT_MAX_BYTES = 50_000_000;

    public static function isEnabledForQuickCreate(): bool
    {
        return strtolower(trim((string) env('QUICK_CREATE_STORAGE_DRIVER', 'local'))) === 'supabase';
    }

    public static function maxBytes(): int
    {
        return max(1, (int) env('SUPABASE_STORAGE_MAX_BYTES', self::DEFAULT_MAX_BYTES));
    }

    public static function uploadLocalFile(
        string $localPath,
        string $prefix,
        ?string $originalName = null,
        array $context = []
    ): string {
        if (!is_file($localPath)) {
            throw new \RuntimeException('Supabase 上传文件不存在');
        }

        $size = filesize($localPath);
        if ($size === false || $size <= 0) {
            throw new \RuntimeException('Supabase 上传文件为空');
        }

        if ($size > self::maxBytes()) {
            throw new \RuntimeException('文件超过 Supabase 免费项目 50MB 单文件限制');
        }

        $extension = self::normalizeExtension(pathinfo($originalName ?: $localPath, PATHINFO_EXTENSION));
        $mime = self::detectMime($localPath, $extension);
        $objectPath = self::buildObjectPath($prefix, $extension, $context);
        self::uploadStream($localPath, $objectPath, $mime, $size);

        return self::publicUrl($objectPath);
    }

    public static function persistRemoteUrl(
        string $url,
        string $prefix,
        array $context = [],
        array $downloadHeaders = []
    ): string
    {
        $url = trim($url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $maxBytes = self::maxBytes();
        $maxAttempts = max(1, min(5, (int) env('MEDIA_REMOTE_DOWNLOAD_ATTEMPTS', 3)));
        $lastDetail = '未知错误';
        $contentType = '';
        $temporaryPath = '';

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            if ($attempt > 0) {
                usleep((int) min(2_000_000, 250_000 * (2 ** ($attempt - 1))));
            }

            $temporaryPath = tempnam(sys_get_temp_dir(), 'malulu-supabase-');
            if ($temporaryPath === false) {
                throw new \RuntimeException('无法创建 Supabase 临时文件');
            }

            $stream = fopen($temporaryPath, 'wb');
            if ($stream === false) {
                @unlink($temporaryPath);
                throw new \RuntimeException('无法写入 Supabase 临时文件');
            }

            $downloadedBytes = 0;
            $writeFailed = false;
            $ch = curl_init($url);
            if ($ch === false) {
                fclose($stream);
                @unlink($temporaryPath);
                $lastDetail = 'curl_init 失败';
                continue;
            }

            curl_setopt_array($ch, [
                // 携带 Bearer Token 时不可跟随跨域重定向，避免凭据泄露。
                CURLOPT_FOLLOWLOCATION => $downloadHeaders === [],
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_TIMEOUT => 300,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$downloadedBytes, &$writeFailed, $stream, $maxBytes): int {
                    $length = strlen($chunk);
                    if ($downloadedBytes + $length > $maxBytes) {
                        $writeFailed = true;

                        return 0;
                    }
                    $written = fwrite($stream, $chunk);
                    if ($written === false || $written !== $length) {
                        $writeFailed = true;

                        return 0;
                    }
                    $downloadedBytes += $written;

                    return $written;
                },
            ]);
            if ($downloadHeaders !== []) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $downloadHeaders);
            }

            curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $contentType = strtolower(trim((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE)));
            $error = trim((string) curl_error($ch));
            $errno = (int) curl_errno($ch);
            curl_close($ch);
            fclose($stream);

            if (!$writeFailed && $errno === 0 && $status >= 200 && $status < 300 && $downloadedBytes > 0) {
                if ($downloadedBytes > $maxBytes) {
                    @unlink($temporaryPath);
                    throw new \RuntimeException('生成视频超过 Supabase 免费项目 50MB 单文件限制');
                }
                try {
                    $extension = self::extensionFromRemote($url, $contentType);

                    return self::uploadLocalFile($temporaryPath, $prefix, 'generated.' . $extension, $context);
                } finally {
                    @unlink($temporaryPath);
                }
            }

            @unlink($temporaryPath);
            if ($writeFailed && $downloadedBytes + 1 > $maxBytes) {
                throw new \RuntimeException('生成视频超过 Supabase 免费项目 50MB 单文件限制');
            }

            $parts = [];
            $parts[] = $status > 0 ? 'HTTP ' . $status : 'HTTP 0';
            if ($errno !== 0) {
                $parts[] = 'curl ' . $errno . ($error !== '' ? ' ' . $error : '');
            } elseif ($error !== '') {
                $parts[] = $error;
            }
            if ($downloadedBytes <= 0) {
                $parts[] = '空响应';
            }
            $lastDetail = mb_substr(implode('；', $parts), 0, 300);

            $retryable = $errno !== 0
                || $status === 0
                || in_array($status, [403, 404, 408, 425, 429, 500, 502, 503, 504], true);
            if (!$retryable || $attempt >= $maxAttempts - 1) {
                break;
            }
        }

        throw new \RuntimeException('远端视频下载失败：' . $lastDetail);
    }

    public static function publicUrl(string $objectPath): string
    {
        [$baseUrl, , $bucket] = self::configuration(false);

        return $baseUrl . '/storage/v1/object/public/' . rawurlencode($bucket) . '/' . self::encodePath($objectPath);
    }

    private static function uploadStream(string $localPath, string $objectPath, string $mime, int $size): void
    {
        [$baseUrl, $serviceKey, $bucket] = self::configuration(true);
        $endpoint = $baseUrl . '/storage/v1/object/' . rawurlencode($bucket) . '/' . self::encodePath($objectPath);
        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('无法读取 Supabase 上传文件');
        }

        $ch = curl_init($endpoint);
        if ($ch === false) {
            fclose($stream);
            throw new \RuntimeException('无法初始化 Supabase 上传请求');
        }

        $headers = [
            'apikey: ' . $serviceKey,
            'Content-Type: ' . $mime,
            'Content-Length: ' . $size,
            'x-upsert: false',
            'Expect:',
        ];
        if (!str_starts_with($serviceKey, 'sb_secret_')) {
            $headers[] = 'Authorization: Bearer ' . $serviceKey;
        }

        curl_setopt_array($ch, [
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILE => $stream,
            CURLOPT_INFILESIZE => $size,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($stream);

        if (!is_string($body) || $status < 200 || $status >= 300) {
            $detail = $error !== '' ? $error : trim((string) $body);
            throw new \RuntimeException('Supabase 上传失败：' . mb_substr($detail !== '' ? $detail : 'HTTP ' . $status, 0, 500));
        }
    }

    /** @return array{0:string,1:string,2:string} */
    private static function configuration(bool $requireServiceKey): array
    {
        $baseUrl = rtrim(trim((string) env('SUPABASE_URL', '')), '/');
        $serviceKey = trim((string) env('SUPABASE_SERVICE_KEY', ''));
        $bucket = trim((string) env('SUPABASE_STORAGE_BUCKET', ''));

        if ($baseUrl === '' || preg_match('#^https?://[^/]+$#i', $baseUrl) !== 1) {
            throw new \RuntimeException('SUPABASE_URL 未配置或格式无效');
        }
        if ($bucket === '' || preg_match('/^[A-Za-z0-9._-]+$/', $bucket) !== 1) {
            throw new \RuntimeException('SUPABASE_STORAGE_BUCKET 未配置或格式无效');
        }
        if ($requireServiceKey && $serviceKey === '') {
            throw new \RuntimeException('SUPABASE_SERVICE_KEY 未配置');
        }

        return [$baseUrl, $serviceKey, $bucket];
    }

    private static function buildObjectPath(string $prefix, string $extension, array $context): string
    {
        $segments = [];
        foreach (explode('/', trim($prefix, '/')) as $segment) {
            $safe = self::safeSegment($segment);
            if ($safe !== '') {
                $segments[] = $safe;
            }
        }

        $segments[] = 'u' . max(0, (int) ($context['user_id'] ?? 0));
        $segments[] = date('Ymd');
        $source = self::safeSegment((string) ($context['source'] ?? 'quick-create-reference'));
        $segments[] = ($source !== '' ? $source : 'quick-create-reference') . '-' . bin2hex(random_bytes(12)) . '.' . $extension;

        return implode('/', $segments);
    }

    private static function safeSegment(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9._-]+/', '-', $value) ?? '';

        return trim(substr($value, 0, 64), '.-_');
    }

    private static function normalizeExtension(string $extension): string
    {
        $extension = strtolower(trim($extension, ". \t\n\r\0\x0B"));

        return match ($extension) {
            'jpg', 'jpeg' => 'jpg',
            'png' => 'png',
            'gif' => 'gif',
            'webp' => 'webp',
            'mp4' => 'mp4',
            'mov' => 'mov',
            default => throw new \RuntimeException('Supabase 仅支持速创允许的图片或视频格式'),
        };
    }

    private static function extensionFromRemote(string $url, string $contentType): string
    {
        $contentType = strtolower(trim(explode(';', $contentType, 2)[0]));
        $fromMime = match ($contentType) {
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => '',
        };
        if ($fromMime !== '') {
            return $fromMime;
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return self::normalizeExtension($extension);
    }

    private static function detectMime(string $localPath, string $extension): string
    {
        $detected = function_exists('mime_content_type') ? mime_content_type($localPath) : false;
        if (is_string($detected) && (str_starts_with($detected, 'image/') || str_starts_with($detected, 'video/'))) {
            return strtolower($detected);
        }

        return match ($extension) {
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'mp4' => 'video/mp4',
            'mov' => 'video/quicktime',
        };
    }

    private static function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', trim($path, '/'))));
    }
}
