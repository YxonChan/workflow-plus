<?php

declare(strict_types=1);

namespace app\support;

use app\model\Asset;
use app\model\AssetImage;
use app\model\ModelConfig;
use think\facade\Db;
use think\facade\Log;

/**
 * ToAPIs Seedance 2 虚拟人像：一个人物一个 group（桶），一套造型一个 pa_。
 */
class ToapisPrivateAvatarService
{
    public const INGEST_JOB_MARKER = '[look_toapis_ingest]';
    public const LEGACY_PENCIL_JOB_MARKER = '[look_video_pencil]';
    public const DEFAULT_BASE = 'https://toapis.cn';
    public const PRIVATE_AVATAR_PREFIX = '/v1/videos/doubao-seedance-2-0/private-avatar';

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
        $endpoint = strtolower($endpoint);

        return str_contains($endpoint, 'toapis.cn')
            || str_contains($endpoint, 'toapis.com')
            || str_contains($endpoint, 'toapis.xyz');
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
        return strtolower(trim($visualStyle)) === 'realistic';
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

    /**
     * @return array{api_key:string,base:string,endpoint:string}
     */
    public function credentials(int $userId = 0): array
    {
        $model = $userId > 0 ? ModelConfigResolver::resolve('video', $userId) : null;
        $endpoint = $model instanceof ModelConfig ? trim((string) $model->getAttr('endpoint')) : '';
        $apiKey = $model instanceof ModelConfig ? trim((string) $model->getAttr('api_key')) : '';
        if ($model instanceof ModelConfig && !self::isToapisEndpoint($endpoint)) {
            $apiKey = '';
            $endpoint = '';
        }
        if ($apiKey === '') {
            $apiKey = trim((string) env('TOAPIS_API_KEY', ''));
        }
        if ($apiKey === '' && $userId > 0) {
            $fallback = ModelConfig::where('type', 'video')
                ->where('enabled', 1)
                ->where(function ($query): void {
                    $query->whereLike('endpoint', '%toapis.cn%')
                        ->whereOr('endpoint', 'like', '%toapis.xyz%')
                        ->whereOr('endpoint', 'like', '%toapis.com%');
                })
                ->order(['is_default' => 'desc', 'id' => 'asc'])
                ->find();
            if ($fallback instanceof ModelConfig) {
                $apiKey = trim((string) $fallback->getAttr('api_key'));
                $endpoint = trim((string) $fallback->getAttr('endpoint'));
            }
        }
        if ($apiKey === '') {
            throw new \RuntimeException('未配置视频通道密钥，无法完成人物造型人像入库');
        }

        $resolvedEndpoint = self::rewriteLegacyEndpoint($endpoint);
        $base = self::normalizeBaseUrl($resolvedEndpoint);

        return [
            'api_key' => $apiKey,
            'base' => $base,
            'endpoint' => $resolvedEndpoint !== '' ? $resolvedEndpoint : ($base . '/v1/videos/generations'),
        ];
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
     * 确保该人物有且仅有一个 group；多套造型复用。
     */
    public function ensureCharacterGroup(Asset $asset, int $userId): string
    {
        $existing = trim((string) ($asset->getAttr('toapis_group_id') ?? ''));
        if ($existing !== '') {
            return $existing;
        }

        $assetId = (int) $asset->getAttr('id');
        $name = trim((string) $asset->getAttr('name'));
        if ($name === '') {
            $name = 'character-' . $assetId;
        }

        $creds = $this->credentials($userId);
        $groupId = '';
        Db::transaction(function () use ($assetId, $name, $creds, &$groupId): void {
            $locked = Asset::where('id', $assetId)->lock(true)->find();
            if (!$locked instanceof Asset) {
                throw new \RuntimeException('人物资产不存在，无法创建人像分组');
            }
            $existing = trim((string) ($locked->getAttr('toapis_group_id') ?? ''));
            if ($existing !== '') {
                $groupId = $existing;
                return;
            }

            $response = $this->request('POST', $creds['base'] . self::PRIVATE_AVATAR_PREFIX . '/groups', $creds['api_key'], [
                'name' => mb_substr($name, 0, 80),
                'description' => 'virtual character looks bucket',
            ]);
            $groupId = trim((string) ($response['data']['group_id'] ?? $response['group_id'] ?? ''));
            if ($groupId === '') {
                throw new \RuntimeException('人像分组创建失败：未返回 group_id');
            }
            $locked->save(['toapis_group_id' => $groupId]);
        });

        if ($groupId === '') {
            throw new \RuntimeException('人像分组创建失败');
        }
        $asset->setAttr('toapis_group_id', $groupId);

        return $groupId;
    }

    /**
     * 把造型展示图上传到该人物 group，轮询到 active 后写回 pa_。
     *
     * @return array{group_id:string,asset_id:string,asset_url:string,status:string}
     */
    public function ingestLook(Asset $asset, AssetImage $image, string $displayUrl, int $userId): array
    {
        $displayUrl = trim($displayUrl);
        if ($displayUrl === '') {
            throw new \RuntimeException('造型展示图为空，无法人像入库');
        }

        $image->save([
            'toapis_asset_id' => '',
            'toapis_asset_url' => '',
            'toapis_status' => 'processing',
        ]);

        try {
            $groupId = $this->ensureCharacterGroup($asset, $userId);
            $creds = $this->credentials($userId);
            $sourceUrl = $this->resolvePublicSourceUrl($displayUrl, $creds);
            $variant = trim((string) ($image->getAttr('variant_name') ?? ''));
            $lookName = $variant !== '' ? $variant : ('look-' . (int) $image->getAttr('id'));

            $uploaded = $this->request('POST', $creds['base'] . self::PRIVATE_AVATAR_PREFIX . '/assets', $creds['api_key'], [
                'group_id' => $groupId,
                'asset_type' => 'image',
                'source_url' => $sourceUrl,
                'name' => mb_substr($lookName, 0, 80),
                'description' => 'character look',
            ]);
            $assetId = trim((string) ($uploaded['data']['asset_id'] ?? $uploaded['asset_id'] ?? ''));
            $assetUrl = trim((string) ($uploaded['data']['asset_url'] ?? $uploaded['asset_url'] ?? ''));
            $status = strtolower(trim((string) ($uploaded['data']['status'] ?? $uploaded['status'] ?? 'processing')));
            if ($assetId === '') {
                throw new \RuntimeException('人像上传失败：未返回 asset_id');
            }
            if ($assetUrl === '') {
                $assetUrl = 'asset://' . $assetId;
            }
            $image->save([
                'toapis_asset_id' => $assetId,
                'toapis_asset_url' => $assetUrl,
                'toapis_status' => $status === 'active' ? 'active' : 'processing',
            ]);

            $polled = $this->pollAssetUntilSettled($creds, $assetId, $assetUrl, $status);
            $finalStatus = $polled['status'];
            $finalUrl = $polled['asset_url'];
            if ($finalStatus !== 'active' || !self::isAssetUri($finalUrl)) {
                $image->save([
                    'toapis_asset_id' => $assetId,
                    'toapis_asset_url' => $finalUrl,
                    'toapis_status' => 'failed',
                ]);
                throw new \RuntimeException('人像入库未完成，状态：' . $finalStatus);
            }
            $image->save([
                'toapis_asset_id' => $assetId,
                'toapis_asset_url' => $finalUrl,
                'toapis_status' => 'active',
            ]);

            return [
                'group_id' => $groupId,
                'asset_id' => $assetId,
                'asset_url' => $finalUrl,
                'status' => 'active',
            ];
        } catch (\Throwable $e) {
            $current = strtolower(trim((string) ($image->getAttr('toapis_status') ?? '')));
            if ($current !== 'active') {
                $image->save(['toapis_status' => 'failed']);
            }
            Log::warning('toapis private-avatar ingest failed: ' . $e->getMessage(), [
                'asset_id' => (int) $asset->getAttr('id'),
                'asset_image_id' => (int) $image->getAttr('id'),
            ]);
            throw $e;
        }
    }

    /**
     * @param array{api_key:string,base:string,endpoint:string} $creds
     * @return array{status:string,asset_url:string}
     */
    private function pollAssetUntilSettled(array $creds, string $assetId, string $assetUrl, string $status): array
    {
        $deadline = time() + 90;
        $lastStatus = $status;
        $lastUrl = $assetUrl;
        while (time() <= $deadline) {
            if (in_array($lastStatus, ['active', 'failed', 'error', 'rejected'], true)) {
                break;
            }
            sleep(3);
            $query = $this->request(
                'GET',
                $creds['base'] . self::PRIVATE_AVATAR_PREFIX . '/assets/' . rawurlencode($assetId),
                $creds['api_key']
            );
            $lastStatus = strtolower(trim((string) ($query['data']['status'] ?? $query['status'] ?? $lastStatus)));
            $polledUrl = trim((string) ($query['data']['asset_url'] ?? $query['asset_url'] ?? ''));
            if ($polledUrl !== '') {
                $lastUrl = $polledUrl;
            }
        }
        if ($lastUrl === '') {
            $lastUrl = 'asset://' . $assetId;
        }

        return ['status' => $lastStatus, 'asset_url' => $lastUrl];
    }

    /**
     * 解析 ToAPIs private-avatar 可用的 source_url。
     * 优先本站本地文件直传；本站公网域可直传 URL；Supabase 等外链先中转上传，避免对方跨海拉图超时。
     *
     * @param array{api_key:string,base:string,endpoint:string} $creds
     */
    private function resolvePublicSourceUrl(string $url, array $creds): string
    {
        $url = trim($url);
        if ($url === '') {
            throw new \RuntimeException('造型展示图为空，无法人像入库');
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
            throw new \RuntimeException('外链图片无法中转到人像服务，请改用本站上传图后重试');
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?: $url);
        if ($this->isLocalStoragePath($path)) {
            $baseUrl = rtrim(trim((string) env('MEDIA_PUBLIC_BASE_URL', '')), '/');
            if ($baseUrl !== '') {
                return $baseUrl . $path;
            }
        }

        throw new \RuntimeException('造型图没有公网地址，无法人像入库。请配置 MEDIA_PUBLIC_BASE_URL');
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

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $url, string $apiKey, ?array $payload = null): array
    {
        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Accept: application/json',
        ];
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('人像接口请求失败：curl_init');
        }
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
        ];
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = (string) curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno !== 0) {
            throw new \RuntimeException('人像接口网络错误：' . $error);
        }
        $raw = is_string($body) ? $body : '';
        $json = json_decode($raw, true);
        if ($http < 200 || $http >= 300) {
            $message = is_array($json)
                ? (string) ($json['error']['message'] ?? $json['message'] ?? $json['msg'] ?? '')
                : '';
            throw new \RuntimeException('人像接口 HTTP ' . $http . ($message !== '' ? '：' . $message : ''));
        }
        if (!is_array($json)) {
            throw new \RuntimeException('人像接口返回不是 JSON');
        }

        return $json;
    }
}
