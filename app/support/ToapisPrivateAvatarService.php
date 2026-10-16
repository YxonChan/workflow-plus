<?php

declare(strict_types=1);

namespace app\support;

use app\model\Asset;
use app\model\AssetImage;
use app\model\ModelConfig;

/**
 * ToAPIs media compatibility and review-on-generation support.
 */
class ToapisPrivateAvatarService
{
    public const INGEST_JOB_MARKER = '[look_toapis_ingest]';
    public const LEGACY_PENCIL_JOB_MARKER = '[look_video_pencil]';
    public const DEFAULT_BASE = 'https://toapis.cn';

    public static function isIngestJob(string $description): bool
    {
        return str_contains($description, self::INGEST_JOB_MARKER);
    }

    public static function isLegacyPencilJob(string $description): bool
    {
        return str_contains($description, self::LEGACY_PENCIL_JOB_MARKER);
    }

    public static function isHiddenBackgroundJob(string $description): bool
    {
        return self::isIngestJob($description) || self::isLegacyPencilJob($description);
    }

    public static function isAssetUri(string $url): bool
    {
        return str_starts_with(strtolower(trim($url)), 'asset://');
    }

    public static function applyVideoReview(array $payload, string $endpoint): array
    {
        if (!self::isToapisEndpoint($endpoint)) return $payload;
        $hasMedia = false;
        foreach (['image_with_roles', 'video_with_roles', 'audio_with_roles', 'reference_images', 'image_urls', 'images', 'image', 'video_list', 'video_urls', 'audio_urls', 'reference_videos', 'reference_audios'] as $field) {
            foreach ((array) ($payload[$field] ?? []) as $media) {
                $url = trim((string) (is_array($media) ? ($media['url'] ?? '') : $media));
                if ($url === '') continue;
                if (self::isAssetUri($url)) {
                    throw new \InvalidArgumentException('视频审核需要原始素材 URL，请重新选择参考图');
                }
                $hasMedia = true;
            }
        }
        if ($hasMedia) $payload['private_asset_review'] = true;
        unset($payload['private_assets']);
        return $payload;
    }

    public static function isLookAvatarActive(string $status, string $assetUrl): bool
    {
        return strtolower(trim($status)) === 'active' && self::isAssetUri($assetUrl);
    }

    public static function isLookAvatarProcessing(string $status): bool
    {
        return strtolower(trim($status)) === 'processing';
    }

    /**
     * 进行中：仍有入库任务，或造型已标 processing。
     * 没有任务的 processing 也算进行中，避免关掉弹窗后被旧的 failed 盖住。
     */
    public static function isLookAvatarPending(bool $hasAvatar, bool $hasActiveJob, string $status): bool
    {
        if ($hasAvatar) {
            return false;
        }

        return $hasActiveJob || self::isLookAvatarProcessing($status);
    }

    public static function isToapisEndpoint(string $endpoint): bool
    {
        $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));
        foreach (['toapis.cn', 'toapis.com', 'toapis.xyz'] as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) return true;
        }
        return false;
    }

    public static function isLegacyToapisHost(string $value): bool
    {
        $value = strtolower($value);

        return str_contains($value, 'toapis.com') || str_contains($value, 'toapis.xyz');
    }

    public static function rewriteLegacyEndpoint(string $endpoint): string
    {
        $endpoint = trim($endpoint);
        if ($endpoint === '' || !self::isLegacyToapisHost($endpoint)) {
            return $endpoint;
        }

        $rewritten = preg_replace('#https?://[^/]+#i', self::DEFAULT_BASE, $endpoint, 1);

        return is_string($rewritten) && $rewritten !== '' ? $rewritten : $endpoint;
    }

    public static function needsLookToapisAvatar(string $visualStyle): bool
    {
        // Review now belongs to the video task, never a separate asset-creation task.
        return false;
    }

    /**
     * @param ModelConfig|array<string, mixed> $model
     */
    public static function isOfficialArkVideo(ModelConfig|array $model): bool
    {
        $type = strtolower(trim((string) (is_array($model) ? ($model['type'] ?? '') : $model->getAttr('type'))));
        if ($type !== '' && $type !== 'video') {
            return false;
        }
        $endpoint = strtolower((string) (is_array($model) ? ($model['endpoint'] ?? '') : $model->getAttr('endpoint')));
        $optionsRaw = is_array($model) ? ($model['options'] ?? []) : $model->getAttr('options');
        $options = is_array($optionsRaw) ? $optionsRaw : [];
        if (is_string($optionsRaw) && $optionsRaw !== '') {
            $decoded = json_decode($optionsRaw, true);
            $options = is_array($decoded) ? $decoded : [];
        }
        $provider = strtolower(trim((string) ($options['provider'] ?? '')));

        return in_array($provider, ['ark', 'volcengine_ark', 'volcano_ark'], true)
            || str_contains($endpoint, 'volces.com')
            || str_contains($endpoint, 'ark.cn-');
    }

    /**
     * 电信网关 Seedance 2.0（aigw.telecomjs.com）。不按 yinhe_async 一刀切，避免误藏银河通道。
     *
     * @param ModelConfig|array<string, mixed> $model
     */
    public static function isTelecomSeedanceVideo(ModelConfig|array $model): bool
    {
        $type = strtolower(trim((string) (is_array($model) ? ($model['type'] ?? '') : $model->getAttr('type'))));
        if ($type !== '' && $type !== 'video') {
            return false;
        }
        $endpoint = strtolower((string) (is_array($model) ? ($model['endpoint'] ?? '') : $model->getAttr('endpoint')));
        $name = (string) (is_array($model) ? ($model['name'] ?? '') : $model->getAttr('name'));
        $optionsRaw = is_array($model) ? ($model['options'] ?? []) : $model->getAttr('options');
        $options = is_array($optionsRaw) ? $optionsRaw : [];
        if (is_string($optionsRaw) && $optionsRaw !== '') {
            $decoded = json_decode($optionsRaw, true);
            $options = is_array($decoded) ? $decoded : [];
        }
        $resultEndpoint = strtolower((string) ($options['result_endpoint'] ?? ''));

        if (str_contains($endpoint, 'telecomjs.com') || str_contains($resultEndpoint, 'telecomjs.com')) {
            return true;
        }

        return str_contains($name, '电信');
    }

    /**
     * 用户侧全局隐藏的视频通道：官方 Ark + 电信 Seedance。
     *
     * @param ModelConfig|array<string, mixed> $model
     */
    public static function isHiddenUserVideo(ModelConfig|array $model): bool
    {
        return self::isOfficialArkVideo($model) || self::isTelecomSeedanceVideo($model);
    }

    public static function normalizeBaseUrl(string $endpoint = ''): string
    {
        $endpoint = trim($endpoint);
        if ($endpoint !== '' && preg_match('#^(https?://[^/]+)#i', $endpoint, $matches) === 1) {
            $origin = rtrim((string) $matches[1], '/');
            if (self::isLegacyToapisHost($origin)) {
                return self::DEFAULT_BASE;
            }
            return $origin;
        }

        return self::DEFAULT_BASE;
    }


    public function clearLookBinding(AssetImage $image): void
    {
        $image->save([
            'toapis_asset_id' => '',
            'toapis_asset_url' => '',
            'toapis_status' => '',
        ]);
    }

    public function markLookProcessing(AssetImage $image): void
    {
        $image->save([
            'toapis_asset_id' => '',
            'toapis_asset_url' => '',
            'toapis_status' => 'processing',
        ]);
    }

    /**
     * Settle legacy queued ingest jobs without claiming the media passed review.
     *
     * @return array{group_id:string,asset_id:string,asset_url:string,status:string}
     */
    public function ingestLook(Asset $asset, AssetImage $image, string $displayUrl, int $userId): array
    {
        $displayUrl = trim($displayUrl);
        if ($displayUrl === '') {
            throw new \RuntimeException('参考图为空，无法提交素材');
        }

        $this->clearLookBinding($image);
        $image->save(['toapis_status' => 'on_generate']);
        return ['group_id' => '', 'asset_id' => '', 'asset_url' => $displayUrl, 'status' => 'on_generate'];
    }

    /**
     * 将本站或私网图片转成 ToAPIs 可访问的 URL。
     * 优先本站本地文件直传；本站公网域可直传 URL；Supabase 等外链先中转上传，避免对方跨海拉图超时。
     *
     * @param array{api_key:string,base:string,endpoint:string} $creds
     */
    private function resolvePublicSourceUrl(string $url, array $creds): string
    {
        $url = trim($url);
        if ($url === '') {
            throw new \RuntimeException('参考图为空，无法提交素材');
        }

        $localUploaded = $this->uploadLocalImage($url, $creds);
        if ($localUploaded !== '') {
            return $localUploaded;
        }

        if (preg_match('#^https?://#i', $url) === 1) {
            if ($this->isTrustedPublicMediaUrl($url)) {
                return $url;
            }
            $relayed = $this->uploadRemoteImage($url, $creds);
            if ($relayed !== '') {
                return $relayed;
            }
            throw new \RuntimeException('参考图无法中转到素材服务，请重新上传后重试');
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?: $url);
        if ($this->isLocalStoragePath($path)) {
            $baseUrl = rtrim(trim((string) env('MEDIA_PUBLIC_BASE_URL', '')), '/');
            if ($baseUrl !== '') {
                return $baseUrl . $path;
            }
        }

        throw new \RuntimeException('参考图没有可访问的地址，请配置 MEDIA_PUBLIC_BASE_URL 或重新上传');
    }

    public function imageSourceUrl(string $url, string $endpoint, string $apiKey): string
    {
        $public = MediaStorage::toPublicHttpUrl($url);
        $host = (string) parse_url($public, PHP_URL_HOST);
        $isPrivate = $host === '' || strtolower($host) === 'localhost'
            || (filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE));
        if (!$isPrivate) return $public;
        return $this->resolvePublicSourceUrl($url, ['base' => self::normalizeBaseUrl($endpoint), 'api_key' => $apiKey, 'endpoint' => $endpoint]);
    }

    /**
     * 本站 MEDIA_PUBLIC_BASE_URL / APP_URL 下的 /storage/ 媒体，ToAPIs 可直拉。
     */
    private function isTrustedPublicMediaUrl(string $url): bool
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
        if (!$this->isLocalStoragePath($path)) {
            return false;
        }
        foreach ([env('MEDIA_PUBLIC_BASE_URL', ''), env('APP_URL', '')] as $base) {
            $base = rtrim(trim((string) $base), '/');
            if ($base !== '' && str_starts_with($url, $base . '/storage/')) {
                return true;
            }
        }

        return false;
    }

    private function isLocalStoragePath(string $path): bool
    {
        // Supabase 公网路径也是 /storage/v1/object/...，不能当成本站磁盘路径。
        return str_starts_with($path, '/storage/') && !str_starts_with($path, '/storage/v1/');
    }

    /**
     * @param array{api_key:string,base:string,endpoint:string} $creds
     */
    private function uploadLocalImage(string $url, array $creds): string
    {
        $path = $this->resolveLocalPublicPath($url);
        if ($path === '' || !is_file($path)) {
            return '';
        }

        return $this->uploadImageFileToToapis($path, $creds, basename($path));
    }

    /**
     * 下载外链图片后上传到 ToAPIs，返回对方托管 URL。
     *
     * @param array{api_key:string,base:string,endpoint:string} $creds
     */
    private function uploadRemoteImage(string $url, array $creds): string
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'toapis-avatar-');
        if ($temporaryPath === false) {
            return '';
        }
        $downloadPath = $temporaryPath . '.img';
        @unlink($temporaryPath);

        try {
            $stream = fopen($downloadPath, 'wb');
            if ($stream === false) {
                return '';
            }
            $maxBytes = 20 * 1024 * 1024;
            $downloaded = 0;
            $writeFailed = false;
            $ch = curl_init($url);
            if ($ch === false) {
                fclose($stream);
                return '';
            }
            curl_setopt_array($ch, [
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_TIMEOUT => 90,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$downloaded, &$writeFailed, $stream, $maxBytes): int {
                    $length = strlen($chunk);
                    if ($downloaded + $length > $maxBytes) {
                        $writeFailed = true;

                        return 0;
                    }
                    $written = fwrite($stream, $chunk);
                    if ($written === false || $written !== $length) {
                        $writeFailed = true;

                        return 0;
                    }
                    $downloaded += $written;

                    return $written;
                },
            ]);
            curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno = (int) curl_errno($ch);
            curl_close($ch);
            fclose($stream);

            if ($writeFailed || $errno !== 0 || $http < 200 || $http >= 300 || $downloaded <= 0 || !is_file($downloadPath)) {
                return '';
            }

            $name = basename((string) (parse_url($url, PHP_URL_PATH) ?: 'remote.png'));
            if ($name === '' || !str_contains($name, '.')) {
                $name = 'remote.png';
            }

            return $this->uploadImageFileToToapis($downloadPath, $creds, $name);
        } finally {
            if (is_file($downloadPath)) {
                @unlink($downloadPath);
            }
        }
    }

    /**
     * @param array{api_key:string,base:string,endpoint:string} $creds
     */
    private function uploadImageFileToToapis(string $path, array $creds, string $filename): string
    {
        if (!is_file($path) || filesize($path) <= 0) {
            return '';
        }
        $mime = 'image/png';
        $info = @getimagesize($path);
        if (is_array($info) && isset($info['mime']) && is_string($info['mime']) && str_starts_with($info['mime'], 'image/')) {
            $mime = $info['mime'];
        }
        $safeName = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $filename) ?: 'image.png';
        $uploadEndpoint = rtrim($creds['base'], '/') . '/v1/uploads/images';
        $ch = curl_init($uploadEndpoint);
        if ($ch === false) {
            return '';
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $creds['api_key']],
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_POSTFIELDS => [
                'file' => new \CURLFile($path, $mime, $safeName),
            ],
        ]);
        $response = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($response) || $response === '' || $http < 200 || $http >= 300) {
            return '';
        }
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return '';
        }

        return trim((string) (
            $decoded['data']['url']
            ?? $decoded['url']
            ?? $decoded['data']['uri']
            ?? $decoded['uri']
            ?? ''
        ));
    }

    private function resolveLocalPublicPath(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: $url);
        if (!$this->isLocalStoragePath($path)) {
            return '';
        }
        $relative = ltrim(substr($path, strlen('/storage/')), '/');
        $relative = rawurldecode($relative);
        $candidates = [
            root_path() . 'public/storage/' . $relative,
            root_path() . 'storage/' . $relative,
        ];
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

}
