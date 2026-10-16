<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\model\Series;
use app\model\StylePreset;
use app\support\RedisCache;
use app\support\SeriesStylePrompt;
use app\support\StylePresetDefaults;
use app\support\StyleParameterRules;
use InvalidArgumentException;
use think\facade\Db;

class StylePresetController extends BaseController
{
    public function index()
    {
        $this->ensureTable();
        $this->seedDefaults();

        $version = RedisCache::version('style_presets');
        $userId = $this->currentUserId();
        $list = RedisCache::remember("style_presets:list:{$this->currentUserCacheSuffix()}:v{$version}", 120, function () use ($userId): array {
            return StylePreset::where('user_id', $userId)
                ->order(['is_system' => 'desc', 'sort' => 'asc', 'id' => 'asc'])
                ->select()
                ->map(fn (StylePreset $preset): array => $this->serialize($preset))
                ->toArray();
        });

        return successCode($list);
    }

    public function save()
    {
        $this->ensureTable();
        $data = $this->normalizePayload($this->request->param());
        $preset = new StylePreset();
        $preset->save($data + [
            'user_id' => $this->currentUserId(),
            'code' => null,
            'is_system' => 0,
            'sort' => ((int) StylePreset::where('user_id', $this->currentUserId())->max('sort')) + 10,
        ]);
        $this->clearCache();

        return successCode($this->serialize($preset), 'success', 201);
    }

    public function update()
    {
        $this->ensureTable();
        $payload = $this->request->param();
        $preset = $this->findOwned((int) ($payload['id'] ?? 0));
        if ((int) $preset->getAttr('is_system') === 1) {
            abort(422, '官方风格不能修改，请复制后再编辑');
        }

        $next = $this->normalizePayload($payload);
        $this->assertVisualStylesStillCoverUsage($preset, $next['visual_styles']);
        $preset->save($next);
        $this->clearCache();

        return successCode($this->serialize($preset));
    }

    public function delete()
    {
        $this->ensureTable();
        $id = (int) ($this->request->param('id') ?? 0);
        if ($id <= 0) {
            abort(422, '风格 id 不能为空');
        }

        $preset = StylePreset::where('id', $id)
            ->where('user_id', $this->currentUserId())
            ->find();
        if ($preset instanceof StylePreset) {
            if ((int) $preset->getAttr('is_system') === 1) {
                abort(422, '官方风格不能删除，请复制后再编辑');
            }
            $used = (int) Series::where('user_id', $this->currentUserId())
                ->where('style_preset_id', $id)
                ->count();
            if ($used > 0) {
                abort(422, "有 {$used} 个作品正在使用这个风格，请先在作品里换掉");
            }
            $preset->delete();
            $this->clearCache();
        }

        return successCode();
    }

    public function duplicate()
    {
        $this->ensureTable();
        $source = $this->findOwned((int) ($this->request->param('id') ?? 0));
        $preset = new StylePreset();
        $preset->save([
            'user_id' => $this->currentUserId(),
            'code' => null,
            'name' => $this->copyName((string) $source->getAttr('name')),
            'summary' => (string) $source->getAttr('summary'),
            'visual_styles' => SeriesStylePrompt::normalizeVisualStyles($source->getAttr('visual_styles')),
            'parameters' => $this->normalizeParameters($source->getAttr('parameters')),
            'technique_prompt' => (string) $source->getAttr('technique_prompt'),
            'prompt' => (string) $source->getAttr('prompt'),
            'lighting_prompt' => (string) $source->getAttr('lighting_prompt'),
            'prompt_mode' => (string) ($source->getAttr('prompt_mode') ?: 'manual'),
            'prompt_extra' => (string) ($source->getAttr('prompt_extra') ?? ''),
            'is_system' => 0,
            'sort' => ((int) StylePreset::where('user_id', $this->currentUserId())->max('sort')) + 10,
        ]);
        $this->clearCache();

        return successCode($this->serialize($preset), 'success', 201);
    }

    private function findOwned(int $id): StylePreset
    {
        if ($id <= 0) {
            abort(422, '风格 id 不能为空');
        }

        $preset = StylePreset::where('id', $id)
            ->where('user_id', $this->currentUserId())
            ->find();
        if (!$preset instanceof StylePreset) {
            abort(404, '风格不存在');
        }

        return $preset;
    }

    public static function ensureTable(): void
    {
        Db::execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS `style_presets` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT 'users.id',
  `code` varchar(64) DEFAULT NULL COMMENT '官方风格稳定键，用户自建为空',
  `name` varchar(120) NOT NULL DEFAULT '' COMMENT '风格名称',
  `summary` varchar(255) NOT NULL DEFAULT '' COMMENT '一句话说明',
  `visual_styles` json DEFAULT NULL COMMENT '适用画风 realistic/anime/3d',
  `parameters` json DEFAULT NULL COMMENT '八组整片质感参数',
  `technique_prompt` mediumtext NOT NULL COMMENT '画法提示词，人物和道具也会使用',
  `prompt` mediumtext NOT NULL COMMENT '整片质感提示词',
  `lighting_prompt` mediumtext NOT NULL COMMENT '可选的片段光影',
  `is_system` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '1=官方默认',
  `sort` int NOT NULL DEFAULT 0,
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_style_presets_user` (`user_id`, `is_system`, `sort`),
  UNIQUE KEY `uniq_style_presets_user_code` (`user_id`, `code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='风格化预设'
SQL);
        self::ensureColumn('style_presets', 'visual_styles', "ALTER TABLE `style_presets` ADD COLUMN `visual_styles` json DEFAULT NULL COMMENT '适用画风 realistic/anime/3d' AFTER `summary`");
        self::ensureColumn('style_presets', 'technique_prompt', "ALTER TABLE `style_presets` ADD COLUMN `technique_prompt` mediumtext NULL COMMENT '画法提示词，人物和道具也会使用' AFTER `parameters`");
        self::ensureColumn('style_presets', 'prompt_mode', "ALTER TABLE `style_presets` ADD COLUMN `prompt_mode` varchar(16) NOT NULL DEFAULT 'manual' COMMENT 'manual/parameters'");
        self::ensureColumn('style_presets', 'prompt_extra', "ALTER TABLE `style_presets` ADD COLUMN `prompt_extra` mediumtext NULL COMMENT '参数模式补充描述'");
    }

    private static function ensureColumn(string $table, string $column, string $sql): void
    {
        $rows = Db::query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        if ($rows === []) {
            Db::execute($sql);
        }
    }

    private function seedDefaults(): void
    {
        if (StylePresetDefaults::syncOfficial($this->currentUserId())) {
            $this->clearCache();
        }
    }

    private function normalizePayload(array $payload): array
    {
        $name = trim((string) ($payload['name'] ?? ''));
        $prompt = trim((string) ($payload['prompt'] ?? ''));
        if ($name === '') {
            abort(422, '风格名称不能为空');
        }
        if (mb_strlen($name) > 120) {
            abort(422, '风格名称不能超过 120 字');
        }
        if (mb_strlen($prompt) > 20000) {
            abort(422, '提示词不能超过 20000 字');
        }

        $summary = trim((string) ($payload['summary'] ?? ''));
        if (mb_strlen($summary) > 255) {
            abort(422, '简介不能超过 255 字');
        }

        $lighting = trim((string) ($payload['lighting_prompt'] ?? ''));
        if (mb_strlen($lighting) > 8000) {
            abort(422, '片段光影不能超过 8000 字');
        }

        $technique = trim((string) ($payload['technique_prompt'] ?? ''));
        if (mb_strlen($technique) > 8000) {
            abort(422, '画法提示词不能超过 8000 字');
        }

        $visualStyles = SeriesStylePrompt::normalizeVisualStyles($payload['visual_styles'] ?? null, false);
        if ($visualStyles === []) {
            abort(422, '请至少选择一个适用画风');
        }
        if (count($visualStyles) !== 1) {
            abort(422, '每条风格只能选择一个主画风，请拆成不同风格保存');
        }

        $parameters = $this->normalizeParameters($payload['parameters'] ?? []);
        $mode = (string) ($payload['prompt_mode'] ?? 'manual');
        if (!in_array($mode, ['manual', 'parameters'], true)) {
            abort(422, '无效的提示词模式');
        }
        $extra = trim((string) ($payload['prompt_extra'] ?? ''));
        if (mb_strlen($extra) > 8000) {
            abort(422, '补充描述不能超过 8000 字');
        }
        if ($mode === 'parameters') {
            try {
                $prompt = StyleParameterRules::compose($parameters, $visualStyles[0]);
                $technique = StyleParameterRules::compose($parameters, $visualStyles[0], 'technique');
            } catch (InvalidArgumentException $error) {
                abort(422, $error->getMessage());
            }
            if ($prompt === '') {
                abort(422, '请至少选择一项整片质感参数');
            }
            $prompt = implode("\n", array_filter([$prompt, $extra], static fn (string $part): bool => $part !== ''));
            $lighting = '';
        }
        if ($prompt === '') {
            abort(422, '提示词不能为空');
        }

        return [
            'name' => $name,
            'summary' => $summary,
            'visual_styles' => $visualStyles,
            'parameters' => $parameters,
            'technique_prompt' => $technique,
            'prompt' => $prompt,
            'lighting_prompt' => $lighting,
            'prompt_mode' => $mode,
            'prompt_extra' => $extra,
        ];
    }

    /** @param list<string> $nextStyles */
    private function assertVisualStylesStillCoverUsage(StylePreset $preset, array $nextStyles): void
    {
        $current = SeriesStylePrompt::normalizeVisualStyles($preset->getAttr('visual_styles'));
        $removed = array_values(array_diff($current, $nextStyles));
        if ($removed === []) {
            return;
        }

        $used = Series::where('user_id', $this->currentUserId())
            ->where('style_preset_id', (int) $preset->getAttr('id'))
            ->whereIn('visual_style', $removed)
            ->column('visual_style');
        $used = array_values(array_unique(array_map(static fn ($style): string => (string) $style, $used)));
        if ($used === []) {
            return;
        }

        $labels = implode('、', array_map(
            static fn (string $style): string => SeriesStylePrompt::visualStyleLabel($style),
            $used
        ));
        abort(422, "有作品正在以{$labels}使用这个风格，不能取消{$labels}");
    }

    private function normalizeParameters(mixed $value): array
    {
        return StyleParameterRules::normalize($value);
    }

    private function copyName(string $name): string
    {
        $base = trim($name) !== '' ? trim($name) : '未命名风格';
        $suffix = ' 副本';
        $limit = 120 - mb_strlen($suffix);
        if (mb_strlen($base) > $limit) {
            $base = rtrim(mb_substr($base, 0, $limit));
        }

        return $base . $suffix;
    }

    private function serialize(StylePreset $preset): array
    {
        return [
            'id' => (int) $preset->getAttr('id'),
            'code' => (string) ($preset->getAttr('code') ?: ''),
            'name' => (string) $preset->getAttr('name'),
            'summary' => (string) $preset->getAttr('summary'),
            'visual_styles' => SeriesStylePrompt::normalizeVisualStyles($preset->getAttr('visual_styles')),
            'parameters' => $this->normalizeParameters($preset->getAttr('parameters')),
            'technique_prompt' => (string) $preset->getAttr('technique_prompt'),
            'prompt' => (string) $preset->getAttr('prompt'),
            'lighting_prompt' => (string) $preset->getAttr('lighting_prompt'),
            'prompt_mode' => (string) ($preset->getAttr('prompt_mode') ?: 'manual'),
            'prompt_extra' => (string) ($preset->getAttr('prompt_extra') ?? ''),
            'is_system' => (int) $preset->getAttr('is_system'),
            'sort' => (int) $preset->getAttr('sort'),
            'create_time' => $preset->getAttr('create_time'),
            'update_time' => $preset->getAttr('update_time'),
        ];
    }

    private function clearCache(): void
    {
        RedisCache::bumpVersion('style_presets');
    }
}
