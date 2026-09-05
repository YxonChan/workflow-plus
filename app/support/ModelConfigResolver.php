<?php

declare(strict_types=1);

namespace app\support;

use app\model\ModelConfig;

class ModelConfigResolver
{
    /**
     * Resolve a model for a business user.
     * Explicit ids must belong to the user or be global; defaults prefer user scoped models.
     */
    public static function resolve(string $type, int $userId, int $modelConfigId = 0): ?ModelConfig
    {
        if ($modelConfigId > 0) {
            $model = ModelConfig::where('id', $modelConfigId)
                ->where('type', $type)
                ->where('enabled', 1)
                ->whereRaw("((`scope` = 'global' AND `user_id` = 0) OR (`scope` = 'user' AND `user_id` = ?))", [$userId])
                ->find();
            if ($model instanceof ModelConfig && !self::isHiddenFromUsers($model)) {
                return $model;
            }
        }

        $userModel = ModelConfig::where('type', $type)
            ->where('scope', 'user')
            ->where('user_id', $userId)
            ->where('enabled', 1)
            ->order(['is_default' => 'desc', 'sort' => 'asc', 'id' => 'asc'])
            ->select();
        foreach ($userModel as $candidate) {
            if ($candidate instanceof ModelConfig && !self::isHiddenFromUsers($candidate)) {
                return $candidate;
            }
        }

        $globalModel = ModelConfig::where('type', $type)
            ->where('scope', 'global')
            ->where('user_id', 0)
            ->where('enabled', 1)
            ->order(['is_default' => 'desc', 'sort' => 'asc', 'id' => 'asc'])
            ->select();
        foreach ($globalModel as $candidate) {
            if ($candidate instanceof ModelConfig && !self::isHiddenFromUsers($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Models visible to a user for dropdowns: all global enabled plus their own enabled overrides.
     * 官方 Ark、电信 Seedance 对用户侧全局隐藏。
     *
     * @return \think\db\Query|\think\Model
     */
    public static function visibleModelsQuery(int $userId)
    {
        return ModelConfig::where('enabled', 1)
            ->whereRaw("((`scope` = 'global' AND `user_id` = 0) OR (`scope` = 'user' AND `user_id` = ?))", [$userId])
            ->whereRaw(self::visibleVideoSql())
            ->orderRaw("CASE WHEN `scope` = 'user' THEN 0 ELSE 1 END ASC")
            ->order(['is_default' => 'desc', 'sort' => 'asc', 'id' => 'asc']);
    }

    public static function isHiddenFromUsers(ModelConfig|array $model): bool
    {
        $type = strtolower(trim((string) (is_array($model) ? ($model['type'] ?? '') : $model->getAttr('type'))));
        if ($type !== 'video') {
            return false;
        }

        return ToapisPrivateAvatarService::isHiddenUserVideo($model);
    }

    private static function visibleVideoSql(): string
    {
        return "(`type` <> 'video' OR ("
            . "LOWER(`endpoint`) NOT LIKE '%volces.com%'"
            . " AND LOWER(`endpoint`) NOT LIKE '%ark.cn-%'"
            . " AND LOWER(`endpoint`) NOT LIKE '%telecomjs.com%'"
            . " AND LOWER(IFNULL(`options`,'')) NOT LIKE '%telecomjs.com%'"
            . " AND LOWER(IFNULL(`options`,'')) NOT LIKE '%\"provider\":\"ark\"%'"
            . " AND LOWER(IFNULL(`options`,'')) NOT LIKE '%\"provider\":\"volcengine_ark\"%'"
            . " AND LOWER(IFNULL(`options`,'')) NOT LIKE '%\"provider\":\"volcano_ark\"%'"
            . " AND `name` NOT LIKE '%电信%'"
            . '))';
    }
}
