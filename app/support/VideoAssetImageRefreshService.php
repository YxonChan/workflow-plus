<?php

declare(strict_types=1);

namespace app\support;

/**
 * 视频任务执行前刷新 assets_json 中的参考图 URL。
 * 资产库替换图片后，已入队/失败重试的 video_jobs 仍可能冻结旧 URL；
 * 本服务按 asset_id / asset_image_id / version_id 解析当前有效图片地址。
 * 写实造型优先 toapis_asset_url（asset://）；非写实始终用展示图。
 */
final class VideoAssetImageRefreshService
{
    /**
     * @param array<int, array<string, mixed>> $assets 冻结的 assets_json
     * @param array<int, array{id:int,type:string,name:string,description?:string,image_prompt?:string,series_id?:int,images:list<array<string,mixed>>}> $assetsById
     * @param array<int, array{id:int,asset_id:int,asset_image_id:int,url:string,is_selected?:bool}> $versionsById
     * @return array{assets: array<int, array<string, mixed>>, changed: bool, url_map: array<string, string>}
     */
    public static function refreshAssets(
        array $assets,
        array $assetsById,
        array $versionsById = [],
        string $visualStyle = 'realistic',
    ): array {
        $changed = false;
        $urlMap = [];
        $refreshed = [];
        $lookService = new AssetLookService();

        foreach ($assets as $item) {
            if (!is_array($item)) {
                continue;
            }

            $beforeUrl = trim((string) ($item['image_url'] ?? $item['url'] ?? ''));
            $resolved = self::resolveAssetItem($item, $assetsById, $versionsById, $visualStyle, $lookService);
            if ($resolved === null) {
                $refreshed[] = $item;
                continue;
            }

            $afterUrl = trim((string) ($resolved['image_url'] ?? ''));
            $merged = $item;
            foreach (['image_url', 'local_path', 'asset_image_id', 'asset_image_version_id', 'display_image_url', 'toapis_asset_url', 'toapis_status', 'used_toapis'] as $key) {
                if (array_key_exists($key, $resolved)) {
                    $merged[$key] = $resolved[$key];
                }
            }

            if ($afterUrl !== '' && $beforeUrl !== '' && $afterUrl !== $beforeUrl) {
                $urlMap[$beforeUrl] = $afterUrl;
                $changed = true;
            } elseif (
                (int) ($merged['asset_image_id'] ?? 0) !== (int) ($item['asset_image_id'] ?? 0)
                || (int) ($merged['asset_image_version_id'] ?? 0) !== (int) ($item['asset_image_version_id'] ?? 0)
                || (bool) ($merged['used_toapis'] ?? false) !== (bool) ($item['used_toapis'] ?? false)
            ) {
                $changed = true;
            } elseif ($afterUrl !== $beforeUrl && $afterUrl !== '') {
                $changed = true;
            }

            $refreshed[] = $merged;
        }

        return [
            'assets' => $refreshed,
            'changed' => $changed,
            'url_map' => $urlMap,
        ];
    }

    /**
     * 按旧→新 URL 映射刷新 source/input；未命中映射时保持原值。
     *
     * @param array<string, string> $urlMap
     * @return array{source_image_url: string, input_image_url: string, changed: bool}
     */
    public static function remapJobImageUrls(string $sourceImageUrl, string $inputImageUrl, array $urlMap): array
    {
        $source = trim($sourceImageUrl);
        $input = trim($inputImageUrl);
        $changed = false;

        if ($source !== '' && isset($urlMap[$source])) {
            $source = $urlMap[$source];
            $changed = true;
        }
        if ($input !== '' && isset($urlMap[$input])) {
            $input = $urlMap[$input];
            $changed = true;
        }

        return [
            'source_image_url' => $source,
            'input_image_url' => $input,
            'changed' => $changed,
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @param array<int, array{id:int,type:string,name:string,description?:string,image_prompt?:string,series_id?:int,images:list<array<string,mixed>>}> $assetsById
     * @param array<int, array{id:int,asset_id:int,asset_image_id:int,url:string,is_selected?:bool}> $versionsById
     * @return array{image_url:string,local_path?:string,asset_image_id:?int,asset_image_version_id:?int,display_image_url?:string,toapis_asset_url?:string,toapis_status?:string,used_toapis?:bool}|null
     */
    public static function resolveAssetItem(
        array $item,
        array $assetsById,
        array $versionsById = [],
        string $visualStyle = 'realistic',
        ?AssetLookService $lookService = null,
    ): ?array {
        $assetId = (int) ($item['id'] ?? $item['asset_id'] ?? 0);
        if ($assetId <= 0 || !isset($assetsById[$assetId]) || !is_array($assetsById[$assetId])) {
            return null;
        }

        $asset = $assetsById[$assetId];
        $images = isset($asset['images']) && is_array($asset['images']) ? $asset['images'] : [];
        $versionId = isset($item['asset_image_version_id']) && $item['asset_image_version_id'] !== null
            ? (int) $item['asset_image_version_id']
            : 0;
        $lookService ??= new AssetLookService();
        $referenceRole = strtolower(trim((string) ($item['reference_role'] ?? 'view'))) === 'look' ? 'look' : 'view';

        // 分镜显式钉死的历史版本：非写实可继续用该展示 URL；写实人物必须回落到当前造型的人像绑定。
        if ($versionId > 0 && isset($versionsById[$versionId]) && is_array($versionsById[$versionId])) {
            $version = $versionsById[$versionId];
            if ((int) ($version['asset_id'] ?? 0) === $assetId) {
                $versionUrl = trim((string) ($version['url'] ?? ''));
                if ($versionUrl !== '' && !($referenceRole === 'look' && $lookService->needsLookToapisAvatar($visualStyle))) {
                    return [
                        'image_url' => $versionUrl,
                        'display_image_url' => $versionUrl,
                        'toapis_asset_url' => '',
                        'toapis_status' => '',
                        'used_toapis' => false,
                        'asset_image_id' => ((int) ($version['asset_image_id'] ?? 0)) ?: null,
                        'asset_image_version_id' => $versionId,
                    ];
                }
            }
        }

        $image = $referenceRole === 'look'
            ? self::resolveLookImage($images, $item)
            : self::resolveViewImage($images, $item);

        if ($image === null) {
            return null;
        }

        $displayUrl = trim((string) ($image['url'] ?? ''));
        if ($displayUrl === '') {
            return null;
        }

        $toapisAssetUrl = trim((string) ($image['toapis_asset_url'] ?? ''));
        $toapisStatus = strtolower(trim((string) ($image['toapis_status'] ?? '')));
        $usedToapis = false;
        $imageUrl = $displayUrl;
        if ($referenceRole === 'look') {
            $resolved = $lookService->resolveVideoLookImageUrl($displayUrl, $toapisAssetUrl, $visualStyle, $toapisStatus);
            $imageUrl = (string) ($resolved['url'] ?? $displayUrl);
            $usedToapis = (bool) ($resolved['used_toapis'] ?? false);
            $toapisStatus = (string) ($resolved['status'] ?? $toapisStatus);
        }

        return [
            'image_url' => $imageUrl,
            'display_image_url' => $displayUrl,
            'toapis_asset_url' => $toapisAssetUrl,
            'toapis_status' => $toapisStatus,
            'used_toapis' => $usedToapis,
            'asset_image_id' => ((int) ($image['id'] ?? 0)) ?: null,
            'asset_image_version_id' => $versionId > 0 && !isset($versionsById[$versionId]) ? null : ($versionId > 0 ? $versionId : null),
        ];
    }

    /**
     * @param list<array<string, mixed>> $images
     * @param array<string, mixed> $item
     * @return array<string, mixed>|null
     */
    public static function resolveLookImage(array $images, array $item): ?array
    {
        $looks = [];
        foreach ($images as $image) {
            if (!is_array($image)) {
                continue;
            }
            $role = strtolower(trim((string) ($image['reference_role'] ?? 'view')));
            $url = trim((string) ($image['url'] ?? ''));
            if ($role !== 'look' || $url === '') {
                continue;
            }
            $looks[] = $image;
        }
        if ($looks === []) {
            // look 槽位已空时，回退到主视图，避免完全丢失人物参考。
            return self::resolveViewImage($images, $item);
        }

        $imageId = (int) ($item['asset_image_id'] ?? 0);
        if ($imageId > 0) {
            foreach ($looks as $image) {
                if ((int) ($image['id'] ?? 0) === $imageId) {
                    return $image;
                }
            }
        }

        $referenceKey = trim((string) ($item['reference_key'] ?? ''));
        if ($referenceKey !== '') {
            foreach ($looks as $image) {
                if (trim((string) ($image['reference_key'] ?? '')) === $referenceKey) {
                    return $image;
                }
            }
        }

        $variantName = trim((string) ($item['variant_name'] ?? ''));
        if ($variantName !== '') {
            foreach ($looks as $image) {
                if (trim((string) ($image['variant_name'] ?? '')) === $variantName) {
                    return $image;
                }
            }
        }

        // 旧 image_id / 造型名已变，但当前只剩一个 look：按该造型刷新（job #640 场景）。
        if (count($looks) === 1) {
            return $looks[0];
        }

        // 多造型且无法匹配：不猜，保留冻结 URL。
        return null;
    }

    /**
     * @param list<array<string, mixed>> $images
     * @param array<string, mixed> $item
     * @return array<string, mixed>|null
     */
    public static function resolveViewImage(array $images, array $item): ?array
    {
        $usable = [];
        foreach ($images as $image) {
            if (!is_array($image)) {
                continue;
            }
            $url = trim((string) ($image['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $usable[] = $image;
        }
        if ($usable === []) {
            return null;
        }

        $imageId = (int) ($item['asset_image_id'] ?? 0);
        if ($imageId > 0) {
            foreach ($usable as $image) {
                if ((int) ($image['id'] ?? 0) === $imageId) {
                    return $image;
                }
            }
        }

        $fallback = null;
        foreach ($usable as $image) {
            $role = strtolower(trim((string) ($image['reference_role'] ?? 'view')));
            if ($role === 'look') {
                continue;
            }
            if ($fallback === null) {
                $fallback = $image;
            }
            if ((string) ($image['view_type'] ?? '') === 'main') {
                return $image;
            }
        }

        return $fallback ?? $usable[0];
    }
}
