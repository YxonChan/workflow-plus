<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\model\Asset;
use app\model\VoiceAsset;
use app\support\MediaStorage;
use app\support\VoiceAssetService;
use think\facade\Db;

class VoiceAssetController extends BaseController
{
    private ?VoiceAssetService $service = null;

    public function index()
    {
        $this->assertEnabled();
        $this->voiceAssetService()->ensureSchema();
        $character = $this->findOwnedCharacter((int) $this->request->param('asset_id', 0));
        $voice = VoiceAsset::where('asset_id', (int) $character->getAttr('id'))
            ->where('user_id', $this->currentUserId())
            ->where('status', 'ready')
            ->whereNull('deleted_at')
            ->order('id', 'desc')
            ->find();

        return successCode([
            'voice_asset' => $voice instanceof VoiceAsset ? $this->serialize($voice) : null,
            'limits' => $this->limits(),
        ]);
    }

    public function upload()
    {
        $this->assertEnabled();
        $service = $this->voiceAssetService();
        $service->ensureSchema();
        $character = $this->findOwnedCharacter((int) $this->request->param('asset_id', 0));

        $file = $this->request->file('file');
        if (!$file) {
            abort(422, '请选择音频文件');
        }
        if (!$this->isTruthy($this->request->param('rights_confirmed', false))) {
            abort(422, '请确认你拥有该声音的合法使用权');
        }

        $originalName = method_exists($file, 'getOriginalName') ? trim((string) $file->getOriginalName()) : 'voice';
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedExtensions = (array) config('voice_asset.allowed_extensions', ['wav', 'mp3', 'm4a']);
        if (!in_array($extension, $allowedExtensions, true)) {
            abort(422, '音频格式不支持，仅允许：' . implode('、', $allowedExtensions));
        }

        $path = $file->getPathname();
        $fileSize = is_file($path) ? (int) filesize($path) : 0;
        $maxSize = (int) config('voice_asset.max_size_bytes', 20 * 1024 * 1024);
        if ($fileSize <= 0) {
            abort(422, '上传的音频文件为空');
        }
        if ($fileSize > $maxSize) {
            abort(422, '音频文件不能超过 ' . $this->formatMb($maxSize) . 'MB');
        }

        $mime = $this->detectMime($path);
        $allowedMimes = (array) config('voice_asset.allowed_mime_types', []);
        if ($mime !== '' && $allowedMimes !== [] && !in_array($mime, $allowedMimes, true)) {
            abort(422, '上传文件不是受支持的音频格式');
        }

        $probe = $service->inspectAudio($path);
        $durationSeconds = $probe['duration_ms'] / 1000;
        $minSeconds = (int) config('voice_asset.min_duration_seconds', 5);
        $maxSeconds = (int) config('voice_asset.max_duration_seconds', 30);
        if ($durationSeconds < $minSeconds) {
            abort(422, "音频时长不能少于 {$minSeconds} 秒");
        }
        if ($durationSeconds > $maxSeconds) {
            abort(422, "音频时长不能超过 {$maxSeconds} 秒");
        }

        $userId = $this->currentUserId();
        $characterId = (int) $character->getAttr('id');
        $sha256 = hash_file('sha256', $path) ?: '';
        $existing = VoiceAsset::where('asset_id', $characterId)
            ->where('user_id', $userId)
            ->where('sha256', $sha256)
            ->where('status', 'ready')
            ->whereNull('deleted_at')
            ->find();
        if ($existing instanceof VoiceAsset) {
            return successCode(['voice_asset' => $this->serialize($existing), 'deduplicated' => true]);
        }

        $name = trim((string) $this->request->param('name', ''));
        if ($name === '') {
            $name = trim((string) $character->getAttr('name')) . '的音色';
        }
        if (mb_strlen($name) > 120) {
            abort(422, '音色名称不能超过 120 个字符');
        }

        $sourceUrl = MediaStorage::uploadBinaryFile($path, $extension, 'uploads/voice-assets', $mime, [
            'user_id' => $userId,
            'asset_id' => $characterId,
            'asset_name' => (string) $character->getAttr('name'),
            'series_id' => (int) $character->getAttr('series_id'),
            'source' => 'character_voice',
            'original_name' => $originalName,
        ]);

        $voice = Db::transaction(function () use ($userId, $characterId, $name, $sourceUrl, $originalName, $mime, $extension, $fileSize, $probe, $sha256): VoiceAsset {
            $currentVoices = VoiceAsset::where('asset_id', $characterId)
                ->where('user_id', $userId)
                ->where('status', 'ready')
                ->whereNull('deleted_at')
                ->lock(true)
                ->select();
            $currentVoiceIds = [];
            foreach ($currentVoices as $currentVoice) {
                if ($currentVoice instanceof VoiceAsset) {
                    $currentVoiceIds[] = (int) $currentVoice->getAttr('id');
                }
            }
            if ($currentVoiceIds !== []) {
                VoiceAsset::whereIn('id', $currentVoiceIds)->update(['status' => 'archived']);
            }

            return VoiceAsset::create([
                'user_id' => $userId,
                'asset_id' => $characterId,
                'name' => $name,
                'source_url' => $sourceUrl,
                'original_filename' => $originalName,
                'mime_type' => $mime,
                'extension' => $extension,
                'file_size' => $fileSize,
                'duration_ms' => $probe['duration_ms'],
                'sha256' => $sha256,
                'status' => 'ready',
                'rights_confirmed' => 1,
                'provider_meta_json' => [
                    'codec_name' => $probe['codec_name'],
                    'sample_rate' => $probe['sample_rate'],
                    'channels' => $probe['channels'],
                ],
                'error_message' => '',
            ]);
        });

        $serialized = $this->serialize($voice);
        $this->writeAdminOperationLog([
            'action' => 'character_voice.upload',
            'target_type' => 'voice_asset',
            'target_id' => (int) $voice->getAttr('id'),
            'target_name_snapshot' => (string) $voice->getAttr('name'),
            'series_id' => (int) $character->getAttr('series_id'),
            'after_json' => $serialized,
            'meta_json' => ['route' => 'voice-assets.upload', 'character_asset_id' => $characterId],
        ]);

        return successCode(['voice_asset' => $serialized, 'deduplicated' => false], 'success', 201);
    }

    public function delete()
    {
        $this->assertEnabled();
        $this->voiceAssetService()->ensureSchema();
        $character = $this->findOwnedCharacter((int) $this->request->param('asset_id', 0));
        $id = (int) $this->request->param('id', 0);
        $voice = VoiceAsset::where('id', $id)
            ->where('asset_id', (int) $character->getAttr('id'))
            ->where('user_id', $this->currentUserId())
            ->where('status', 'ready')
            ->whereNull('deleted_at')
            ->find();
        if (!$voice instanceof VoiceAsset) {
            abort(404, '角色音色不存在');
        }
        $before = $this->serialize($voice);
        $voice->save(['status' => 'deleted', 'deleted_at' => date('Y-m-d H:i:s')]);

        $this->writeAdminOperationLog([
            'action' => 'character_voice.delete',
            'target_type' => 'voice_asset',
            'target_id' => (int) $voice->getAttr('id'),
            'target_name_snapshot' => (string) $voice->getAttr('name'),
            'series_id' => (int) $character->getAttr('series_id'),
            'before_json' => $before,
            'after_json' => [],
            'meta_json' => ['route' => 'voice-assets.delete', 'character_asset_id' => (int) $character->getAttr('id'), 'soft_delete' => true],
        ]);

        return successCode();
    }

    private function findOwnedCharacter(int $assetId): Asset
    {
        if ($assetId <= 0) {
            abort(422, '角色资产 id 不能为空');
        }
        $asset = Asset::where('id', $assetId)->where('user_id', $this->currentUserId())->find();
        if (!$asset instanceof Asset) {
            abort(404, '角色资产不存在');
        }
        if ((string) $asset->getAttr('type') !== 'character') {
            abort(422, '只有角色资产可以绑定音色');
        }
        return $asset;
    }

    private function voiceAssetService(): VoiceAssetService
    {
        return $this->service ??= new VoiceAssetService();
    }

    private function assertEnabled(): void
    {
        if (!(bool) config('voice_asset.enabled', true)) {
            abort(404, '角色音色功能未开启');
        }
    }

    private function detectMime(string $path): string
    {
        if (!function_exists('finfo_open')) return '';
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) return '';
        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);
        return is_string($mime) ? strtolower(trim($mime)) : '';
    }

    private function limits(): array
    {
        return [
            'max_size_bytes' => (int) config('voice_asset.max_size_bytes', 20 * 1024 * 1024),
            'min_duration_seconds' => (int) config('voice_asset.min_duration_seconds', 5),
            'max_duration_seconds' => (int) config('voice_asset.max_duration_seconds', 30),
            'allowed_extensions' => array_values((array) config('voice_asset.allowed_extensions', ['wav', 'mp3', 'm4a'])),
        ];
    }

    private function serialize(VoiceAsset $voice): array
    {
        $meta = $voice->getAttr('provider_meta_json') ?: [];
        return [
            'id' => (int) $voice->getAttr('id'),
            'asset_id' => (int) $voice->getAttr('asset_id'),
            'name' => (string) $voice->getAttr('name'),
            'source_url' => (string) $voice->getAttr('source_url'),
            'original_filename' => (string) $voice->getAttr('original_filename'),
            'mime_type' => (string) $voice->getAttr('mime_type'),
            'extension' => (string) $voice->getAttr('extension'),
            'file_size' => (int) $voice->getAttr('file_size'),
            'duration_ms' => (int) $voice->getAttr('duration_ms'),
            'status' => (string) $voice->getAttr('status'),
            'codec_name' => is_array($meta) ? (string) ($meta['codec_name'] ?? '') : '',
            'sample_rate' => is_array($meta) ? (int) ($meta['sample_rate'] ?? 0) : 0,
            'channels' => is_array($meta) ? (int) ($meta['channels'] ?? 0) : 0,
            'create_time' => $voice->getAttr('create_time'),
            'update_time' => $voice->getAttr('update_time'),
        ];
    }

    private function isTruthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'on', 'yes'], true);
    }

    private function formatMb(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1024 / 1024, 1, '.', ''), '0'), '.');
    }
}
