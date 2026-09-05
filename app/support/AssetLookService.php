<?php

declare(strict_types=1);

namespace app\support;

use app\model\Asset;
use app\model\AssetImage;
use think\facade\Db;

class AssetLookService
{
    private static bool $schemaReady = false;

    public function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }

        $this->ensureAssetColumns();
        $this->ensureAssetImageColumns();
        $this->ensureAssetImageJobColumns();
        $this->ensureAssetImageVersionColumns();
        self::$schemaReady = true;
    }

    public function ensureSeriesMigration(int $userId, int $seriesId): void
    {
        $this->ensureSchema();
        if ($userId <= 0 || $seriesId <= 0) {
            return;
        }

        $characters = Asset::where('user_id', $userId)
            ->where('series_id', $seriesId)
            ->where('type', 'character')
            ->where('is_hidden', 0)
            ->select()
            ->all();
        if ($characters === []) {
            return;
        }

        $characterRows = [];
        foreach ($characters as $character) {
            if (!$character instanceof Asset) {
                continue;
            }
            $characterRows[] = $character;
        }
        if ($characterRows === []) {
            return;
        }

        $props = Asset::with(['images'])
            ->where('user_id', $userId)
            ->where('series_id', $seriesId)
            ->where('type', 'prop')
            ->where('is_hidden', 0)
            ->order(['sort' => 'asc', 'id' => 'asc'])
            ->select();

        foreach ($props as $prop) {
            if (!$prop instanceof Asset || !$this->isLegacyCostumeProp($prop)) {
                continue;
            }

            $character = $this->matchCharacterForProp($prop, $characterRows);
            if (!$character instanceof Asset) {
                continue;
            }

            $this->migratePropToCharacterLook($prop, $character);
        }
    }

    public function makeReferenceKey(string $characterName, string $variantName): string
    {
        $base = trim($characterName) . '-' . trim($variantName);
        $slug = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $this->toAscii($base)), '-'));
        if ($slug === '') {
            $slug = 'look-' . substr(md5($base !== '' ? $base : uniqid('look', true)), 0, 10);
        }
        return substr($slug, 0, 180);
    }

    public function makeReferenceName(string $characterName, string $variantName): string
    {
        $characterName = trim($characterName);
        $variantName = trim($variantName);
        if ($characterName === '') {
            return $variantName;
        }
        if ($variantName === '') {
            return $characterName;
        }
        return $characterName . '·' . $variantName;
    }

    public function normalizeReferenceRole(string $role): string
    {
        return strtolower(trim($role)) === 'look' ? 'look' : 'view';
    }

    public function normalizeVariantName(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value));
    }

    public function isLegacyCostumeProp(array|Asset $asset): bool
    {
        $name = $asset instanceof Asset ? (string) $asset->getAttr('name') : (string) ($asset['name'] ?? '');
        $description = $asset instanceof Asset ? (string) $asset->getAttr('description') : (string) ($asset['description'] ?? '');
        $imagePrompt = $asset instanceof Asset ? (string) ($asset->getAttr('image_prompt') ?? '') : (string) ($asset['image_prompt'] ?? '');
        $tags = $asset instanceof Asset ? ($asset->getAttr('tags') ?: []) : ($asset['tags'] ?? []);
        if (!is_array($tags)) {
            $tags = [];
        }

        $haystack = mb_strtolower(implode(' ', array_filter([
            $name,
            $description,
            $imagePrompt,
            implode(' ', array_map(static fn ($tag): string => trim((string) $tag), $tags)),
        ])));

        foreach ($this->costumeKeywords() as $keyword) {
            if (str_contains($haystack, mb_strtolower($keyword))) {
                return true;
            }
        }

        return false;
    }

    public function extractVariantNameFromLegacyProp(string $characterName, string $propName): string
    {
        $name = trim($propName);
        $characterName = trim($characterName);
        if ($characterName !== '' && str_starts_with($name, $characterName)) {
            $name = trim((string) preg_replace('/^' . preg_quote($characterName, '/') . '[\s\-_·.、：:|丨]*/u', '', $name));
        }
        $name = trim((string) preg_replace('/^[第]?(\d+)[集话回章]\s*/u', '第$1集', $name));
        $name = trim($name, " \t\n\r\0\x0B·.-_");
        return $name !== '' ? $name : '默认造型';
    }

    /**
     * @param array<int, Asset> $characters
     */
    private function matchCharacterForProp(Asset $prop, array $characters): ?Asset
    {
        $name = trim((string) $prop->getAttr('name'));
        $description = mb_strtolower(trim((string) $prop->getAttr('description')));

        foreach ($characters as $character) {
            $characterName = trim((string) $character->getAttr('name'));
            if ($characterName !== '' && str_starts_with($name, $characterName)) {
                return $character;
            }
        }

        foreach ($characters as $character) {
            $characterName = trim((string) $character->getAttr('name'));
            if ($characterName !== '' && str_contains($description, mb_strtolower($characterName))) {
                return $character;
            }
        }

        return null;
    }

    private function migratePropToCharacterLook(Asset $prop, Asset $character): void
    {
        $characterId = (int) $character->getAttr('id');
        $characterName = (string) $character->getAttr('name');
        $variantName = $this->extractVariantNameFromLegacyProp(
            $characterName,
            (string) $prop->getAttr('name'),
        );
        $referenceKey = $this->makeReferenceKey($characterName, $variantName);
        $sourcePrompt = $this->buildMigratedLookPrompt($characterName, $variantName, $prop);

        Db::transaction(function () use ($prop, $characterId, $variantName, $referenceKey, $sourcePrompt): void {
            $target = AssetImage::where('asset_id', $characterId)
                ->where('reference_role', 'look')
                ->where('reference_key', $referenceKey)
                ->find();

            if (!$target instanceof AssetImage) {
                $target = AssetImage::create([
                    'user_id' => (int) $prop->getAttr('user_id'),
                    'asset_id' => $characterId,
                    'view_type' => 'look',
                    'url' => '',
                    'note' => '人物造型（由旧服装迁移，待生成）',
                    'image_prompt' => $sourcePrompt,
                    'sort' => ((int) AssetImage::where('asset_id', $characterId)->max('sort')) + 10,
                    'reference_role' => 'look',
                    'variant_name' => $variantName,
                    'reference_key' => $referenceKey,
                ]);
            } else {
                $target->save([
                    'view_type' => 'look',
                    'reference_role' => 'look',
                    'variant_name' => $variantName,
                    'reference_key' => $referenceKey,
                    'note' => (string) ($target->getAttr('note') ?: '人物造型（由旧服装迁移，待生成）'),
                    'image_prompt' => trim((string) $target->getAttr('image_prompt')) !== ''
                        ? (string) $target->getAttr('image_prompt')
                        : $sourcePrompt,
                ]);
            }

            $tags = $prop->getAttr('tags') ?: [];
            if (!is_array($tags)) {
                $tags = [];
            }
            if (!in_array('migrated_to_character_look', $tags, true)) {
                $tags[] = 'migrated_to_character_look';
            }
            $prop->save([
                'is_hidden' => 1,
                'tags' => array_values(array_unique($tags)),
            ]);
        });
    }

    private function buildMigratedLookPrompt(string $characterName, string $variantName, Asset $prop): string
    {
        $characterName = trim($characterName);
        $variantName = trim($variantName) !== '' ? trim($variantName) : '默认造型';
        $description = trim((string) $prop->getAttr('description'));
        $imagePrompt = trim((string) ($prop->getAttr('image_prompt') ?? ''));
        $legacyHint = trim(implode('；', array_filter([$description, $imagePrompt])));
        $base = "参考该角色的主图，生成角色「{$characterName}」的完整人物造型图「{$variantName}」。保持同一角色的脸部、发型、体型、年龄感和气质一致，变化点只在服装与整体造型。白底棚拍，不要场景，不要服装平铺，不要衣架，不要第二个人，不要手持道具。";
        if ($legacyHint !== '') {
            $base .= " 旧服装信息仅作造型线索参考：{$legacyHint}";
        }
        return $base;
    }

    private function ensureAssetColumns(): void
    {
        if (!$this->tableExists('assets')) {
            return;
        }

        if (!$this->columnExists('assets', 'is_hidden')) {
            Db::execute("ALTER TABLE `assets` ADD COLUMN `is_hidden` tinyint(1) unsigned NOT NULL DEFAULT 0 AFTER `tags`");
            Db::execute("ALTER TABLE `assets` ADD INDEX `idx_assets_series_hidden` (`series_id`, `is_hidden`, `type`, `sort`)");
        }
        if (!$this->columnExists('assets', 'toapis_group_id')) {
            Db::execute("ALTER TABLE `assets` ADD COLUMN `toapis_group_id` varchar(64) NOT NULL DEFAULT '' COMMENT 'ToAPIs private-avatar group_id，一人一桶' AFTER `is_hidden`");
        }

        Db::execute("UPDATE `assets` SET `is_hidden` = 0 WHERE `is_hidden` IS NULL");
        Db::execute("UPDATE `assets` SET `toapis_group_id` = '' WHERE `toapis_group_id` IS NULL");
    }

    private function ensureAssetImageColumns(): void
    {
        if (!$this->tableExists('asset_images')) {
            return;
        }

        if (!$this->columnExists('asset_images', 'reference_role')) {
            Db::execute("ALTER TABLE `asset_images` ADD COLUMN `reference_role` varchar(16) NOT NULL DEFAULT 'view' AFTER `sort`");
        }
        if (!$this->columnExists('asset_images', 'variant_name')) {
            Db::execute("ALTER TABLE `asset_images` ADD COLUMN `variant_name` varchar(120) NOT NULL DEFAULT '' AFTER `reference_role`");
        }
        if (!$this->columnExists('asset_images', 'reference_key')) {
            Db::execute("ALTER TABLE `asset_images` ADD COLUMN `reference_key` varchar(191) NOT NULL DEFAULT '' AFTER `variant_name`");
            Db::execute("ALTER TABLE `asset_images` ADD INDEX `idx_asset_images_reference` (`asset_id`, `reference_role`, `reference_key`)");
        }
        // 历史彩铅列保留不删；新代码不再读写。
        if (!$this->columnExists('asset_images', 'video_ref_url')) {
            Db::execute("ALTER TABLE `asset_images` ADD COLUMN `video_ref_url` varchar(1000) NOT NULL DEFAULT '' AFTER `reference_key`");
        }
        if (!$this->columnExists('asset_images', 'toapis_asset_id')) {
            Db::execute("ALTER TABLE `asset_images` ADD COLUMN `toapis_asset_id` varchar(64) NOT NULL DEFAULT '' COMMENT 'ToAPIs private-avatar pa_' AFTER `video_ref_url`");
        }
        if (!$this->columnExists('asset_images', 'toapis_asset_url')) {
            Db::execute("ALTER TABLE `asset_images` ADD COLUMN `toapis_asset_url` varchar(128) NOT NULL DEFAULT '' COMMENT 'asset://pa_...' AFTER `toapis_asset_id`");
        }
        if (!$this->columnExists('asset_images', 'toapis_status')) {
            Db::execute("ALTER TABLE `asset_images` ADD COLUMN `toapis_status` varchar(16) NOT NULL DEFAULT '' COMMENT '空|processing|active|failed' AFTER `toapis_asset_url`");
        }

        Db::execute("UPDATE `asset_images` SET `reference_role` = 'view' WHERE `reference_role` IS NULL OR `reference_role` = ''");
        Db::execute("UPDATE `asset_images` SET `reference_role` = 'look' WHERE `view_type` = 'look'");
        Db::execute("UPDATE `asset_images` SET `reference_key` = '' WHERE `reference_key` IS NULL");
        Db::execute("UPDATE `asset_images` SET `variant_name` = '' WHERE `variant_name` IS NULL");
        Db::execute("UPDATE `asset_images` SET `video_ref_url` = '' WHERE `video_ref_url` IS NULL");
        Db::execute("UPDATE `asset_images` SET `toapis_asset_id` = '' WHERE `toapis_asset_id` IS NULL");
        Db::execute("UPDATE `asset_images` SET `toapis_asset_url` = '' WHERE `toapis_asset_url` IS NULL");
        Db::execute("UPDATE `asset_images` SET `toapis_status` = '' WHERE `toapis_status` IS NULL");
    }

    /**
     * 仅写实人物造型需要上传到 ToAPIs 虚拟人像库。
     */
    public function needsLookToapisAvatar(string $visualStyle): bool
    {
        return ToapisPrivateAvatarService::needsLookToapisAvatar($visualStyle);
    }

    /**
     * 写实且人像入库 active 时返回 asset://；否则返回展示图（调用方不得把展示图当写实人脸发给 Seedance）。
     *
     * @return array{url:string,used_toapis:bool,status:string}
     */
    public function resolveVideoLookImageUrl(
        string $displayUrl,
        string $toapisAssetUrl,
        string $visualStyle,
        string $toapisStatus = '',
    ): array {
        $displayUrl = trim($displayUrl);
        $toapisAssetUrl = trim($toapisAssetUrl);
        $toapisStatus = strtolower(trim($toapisStatus));
        if (
            $this->needsLookToapisAvatar($visualStyle)
            && ToapisPrivateAvatarService::isLookAvatarActive($toapisStatus, $toapisAssetUrl)
        ) {
            return ['url' => $toapisAssetUrl, 'used_toapis' => true, 'status' => 'active'];
        }

        return ['url' => $displayUrl, 'used_toapis' => false, 'status' => $toapisStatus];
    }

    /**
     * @return array{toapis_asset_id:string,toapis_asset_url:string,toapis_status:string}
     */
    public function emptyToapisBinding(): array
    {
        return [
            'toapis_asset_id' => '',
            'toapis_asset_url' => '',
            'toapis_status' => '',
        ];
    }

    private function ensureAssetImageJobColumns(): void
    {
        if (!$this->tableExists('asset_image_jobs')) {
            return;
        }

        if (!$this->columnExists('asset_image_jobs', 'asset_image_id')) {
            Db::execute("ALTER TABLE `asset_image_jobs` ADD COLUMN `asset_image_id` int unsigned DEFAULT NULL AFTER `asset_id`");
            Db::execute("ALTER TABLE `asset_image_jobs` ADD INDEX `idx_asset_image_jobs_image` (`asset_image_id`)");
        }
        if (!$this->columnExists('asset_image_jobs', 'retry_after')) {
            try {
                Db::execute("ALTER TABLE `asset_image_jobs` ADD COLUMN `retry_after` datetime DEFAULT NULL AFTER `update_time`");
            } catch (\Throwable $e) {
                // 多个 Worker 首次同时启动时，另一个进程可能已完成 ALTER；确认字段已出现即可继续。
                if (!$this->columnExists('asset_image_jobs', 'retry_after')) {
                    throw $e;
                }
            }
        }
        if (!$this->indexExists('asset_image_jobs', 'idx_asset_image_jobs_retry')) {
            try {
                Db::execute("ALTER TABLE `asset_image_jobs` ADD INDEX `idx_asset_image_jobs_retry` (`status`, `retry_after`, `id`)");
            } catch (\Throwable $e) {
                if (!$this->indexExists('asset_image_jobs', 'idx_asset_image_jobs_retry')) {
                    throw $e;
                }
            }
        }
    }

    private function ensureAssetImageVersionColumns(): void
    {
        if (!$this->tableExists('asset_image_versions')) {
            return;
        }

        if (!$this->columnExists('asset_image_versions', 'asset_image_id')) {
            Db::execute("ALTER TABLE `asset_image_versions` ADD COLUMN `asset_image_id` int unsigned DEFAULT NULL AFTER `asset_id`");
            Db::execute("ALTER TABLE `asset_image_versions` ADD INDEX `idx_aiv_asset_image` (`asset_image_id`)");
        }
    }

    private function costumeKeywords(): array
    {
        return [
            '服装', '造型', '衣服', '衣物', '外套', '内衬', '裙', '裤', '鞋', '帽', '冠',
            '配饰', '饰品', '工服', '制服', '盔甲', '甲胄', '战袍', '常服', '便服',
            'costume', 'outfit', 'clothing', 'uniform', 'dress', 'coat', 'jacket',
        ];
    }

    private function tableExists(string $table): bool
    {
        return Db::query("SHOW TABLES LIKE '{$table}'") !== [];
    }

    private function columnExists(string $table, string $column): bool
    {
        return Db::query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'") !== [];
    }

    private function indexExists(string $table, string $index): bool
    {
        return Db::query("SHOW INDEX FROM `{$table}` WHERE Key_name = '{$index}'") !== [];
    }

    private function toAscii(string $value): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        return $ascii !== false ? $ascii : $value;
    }
}
