<?php

declare(strict_types=1);

namespace app\support;

use think\facade\Db;

class VoiceAssetService
{
    public function ensureSchema(): void
    {
        Db::execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS `voice_assets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'users.id',
  `asset_id` int unsigned NOT NULL DEFAULT '0' COMMENT '角色 assets.id',
  `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `source_url` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `original_filename` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `mime_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `extension` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `file_size` bigint unsigned NOT NULL DEFAULT '0',
  `duration_ms` int unsigned NOT NULL DEFAULT '0',
  `sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ready',
  `rights_confirmed` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `provider_meta_json` json DEFAULT NULL,
  `error_message` varchar(2000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `deleted_at` datetime DEFAULT NULL,
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_voice_assets_character_status` (`asset_id`,`status`,`id`),
  KEY `idx_voice_assets_user_status` (`user_id`,`status`,`id`),
  KEY `idx_voice_assets_user_hash` (`user_id`,`sha256`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户音色资产';
SQL);

        if (!$this->columnExists('voice_assets', 'asset_id')) {
            Db::execute("ALTER TABLE `voice_assets` ADD COLUMN `asset_id` int unsigned NOT NULL DEFAULT 0 COMMENT '角色 assets.id' AFTER `user_id`");
        }
        if (!$this->indexExists('voice_assets', 'idx_voice_assets_character_status')) {
            Db::execute('ALTER TABLE `voice_assets` ADD INDEX `idx_voice_assets_character_status` (`asset_id`,`status`,`id`)');
        }

        Db::execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS `voice_asset_bindings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `voice_asset_id` bigint unsigned NOT NULL DEFAULT '0',
  `model_config_id` int unsigned NOT NULL DEFAULT '0',
  `provider` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `provider_asset_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `provider_payload_json` json DEFAULT NULL,
  `error_message` varchar(2000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_voice_binding_model` (`voice_asset_id`,`model_config_id`),
  KEY `idx_voice_binding_status` (`model_config_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='音色资产供应商绑定';
SQL);
    }

    /**
     * @return array{duration_ms:int,codec_name:string,sample_rate:int,channels:int}
     */
    public function inspectAudio(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException('上传的音频文件不存在');
        }

        $binary = trim((string) config('voice_asset.ffprobe_binary', 'ffprobe')) ?: 'ffprobe';
        if (!function_exists('exec')) {
            throw new \RuntimeException('服务器未启用音频分析能力，请检查 PHP exec 配置');
        }
        $command = escapeshellarg($binary)
            . ' -v error -select_streams a:0'
            . ' -show_entries stream=codec_name,sample_rate,channels:format=duration'
            . ' -of json ' . escapeshellarg($path) . ' 2>&1';
        $lines = [];
        $exitCode = 0;
        exec($command, $lines, $exitCode);
        if ($exitCode !== 0) {
            throw new \RuntimeException('无法读取音频，请确认 FFprobe 已安装且文件未损坏');
        }

        return self::parseProbeJson(implode("\n", $lines));
    }

    /**
     * @return array{duration_ms:int,codec_name:string,sample_rate:int,channels:int}
     */
    public static function parseProbeJson(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('音频分析结果无效');
        }
        $stream = is_array($decoded['streams'][0] ?? null) ? $decoded['streams'][0] : [];
        $duration = (float) ($decoded['format']['duration'] ?? 0);
        if ($stream === [] || $duration <= 0) {
            throw new \RuntimeException('文件中没有可用的音频轨道');
        }

        return [
            'duration_ms' => max(1, (int) round($duration * 1000)),
            'codec_name' => trim((string) ($stream['codec_name'] ?? '')),
            'sample_rate' => max(0, (int) ($stream['sample_rate'] ?? 0)),
            'channels' => max(0, (int) ($stream['channels'] ?? 0)),
        ];
    }

    private function columnExists(string $table, string $column): bool
    {
        return (int) Db::query(
            'SELECT COUNT(*) AS aggregate FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        )[0]['aggregate'] > 0;
    }

    private function indexExists(string $table, string $index): bool
    {
        return (int) Db::query(
            'SELECT COUNT(*) AS aggregate FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $index]
        )[0]['aggregate'] > 0;
    }
}
