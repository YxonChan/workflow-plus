<?php

declare(strict_types=1);

namespace app\support;

use app\model\AiRequestLog;
use app\model\ModelConfig;
use think\facade\Db;
use think\facade\Log;

class ScriptCreationService
{
    private const CONFIGS = [
        [
            'key' => 'default',
            'name' => '默认配置',
            'description' => '所有角色 AI 的基础配置。角色未单独指定模型或参数时自动继承这里。',
            'sort' => 0,
            'temperature' => 0.70,
            'max_tokens' => 65536,
            'system_prompt' => '你是专业的影视剧本创作系统。所有交付必须围绕用户提供的题材、故事梗概和创作要求展开，保持人物、世界观、时间线与前序产物一致。只输出当前步骤需要的交付内容，不解释工作过程，不虚构未提供的硬性事实。除审校按集模式外，统一使用标记块输出：先写 <<<DELIVERABLE>>> 完整交付，再写 <<<HANDOFF>>> 给下一步的精简交接包。',
            'task_prompt' => "项目名称：{{title}}\n题材：{{genre}}\n输出语言：{{output_language}}\n地区风格：{{region_style}}\n故事梗概：\n{{synopsis}}\n\n创作要求：\n{{requirements}}\n\n上一步交接包：\n{{previous_handoff}}\n\n请根据当前角色的具体职责继续完成创作，并按要求输出 DELIVERABLE 与 HANDOFF。",
        ],
        [
            'key' => 'planner',
            'name' => '统筹策划 AI',
            'description' => '理解用户目标，建立故事结构、人物关系、冲突与交付约束。',
            'sort' => 10,
            'temperature' => null,
            'max_tokens' => null,
            'system_prompt' => '你是剧本项目的统筹策划 AI。你的职责是把模糊需求转化为后续 AI 可直接执行的创作蓝图，并主动识别逻辑缺口与冲突。HANDOFF 必须短于完整蓝图，只保留主题、人物动机、冲突、分段结构、关键转折与硬约束。',
            'task_prompt' => '输出创作蓝图到 <<<DELIVERABLE>>>：至少包含核心主题、类型定位、受众、主要人物与动机、核心冲突、三幕或分段结构、关键转折、结局方向、必须遵守的创作约束；不要直接写完整剧本。再输出 <<<HANDOFF>>>：给导演的压缩执行摘要（建议 800-1500 字）。',
        ],
        [
            'key' => 'director',
            'name' => '导演 AI',
            'description' => '把策划蓝图转化为节奏、场面调度、视听方向和叙事执行方案。',
            'sort' => 20,
            'temperature' => null,
            'max_tokens' => null,
            'system_prompt' => '你是影视导演 AI。你的职责是依据统筹交接包确定叙事节奏、场景组织、人物调度、情绪曲线和视听表达，让编剧可以据此直接落笔。HANDOFF 只保留编剧落笔必需信息，避免复述全文。',
            'task_prompt' => '基于上一步交接包输出导演方案到 <<<DELIVERABLE>>>：整体风格、节奏设计、分场结构、每场目标与冲突、人物调度、情绪推进、关键视觉与声音提示、编剧必须落实事项。再输出 <<<HANDOFF>>>：给编剧的压缩执行要点（建议 1000-2000 字，含分集/分场目标清单）。',
        ],
        [
            'key' => 'writer',
            'name' => '编剧 AI',
            'description' => '依据策划与导演方案生成可拍摄的完整剧本初稿。',
            'sort' => 30,
            'temperature' => null,
            'max_tokens' => 65536,
            'system_prompt' => '你是专业影视编剧 AI。你的职责是把导演交接包写成可直接进入制作环节的完整剧本，确保对白自然、动作可执行、人物行为符合动机。HANDOFF 只能是结构摘要与风险点，不得再贴整本剧本。',
            'task_prompt' => '依据上一步交接包完成完整剧本初稿到 <<<DELIVERABLE>>>。严格使用标准剧本文本结构：集标题、内/外景场景标题、地点与时间、动作描述、角色名、必要的表演提示、对白及转场；保持剧情连续，落实所有关键转折，不要省略为提纲。再输出 <<<HANDOFF>>>：分集标题列表、每集 5-8 句剧情摘要、人物关系提醒、连续性风险点。',
        ],
        [
            'key' => 'reviewer',
            'name' => '审校 AI',
            'description' => '自动检查并修订剧本，输出最终可用版本。',
            'sort' => 40,
            'temperature' => null,
            'max_tokens' => 65536,
            'system_prompt' => '你是资深剧本审校 AI。你不是给人类写评语，而是直接修复结构、人物、对白、节奏、连续性和可拍摄性问题。若系统按集提供正文，只修订当前集并输出该集完整正文；不要输出其他集，不要输出评分表或修改说明。',
            'task_prompt' => "全局交接摘要：\n{{previous_handoff}}\n\n请审校并修订下面这一集剧本正文，只输出修订后的该集完整正文：\n{{episode_body}}",
        ],
    ];

    private const STEPS = [
        ['key' => 'planning', 'name' => '创作蓝图', 'agent' => 'planner', 'sort' => 10],
        ['key' => 'direction', 'name' => '导演方案', 'agent' => 'director', 'sort' => 20],
        ['key' => 'drafting', 'name' => '剧本初稿', 'agent' => 'writer', 'sort' => 30],
        ['key' => 'reviewing', 'name' => '审校定稿', 'agent' => 'reviewer', 'sort' => 40],
    ];

    private static bool $schemaReady = false;

    public static function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }

        Db::execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS `script_ai_configs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `config_key` varchar(32) NOT NULL DEFAULT '',
  `name` varchar(80) NOT NULL DEFAULT '',
  `description` varchar(255) NOT NULL DEFAULT '',
  `model_config_id` int unsigned NOT NULL DEFAULT 0,
  `system_prompt` longtext,
  `task_prompt` longtext,
  `temperature` decimal(4,2) DEFAULT NULL,
  `max_tokens` int unsigned DEFAULT NULL,
  `enabled` tinyint unsigned NOT NULL DEFAULT 1,
  `sort` int unsigned NOT NULL DEFAULT 0,
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_script_ai_config_user_key` (`user_id`,`config_key`),
  KEY `idx_script_ai_config_user_sort` (`user_id`,`sort`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='剧本创作 AI 默认与角色配置'
SQL);

        Db::execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS `script_ai_projects` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `title` varchar(180) NOT NULL DEFAULT '',
  `genre` varchar(80) NOT NULL DEFAULT '',
  `output_language` varchar(16) NOT NULL DEFAULT 'zh-CN',
  `region_style` varchar(16) NOT NULL DEFAULT 'mainland',
  `synopsis` text,
  `requirements` text,
  `status` varchar(24) NOT NULL DEFAULT 'draft',
  `current_step_key` varchar(32) NOT NULL DEFAULT 'planning',
  `waiting_message` varchar(255) NOT NULL DEFAULT '',
  `pause_requested` tinyint unsigned NOT NULL DEFAULT 0,
  `run_no` int unsigned NOT NULL DEFAULT 0,
  `final_content` longtext,
  `error_message` text,
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_script_ai_project_user_status` (`user_id`,`status`,`id`),
  KEY `idx_script_ai_project_worker` (`status`,`pause_requested`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='AI 驱动剧本创作项目'
SQL);

        Db::execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS `script_ai_steps` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint unsigned NOT NULL,
  `step_key` varchar(32) NOT NULL DEFAULT '',
  `step_name` varchar(80) NOT NULL DEFAULT '',
  `agent_config_key` varchar(32) NOT NULL DEFAULT '',
  `agent_name` varchar(80) NOT NULL DEFAULT '',
  `sort` int unsigned NOT NULL DEFAULT 0,
  `run_no` int unsigned NOT NULL DEFAULT 0,
  `status` varchar(24) NOT NULL DEFAULT 'pending',
  `attempts` int unsigned NOT NULL DEFAULT 0,
  `model_config_id` int unsigned NOT NULL DEFAULT 0,
  `system_prompt_snapshot` longtext,
  `task_prompt_snapshot` longtext,
  `input_content` longtext,
  `output_content` longtext,
  `handoff_content` longtext,
  `error_message` text,
  `duration_ms` int unsigned NOT NULL DEFAULT 0,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_script_ai_step` (`project_id`,`step_key`),
  KEY `idx_script_ai_step_project_sort` (`project_id`,`sort`,`id`),
  KEY `idx_script_ai_step_worker` (`status`,`project_id`,`sort`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='AI 剧本创作运行步骤'
SQL);

        self::addColumnIfMissing('script_ai_steps', 'run_no', "int unsigned NOT NULL DEFAULT 0 AFTER `sort`");
        self::addColumnIfMissing('script_ai_steps', 'handoff_content', "longtext NULL AFTER `output_content`");
        self::addColumnIfMissing('script_ai_projects', 'output_language', "varchar(16) NOT NULL DEFAULT 'zh-CN' AFTER `genre`");
        self::addColumnIfMissing('script_ai_projects', 'region_style', "varchar(16) NOT NULL DEFAULT 'mainland' AFTER `output_language`");
        Db::name('script_ai_projects')->where('output_language', '<>', 'zh-CN')->update(['output_language' => 'zh-CN']);

        self::$schemaReady = true;
    }

    public static function configs(int $userId): array
    {
        self::ensureSchema();
        self::ensureDefaultConfigs($userId);

        return array_map(
            static fn (array $row): array => self::publicConfig($row),
            Db::name('script_ai_configs')->where('user_id', $userId)->order(['sort' => 'asc', 'id' => 'asc'])->select()->toArray()
        );
    }

    public static function textModels(int $userId): array
    {
        self::ensureSchema();
        $models = ModelConfigResolver::visibleModelsQuery($userId)
            ->where('type', 'text')
            ->select();

        $result = [];
        foreach ($models as $model) {
            if (!$model instanceof ModelConfig) {
                continue;
            }
            $result[] = [
                'id' => (int) $model->getAttr('id'),
                'name' => (string) $model->getAttr('name'),
                'model_id' => (string) $model->getAttr('model_id'),
                'scope' => (string) ($model->getAttr('scope') ?: 'global'),
                'is_default' => (int) $model->getAttr('is_default'),
            ];
        }
        return $result;
    }

    public static function saveConfigs(array $payload, int $userId): array
    {
        self::ensureSchema();
        self::ensureDefaultConfigs($userId);
        $items = $payload['configs'] ?? null;
        if (!is_array($items) || $items === []) {
            abort(422, '请提交 AI 配置');
        }

        $allowed = array_column(self::CONFIGS, 'key');
        Db::transaction(function () use ($items, $allowed, $userId): void {
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $key = trim((string) ($item['config_key'] ?? ''));
                if (!in_array($key, $allowed, true)) {
                    abort(422, '包含未知 AI 配置：' . $key);
                }

                $modelConfigId = max(0, (int) ($item['model_config_id'] ?? 0));
                if ($modelConfigId > 0 && !ModelConfigResolver::resolve('text', $userId, $modelConfigId) instanceof ModelConfig) {
                    abort(422, '所选文本模型不可用');
                }

                $isDefault = $key === 'default';
                $temperature = self::nullableFloat($item['temperature'] ?? null, $isDefault ? 0.70 : null, 0, 2);
                $maxTokens = self::nullableInt($item['max_tokens'] ?? null, $isDefault ? 65536 : null, 512, 65536);
                $now = date('Y-m-d H:i:s');
                Db::name('script_ai_configs')
                    ->where('user_id', $userId)
                    ->where('config_key', $key)
                    ->update([
                        'model_config_id' => $modelConfigId,
                        'system_prompt' => mb_substr(trim((string) ($item['system_prompt'] ?? '')), 0, 30000),
                        'task_prompt' => mb_substr(trim((string) ($item['task_prompt'] ?? '')), 0, 30000),
                        'temperature' => $temperature,
                        'max_tokens' => $maxTokens,
                        'enabled' => $isDefault ? 1 : ((int) ($item['enabled'] ?? 1) ? 1 : 0),
                        'update_time' => $now,
                    ]);
            }
        });

        return self::configs($userId);
    }

    public static function projects(int $userId): array
    {
        self::ensureSchema();
        $rows = Db::name('script_ai_projects')
            ->where('user_id', $userId)
            ->field('id,user_id,title,genre,output_language,region_style,synopsis,requirements,status,current_step_key,waiting_message,pause_requested,run_no,error_message,started_at,finished_at,create_time,update_time')
            ->order(['update_time' => 'desc', 'id' => 'desc'])
            ->select()
            ->toArray();

        return array_map(static fn (array $row): array => self::projectSummary($row), $rows);
    }

    public static function detail(int $projectId, int $userId): array
    {
        self::ensureSchema();
        $project = self::visibleProject($projectId, $userId);
        $project = self::projectSummary($project);
        $project['steps'] = array_map(
            static fn (array $row): array => self::publicStep($row),
            Db::name('script_ai_steps')->where('project_id', $projectId)->order(['sort' => 'asc', 'id' => 'asc'])->select()->toArray()
        );
        $project['score_history'] = ScriptExternalScoringService::scoreHistory($projectId);
        return $project;
    }

    public static function create(array $payload, int $userId): int
    {
        self::ensureSchema();
        self::ensureDefaultConfigs($userId);
        $title = mb_substr(trim((string) ($payload['title'] ?? '')), 0, 180);
        if ($title === '') {
            abort(422, '剧本项目名称不能为空');
        }

        return Db::transaction(function () use ($payload, $userId, $title): int {
            $now = date('Y-m-d H:i:s');
            $projectId = (int) Db::name('script_ai_projects')->insertGetId([
                'user_id' => $userId,
                'title' => $title,
                'genre' => mb_substr(trim((string) ($payload['genre'] ?? '')), 0, 80),
                'output_language' => self::normalizeOutputLanguage($payload['output_language'] ?? null),
                'region_style' => self::normalizeRegionStyle($payload['region_style'] ?? null),
                'synopsis' => trim((string) ($payload['synopsis'] ?? '')),
                'requirements' => trim((string) ($payload['requirements'] ?? '')),
                'status' => 'draft',
                'current_step_key' => self::STEPS[0]['key'],
                'waiting_message' => '等待启动 AI 创作流程',
                'create_time' => $now,
                'update_time' => $now,
            ]);

            $configs = self::configMap($userId);
            $rows = [];
            foreach (self::STEPS as $step) {
                $config = $configs[$step['agent']] ?? [];
                $rows[] = [
                    'project_id' => $projectId,
                    'step_key' => $step['key'],
                    'step_name' => $step['name'],
                    'agent_config_key' => $step['agent'],
                    'agent_name' => (string) ($config['name'] ?? $step['agent']),
                    'sort' => $step['sort'],
                    'run_no' => 0,
                    'status' => 'pending',
                    'create_time' => $now,
                    'update_time' => $now,
                ];
            }
            Db::name('script_ai_steps')->insertAll($rows);
            return $projectId;
        });
    }

    public static function updateProject(int $projectId, array $payload, int $userId): void
    {
        self::ensureSchema();
        $project = self::visibleProject($projectId, $userId);
        if (in_array((string) $project['status'], ['queued', 'running', 'paused'], true)) {
            abort(409, '流程运行期间不能修改创作需求');
        }
        $title = mb_substr(trim((string) ($payload['title'] ?? $project['title'])), 0, 180);
        if ($title === '') {
            abort(422, '剧本项目名称不能为空');
        }
        Db::name('script_ai_projects')->where('id', $projectId)->update([
            'title' => $title,
            'genre' => mb_substr(trim((string) ($payload['genre'] ?? $project['genre'])), 0, 80),
            'output_language' => self::normalizeOutputLanguage($payload['output_language'] ?? $project['output_language']),
            'region_style' => self::normalizeRegionStyle($payload['region_style'] ?? $project['region_style']),
            'synopsis' => trim((string) ($payload['synopsis'] ?? $project['synopsis'])),
            'requirements' => trim((string) ($payload['requirements'] ?? $project['requirements'])),
            'update_time' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function start(int $projectId, int $userId): void
    {
        self::ensureSchema();
        self::visibleProject($projectId, $userId);
        self::ensureDefaultConfigs($userId);
        $configs = self::configMap($userId);
        $default = $configs['default'] ?? [];
        $defaultModelId = (int) ($default['model_config_id'] ?? 0);
        if (!ModelConfigResolver::resolve('text', $userId, $defaultModelId) instanceof ModelConfig) {
            abort(422, '没有可用的文本模型，请先完成默认 AI 配置');
        }

        Db::transaction(function () use ($projectId, $configs): void {
            $project = Db::name('script_ai_projects')->where('id', $projectId)->lock(true)->find();
            if (!is_array($project)) {
                abort(404, '剧本项目不存在');
            }
            if (in_array((string) $project['status'], ['queued', 'running', 'paused'], true)) {
                abort(409, '当前流程已经启动');
            }

            $now = date('Y-m-d H:i:s');
            $nextRunNo = (int) $project['run_no'] + 1;
            $first = null;
            foreach (self::STEPS as $step) {
                $enabled = (int) ($configs[$step['agent']]['enabled'] ?? 1) === 1;
                $status = $enabled ? 'pending' : 'skipped';
                if ($enabled && $first === null) {
                    $first = $step;
                    $status = 'queued';
                }
                Db::name('script_ai_steps')
                    ->where('project_id', $projectId)
                    ->where('step_key', $step['key'])
                    ->update([
                        'agent_name' => (string) ($configs[$step['agent']]['name'] ?? $step['agent']),
                        'run_no' => $nextRunNo,
                        'status' => $status,
                        'attempts' => 0,
                        'model_config_id' => 0,
                        'system_prompt_snapshot' => '',
                        'task_prompt_snapshot' => '',
                        'input_content' => '',
                        'output_content' => '',
                        'handoff_content' => '',
                        'error_message' => '',
                        'duration_ms' => 0,
                        'started_at' => null,
                        'completed_at' => null,
                        'update_time' => $now,
                    ]);
            }
            if ($first === null) {
                abort(422, '至少需要启用一个角色 AI');
            }

            $agentName = (string) ($configs[$first['agent']]['name'] ?? $first['agent']);
            Db::name('script_ai_projects')->where('id', $projectId)->update([
                'status' => 'queued',
                'current_step_key' => $first['key'],
                'waiting_message' => '等待 ' . $agentName . ' 领取任务',
                'pause_requested' => 0,
                'run_no' => $nextRunNo,
                'final_content' => '',
                'error_message' => '',
                'started_at' => $now,
                'finished_at' => null,
                'update_time' => $now,
            ]);
        });
    }

    public static function pause(int $projectId, int $userId): void
    {
        self::ensureSchema();
        $project = self::visibleProject($projectId, $userId);
        $status = (string) $project['status'];
        if (!in_array($status, ['queued', 'running'], true)) {
            abort(409, '当前流程不能暂停');
        }
        $running = Db::name('script_ai_steps')->where('project_id', $projectId)->where('status', 'running')->count() > 0;
        Db::name('script_ai_projects')->where('id', $projectId)->update([
            'status' => $running ? 'running' : 'paused',
            'pause_requested' => $running ? 1 : 0,
            'waiting_message' => $running ? '正在等待当前 AI 返回，随后暂停流程' : '流程已暂停，等待继续操作',
            'update_time' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function resume(int $projectId, int $userId): void
    {
        self::ensureSchema();
        $project = self::visibleProject($projectId, $userId);
        if ((string) $project['status'] !== 'paused') {
            abort(409, '当前流程不处于暂停状态');
        }

        Db::transaction(function () use ($projectId): void {
            $step = Db::name('script_ai_steps')
                ->where('project_id', $projectId)
                ->whereIn('status', ['queued', 'pending'])
                ->order(['sort' => 'asc', 'id' => 'asc'])
                ->lock(true)
                ->find();
            if (!is_array($step)) {
                abort(409, '没有可继续的 AI 步骤');
            }
            $now = date('Y-m-d H:i:s');
            Db::name('script_ai_steps')->where('id', (int) $step['id'])->update(['status' => 'queued', 'update_time' => $now]);
            Db::name('script_ai_projects')->where('id', $projectId)->update([
                'status' => 'queued',
                'pause_requested' => 0,
                'current_step_key' => (string) $step['step_key'],
                'waiting_message' => '等待 ' . (string) $step['agent_name'] . ' 领取任务',
                'update_time' => $now,
            ]);
        });
    }

    public static function retry(int $projectId, int $userId): void
    {
        self::ensureSchema();
        $project = self::visibleProject($projectId, $userId);
        if ((string) $project['status'] !== 'failed') {
            abort(409, '只有失败的流程可以重试');
        }

        Db::transaction(function () use ($projectId): void {
            $failed = Db::name('script_ai_steps')->where('project_id', $projectId)->where('status', 'failed')->order('sort', 'asc')->lock(true)->find();
            if (!is_array($failed)) {
                abort(409, '没有可重试的失败步骤');
            }
            $now = date('Y-m-d H:i:s');
            Db::name('script_ai_steps')->where('project_id', $projectId)->where('sort', '>', (int) $failed['sort'])->where('status', '<>', 'skipped')->update([
                'status' => 'pending',
                'output_content' => '',
                'handoff_content' => '',
                'error_message' => '',
                'started_at' => null,
                'completed_at' => null,
                'update_time' => $now,
            ]);
            Db::name('script_ai_steps')->where('id', (int) $failed['id'])->update([
                'status' => 'queued',
                'output_content' => '',
                'handoff_content' => '',
                'error_message' => '',
                'started_at' => null,
                'completed_at' => null,
                'update_time' => $now,
            ]);
            Db::name('script_ai_projects')->where('id', $projectId)->update([
                'status' => 'queued',
                'pause_requested' => 0,
                'current_step_key' => (string) $failed['step_key'],
                'waiting_message' => '等待 ' . (string) $failed['agent_name'] . ' 重新领取任务',
                'error_message' => '',
                'finished_at' => null,
                'update_time' => $now,
            ]);
        });
    }

    public static function cancel(int $projectId, int $userId): void
    {
        self::ensureSchema();
        $project = self::visibleProject($projectId, $userId);
        if (!in_array((string) $project['status'], ['queued', 'running', 'paused'], true)) {
            abort(409, '当前流程不能取消');
        }
        $now = date('Y-m-d H:i:s');
        Db::transaction(function () use ($projectId, $now): void {
            Db::name('script_ai_steps')->where('project_id', $projectId)->whereIn('status', ['pending', 'queued'])->update([
                'status' => 'skipped',
                'completed_at' => $now,
                'update_time' => $now,
            ]);
            Db::name('script_ai_projects')->where('id', $projectId)->update([
                'status' => 'cancelled',
                'pause_requested' => 0,
                'waiting_message' => '流程已取消',
                'finished_at' => $now,
                'update_time' => $now,
            ]);
        });
    }

    public static function runNextQueuedStep(): bool
    {
        self::ensureSchema();
        $claimed = self::claimNextStep();
        if ($claimed === null) {
            return false;
        }
        self::executeStep((int) $claimed['step_id']);
        return true;
    }

    public static function recoverStaleSteps(int $staleMinutes): int
    {
        self::ensureSchema();
        $expiredAt = date('Y-m-d H:i:s', time() - (max(5, $staleMinutes) * 60));
        $steps = Db::name('script_ai_steps')->where('status', 'running')->where('update_time', '<', $expiredAt)->select()->toArray();
        $count = 0;
        foreach ($steps as $step) {
            $now = date('Y-m-d H:i:s');
            Db::transaction(function () use ($step, $now): void {
                Db::name('script_ai_steps')->where('id', (int) $step['id'])->where('status', 'running')->update([
                    'status' => 'failed',
                    'error_message' => 'AI 步骤执行超时或工作进程中断',
                    'completed_at' => $now,
                    'update_time' => $now,
                ]);
                Db::name('script_ai_projects')->where('id', (int) $step['project_id'])->whereIn('status', ['queued', 'running'])->update([
                    'status' => 'failed',
                    'waiting_message' => '等待重试失败步骤',
                    'error_message' => 'AI 步骤执行超时或工作进程中断',
                    'finished_at' => $now,
                    'update_time' => $now,
                ]);
            });
            $count++;
        }
        return $count;
    }

    private static function claimNextStep(): ?array
    {
        return Db::transaction(function (): ?array {
            $step = Db::name('script_ai_steps')->alias('s')
                ->join('script_ai_projects p', 'p.id = s.project_id')
                ->where('s.status', 'queued')
                ->whereIn('p.status', ['queued', 'running'])
                ->where('p.pause_requested', 0)
                ->field('s.id,s.project_id,s.step_key,s.agent_name')
                ->order(['p.id' => 'asc', 's.sort' => 'asc', 's.id' => 'asc'])
                ->lock(true)
                ->find();
            if (!is_array($step)) {
                return null;
            }

            $now = date('Y-m-d H:i:s');
            Db::name('script_ai_steps')->where('id', (int) $step['id'])->where('status', 'queued')->update([
                'status' => 'running',
                'attempts' => Db::raw('attempts + 1'),
                'error_message' => '',
                'started_at' => $now,
                'completed_at' => null,
                'update_time' => $now,
            ]);
            Db::name('script_ai_projects')->where('id', (int) $step['project_id'])->update([
                'status' => 'running',
                'current_step_key' => (string) $step['step_key'],
                'waiting_message' => '正在等待 ' . (string) $step['agent_name'] . ' 返回内容',
                'update_time' => $now,
            ]);
            return ['step_id' => (int) $step['id']];
        });
    }

    private static function executeStep(int $stepId): void
    {
        $step = Db::name('script_ai_steps')->where('id', $stepId)->find();
        if (!is_array($step) || (string) $step['status'] !== 'running') {
            return;
        }
        $project = Db::name('script_ai_projects')->where('id', (int) $step['project_id'])->find();
        if (!is_array($project)) {
            return;
        }

        $startedAt = microtime(true);
        try {
            $configs = self::configMap((int) $project['user_id']);
            $default = $configs['default'] ?? null;
            $agent = $configs[(string) $step['agent_config_key']] ?? null;
            if (!is_array($default) || !is_array($agent)) {
                throw new \RuntimeException('AI 配置不存在，请重新保存配置');
            }

            $modelConfigId = (int) ($agent['model_config_id'] ?: $default['model_config_id']);
            $model = ModelConfigResolver::resolve('text', (int) $project['user_id'], $modelConfigId);
            if (!$model instanceof ModelConfig) {
                throw new \RuntimeException('当前角色没有可用的文本模型');
            }
            $temperature = $agent['temperature'] === null ? (float) $default['temperature'] : (float) $agent['temperature'];
            $maxTokens = $agent['max_tokens'] === null ? (int) $default['max_tokens'] : (int) $agent['max_tokens'];

            $previousRow = Db::name('script_ai_steps')
                ->where('project_id', (int) $project['id'])
                ->where('sort', '<', (int) $step['sort'])
                ->where('status', 'completed')
                ->order('sort', 'desc')
                ->field('output_content,handoff_content')
                ->find();
            $previousOutput = trim((string) ($previousRow['output_content'] ?? ''));
            $previousHandoff = trim((string) ($previousRow['handoff_content'] ?? ''));
            if ($previousOutput === '') {
                $previousOutput = '无，这是流程第一步。';
            }
            if ($previousHandoff === '') {
                $previousHandoff = self::buildFallbackHandoff($previousOutput);
            }

            $systemPrompt = trim((string) $default['system_prompt']);
            if (trim((string) $agent['system_prompt']) !== '') {
                $systemPrompt .= "\n\n当前角色规则：\n" . trim((string) $agent['system_prompt']);
            }
            $systemPrompt .= "\n\n项目硬性约束：\n" . self::projectConstraintPrompt($project, (string) $step['step_key']);

            $stepKey = (string) $step['step_key'];
            $logContext = [
                'user_id' => (int) $project['user_id'],
                'project_id' => (int) $project['id'],
                'step_id' => $stepId,
                'step_key' => $stepKey,
                'agent_config_key' => (string) $step['agent_config_key'],
            ];

            if ($stepKey === 'reviewing') {
                $result = self::executeReviewingStep(
                    $project,
                    $default,
                    $agent,
                    $model,
                    $systemPrompt,
                    $temperature,
                    $maxTokens,
                    $previousOutput,
                    $previousHandoff,
                    $logContext
                );
            } else {
                $result = self::executeStandardStep(
                    $project,
                    $default,
                    $agent,
                    $model,
                    $systemPrompt,
                    $temperature,
                    $maxTokens,
                    $previousOutput,
                    $previousHandoff,
                    $stepKey,
                    $logContext
                );
            }

            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            self::reconnectDatabase();
            self::completeStep(
                $step,
                $project,
                $model,
                (string) $result['system_prompt'],
                (string) $result['task_prompt'],
                (string) $result['input_content'],
                (string) $result['deliverable'],
                (string) $result['handoff'],
                $durationMs
            );
        } catch (\Throwable $e) {
            try {
                self::reconnectDatabase();
                self::failStep($step, $project, $e->getMessage(), (int) round((microtime(true) - $startedAt) * 1000));
            } catch (\Throwable $persistError) {
                Log::error('[ScriptCreation] step #' . $stepId . ' failed and could not persist status: ' . $persistError->getMessage());
            }
            Log::error('[ScriptCreation] step #' . $stepId . ' failed: ' . $e->getMessage());
        }
    }

    /**
     * @return array{system_prompt:string,task_prompt:string,input_content:string,deliverable:string,handoff:string}
     */
    private static function executeStandardStep(
        array $project,
        array $default,
        array $agent,
        ModelConfig $model,
        string $systemPrompt,
        float $temperature,
        int $maxTokens,
        string $previousOutput,
        string $previousHandoff,
        string $stepKey,
        array $logContext
    ): array {
        $variables = self::buildPromptVariables($project, $previousOutput, $previousHandoff, $stepKey);
        $taskTemplate = self::composeTaskTemplate($default, $agent, $stepKey);
        $taskPrompt = strtr($taskTemplate, $variables);
        $taskPrompt = self::enforceInputBudget($systemPrompt, $taskPrompt, $maxTokens);

        $content = self::callTextModel($model, $systemPrompt, $taskPrompt, $temperature, $maxTokens, $logContext);
        $parsed = self::parseDeliverablePackage($content, $stepKey);

        return [
            'system_prompt' => $systemPrompt,
            'task_prompt' => $taskPrompt,
            'input_content' => $previousHandoff !== '' ? $previousHandoff : $previousOutput,
            'deliverable' => $parsed['deliverable'],
            'handoff' => $parsed['handoff'],
        ];
    }

    /**
     * @return array{system_prompt:string,task_prompt:string,input_content:string,deliverable:string,handoff:string}
     */
    private static function executeReviewingStep(
        array $project,
        array $default,
        array $agent,
        ModelConfig $model,
        string $systemPrompt,
        float $temperature,
        int $maxTokens,
        string $previousOutput,
        string $previousHandoff,
        array $logContext
    ): array {
        $episodes = self::splitScriptEpisodes($previousOutput);
        $taskTemplate = self::composeTaskTemplate($default, $agent, 'reviewing');
        $taskSnapshots = [];
        $inputSnapshots = [];

        if (count($episodes) <= 1) {
            $body = $episodes[0]['body'] ?? $previousOutput;
            $variables = self::buildPromptVariables($project, $previousOutput, $previousHandoff, 'reviewing', $body);
            $taskPrompt = self::enforceInputBudget($systemPrompt, strtr($taskTemplate, $variables), $maxTokens);
            $content = self::callTextModel($model, $systemPrompt, $taskPrompt, $temperature, $maxTokens, $logContext + ['episode_index' => 1]);
            $parsed = self::parseDeliverablePackage($content, 'reviewing');
            return [
                'system_prompt' => $systemPrompt,
                'task_prompt' => $taskPrompt,
                'input_content' => $previousHandoff . "\n\n" . mb_substr($body, 0, 20000),
                'deliverable' => $parsed['deliverable'],
                'handoff' => '',
            ];
        }

        $revisedParts = [];
        foreach ($episodes as $index => $episode) {
            $marker = trim((string) ($episode['marker'] ?? ''));
            $body = trim((string) ($episode['body'] ?? ''));
            $episodeText = $marker !== '' ? ($marker . "\n" . $body) : $body;
            $variables = self::buildPromptVariables($project, $previousOutput, $previousHandoff, 'reviewing', $episodeText);
            $taskPrompt = self::enforceInputBudget($systemPrompt, strtr($taskTemplate, $variables), $maxTokens);
            $taskSnapshots[] = '### Episode ' . ($index + 1) . "\n" . $taskPrompt;
            $inputSnapshots[] = $episodeText;
            $content = self::callTextModel(
                $model,
                $systemPrompt,
                $taskPrompt,
                $temperature,
                $maxTokens,
                $logContext + ['episode_index' => $index + 1, 'episode_count' => count($episodes)]
            );
            $parsed = self::parseDeliverablePackage($content, 'reviewing');
            $revised = trim($parsed['deliverable']);
            if ($revised === '') {
                $revised = $episodeText;
            }
            // Keep marker if model omitted it.
            if ($marker !== '' && !preg_match('/^(?:EPISODE|Episode|episode|第)/u', $revised)) {
                $revised = $marker . "\n" . $revised;
            }
            $revisedParts[] = $revised;
        }

        return [
            'system_prompt' => $systemPrompt,
            'task_prompt' => implode("\n\n-----\n\n", $taskSnapshots),
            'input_content' => $previousHandoff . "\n\n" . implode("\n\n-----\n\n", array_map(
                static fn (string $text): string => mb_substr($text, 0, 4000),
                $inputSnapshots
            )),
            'deliverable' => implode("\n\n", $revisedParts),
            'handoff' => '',
        ];
    }

    private static function composeTaskTemplate(array $default, array $agent, string $stepKey): string
    {
        if ($stepKey === 'reviewing') {
            $taskTemplate = trim((string) ($agent['task_prompt'] ?? ''));
            if ($taskTemplate === '') {
                $taskTemplate = trim((string) ($default['task_prompt'] ?? ''));
            }
            return $taskTemplate;
        }

        $taskTemplate = trim((string) ($default['task_prompt'] ?? ''));
        if (trim((string) ($agent['task_prompt'] ?? '')) !== '') {
            $taskTemplate .= "\n\n当前角色具体任务：\n" . trim((string) $agent['task_prompt']);
        }
        return $taskTemplate;
    }

    private static function buildPromptVariables(
        array $project,
        string $previousOutput,
        string $previousHandoff,
        string $stepKey,
        string $episodeBody = ''
    ): array {
        $includeFullBrief = in_array($stepKey, ['planning', 'direction'], true);
        return [
            '{{title}}' => (string) $project['title'],
            '{{genre}}' => (string) ($project['genre'] ?: '未指定'),
            '{{output_language}}' => self::outputLanguageLabel((string) ($project['output_language'] ?? 'zh-CN')),
            '{{region_style}}' => self::regionStyleLabel((string) ($project['region_style'] ?? 'mainland')),
            '{{synopsis}}' => $includeFullBrief
                ? (string) ($project['synopsis'] ?: '未提供')
                : mb_substr((string) ($project['synopsis'] ?: '未提供'), 0, 800),
            '{{requirements}}' => $includeFullBrief
                ? (string) ($project['requirements'] ?: '未提供额外要求')
                : mb_substr((string) ($project['requirements'] ?: '未提供额外要求'), 0, 800),
            '{{previous_output}}' => mb_substr($previousOutput, 0, 80000),
            '{{previous_handoff}}' => mb_substr($previousHandoff, 0, 40000),
            '{{episode_body}}' => mb_substr($episodeBody, 0, 120000),
        ];
    }

    private static function enforceInputBudget(string $systemPrompt, string $taskPrompt, int $maxTokens): string
    {
        // DeepSeek V4 Flash 上下文按约 128k tokens 估算；字符近似用 1.6 chars/token。
        $contextLimit = 200000;
        $reserve = max(512, $maxTokens) + 4000;
        $budget = max(8000, $contextLimit - self::estimateTokens($systemPrompt) - $reserve);
        if (self::estimateTokens($taskPrompt) <= $budget) {
            return $taskPrompt;
        }

        // Prefer trimming the largest variable payload at the end of the prompt.
        $overflow = self::estimateTokens($taskPrompt) - $budget;
        $trimChars = max(500, (int) ($overflow * 1.6));
        if (mb_strlen($taskPrompt) <= $trimChars + 200) {
            return mb_substr($taskPrompt, 0, max(1000, $budget));
        }
        return mb_substr($taskPrompt, 0, mb_strlen($taskPrompt) - $trimChars)
            . "\n\n[系统裁剪] 输入超过模型预算，已截断尾部内容；请基于已提供的交接包与正文继续。";
    }

    private static function estimateTokens(string $text): int
    {
        // Mixed CN/EN heuristic: ~1.6 chars/token average.
        return max(1, (int) ceil(mb_strlen($text) / 1.6));
    }

    /**
     * @return array{deliverable:string,handoff:string}
     */
    private static function parseDeliverablePackage(string $raw, string $stepKey): array
    {
        $raw = trim($raw);
        $raw = preg_replace('/^```(?:text|plaintext|markdown)?\s*|\s*```$/iu', '', $raw) ?? $raw;
        $raw = trim($raw);

        if (preg_match('/<<<DELIVERABLE>>>\s*(.*?)\s*<<<HANDOFF>>>\s*(.*)\z/us', $raw, $matches) === 1) {
            return [
                'deliverable' => trim((string) $matches[1]),
                'handoff' => $stepKey === 'reviewing' ? '' : trim((string) $matches[2]),
            ];
        }
        if (preg_match('/<<<HANDOFF>>>\s*(.*?)\s*<<<DELIVERABLE>>>\s*(.*)\z/us', $raw, $matches) === 1) {
            return [
                'deliverable' => trim((string) $matches[2]),
                'handoff' => $stepKey === 'reviewing' ? '' : trim((string) $matches[1]),
            ];
        }
        if (preg_match('/<<<DELIVERABLE>>>\s*(.*)\z/us', $raw, $matches) === 1) {
            $deliverable = trim((string) $matches[1]);
            return [
                'deliverable' => $deliverable,
                'handoff' => $stepKey === 'reviewing' ? '' : self::buildFallbackHandoff($deliverable),
            ];
        }

        return [
            'deliverable' => $raw,
            'handoff' => $stepKey === 'reviewing' ? '' : self::buildFallbackHandoff($raw),
        ];
    }

    private static function buildFallbackHandoff(string $content): string
    {
        $content = trim($content);
        if ($content === '' || $content === '无，这是流程第一步。') {
            return $content;
        }

        $episodes = self::splitScriptEpisodes($content);
        if (count($episodes) > 1) {
            $lines = ['【自动交接摘要】'];
            foreach ($episodes as $index => $episode) {
                $marker = trim((string) ($episode['marker'] ?? ('第' . ($index + 1) . '集')));
                $body = trim((string) ($episode['body'] ?? ''));
                $lines[] = $marker . '：' . mb_substr(preg_replace('/\s+/u', ' ', $body) ?? $body, 0, 80);
            }
            return implode("\n", $lines);
        }

        if (mb_strlen($content) <= 1800) {
            return $content;
        }
        return mb_substr($content, 0, 1400) . "\n...\n" . mb_substr($content, -400);
    }

    /**
     * @return list<array{marker:string,body:string}>
     */
    private static function splitScriptEpisodes(string $text): array
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($text === '') {
            return [['marker' => '', 'body' => '']];
        }
        $text = preg_replace('/^[ \t]*#{1,6}[ \t]+/mu', '', $text) ?? $text;
        $text = preg_replace('/^[ \t]*\*\*[ \t]*/mu', '', $text) ?? $text;
        $text = preg_replace('/[ \t]*\*\*[ \t]*$/mu', '', $text) ?? $text;

        preg_match_all(
            '/^[ \t]*(?:(?:EPISODE|Episode|episode)\s*[0-9一二三四五六七八九十百]+|第\s*[0-9一二三四五六七八九十百]+\s*集)[^\n]*$/mu',
            $text,
            $matches,
            PREG_OFFSET_CAPTURE
        );
        if (($matches[0] ?? []) === []) {
            return [['marker' => '', 'body' => $text]];
        }

        $episodes = [];
        $count = count($matches[0]);
        for ($i = 0; $i < $count; $i++) {
            $marker = trim((string) $matches[0][$i][0]);
            $start = (int) $matches[0][$i][1];
            $markerEnd = $start + strlen((string) $matches[0][$i][0]);
            $end = $i + 1 < $count ? (int) $matches[0][$i + 1][1] : strlen($text);
            $body = trim(substr($text, $markerEnd, max(0, $end - $markerEnd)));
            $episodes[] = ['marker' => $marker, 'body' => $body];
        }
        return $episodes !== [] ? $episodes : [['marker' => '', 'body' => $text]];
    }

    private static function reconnectDatabase(): void
    {
        try {
            Db::connect()->close();
        } catch (\Throwable) {
            // Ignore close failures on already-dead connections.
        }
        Db::connect();
    }

    private static function completeStep(
        array $step,
        array $project,
        ModelConfig $model,
        string $systemPrompt,
        string $taskPrompt,
        string $input,
        string $output,
        string $handoff,
        int $durationMs
    ): void {
        Db::transaction(function () use ($step, $project, $model, $systemPrompt, $taskPrompt, $input, $output, $handoff, $durationMs): void {
            $now = date('Y-m-d H:i:s');
            $updated = Db::name('script_ai_steps')
                ->where('id', (int) $step['id'])
                ->where('run_no', (int) $step['run_no'])
                ->where('status', 'running')
                ->update([
                'status' => 'completed',
                'model_config_id' => (int) $model->getAttr('id'),
                'system_prompt_snapshot' => $systemPrompt,
                'task_prompt_snapshot' => $taskPrompt,
                'input_content' => $input,
                'output_content' => $output,
                'handoff_content' => $handoff,
                'error_message' => '',
                'duration_ms' => max(0, $durationMs),
                'completed_at' => $now,
                'update_time' => $now,
            ]);
            if ($updated <= 0) {
                return;
            }

            $currentProject = Db::name('script_ai_projects')->where('id', (int) $project['id'])->lock(true)->find();
            if (!is_array($currentProject) || (int) $currentProject['run_no'] !== (int) $step['run_no'] || (string) $currentProject['status'] === 'cancelled') {
                return;
            }
            if ((int) $currentProject['pause_requested'] === 1) {
                Db::name('script_ai_projects')->where('id', (int) $project['id'])->update([
                    'status' => 'paused',
                    'pause_requested' => 0,
                    'waiting_message' => '流程已暂停，等待继续操作',
                    'update_time' => $now,
                ]);
                return;
            }

            $next = Db::name('script_ai_steps')
                ->where('project_id', (int) $project['id'])
                ->where('sort', '>', (int) $step['sort'])
                ->where('status', 'pending')
                ->order(['sort' => 'asc', 'id' => 'asc'])
                ->find();
            if (is_array($next)) {
                Db::name('script_ai_steps')->where('id', (int) $next['id'])->update(['status' => 'queued', 'update_time' => $now]);
                Db::name('script_ai_projects')->where('id', (int) $project['id'])->update([
                    'status' => 'queued',
                    'current_step_key' => (string) $next['step_key'],
                    'waiting_message' => '等待 ' . (string) $next['agent_name'] . ' 领取任务',
                    'update_time' => $now,
                ]);
                return;
            }

            Db::name('script_ai_projects')->where('id', (int) $project['id'])->update([
                'status' => 'completed',
                'current_step_key' => (string) $step['step_key'],
                'waiting_message' => 'AI 创作流程已完成',
                'final_content' => $output,
                'error_message' => '',
                'finished_at' => $now,
                'update_time' => $now,
            ]);
        });
    }

    private static function failStep(array $step, array $project, string $message, int $durationMs): void
    {
        $message = mb_substr(trim($message) ?: 'AI 步骤执行失败', 0, 4000);
        $now = date('Y-m-d H:i:s');
        Db::transaction(function () use ($step, $project, $message, $durationMs, $now): void {
            $updated = Db::name('script_ai_steps')
                ->where('id', (int) $step['id'])
                ->where('run_no', (int) $step['run_no'])
                ->where('status', 'running')
                ->update([
                'status' => 'failed',
                'error_message' => $message,
                'duration_ms' => max(0, $durationMs),
                'completed_at' => $now,
                'update_time' => $now,
            ]);
            if ($updated <= 0) {
                return;
            }
            $currentStatus = (string) Db::name('script_ai_projects')->where('id', (int) $project['id'])->value('status');
            $currentRunNo = (int) Db::name('script_ai_projects')->where('id', (int) $project['id'])->value('run_no');
            if ($currentRunNo === (int) $step['run_no'] && $currentStatus !== 'cancelled') {
                Db::name('script_ai_projects')->where('id', (int) $project['id'])->update([
                    'status' => 'failed',
                    'pause_requested' => 0,
                    'waiting_message' => '等待重试失败步骤',
                    'error_message' => $message,
                    'finished_at' => $now,
                    'update_time' => $now,
                ]);
            }
        });
    }

    private static function callTextModel(ModelConfig $model, string $systemPrompt, string $taskPrompt, float $temperature, int $maxTokens, array $context): string
    {
        $startedAt = microtime(true);
        $endpoint = '';
        $httpStatus = 0;
        $rawBody = '';
        $curlErrno = 0;
        $curlError = '';
        $requestOk = 0;
        $errorMessage = '';
        $contentPreview = '';
        $usage = [];
        $payload = [];

        try {
            $endpoint = trim((string) $model->getAttr('endpoint'));
            if ($endpoint === '') {
                throw new \RuntimeException('文本模型 endpoint 为空');
            }
            if (!str_contains($endpoint, '/chat/completions')) {
                $endpoint = rtrim($endpoint, '/') . '/chat/completions';
            }
            $modelId = trim((string) $model->getAttr('model_id'));
            if ($modelId === '') {
                throw new \RuntimeException('文本模型 model_id 为空');
            }

            $payload = [
                'model' => $modelId,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $taskPrompt],
                ],
                'temperature' => max(0, min(2, $temperature)),
                'max_tokens' => max(512, min(65536, $maxTokens)),
            ];
            // DeepSeek V4 默认开启 thinking；推理 token 计入 max_tokens。
            // 剧本创作需要稳定返回正文，因此对 DeepSeek 官方接口关闭 thinking。
            if (self::shouldDisableDeepSeekThinking($endpoint, $modelId)) {
                $payload['thinking'] = ['type' => 'disabled'];
            }
            CreditService::assertTextAffordable(
                (int) ($context['user_id'] ?? 0),
                CreditService::estimatePromptTokensFromMessages($payload['messages']),
                (int) $payload['max_tokens'],
                $modelId
            );
            $headers = ['Content-Type: application/json'];
            $apiKey = trim((string) $model->getAttr('api_key'));
            if ($apiKey !== '') {
                $headers[] = 'Authorization: Bearer ' . $apiKey;
            }

            $ch = curl_init($endpoint);
            if ($ch === false) {
                throw new \RuntimeException('初始化 AI 请求失败');
            }
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => max(30, (int) env('AI_TEXT_TIMEOUT_SECONDS', 600)),
                CURLOPT_CONNECTTIMEOUT => max(5, (int) env('AI_TEXT_CONNECT_TIMEOUT_SECONDS', 20)),
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]);
            $response = curl_exec($ch);
            $curlErrno = curl_errno($ch);
            $curlError = (string) curl_error($ch);
            $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($curlErrno !== 0) {
                throw new \RuntimeException('AI 请求失败：' . $curlError);
            }
            if (!is_string($response) || $response === '') {
                throw new \RuntimeException('AI 返回为空');
            }
            $rawBody = $response;
            if ($httpStatus < 200 || $httpStatus >= 300) {
                throw new \RuntimeException('AI 服务异常：HTTP ' . $httpStatus . '，响应：' . mb_substr($response, 0, 500));
            }
            $decoded = json_decode($response, true);
            if (!is_array($decoded)) {
                throw new \RuntimeException('AI 返回不是有效 JSON');
            }
            $usage = is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];
            $message = is_array($decoded['choices'][0]['message'] ?? null) ? $decoded['choices'][0]['message'] : [];
            $content = self::extractAssistantTextContent($message);
            if ($content === '') {
                $reasoningTokens = (int) ($usage['completion_tokens_details']['reasoning_tokens'] ?? 0);
                $completionTokens = (int) ($usage['completion_tokens'] ?? 0);
                if ($reasoningTokens > 0 && $reasoningTokens >= max(1, $completionTokens - 8)) {
                    throw new \RuntimeException('AI 仅返回了思考过程，未生成正文（reasoning 占满 max_tokens）。请关闭 thinking 或提高输出上限后重试');
                }
                throw new \RuntimeException('AI 未返回可用内容');
            }

            $requestOk = 1;
            $contentPreview = mb_substr($content, 0, 1000);
            return $content;
        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage();
            throw $e;
        } finally {
            try {
                AiRequestLog::create([
                    'user_id' => (int) ($context['user_id'] ?? 0),
                    'source' => 'script_creation_agent',
                    'model_config_id' => (int) ($model->getAttr('id') ?? 0) ?: null,
                    'llm_model' => (string) ($payload['model'] ?? ''),
                    'endpoint' => $endpoint,
                    'context_json' => self::encodeLogJson($context),
                    'request_json' => self::encodeLogJson($payload),
                    'http_status' => $httpStatus,
                    'response_body' => self::truncateLogText($rawBody, 400000),
                    'usage_json' => $usage,
                    'curl_errno' => $curlErrno,
                    'curl_error' => mb_substr($curlError, 0, 512),
                    'request_ok' => $requestOk,
                    'error_message' => mb_substr($errorMessage, 0, 2000),
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'max_tokens' => (int) ($payload['max_tokens'] ?? 0),
                    'content_preview' => $contentPreview,
                ]);
            } catch (\Throwable $e) {
                Log::error('[AiRequestLog] script creation insert failed: ' . $e->getMessage());
            }
        }
    }

    private static function ensureDefaultConfigs(int $userId): void
    {
        $existingRows = Db::name('script_ai_configs')->where('user_id', $userId)->select()->toArray();
        $existing = [];
        foreach ($existingRows as $row) {
            $existing[(string) ($row['config_key'] ?? '')] = $row;
        }
        $now = date('Y-m-d H:i:s');
        foreach (self::CONFIGS as $config) {
            if (isset($existing[$config['key']])) {
                self::syncHandoffPromptTemplateIfNeeded($existing[$config['key']], $config, $now);
                continue;
            }
            Db::name('script_ai_configs')->insert([
                'user_id' => $userId,
                'config_key' => $config['key'],
                'name' => $config['name'],
                'description' => $config['description'],
                'model_config_id' => 0,
                'system_prompt' => $config['system_prompt'],
                'task_prompt' => $config['task_prompt'],
                'temperature' => $config['temperature'],
                'max_tokens' => $config['max_tokens'],
                'enabled' => 1,
                'sort' => $config['sort'],
                'create_time' => $now,
                'update_time' => $now,
            ]);
        }
    }

    private static function syncHandoffPromptTemplateIfNeeded(array $existing, array $config, string $now): void
    {
        $key = (string) ($config['key'] ?? '');
        $systemPrompt = (string) ($existing['system_prompt'] ?? '');
        $taskPrompt = (string) ($existing['task_prompt'] ?? '');
        $maxTokens = $existing['max_tokens'] === null ? null : (int) $existing['max_tokens'];
        $needsSync = false;
        $update = ['update_time' => $now];

        if ($key === 'default' && !str_contains($taskPrompt, '{{previous_handoff}}')) {
            $needsSync = true;
        }
        if ($key === 'reviewer' && !str_contains($taskPrompt, '{{episode_body}}')) {
            $needsSync = true;
        }
        if (in_array($key, ['planner', 'director', 'writer', 'default'], true)
            && !str_contains($systemPrompt, '<<<DELIVERABLE>>>')
            && !str_contains($taskPrompt, '<<<DELIVERABLE>>>')
            && !str_contains($systemPrompt, 'HANDOFF')) {
            $needsSync = true;
        }
        if ($needsSync) {
            $update['system_prompt'] = (string) $config['system_prompt'];
            $update['task_prompt'] = (string) $config['task_prompt'];
        }

        // Keep writing/review budgets aligned with current DeepSeek long-output defaults.
        if ($key === 'default' && ($maxTokens === null || $maxTokens < 65536) && array_key_exists('max_tokens', $config)) {
            $update['max_tokens'] = (int) $config['max_tokens'];
            $needsSync = true;
        }
        if ($key === 'writer' && ($maxTokens === null || $maxTokens < 65536) && ($config['max_tokens'] ?? null) !== null) {
            $update['max_tokens'] = (int) $config['max_tokens'];
            $needsSync = true;
        }
        if ($key === 'reviewer' && ($maxTokens === null || $maxTokens < 65536) && ($config['max_tokens'] ?? null) !== null) {
            $update['max_tokens'] = (int) $config['max_tokens'];
            $needsSync = true;
        }

        if (!$needsSync) {
            return;
        }

        Db::name('script_ai_configs')->where('id', (int) $existing['id'])->update($update);
    }

    private static function shouldDisableDeepSeekThinking(string $endpoint, string $modelId): bool
    {
        $endpoint = strtolower($endpoint);
        $modelId = strtolower($modelId);
        return str_contains($endpoint, 'api.deepseek.com')
            || str_starts_with($modelId, 'deepseek-v4')
            || str_starts_with($modelId, 'deepseek-chat')
            || str_starts_with($modelId, 'deepseek-reasoner');
    }

    private static function extractAssistantTextContent(array $message): string
    {
        $content = $message['content'] ?? '';
        if (is_array($content)) {
            $parts = [];
            foreach ($content as $chunk) {
                if (is_array($chunk) && isset($chunk['text'])) {
                    $parts[] = (string) $chunk['text'];
                } elseif (is_string($chunk)) {
                    $parts[] = $chunk;
                }
            }
            $content = implode("\n", $parts);
        }
        return trim((string) $content);
    }

    private static function configMap(int $userId): array
    {
        self::ensureDefaultConfigs($userId);
        $rows = Db::name('script_ai_configs')->where('user_id', $userId)->select()->toArray();
        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['config_key']] = self::publicConfig($row);
        }
        return $map;
    }

    private static function projectSummary(array $project): array
    {
        $projectId = (int) $project['id'];
        $steps = Db::name('script_ai_steps')->where('project_id', $projectId)->field('id,step_key,step_name,agent_name,status,sort,duration_ms,error_message')->order(['sort' => 'asc', 'id' => 'asc'])->select()->toArray();
        $current = null;
        $completed = 0;
        $total = 0;
        foreach ($steps as $step) {
            if ((string) $step['status'] !== 'skipped') {
                $total++;
            }
            if ((string) $step['status'] === 'completed') {
                $completed++;
            }
            if ((string) $step['step_key'] === (string) $project['current_step_key']) {
                $current = self::publicStep($step);
            }
        }
        $project['id'] = $projectId;
        $project['user_id'] = (int) $project['user_id'];
        $project['pause_requested'] = (int) $project['pause_requested'];
        $project['run_no'] = (int) $project['run_no'];
        $project['final_content'] = (string) ($project['final_content'] ?? '');
        $project['error_message'] = MessageLocalizer::translate((string) ($project['error_message'] ?? ''));
        $project['progress_completed'] = $completed;
        $project['progress_total'] = $total;
        $project['current_step'] = $current;
        $project['latest_score'] = ScriptExternalScoringService::latestScore($projectId);
        return $project;
    }

    private static function publicConfig(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'config_key' => (string) $row['config_key'],
            'name' => (string) $row['name'],
            'description' => (string) $row['description'],
            'model_config_id' => (int) $row['model_config_id'],
            'system_prompt' => (string) ($row['system_prompt'] ?? ''),
            'task_prompt' => (string) ($row['task_prompt'] ?? ''),
            'temperature' => $row['temperature'] === null ? null : (float) $row['temperature'],
            'max_tokens' => $row['max_tokens'] === null ? null : (int) $row['max_tokens'],
            'enabled' => (int) $row['enabled'],
            'sort' => (int) $row['sort'],
        ];
    }

    private static function publicStep(array $row): array
    {
        foreach (['id', 'project_id', 'sort', 'run_no', 'attempts', 'model_config_id', 'duration_ms'] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = (int) $row[$key];
            }
        }
        if (array_key_exists('error_message', $row)) {
            $row['error_message'] = MessageLocalizer::translate((string) $row['error_message']);
        }
        return $row;
    }

    private static function visibleProject(int $projectId, int $userId): array
    {
        $project = Db::name('script_ai_projects')->where('id', $projectId)->where('user_id', $userId)->find();
        if (!is_array($project)) {
            abort(404, '剧本项目不存在');
        }
        return $project;
    }

    private static function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        if (preg_match('/^[a-zA-Z0-9_]+$/', $table) !== 1 || preg_match('/^[a-zA-Z0-9_]+$/', $column) !== 1) {
            throw new \InvalidArgumentException('数据库表名或字段名不合法');
        }
        $exists = Db::query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        if ($exists === []) {
            try {
                Db::execute("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            } catch (\Throwable $e) {
                $message = $e->getMessage();
                $duplicateColumn = str_contains($message, '1060')
                    || str_contains($message, '42S21')
                    || str_contains($message, 'Duplicate column name');
                if (!$duplicateColumn) {
                    throw $e;
                }
            }
        }
    }

    private static function nullableFloat(mixed $value, ?float $fallback, float $min, float $max): ?float
    {
        if ($value === null || $value === '') {
            return $fallback;
        }
        return round(max($min, min($max, (float) $value)), 2);
    }

    private static function normalizeOutputLanguage(mixed $value): string
    {
        return 'zh-CN';
    }

    private static function normalizeRegionStyle(mixed $value): string
    {
        return (string) $value === 'overseas' ? 'overseas' : 'mainland';
    }

    private static function outputLanguageLabel(string $value): string
    {
        return '简体中文';
    }

    private static function regionStyleLabel(string $value): string
    {
        return self::normalizeRegionStyle($value) === 'overseas' ? '海外' : '中国大陆';
    }

    private static function projectConstraintPrompt(array $project, string $stepKey): string
    {
        $languageRule = '所有面向用户的内容必须使用简体中文，包括标题、动作、人物提示和对白。';
        $region = self::normalizeRegionStyle($project['region_style'] ?? null);
        $regionRule = $region === 'overseas'
            ? '采用海外地区语境：人物命名、文化背景、地点、社会关系与对白习惯应符合海外故事设定。'
            : '采用中国大陆地区语境：人物命名、文化背景、地点、社会关系与对白习惯应符合中国大陆故事设定。';
        $formatRule = in_array($stepKey, ['drafting', 'reviewing'], true)
            ? "\n输出必须是完整、可拍摄的标准剧本文本，结构参考专业剧本 PDF：\n- 使用“第 N 集”标识分集；\n- 每场使用“内景/外景 + 地点 + 时间”作为场景标题；\n- 动作描述使用现在时并独立成段；\n- 角色名与对白清晰分行，表演提示置于角色名后或对白前；\n- 必要时使用画外音、画外声、字幕、插入镜头和转场；\n- 不得输出 Markdown 表格、JSON、创作说明、摘要或审校报告。"
            : '';

        return $languageRule . "\n" . $regionRule . "\n输出语言与地区风格是两个独立维度，不得因地区风格擅自改变输出语言。" . $formatRule;
    }

    private static function nullableInt(mixed $value, ?int $fallback, int $min, int $max): ?int
    {
        if ($value === null || $value === '') {
            return $fallback;
        }
        return max($min, min($max, (int) $value));
    }

    private static function encodeLogJson(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? self::truncateLogText($json, 400000) : '';
    }

    private static function truncateLogText(string $value, int $maxLength): string
    {
        return mb_strlen($value) > $maxLength ? mb_substr($value, 0, $maxLength) : $value;
    }
}
