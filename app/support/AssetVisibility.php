<?php

declare(strict_types=1);

namespace app\support;

use app\model\Asset;
use app\model\AssetShare;
use app\model\SeriesShare;
use think\db\Query;

/**
 * 资产可见性统一收口：自己拥有的资产、别人单独分享给自己的资产、或别人把整个剧本分享给自己
 * （剧本整体分享是动态判断，剧本下后续新增的资产会自动一并可见，无需重新分享）。
 * 避免在 QuickCreateController、SeriesController、AssetController 里各自重复一遍 OR 逻辑。
 */
final class AssetVisibility
{
    private const SHARED_CONDITION = '('
        . '`id` IN (SELECT `asset_id` FROM `asset_shares` WHERE `shared_with_user_id` IN (0, ?))'
        . ' OR `series_id` IN (SELECT `series_id` FROM `series_shares` WHERE `shared_with_user_id` IN (0, ?))'
        . ')';

    /**
     * @param string $scope mine=仅自己（默认，等价旧行为）；shared=仅他人分享给我的；all=自己 + 他人分享给我的
     */
    public static function scopedAssetQuery(int $userId, string $scope = 'mine'): Query
    {
        return match ($scope) {
            'shared' => Asset::where('user_id', '<>', $userId)
                ->whereRaw(self::SHARED_CONDITION, [$userId, $userId]),
            'all' => Asset::whereRaw(
                '(`user_id` = ? OR ' . self::SHARED_CONDITION . ')',
                [$userId, $userId, $userId]
            ),
            default => Asset::where('user_id', $userId),
        };
    }

    /**
     * 单条资产的可见性判断，供生成收尾环节按 asset_id 校验引用是否合法。
     */
    public static function canView(int $assetId, int $userId): bool
    {
        if ($assetId <= 0) {
            return false;
        }

        $asset = Asset::where('id', $assetId)->find();
        if (!$asset instanceof Asset) {
            return false;
        }

        if ((int) $asset->getAttr('user_id') === $userId) {
            return true;
        }

        $hasAssetShare = AssetShare::where('asset_id', $assetId)
            ->whereIn('shared_with_user_id', [0, $userId])
            ->count() > 0;
        if ($hasAssetShare) {
            return true;
        }

        $seriesId = (int) $asset->getAttr('series_id');

        return SeriesShare::where('series_id', $seriesId)
            ->whereIn('shared_with_user_id', [0, $userId])
            ->count() > 0;
    }

    /**
     * 分享人信息（用于共享列表/mention 标注"来自 XXX"），返回 [asset_id => owner_user_id]。
     *
     * @param int[] $assetIds
     * @return array<int, int>
     */
    public static function ownerMap(array $assetIds): array
    {
        if ($assetIds === []) {
            return [];
        }

        return Asset::whereIn('id', array_unique($assetIds))
            ->column('user_id', 'id');
    }
}
