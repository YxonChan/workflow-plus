<?php

declare(strict_types=1);

namespace app\support;

/**
 * Video creation readiness must only enforce assets referenced by the current
 * video/shot job. Unrelated pending assets (e.g. from a newly created episode)
 * must not block earlier episodes.
 */
final class VideoReferencedAssetGate
{
    /**
     * Normalize referenced asset ids for the readiness gate.
     *
     * @param array<int|string, mixed> $referencedAssetIds list of ids, or id=>true map
     * @return list<int> asset ids to enforce; empty means check none (never scan whole series)
     */
    public static function assetIdsToEnforce(array $referencedAssetIds): array
    {
        $ids = [];
        $isList = array_is_list($referencedAssetIds);
        foreach ($referencedAssetIds as $key => $value) {
            if ($isList) {
                $candidate = (int) $value;
            } else {
                $keyId = (int) $key;
                $candidate = $keyId > 0 ? $keyId : (int) $value;
            }
            if ($candidate > 0) {
                $ids[$candidate] = true;
            }
        }

        return array_map('intval', array_keys($ids));
    }
}