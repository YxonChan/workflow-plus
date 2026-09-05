<?php

declare(strict_types=1);

namespace app\support;

use app\model\VoiceAsset;

final class VideoVoiceAssetService
{
    /**
     * 只解析当前镜头实际携带的角色资产，并冻结当前有效音色快照。
     *
     * @return array<int, array<string, mixed>>
     */
    public function resolveForVideoAssets(array $assets, int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $characters = [];
        foreach ($assets as $asset) {
            if (!is_array($asset) || strtolower(trim((string) ($asset['type'] ?? ''))) !== 'character') {
                continue;
            }
            $assetId = (int) ($asset['id'] ?? $asset['asset_id'] ?? 0);
            if ($assetId <= 0) {
                continue;
            }
            $characters[$assetId] = trim((string) ($asset['name'] ?? $asset['character_name'] ?? ''));
        }
        if ($characters === []) {
            return [];
        }

        $rows = VoiceAsset::where('user_id', $userId)
            ->whereIn('asset_id', array_keys($characters))
            ->where('status', 'ready')
            ->whereNull('deleted_at')
            ->order('id', 'desc')
            ->select();

        $snapshots = [];
        $seenAssets = [];
        foreach ($rows as $voice) {
            if (!$voice instanceof VoiceAsset) {
                continue;
            }
            $assetId = (int) $voice->getAttr('asset_id');
            $sourceUrl = trim((string) $voice->getAttr('source_url'));
            if ($assetId <= 0 || $sourceUrl === '' || isset($seenAssets[$assetId])) {
                continue;
            }
            $seenAssets[$assetId] = true;
            $snapshots[] = [
                'voice_asset_id' => (int) $voice->getAttr('id'),
                'character_asset_id' => $assetId,
                'character_name' => $characters[$assetId] !== ''
                    ? $characters[$assetId]
                    : trim((string) $voice->getAttr('name')),
                'source_url' => $sourceUrl,
                'mime_type' => trim((string) $voice->getAttr('mime_type')),
                'duration_ms' => (int) $voice->getAttr('duration_ms'),
                'sha256' => trim((string) $voice->getAttr('sha256')),
            ];
        }

        usort($snapshots, static fn (array $a, array $b): int => ((int) $a['character_asset_id']) <=> ((int) $b['character_asset_id']));
        return $snapshots;
    }

    public static function prependArkPrompt(string $prompt, array $voiceAssets): string
    {
        $lines = [];
        foreach (self::validSnapshots($voiceAssets) as $index => $voice) {
            $name = trim((string) ($voice['character_name'] ?? ''));
            $label = '@音频' . ($index + 1);
            $lines[] = $name !== ''
                ? "{$label} 作为角色「{$name}」的音色参考；该角色对白必须保持此声音的音质、音高、语速和说话特征。"
                : "{$label} 作为当前角色的音色参考；角色对白必须保持此声音的音质、音高、语速和说话特征。";
        }
        if ($lines === []) {
            return trim($prompt);
        }

        return implode("\n", $lines) . "\n\n" . trim($prompt);
    }

    /**
     * @return array<int, array{type:string,audio_url:array{url:string},role:string}>
     */
    public static function arkContentItems(array $voiceAssets): array
    {
        $items = [];
        foreach (self::validSnapshots($voiceAssets) as $voice) {
            $items[] = [
                'type' => 'audio_url',
                'audio_url' => ['url' => trim((string) $voice['source_url'])],
                'role' => 'reference_audio',
            ];
        }
        return $items;
    }

    /** @return array<int, array<string, mixed>> */
    private static function validSnapshots(array $voiceAssets): array
    {
        $result = [];
        $seen = [];
        foreach ($voiceAssets as $voice) {
            if (!is_array($voice)) {
                continue;
            }
            $voiceAssetId = (int) ($voice['voice_asset_id'] ?? 0);
            $sourceUrl = trim((string) ($voice['source_url'] ?? ''));
            $key = $voiceAssetId > 0 ? 'id:' . $voiceAssetId : 'url:' . $sourceUrl;
            if ($sourceUrl === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $voice;
        }
        return array_slice($result, 0, 9);
    }
}
