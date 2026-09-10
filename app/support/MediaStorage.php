<?php

declare(strict_types=1);

namespace app\support;

use think\facade\Db;

class MediaStorage
{
    public static function uploadImageBinary(string $binary, string $extension = 'png', string $prefix = 'generated', string $mime = '', array $context = []): string
    {
        if ($binary === '') {
            throw new \RuntimeException('图片内容为空，无法保存');
        }

        $extension = self::normalizeExtension($extension);
        $mime = $mime !== '' ? $mime : self::mimeFromExtension($extension);

        return self::putBinary($binary, $extension, $prefix, $mime, $context);
    }

    public static function uploadBinaryFile(string $localPath, string $extension, string $prefix = 'generated', string $mime = '', array $context = []): string
    {
        if (!is_file($localPath)) {
            throw new \RuntimeException('上传文件不存在');
        }

        $binary = file_get_contents($localPath);
        if ($binary === false || $binary === '') {
            throw new \RuntimeException('读取上传文件失败');
        }

        $extension = self::normalizeExtension($extension);
        $mime = $mime !== '' ? $mime : self::mimeFromExtension($extension);

        return self::putBinary($binary, $extension, $prefix, $mime, $context);
    }

    public static function uploadLocalImage(string $localPath, string $prefix = 'uploads', ?string $originalName = null, array $context = []): string
    {
        if (!is_file($localPath)) {
            throw new \RuntimeException('上传文件不存在');
        }

        $binary = file_get_contents($localPath);
        if ($binary === false || $binary === '') {
            throw new \RuntimeException('读取上传文件失败');
        }

        $extension = self::normalizeExtension(pathinfo($originalName ?: $localPath, PATHINFO_EXTENSION) ?: 'png');
        $mime = self::detectMime($localPath, $extension);
        $context['original_name'] = $context['original_name'] ?? $originalName;

        return self::putBinary($binary, $extension, $prefix, $mime, $context);
    }

    public static function uploadLocalFile(string $localPath, string $prefix = 'uploads', ?string $originalName = null, array $context = []): string
    {
        if (!is_file($localPath) || filesize($localPath) <= 0) {
            throw new \RuntimeException('上传文件不存在或内容为空');
        }

        $extension = self::normalizeExtension(pathinfo($originalName ?: $localPath, PATHINFO_EXTENSION));
        $mime = self::detectMime($localPath, $extension);
        $context['original_name'] = $context['original_name'] ?? $originalName;
        $relativePath = self::buildRelativePath($prefix, $extension, $context);
        $absolutePath = self::publicRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $dir = dirname($absolutePath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('创建媒体目录失败');
        }
        if (!copy($localPath, $absolutePath) || !is_file($absolutePath) || filesize($absolutePath) <= 0) {
            throw new \RuntimeException('保存上传文件失败');
        }
        self::ensurePublicReadable($absolutePath);

        return self::publicUrl($relativePath);
    }

    /**
     * 把本地 /storage/... 路径拼成上游可拉取的公网 HTTP(S) URL。
     * 已是 http(s) 的原样返回；拼不出公网地址时返回空字符串。
     */
    public static function toPublicHttpUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?: $url);
        $path = '/' . ltrim(str_replace('\\', '/', $path), '/');
        if (!str_starts_with($path, '/storage/')) {
            return '';
        }

        $baseUrl = rtrim(trim((string) env('MEDIA_PUBLIC_BASE_URL', '')), '/');
        if ($baseUrl === '') {
            $baseUrl = rtrim(trim((string) env('APP_URL', '')), '/');
        }
        if ($baseUrl === '' || !preg_match('#^https?://#i', $baseUrl)) {
            return '';
        }

        return $baseUrl . $path;
    }

    public static function persistRemoteUrl(
        string $url,
        string $prefix = 'generated',
        array $context = [],
        array $downloadHeaders = []
    ): string
    {
        $url = trim($url);
        if ($url === '' || self::isLocalUrl($url)) {
            return $url;
        }
        if (!preg_match('#^https?://#i', $url)) {
            return $url;
        }

        // 成片/大图走流式落盘，避免整包进内存；临时签名 URL 偶发 403/5xx 时自动短重试。
        [$tempPath, $mime] = self::downloadRemoteToTemp($url, $context, $downloadHeaders);
        try {
            $extension = self::extensionFromMimeOrUrl($mime, $url, $context);
            $context['source_url'] = $url;

            return self::putLocalFile(
                $tempPath,
                $extension,
                $prefix,
                $mime !== '' ? $mime : self::mimeFromExtension($extension),
                $context
            );
        } finally {
            if (is_string($tempPath) && $tempPath !== '' && is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    private static function putBinary(string $binary, string $extension, string $prefix, string $mime, array $context): string
    {
        $relativePath = self::buildRelativePath($prefix, $extension, $context);
        $absolutePath = self::publicRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $dir = dirname($absolutePath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('创建媒体目录失败');
        }

        if (file_put_contents($absolutePath, $binary, LOCK_EX) === false) {
            throw new \RuntimeException('保存媒体文件失败');
        }
        if (!is_file($absolutePath) || filesize($absolutePath) <= 0) {
            throw new \RuntimeException('保存媒体文件校验失败');
        }
        self::ensurePublicReadable($absolutePath);

        return self::publicUrl($relativePath);
    }

    private static function putLocalFile(string $localPath, string $extension, string $prefix, string $mime, array $context): string
    {
        if (!is_file($localPath) || filesize($localPath) <= 0) {
            throw new \RuntimeException('下载结果为空，无法保存到本地服务器');
        }

        $relativePath = self::buildRelativePath($prefix, $extension, $context);
        $absolutePath = self::publicRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $dir = dirname($absolutePath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('创建媒体目录失败');
        }

        if (!@rename($localPath, $absolutePath)) {
            if (!@copy($localPath, $absolutePath) || !is_file($absolutePath) || filesize($absolutePath) <= 0) {
                throw new \RuntimeException('保存媒体文件失败');
            }
            @unlink($localPath);
        }
        if (!is_file($absolutePath) || filesize($absolutePath) <= 0) {
            throw new \RuntimeException('保存媒体文件校验失败');
        }
        self::ensurePublicReadable($absolutePath);

        return self::publicUrl($relativePath);
    }

    /** 验证内部 /storage URL 对应的文件已真实落盘且非空。 */
    public static function isStoredMediaAvailable(string $url): bool
    {
        $path = (string) (parse_url(trim($url), PHP_URL_PATH) ?: trim($url));
        if (!str_starts_with($path, '/storage/')) {
            return false;
        }
        $relative = rawurldecode(ltrim($path, '/'));
        $absolute = self::publicRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, substr($relative, strlen('storage/')));
        return is_file($absolute) && filesize($absolute) > 0 && is_readable($absolute);
    }

    private static function buildRelativePath(string $prefix, string $extension, array $context): string
    {
        $context = self::hydrateContext($context);
        $segments = [];
        foreach (explode('/', trim($prefix, "/ \t\n\r\0\x0B")) as $segment) {
            $segment = self::safeSegment($segment);
            if ($segment !== '') {
                $segments[] = $segment;
            }
        }

        if (isset($context['user_id']) || isset($context['username'])) {
            $segments[] = self::idNameSegment('u', (int) ($context['user_id'] ?? 0), (string) ($context['username'] ?? $context['display_name'] ?? 'user'));
        }
        if (isset($context['series_id']) || isset($context['series_title'])) {
            $segments[] = self::idNameSegment('s', (int) ($context['series_id'] ?? 0), (string) ($context['series_title'] ?? 'series'));
        }
        if (isset($context['episode_id']) || isset($context['episode_title']) || isset($context['episode_number'])) {
            $episodeLabel = (string) ($context['episode_title'] ?? 'episode');
            $episodeNumber = (int) ($context['episode_number'] ?? 0);
            $prefix = $episodeNumber > 0 ? 'e' . str_pad((string) $episodeNumber, 2, '0', STR_PAD_LEFT) : 'e';
            $segments[] = self::idNameSegment($prefix, (int) ($context['episode_id'] ?? 0), $episodeLabel);
        }

        $segments[] = date('Ymd');
        $segments[] = self::buildFilename($extension, $context);

        return implode('/', array_values(array_filter($segments, static fn (string $value): bool => $value !== '')));
    }

    private static function buildFilename(string $extension, array $context): string
    {
        $parts = [];
        $source = self::safeSegment((string) ($context['source'] ?? 'media'));
        if ($source !== '') {
            $parts[] = $source;
        }
        if ((int) ($context['asset_id'] ?? 0) > 0) {
            $assetName = self::safeSegment((string) ($context['asset_name'] ?? 'asset'));
            $parts[] = 'asset' . (int) $context['asset_id'] . ($assetName !== '' ? '_' . $assetName : '');
        }
        $viewType = self::safeSegment((string) ($context['view_type'] ?? ''));
        if ($viewType !== '') {
            $parts[] = $viewType;
        }
        if ((int) ($context['shot_index'] ?? 0) > 0) {
            $parts[] = 'shot' . str_pad((string) (int) $context['shot_index'], 2, '0', STR_PAD_LEFT);
        }
        $nodeLabel = self::safeSegment((string) ($context['node_label'] ?? ''));
        if ($nodeLabel !== '') {
            $parts[] = $nodeLabel;
        }
        if ((int) ($context['job_id'] ?? 0) > 0) {
            $parts[] = 'job' . (int) $context['job_id'];
        }
        if ((int) ($context['video_job_id'] ?? 0) > 0) {
            $parts[] = 'videojob' . (int) $context['video_job_id'];
        }
        if ($parts === []) {
            $originalName = self::safeSegment(pathinfo((string) ($context['original_name'] ?? ''), PATHINFO_FILENAME));
            $parts[] = $originalName !== '' ? $originalName : 'media';
        }

        $parts[] = bin2hex(random_bytes(4));

        return implode('-', array_slice($parts, 0, 8)) . '.' . $extension;
    }

    private static function hydrateContext(array $context): array
    {
        $assetId = (int) ($context['asset_id'] ?? 0);
        if ($assetId > 0 && empty($context['asset_name'])) {
            $asset = Db::name('assets')->where('id', $assetId)->find();
            if (is_array($asset)) {
                $context['asset_name'] = $asset['name'] ?? '';
                $context['asset_type'] = $asset['type'] ?? '';
                $context['series_id'] = (int) ($context['series_id'] ?? 0) ?: (int) ($asset['series_id'] ?? 0);
                $context['user_id'] = (int) ($context['user_id'] ?? 0) ?: (int) ($asset['user_id'] ?? 0);
            }
        }

        $episodeId = (int) ($context['episode_id'] ?? 0);
        if ($episodeId > 0 && (empty($context['episode_title']) || empty($context['series_id']))) {
            $episode = Db::name('episodes')->where('id', $episodeId)->find();
            if (is_array($episode)) {
                $context['episode_title'] = $context['episode_title'] ?? ($episode['title'] ?? '');
                $context['episode_number'] = (int) ($context['episode_number'] ?? 0) ?: (int) ($episode['number'] ?? 0);
                $context['series_id'] = (int) ($context['series_id'] ?? 0) ?: (int) ($episode['series_id'] ?? 0);
                $context['user_id'] = (int) ($context['user_id'] ?? 0) ?: (int) ($episode['user_id'] ?? 0);
            }
        }

        $seriesId = (int) ($context['series_id'] ?? 0);
        if ($seriesId > 0 && empty($context['series_title'])) {
            $series = Db::name('series')->where('id', $seriesId)->find();
            if (is_array($series)) {
                $context['series_title'] = $series['title'] ?? '';
                $context['user_id'] = (int) ($context['user_id'] ?? 0) ?: (int) ($series['user_id'] ?? 0);
            }
        }

        $userId = (int) ($context['user_id'] ?? 0);
        if ($userId > 0 && empty($context['username'])) {
            $user = Db::name('users')->where('id', $userId)->find();
            if (is_array($user)) {
                $context['username'] = (string) ($user['username'] ?? '');
                $context['display_name'] = (string) ($user['display_name'] ?? '');
            }
        }

        return $context;
    }

    private static function idNameSegment(string $prefix, int $id, string $name): string
    {
        $name = self::safeSegment($name);
        $idPart = $id > 0 ? (string) $id : '0';
        return self::safeSegment($prefix . $idPart . ($name !== '' ? '_' . $name : ''));
    }

    private static function safeSegment(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $value = preg_replace('#[\\\\/:*?"<>|\x00-\x1F]+#u', '_', $value) ?? '';
        $value = preg_replace('#\s+#u', '_', $value) ?? $value;
        $value = trim($value, "._- \t\n\r\0\x0B");
        if (mb_strlen($value) > 64) {
            $value = mb_substr($value, 0, 64);
        }

        return $value !== '' ? $value : 'untitled';
    }

    private static function publicRoot(): string
    {
        return rtrim(app()->getRootPath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'storage';
    }


    private static function ensurePublicReadable(string $absolutePath): void
    {
        @chmod($absolutePath, 0664);
        $dir = dirname($absolutePath);
        for ($i = 0; $i < 8; $i++) {
            if ($dir === '' || $dir === '/' || !str_contains($dir, DIRECTORY_SEPARATOR . 'storage')) {
                break;
            }
            @chmod($dir, 0775);
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }
    }

    private static function publicUrl(string $relativePath): string
    {
        $path = '/storage/' . self::encodePath($relativePath);
        $baseUrl = rtrim(trim((string) env('MEDIA_PUBLIC_BASE_URL', '')), '/');
        return $baseUrl !== '' ? $baseUrl . $path : $path;
    }

    private static function isLocalUrl(string $url): bool
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
        if (str_starts_with($url, '/storage/') || str_starts_with($path, '/storage/')) {
            return true;
        }
        $baseUrl = rtrim(trim((string) env('MEDIA_PUBLIC_BASE_URL', '')), '/');
        if ($baseUrl !== '' && str_starts_with($url, $baseUrl . '/storage/')) {
            return true;
        }

        return false;
    }

    /**
     * 流式下载远端媒体到临时文件。
     *
     * @return array{0:string,1:string} [tempPath, mime]
     */
    private static function downloadRemoteToTemp(string $url, array $context, array $downloadHeaders = []): array
    {
        $maxBytes = max(1, (int) env('MEDIA_REMOTE_MAX_BYTES', 300 * 1024 * 1024));
        // 火山 TOS 等临时签名 URL：成片刚就绪时偶发 403/连接重置；最多 3 次，短退避。
        $maxAttempts = max(1, min(5, (int) env('MEDIA_REMOTE_DOWNLOAD_ATTEMPTS', 3)));
        $lastDetail = '未知错误';

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            if ($attempt > 0) {
                // 250ms → 1s → 2s …
                $delayUs = (int) min(2_000_000, 250_000 * (2 ** ($attempt - 1)));
                usleep($delayUs);
            }

            $tempPath = tempnam(sys_get_temp_dir(), 'malulu-media-');
            if ($tempPath === false) {
                throw new \RuntimeException('无法创建媒体下载临时文件');
            }
            $stream = fopen($tempPath, 'wb');
            if ($stream === false) {
                @unlink($tempPath);
                throw new \RuntimeException('无法写入媒体下载临时文件');
            }

            $downloadedBytes = 0;
            $writeFailed = false;
            $ch = curl_init($url);
            if ($ch === false) {
                fclose($stream);
                @unlink($tempPath);
                $lastDetail = 'curl_init 失败';
                continue;
            }

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => false,
                // 私有成片下载会携带 Bearer Token，禁止跨域重定向时继续转发凭据。
                CURLOPT_FOLLOWLOCATION => $downloadHeaders === [],
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => 300,
                CURLOPT_CONNECTTIMEOUT => 20,
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
            $mime = strtolower(trim((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE)));
            $curlErrno = (int) curl_errno($ch);
            $curlError = trim((string) curl_error($ch));
            curl_close($ch);
            fclose($stream);

            $mime = explode(';', $mime)[0] ?? '';
            $fileSize = is_file($tempPath) ? (int) filesize($tempPath) : 0;

            if (!$writeFailed && $curlErrno === 0 && $status >= 200 && $status < 300 && $fileSize > 0) {
                if ($fileSize > $maxBytes) {
                    @unlink($tempPath);
                    throw new \RuntimeException('远端媒体超过允许大小');
                }
                $head = (string) (file_get_contents($tempPath, false, null, 0, 64) ?: '');
                if (!self::isAllowedRemoteMedia($head, $mime, $url, $context)) {
                    @unlink($tempPath);
                    $source = (string) ($context['source'] ?? 'media');
                    throw new \RuntimeException("远端 {$source} 返回的类型不是图片或视频：" . ($mime !== '' ? $mime : 'unknown'));
                }

                return [$tempPath, $mime];
            }

            @unlink($tempPath);

            if ($writeFailed && $downloadedBytes + 1 > $maxBytes) {
                throw new \RuntimeException('远端媒体超过允许大小');
            }

            $lastDetail = self::formatDownloadFailureDetail($status, $curlErrno, $curlError, $fileSize);
            if (!self::shouldRetryRemoteDownload($attempt, $maxAttempts, $status, $curlErrno)) {
                break;
            }
        }

        throw new \RuntimeException('下载远端媒体失败，无法保存到本地服务器（' . $lastDetail . '）');
    }

    private static function shouldRetryRemoteDownload(int $attempt, int $maxAttempts, int $status, int $curlErrno): bool
    {
        if ($attempt >= $maxAttempts - 1) {
            return false;
        }
        if ($curlErrno !== 0 || $status === 0) {
            return true;
        }
        // 对象存储成片刚发布时偶发 403/404；网关限流/抖动 408/429/5xx。
        return in_array($status, [403, 404, 408, 425, 429, 500, 502, 503, 504], true);
    }

    private static function formatDownloadFailureDetail(int $status, int $curlErrno, string $curlError, int $fileSize): string
    {
        $parts = [];
        if ($status > 0) {
            $parts[] = 'HTTP ' . $status;
        } else {
            $parts[] = 'HTTP 0';
        }
        if ($curlErrno !== 0) {
            $parts[] = 'curl ' . $curlErrno . ($curlError !== '' ? ' ' . $curlError : '');
        } elseif ($curlError !== '') {
            $parts[] = $curlError;
        }
        if ($fileSize <= 0) {
            $parts[] = '空响应';
        }

        return mb_substr(implode('；', $parts), 0, 300);
    }

    private static function isAllowedRemoteMedia(string $body, string $mime, string $url, array $context): bool
    {
        if ($mime === '' || str_starts_with($mime, 'image/') || str_starts_with($mime, 'video/')) {
            return true;
        }

        // 部分对象存储将视频标为 application/octet-stream；同时校验扩展名或常见媒体文件头。
        if (!in_array($mime, ['application/octet-stream', 'binary/octet-stream', 'application/mp4'], true)) {
            return false;
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($extension, ['mp4', 'mov', 'webm', 'm4v', 'avi', 'png', 'jpg', 'jpeg', 'webp', 'gif'], true)) {
            return true;
        }

        $prefix = substr($body, 0, 16);
        return str_starts_with($prefix, "\x89PNG")
            || str_starts_with($prefix, "\xFF\xD8\xFF")
            || str_starts_with($prefix, 'GIF8')
            || (strlen($body) >= 12 && substr($body, 0, 4) === 'RIFF' && substr($body, 8, 4) === 'WEBP')
            || (strlen($body) >= 8 && substr($body, 4, 4) === 'ftyp')
            || str_starts_with($prefix, "\x1A\x45\xDF\xA3");
    }

    private static function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
    }

    private static function normalizeExtension(string $extension): string
    {
        $extension = strtolower(trim($extension, ". \t\n\r\0\x0B"));

        return match ($extension) {
            'jpg', 'jpeg' => 'jpg',
            'webp' => 'webp',
            'gif' => 'gif',
            'mp4' => 'mp4',
            'mov' => 'mov',
            'webm' => 'webm',
            'wav', 'wave' => 'wav',
            'mp3' => 'mp3',
            'm4a' => 'm4a',
            'aac' => 'aac',
            default => 'png',
        };
    }

    private static function extensionFromMimeOrUrl(string $mime, string $url, array $context = []): string
    {
        $mime = strtolower(explode(';', $mime)[0] ?? '');
        if (str_contains($mime, 'jpeg') || str_contains($mime, 'jpg')) {
            return 'jpg';
        }
        if (str_contains($mime, 'webp')) {
            return 'webp';
        }
        if (str_contains($mime, 'gif')) {
            return 'gif';
        }
        if (str_contains($mime, 'webm')) {
            return 'webm';
        }
        if (str_contains($mime, 'mp4')) {
            return 'mp4';
        }

        $extension = pathinfo((string) (parse_url($url, PHP_URL_PATH) ?: ''), PATHINFO_EXTENSION);
        if ($extension !== '') {
            return self::normalizeExtension($extension);
        }

        $source = strtolower((string) ($context['source'] ?? ''));
        return str_contains($source, 'video') ? 'mp4' : 'png';
    }

    private static function detectMime(string $path, string $extension): string
    {
        $mime = function_exists('mime_content_type') ? mime_content_type($path) : false;
        if (is_string($mime) && (str_starts_with($mime, 'image/') || str_starts_with($mime, 'video/') || str_starts_with($mime, 'audio/'))) {
            return $mime;
        }

        return self::mimeFromExtension($extension);
    }

    private static function mimeFromExtension(string $extension): string
    {
        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'mp4' => 'video/mp4',
            'mov' => 'video/quicktime',
            'webm' => 'video/webm',
            'wav' => 'audio/wav',
            'mp3' => 'audio/mpeg',
            'm4a' => 'audio/mp4',
            'aac' => 'audio/aac',
            default => 'image/png',
        };
    }
}
