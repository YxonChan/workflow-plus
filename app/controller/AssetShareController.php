<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\model\Asset;
use app\model\AssetShare;
use app\model\Series;
use app\model\SeriesShare;
use app\model\User;
use app\support\RedisCache;
use think\facade\Db;

/**
 * 资产分享管理：把某个资产、或某部作品下的全部资产（含后续新增，动态判断，非快照）分享给
 * 指定用户或所有人；管理员账号不参与分享（不可选为分享目标，也不因“分享给所有人”获得可见
 * 权限——管理员是运营角色，不使用生产/引用功能）。
 */
class AssetShareController extends BaseController
{
    /**
     * 可分享的用户列表：启用状态、排除自己、排除管理员。资产分享和作品分享共用同一份目标列表。
     */
    public function targets()
    {
        $keyword = trim((string) $this->request->param('keyword', ''));
        $query = User::where('status', 1)
            ->where('role', '<>', 'admin')
            ->where('id', '<>', $this->currentUserId())
            ->order(['display_name' => 'asc', 'id' => 'asc']);
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('display_name', '%' . $keyword . '%')
                    ->whereOr('username', 'like', '%' . $keyword . '%');
            });
        }

        $users = $query->select()->map(fn (User $user): array => [
            'id' => (int) $user->getAttr('id'),
            'display_name' => (string) $user->getAttr('display_name'),
            'username' => (string) $user->getAttr('username'),
        ])->toArray();

        return successCode(['users' => $users]);
    }

    /**
     * 查某资产当前的分享设置，供分享弹窗回显；仅所有者可查。
     */
    public function forAsset()
    {
        $assetId = (int) $this->request->param('asset_id', 0);
        $this->ownedAssetOrFail($assetId);

        return successCode($this->shareStatus(AssetShare::where('asset_id', $assetId)->select()));
    }

    /**
     * 整体保存资产分享设置：先清空该资产已有分享行，再按传入目标重建；仅所有者可操作。
     */
    public function update()
    {
        $payload = $this->request->param();
        $assetId = (int) ($payload['asset_id'] ?? 0);
        $asset = $this->ownedAssetOrFail($assetId);

        [$everyone, $userIds] = $this->resolveShareTargets($payload);
        $ownerId = (int) $asset->getAttr('user_id');

        Db::transaction(function () use ($assetId, $ownerId, $everyone, $userIds): void {
            AssetShare::where('asset_id', $assetId)->delete();
            if ($everyone) {
                AssetShare::create(['asset_id' => $assetId, 'owner_user_id' => $ownerId, 'shared_with_user_id' => 0]);
            }
            foreach ($userIds as $userId) {
                AssetShare::create(['asset_id' => $assetId, 'owner_user_id' => $ownerId, 'shared_with_user_id' => $userId]);
            }
        });
        RedisCache::bumpVersion('assets');
        RedisCache::bumpVersion('series');

        return successCode();
    }

    /**
     * 查某部作品当前的整体分享设置，供分享弹窗回显；仅所有者可查。
     */
    public function forSeries()
    {
        $seriesId = (int) $this->request->param('series_id', 0);
        $this->ownedSeriesOrFail($seriesId);

        return successCode($this->shareStatus(SeriesShare::where('series_id', $seriesId)->select()));
    }

    /**
     * 整体保存作品分享设置：分享的是整部作品下的全部资产，含分享之后新增的资产（动态判断，
     * 不是分享时刻的快照）；先清空该作品已有分享行，再按传入目标重建；仅所有者可操作。
     */
    public function updateSeries()
    {
        $payload = $this->request->param();
        $seriesId = (int) ($payload['series_id'] ?? 0);
        $series = $this->ownedSeriesOrFail($seriesId);

        [$everyone, $userIds] = $this->resolveShareTargets($payload);
        $ownerId = (int) $series->getAttr('user_id');

        Db::transaction(function () use ($seriesId, $ownerId, $everyone, $userIds): void {
            SeriesShare::where('series_id', $seriesId)->delete();
            if ($everyone) {
                SeriesShare::create(['series_id' => $seriesId, 'owner_user_id' => $ownerId, 'shared_with_user_id' => 0]);
            }
            foreach ($userIds as $userId) {
                SeriesShare::create(['series_id' => $seriesId, 'owner_user_id' => $ownerId, 'shared_with_user_id' => $userId]);
            }
        });
        RedisCache::bumpVersion('assets');
        RedisCache::bumpVersion('series');

        return successCode();
    }

    /**
     * 从请求体解析 everyone/user_ids，并把 user_ids 校验收窄为启用状态、非管理员、非本人。
     *
     * @return array{0: bool, 1: int[]}
     */
    private function resolveShareTargets(array $payload): array
    {
        $everyone = (bool) ($payload['everyone'] ?? false);
        $currentUserId = $this->currentUserId();
        $userIds = array_values(array_unique(array_filter(
            array_map('intval', (array) ($payload['user_ids'] ?? [])),
            static fn (int $id): bool => $id > 0 && $id !== $currentUserId
        )));
        if (count($userIds) > 0) {
            $validIds = User::where('status', 1)
                ->where('role', '<>', 'admin')
                ->whereIn('id', $userIds)
                ->column('id');
            $userIds = array_values(array_intersect($userIds, $validIds));
        }

        return [$everyone, $userIds];
    }

    /**
     * 把 AssetShare/SeriesShare 行集合归纳为 {everyone, users} 供前端回显。
     */
    private function shareStatus(iterable $rows): array
    {
        $everyone = false;
        $userIds = [];
        foreach ($rows as $row) {
            $target = (int) $row->getAttr('shared_with_user_id');
            if ($target === 0) {
                $everyone = true;
            } else {
                $userIds[] = $target;
            }
        }

        $names = $userIds !== [] ? User::whereIn('id', $userIds)->column('display_name', 'id') : [];
        $users = array_map(
            static fn (int $id): array => ['id' => $id, 'display_name' => $names[$id] ?? ''],
            $userIds
        );

        return ['everyone' => $everyone, 'users' => $users];
    }

    private function ownedAssetOrFail(int $assetId): Asset
    {
        if ($assetId <= 0) {
            abort(422, '资产 id 不能为空');
        }
        $asset = Asset::where('id', $assetId)->where('user_id', $this->currentUserId())->find();
        if (!$asset instanceof Asset) {
            abort(404, '资产不存在或无权限操作');
        }

        return $asset;
    }

    private function ownedSeriesOrFail(int $seriesId): Series
    {
        if ($seriesId <= 0) {
            abort(422, '作品 id 不能为空');
        }
        $series = Series::where('id', $seriesId)->where('user_id', $this->currentUserId())->find();
        if (!$series instanceof Series) {
            abort(404, '作品不存在或无权限操作');
        }

        return $series;
    }
}
