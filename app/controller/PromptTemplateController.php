<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\model\PromptTemplate;
use app\support\RedisCache;
use think\facade\Db;

class PromptTemplateController extends BaseController
{
    public function index()
    {
        $this->ensureTable();
        $this->seedDefaults();

        $version = RedisCache::version('prompt_templates');
        $userId = $this->currentUserId();
        $list = RedisCache::remember("prompt_templates:list:{$this->currentUserCacheSuffix()}:v{$version}", 120, function () use ($userId): array {
            return PromptTemplate::where('user_id', $userId)
                ->order(['is_system' => 'desc', 'scope' => 'asc', 'node_kind' => 'asc', 'sort' => 'asc', 'id' => 'asc'])
                ->select()
                ->map(fn (PromptTemplate $template): array => $this->serialize($template))
                ->toArray();
        });

        return successCode($list);
    }

    public function save()
    {
        $this->ensureTable();
        $payload = $this->request->param();
        $data = $this->normalizePayload($payload);

        $template = new PromptTemplate();
        $template->save($data + [
            'user_id' => $this->currentUserId(),
            'is_system' => 0,
            'sort' => ((int) PromptTemplate::where('user_id', $this->currentUserId())->max('sort')) + 10,
        ]);
        $this->clearCache();

        return successCode($this->serialize($template), 'success', 201);
    }

    public function update()
    {
        $this->ensureTable();
        $payload = $this->request->param();
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            abort(422, '提示词模板 id 不能为空');
        }

        $template = PromptTemplate::where('id', $id)
            ->where('user_id', $this->currentUserId())
            ->find();
        if (!$template instanceof PromptTemplate) {
            abort(404, '提示词模板不存在');
        }
        if ((int) $template->getAttr('is_system') === 1) {
            abort(422, '系统默认提示词不支持编辑');
        }

        $template->save($this->normalizePayload($payload));
        $this->clearCache();

        return successCode($this->serialize($template));
    }

    public function delete()
    {
        $this->ensureTable();
        $payload = $this->request->param();
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            abort(422, '提示词模板 id 不能为空');
        }

        $template = PromptTemplate::where('id', $id)
            ->where('user_id', $this->currentUserId())
            ->find();
        if ($template instanceof PromptTemplate) {
            if ((int) $template->getAttr('is_system') === 1) {
                abort(422, '系统默认提示词不支持删除');
            }
            $template->delete();
            $this->clearCache();
        }

        return successCode();
    }

    private function ensureTable(): void
    {
        Db::execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS `prompt_templates` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT 1 COMMENT 'users.id',
  `title` varchar(120) NOT NULL DEFAULT '' COMMENT '模板名称',
  `scope` varchar(16) NOT NULL DEFAULT 'episode' COMMENT 'episode|series|all',
  `node_kind` varchar(24) NOT NULL DEFAULT 'text' COMMENT 'text|image|video|voice|input|output|all',
  `node_label` varchar(120) NOT NULL DEFAULT '' COMMENT '推荐匹配的节点名称，空表示通用',
  `description` varchar(255) NOT NULL DEFAULT '' COMMENT '用途说明',
  `prompt` mediumtext NOT NULL COMMENT '提示词正文',
  `tags` json DEFAULT NULL COMMENT '标签',
  `is_system` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '系统内置模板',
  `sort` int NOT NULL DEFAULT 0,
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_prompt_templates_user_id` (`user_id`),
  KEY `idx_prompt_templates_scope_kind` (`scope`, `node_kind`),
  KEY `idx_prompt_templates_label` (`node_label`),
  KEY `idx_prompt_templates_system` (`is_system`, `sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='工作流提示词模板库'
SQL);
    }

    private function seedDefaults(): void
    {
        $this->moveCallSheetTemplateToSeries();

        $defaults = $this->defaultTemplates();
        $defaultKeys = array_map(fn (array $item): string => $this->templateKey($item), $defaults);
        $existingSystemTemplates = PromptTemplate::where('is_system', 1)
            ->where('user_id', $this->currentUserId())
            ->select();
        foreach ($existingSystemTemplates as $existingSystemTemplate) {
            if (!$existingSystemTemplate instanceof PromptTemplate) {
                continue;
            }
            if (!in_array($this->templateKey($existingSystemTemplate->toArray()), $defaultKeys, true)) {
                $existingSystemTemplate->delete();
                $this->clearCache();
            }
        }

        foreach ($defaults as $item) {
            $existingTemplates = PromptTemplate::where('is_system', 1)
                ->where('user_id', $this->currentUserId())
                ->where('scope', $item['scope'])
                ->where('node_kind', $item['node_kind'])
                ->where('node_label', $item['node_label'])
                ->where('title', $item['title'])
                ->select();
            if (count($existingTemplates) > 0) {
                foreach ($existingTemplates as $exists) {
                    if (!$exists instanceof PromptTemplate) {
                        continue;
                    }
                    $exists->save([
                        'description' => $item['description'],
                        'prompt' => $item['prompt'],
                        'tags' => $item['tags'],
                        'sort' => $item['sort'],
                    ]);
                }
                $this->clearCache();
                continue;
            }

            PromptTemplate::create($item + ['user_id' => $this->currentUserId(), 'is_system' => 1]);
            $this->clearCache();
        }
    }

    private function moveCallSheetTemplateToSeries(): void
    {
        $episodeTemplate = PromptTemplate::where('is_system', 1)
            ->where('user_id', $this->currentUserId())
            ->where('scope', 'episode')
            ->where('node_label', '通告单生成')
            ->find();
        if (!$episodeTemplate instanceof PromptTemplate) {
            return;
        }

        $seriesTemplate = PromptTemplate::where('is_system', 1)
            ->where('user_id', $this->currentUserId())
            ->where('scope', 'series')
            ->where('node_label', '通告单生成')
            ->find();
        if ($seriesTemplate instanceof PromptTemplate) {
            $episodeTemplate->delete();
        } else {
            $episodeTemplate->save([
                'scope' => 'series',
                'description' => '根据整剧拆解与分集规划生成拍摄通告单 HTML。',
            ]);
        }
        $this->clearCache();
    }

    private function defaultTemplates(): array
    {
        return array_merge(
            $this->templatesFromGraph('series', \app\support\DefaultWorkflowGraphs::seriesFull(), 10),
            $this->templatesFromGraph('episode', \app\support\DefaultWorkflowGraphs::episode(), 100)
        );

    }

    private function templateKey(array $item): string
    {
        return implode("\n", [
            (string) ($item['scope'] ?? ''),
            (string) ($item['node_kind'] ?? ''),
            (string) ($item['node_label'] ?? ''),
            (string) ($item['title'] ?? ''),
        ]);
    }

    private function normalizePayload(array $payload): array
    {
        $title = trim((string) ($payload['title'] ?? ''));
        $prompt = trim((string) ($payload['prompt'] ?? ''));
        if ($title === '') {
            abort(422, '提示词名称不能为空');
        }
        if ($prompt === '') {
            abort(422, '提示词内容不能为空');
        }

        return [
            'title' => $title,
            'scope' => $this->normalizeScope((string) ($payload['scope'] ?? 'episode')),
            'node_kind' => $this->normalizeKind((string) ($payload['node_kind'] ?? 'text')),
            'node_label' => trim((string) ($payload['node_label'] ?? '')),
            'description' => trim((string) ($payload['description'] ?? '')),
            'prompt' => $prompt,
            'tags' => $this->normalizeTags($payload['tags'] ?? []),
        ];
    }

    private function normalizeScope(string $scope): string
    {
        return in_array($scope, ['episode', 'series', 'all'], true) ? $scope : 'episode';
    }

    private function normalizeKind(string $kind): string
    {
        return in_array($kind, ['input', 'text', 'image', 'video', 'voice', 'output', 'all'], true) ? $kind : 'text';
    }

    private function normalizeTags(mixed $tags): array
    {
        if (is_string($tags)) {
            $tags = array_filter(array_map('trim', explode(',', $tags)));
        }
        if (!is_array($tags)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn ($tag): string => trim((string) $tag), $tags)));
    }

    private function serialize(PromptTemplate $template): array
    {
        $isSystem = (int) $template->getAttr('is_system');

        return [
            'id' => (int) $template->getAttr('id'),
            'title' => (string) $template->getAttr('title'),
            'scope' => (string) $template->getAttr('scope'),
            'node_kind' => (string) $template->getAttr('node_kind'),
            'node_label' => (string) $template->getAttr('node_label'),
            'description' => (string) $template->getAttr('description'),
            'prompt' => (string) $template->getAttr('prompt'),
            'tags' => $template->getAttr('tags') ?: [],
            'is_system' => $isSystem,
            'sort' => (int) $template->getAttr('sort'),
            'create_time' => $template->getAttr('create_time'),
            'update_time' => $template->getAttr('update_time'),
        ];
    }

    private function templatesFromGraph(string $scope, array $graph, int $baseSort): array
    {
        $items = [];
        foreach (($graph['nodes'] ?? []) as $node) {
            if (!is_array($node)) {
                continue;
            }
            $label = trim((string) ($node['label'] ?? ''));
            $data = $node['data'] ?? [];
            $params = is_array($data) ? ($data['params'] ?? []) : [];
            if ($label === '' || !is_array($params)) {
                continue;
            }

            $kind = (string) ($data['kind'] ?? 'text');
            $prompt = (string) ($params['prompt'] ?? '');
            if ($prompt === '' && $kind === 'video') {
                $prompt = (string) ($params['video_style_prompt'] ?? '');
            }

            $items[] = [
                'title' => $label,
                'scope' => $scope,
                'node_kind' => $kind,
                'node_label' => $label,
                'description' => (string) ($data['desc'] ?? ''),
                'prompt' => $prompt,
                'tags' => ['官方流程节点'],
                'sort' => $baseSort + count($items) * 10,
            ];
        }

        return $items;
    }

    private function clearCache(): void
    {
        RedisCache::bumpVersion('prompt_templates');
    }
}
