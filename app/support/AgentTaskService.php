<?php

declare(strict_types=1);

namespace app\support;

use app\controller\SeriesController;
use app\model\AgentTask;
use app\model\Asset;
use app\model\AssetImageJob;
use app\model\Episode;
use app\model\Series;
use app\model\Shot;
use app\model\VideoJob;
use app\model\Workflow;
use app\model\WorkflowRun;
use think\App;
use think\facade\Db;

class AgentTaskService
{
    public const STATUS_CREATED = 'created';
    public const STATUS_SERIES_QUEUED = 'series_queued';
    public const STATUS_SERIES_RUNNING = 'series_running';
    public const STATUS_ASSET_GENERATING = 'asset_generating';
    public const STATUS_WAITING_ASSET_REVIEW = 'waiting_asset_review';
    public const STATUS_EPISODE_READY = 'episode_ready';
    public const STATUS_EPISODE_RUNNING = 'episode_running';
    public const STATUS_VIDEO_GENERATING = 'video_generating';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const CHECKPOINT_NONE = '';
    public const CHECKPOINT_ASSET_REVIEW = 'asset_review';
    public const CHECKPOINT_MANUAL_RESUME = 'manual_resume';

    public function ensureSchema(): void
    {
        Db::execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS `agent_tasks` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int UNSIGNED NOT NULL DEFAULT 1 COMMENT 'users.id',
  `task_no` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `agent_id` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `title` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `source_type` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'script_text',
  `source_file_token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `series_id` int UNSIGNED NULL DEFAULT NULL,
  `series_workflow_id` int UNSIGNED NULL DEFAULT NULL,
  `episode_workflow_id` int UNSIGNED NULL DEFAULT NULL,
  `series_workflow_run_id` bigint UNSIGNED NULL DEFAULT NULL,
  `episode_count` int UNSIGNED NULL DEFAULT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'created',
  `phase` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'created',
  `checkpoint` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `progress` tinyint UNSIGNED NOT NULL DEFAULT 0,
  `request_json` json NULL,
  `review_payload_json` json NULL,
  `result_json` json NULL,
  `meta_json` json NULL,
  `callback_url` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `error_message` varchar(2000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `create_time` datetime NULL DEFAULT NULL,
  `update_time` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uniq_agent_tasks_task_no` (`task_no`) USING BTREE,
  KEY `idx_agent_tasks_user_status` (`user_id`, `status`, `id`) USING BTREE,
  KEY `idx_agent_tasks_series` (`series_id`) USING BTREE,
  KEY `idx_agent_tasks_run` (`series_workflow_run_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Agent-facing independent task state'
SQL);
    }

    public function createFromPayload(array $payload, int $userId, App $app): AgentTask
    {
        $this->ensureSchema();
        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') {
            abort(422, '任务标题不能为空');
        }

        $sourceText = trim((string) ($payload['source_text'] ?? $payload['script_text'] ?? ''));
        $sourceFileToken = trim((string) ($payload['source_file_token'] ?? $payload['file_token'] ?? ''));
        if ($sourceText === '' && $sourceFileToken === '') {
            abort(422, '请传入剧本文本 source_text 或上传文件 token');
        }

        $seriesWorkflowId = (int) ($payload['series_workflow_id'] ?? 0);
        $episodeWorkflowId = (int) ($payload['episode_workflow_id'] ?? 0);
        if ($seriesWorkflowId <= 0) {
            $workflow = Workflow::where('user_id', $userId)
                ->where('scope', 'series')
                ->order(['is_default' => 'desc', 'sort' => 'asc', 'id' => 'asc'])
                ->find();
            $seriesWorkflowId = $workflow instanceof Workflow ? (int) $workflow->getAttr('id') : 0;
        }
        if ($seriesWorkflowId <= 0) {
            abort(422, '未找到可用的剧本工作流，请先配置剧本工作流');
        }

        if ($episodeWorkflowId <= 0) {
            $workflow = Workflow::where('user_id', $userId)
                ->where('scope', 'episode')
                ->order(['is_default' => 'desc', 'sort' => 'asc', 'id' => 'asc'])
                ->find();
            $episodeWorkflowId = $workflow instanceof Workflow ? (int) $workflow->getAttr('id') : 0;
        }

        return Db::transaction(function () use ($payload, $userId, $app, $title, $sourceText, $sourceFileToken, $seriesWorkflowId, $episodeWorkflowId): AgentTask {
            $task = AgentTask::create([
                'user_id' => $userId,
                'task_no' => $this->newTaskNo(),
                'agent_id' => trim((string) ($payload['agent_id'] ?? '')),
                'title' => $title,
                'source_type' => $sourceFileToken !== '' ? 'script_upload' : 'script_text',
                'source_file_token' => $sourceFileToken,
                'series_id' => null,
                'series_workflow_id' => $seriesWorkflowId,
                'episode_workflow_id' => $episodeWorkflowId > 0 ? $episodeWorkflowId : null,
                'series_workflow_run_id' => null,
                'episode_count' => $this->normalizeEpisodeCount($payload['episode_count'] ?? null),
                'status' => self::STATUS_CREATED,
                'phase' => 'created',
                'checkpoint' => self::CHECKPOINT_NONE,
                'progress' => 0,
                'request_json' => $this->publicRequestPayload($payload, $sourceText),
                'review_payload_json' => [],
                'result_json' => [],
                'meta_json' => [],
                'callback_url' => trim((string) ($payload['callback_url'] ?? '')),
                'error_message' => '',
            ]);

            $controller = new SeriesController($app);
            $series = $controller->createAgentSeriesAndQueueWorkflow($userId, [
                'title' => $title,
                'description' => $sourceText !== '' ? mb_substr($sourceText, 0, 1000) : '[Agent 上传剧本文件]',
                'visual_style' => $payload['visual_style'] ?? 'realistic',
                'visual_style_variant' => $payload['visual_style_variant'] ?? '',
                'region' => $payload['region'] ?? 'china',
                'series_workflow_id' => $seriesWorkflowId,
                'workflow_id' => $seriesWorkflowId,
                'episode_workflow_id' => $episodeWorkflowId > 0 ? $episodeWorkflowId : null,
                'episode_count' => $task->getAttr('episode_count') ?: null,
                'source_text' => $sourceText,
                'source_file_token' => $sourceFileToken,
                'user_id' => $userId,
                'agent_task_no' => (string) $task->getAttr('task_no'),
            ]);

            $run = is_array($series['workflow_run'] ?? null) ? $series['workflow_run'] : [];
            $task->save([
                'series_id' => (int) ($series['id'] ?? 0) ?: null,
                'series_workflow_run_id' => (int) ($run['id'] ?? 0) ?: null,
                'status' => self::STATUS_SERIES_QUEUED,
                'phase' => 'series_workflow',
                'progress' => 3,
                'result_json' => [
                    'series_id' => (int) ($series['id'] ?? 0),
                    'series_workflow_run_id' => (int) ($run['id'] ?? 0),
                ],
            ]);

            return $task;
        });
    }

    public function refresh(AgentTask $task, App $app): AgentTask
    {
        $this->ensureSchema();
        if (in_array((string) $task->getAttr('status'), [self::STATUS_FAILED, self::STATUS_CANCELLED, self::STATUS_COMPLETED], true)) {
            return $task;
        }

        $runId = (int) ($task->getAttr('series_workflow_run_id') ?? 0);
        $run = $runId > 0 ? WorkflowRun::find($runId) : null;
        if ($run instanceof WorkflowRun) {
            $runStatus = (string) $run->getAttr('status');
            if ($runStatus === 'failed') {
                return $this->fail($task, (string) $run->getAttr('error_message') ?: '剧本工作流执行失败');
            }
            if (in_array($runStatus, ['queued', 'running'], true)) {
                $task->save([
                    'status' => $runStatus === 'queued' ? self::STATUS_SERIES_QUEUED : self::STATUS_SERIES_RUNNING,
                    'phase' => 'series_workflow',
                    'progress' => max(3, min(45, (int) $run->getAttr('progress'))),
                    'error_message' => '',
                ]);
                return $task;
            }
        }

        $seriesId = (int) ($task->getAttr('series_id') ?? 0);
        if ($seriesId <= 0) {
            return $this->fail($task, 'Agent 任务未绑定剧本');
        }

        $assetStats = $this->assetStats((int) $task->getAttr('user_id'), $seriesId);
        if ($assetStats['total'] > 0 && $assetStats['missing_core_images'] > 0) {
            $this->queueMissingAssetImages($task, $app);
            $assetStats = $this->assetStats((int) $task->getAttr('user_id'), $seriesId);
            if ($assetStats['queued_or_running'] > 0 || $assetStats['missing_core_images'] > 0) {
                $task->save([
                    'status' => self::STATUS_ASSET_GENERATING,
                    'phase' => 'asset_generation',
                    'checkpoint' => self::CHECKPOINT_NONE,
                    'progress' => $this->assetProgress($assetStats),
                    'review_payload_json' => $this->assetReviewPayload($task, $assetStats),
                    'error_message' => '',
                ]);
                return $task;
            }
        }

        if ($assetStats['total'] > 0 && (string) $task->getAttr('checkpoint') !== self::CHECKPOINT_NONE) {
            return $task;
        }

        if ($assetStats['total'] > 0 && !($this->meta($task)['asset_review_approved'] ?? false)) {
            $task->save([
                'status' => self::STATUS_WAITING_ASSET_REVIEW,
                'phase' => 'asset_review',
                'checkpoint' => self::CHECKPOINT_ASSET_REVIEW,
                'progress' => 55,
                'review_payload_json' => $this->assetReviewPayload($task, $assetStats),
                'error_message' => '',
            ]);
            return $task;
        }

        return $this->refreshEpisodePhase($task);
    }

    public function approve(AgentTask $task, string $checkpoint, array $payload, App $app): AgentTask
    {
        $checkpoint = trim($checkpoint);
        if ($checkpoint === '') {
            $checkpoint = (string) $task->getAttr('checkpoint');
        }
        if ($checkpoint !== self::CHECKPOINT_ASSET_REVIEW) {
            abort(422, '当前检查点不支持确认');
        }
        if ((string) $task->getAttr('checkpoint') !== self::CHECKPOINT_ASSET_REVIEW) {
            abort(422, '当前任务不在资产确认阶段');
        }

        $meta = $this->meta($task);
        $meta['asset_review_approved'] = true;
        $meta['asset_review_approved_at'] = date('Y-m-d H:i:s');
        $meta['asset_review_note'] = trim((string) ($payload['note'] ?? ''));

        $task->save([
            'checkpoint' => self::CHECKPOINT_NONE,
            'status' => self::STATUS_EPISODE_READY,
            'phase' => 'episode_ready',
            'progress' => 60,
            'meta_json' => $meta,
            'error_message' => '',
        ]);

        return $this->refresh($task, $app);
    }

    public function resume(AgentTask $task, array $payload, App $app): AgentTask
    {
        if ((string) $task->getAttr('status') === self::STATUS_CANCELLED) {
            abort(422, '已取消的任务不能继续');
        }
        if ((string) $task->getAttr('status') === self::STATUS_FAILED && !($payload['retry'] ?? false)) {
            abort(422, '失败任务继续前请明确传 retry=true');
        }

        $meta = $this->meta($task);
        $meta['manual_resumed_at'] = date('Y-m-d H:i:s');
        $meta['manual_resume_note'] = trim((string) ($payload['note'] ?? ''));
        $task->save([
            'checkpoint' => self::CHECKPOINT_NONE,
            'status' => self::STATUS_EPISODE_READY,
            'phase' => 'manual_resumed',
            'meta_json' => $meta,
            'error_message' => '',
        ]);

        return $this->refresh($task, $app);
    }

    public function cancel(AgentTask $task): AgentTask
    {
        $runId = (int) ($task->getAttr('series_workflow_run_id') ?? 0);
        if ($runId > 0) {
            WorkflowRun::where('id', $runId)
                ->whereIn('status', ['queued', 'running', 'failed'])
                ->update([
                    'status' => 'cancelled',
                    'error_message' => 'Agent 任务已取消',
                    'finished_at' => date('Y-m-d H:i:s'),
                ]);
            RedisCache::bumpVersion("workflow_run:{$runId}");
        }

        $seriesId = (int) ($task->getAttr('series_id') ?? 0);
        if ($seriesId > 0) {
            $assetIds = Asset::where('series_id', $seriesId)->column('id');
            $assetJobQuery = AssetImageJob::where('user_id', (int) $task->getAttr('user_id'))
                ->whereIn('status', ImageJobStatus::active());
            if ($assetIds !== []) {
                $assetJobQuery->whereIn('asset_id', $assetIds);
            } else {
                $assetJobQuery->where('asset_id', -1);
            }
            $assetJobQuery->update([
                'status' => 'cancelled',
                'error_message' => 'Agent 任务已取消',
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
            VideoJob::where('user_id', (int) $task->getAttr('user_id'))
                ->where('series_id', $seriesId)
                ->whereIn('status', ['queued', 'blocked', 'running'])
                ->update([
                    'status' => 'cancelled',
                    'error_message' => 'Agent 任务已取消',
                    'finished_at' => date('Y-m-d H:i:s'),
                ]);
        }

        $task->save([
            'status' => self::STATUS_CANCELLED,
            'phase' => 'cancelled',
            'checkpoint' => self::CHECKPOINT_NONE,
            'error_message' => 'Agent 任务已取消',
        ]);

        return $task;
    }

    public function serialize(AgentTask $task): array
    {
        $seriesId = (int) ($task->getAttr('series_id') ?? 0);
        $userId = (int) $task->getAttr('user_id');
        $assetStats = $seriesId > 0 ? $this->assetStats($userId, $seriesId) : null;
        $episodeStats = $seriesId > 0 ? $this->episodeStats($userId, $seriesId) : null;

        return [
            'id' => (int) $task->getAttr('id'),
            'task_no' => (string) $task->getAttr('task_no'),
            'agent_id' => (string) $task->getAttr('agent_id'),
            'title' => (string) $task->getAttr('title'),
            'status' => (string) $task->getAttr('status'),
            'phase' => (string) $task->getAttr('phase'),
            'checkpoint' => (string) $task->getAttr('checkpoint'),
            'progress' => (int) $task->getAttr('progress'),
            'series_id' => $seriesId ?: null,
            'series_workflow_run_id' => (int) ($task->getAttr('series_workflow_run_id') ?? 0) ?: null,
            'episode_count' => (int) ($task->getAttr('episode_count') ?? 0) ?: null,
            'asset_stats' => $assetStats,
            'episode_stats' => $episodeStats,
            'review_payload' => $task->getAttr('review_payload_json') ?: [],
            'result' => $this->resultPayload($task),
            'error_message' => (string) $task->getAttr('error_message'),
            'callback_url' => (string) $task->getAttr('callback_url'),
            'create_time' => $task->getAttr('create_time'),
            'update_time' => $task->getAttr('update_time'),
        ];
    }

    private function queueMissingAssetImages(AgentTask $task, App $app): void
    {
        $controller = new \app\controller\AssetController($app);
        if (method_exists($controller, 'queueAgentCoreImageJobs')) {
            $controller->queueAgentCoreImageJobs((int) $task->getAttr('user_id'), (int) $task->getAttr('series_id'));
        }
    }

    private function refreshEpisodePhase(AgentTask $task): AgentTask
    {
        $stats = $this->episodeStats((int) $task->getAttr('user_id'), (int) $task->getAttr('series_id'));
        $videoStats = $this->videoStats((int) $task->getAttr('user_id'), (int) $task->getAttr('series_id'));

        if ($stats['total'] <= 0) {
            return $this->fail($task, '剧本工作流未生成可用剧集');
        }

        if ($videoStats['running'] > 0 || $videoStats['queued'] > 0) {
            $task->save([
                'status' => self::STATUS_VIDEO_GENERATING,
                'phase' => 'video_generation',
                'progress' => max(65, min(95, 65 + (int) floor($videoStats['success_ratio'] * 30))),
                'error_message' => '',
                'result_json' => $this->resultPayload($task),
            ]);
            return $task;
        }

        if ($stats['done'] > 0 && $stats['done'] >= $stats['total']) {
            $task->save([
                'status' => self::STATUS_COMPLETED,
                'phase' => 'completed',
                'progress' => 100,
                'result_json' => $this->resultPayload($task),
                'error_message' => '',
            ]);
            return $task;
        }

        $task->save([
            'status' => self::STATUS_EPISODE_READY,
            'phase' => 'episode_ready',
            'progress' => 60,
            'result_json' => $this->resultPayload($task),
            'error_message' => '',
        ]);

        return $task;
    }

    private function fail(AgentTask $task, string $message): AgentTask
    {
        $task->save([
            'status' => self::STATUS_FAILED,
            'phase' => 'failed',
            'checkpoint' => self::CHECKPOINT_MANUAL_RESUME,
            'error_message' => mb_substr($message, 0, 2000),
        ]);
        return $task;
    }

    private function assetStats(int $userId, int $seriesId): array
    {
        $assets = Asset::with(['images'])
            ->where('user_id', $userId)
            ->where('series_id', $seriesId)
            ->select();
        $total = 0;
        $missing = 0;
        $withImage = 0;
        foreach ($assets as $asset) {
            if (!$asset instanceof Asset) {
                continue;
            }
            $total++;
            $has = false;
            foreach ($asset->images as $image) {
                if ((string) $image->getAttr('view_type') === 'main' && trim((string) $image->getAttr('url')) !== '') {
                    $has = true;
                    break;
                }
            }
            if ($has) {
                $withImage++;
            } else {
                $missing++;
            }
        }

        $assetIds = Asset::where('user_id', $userId)->where('series_id', $seriesId)->column('id');
        $queued = $assetIds === [] ? 0 : AssetImageJob::where('user_id', $userId)
            ->whereIn('asset_id', $assetIds)
            ->whereIn('status', ImageJobStatus::active())
            ->count();

        return [
            'total' => $total,
            'with_core_images' => $withImage,
            'missing_core_images' => $missing,
            'queued_or_running' => (int) $queued,
        ];
    }

    private function assetProgress(array $stats): int
    {
        if ((int) $stats['total'] <= 0) {
            return 45;
        }
        return 45 + (int) floor(((int) $stats['with_core_images'] / max(1, (int) $stats['total'])) * 10);
    }

    private function assetReviewPayload(AgentTask $task, array $stats): array
    {
        $seriesId = (int) ($task->getAttr('series_id') ?? 0);
        $assets = [];
        if ($seriesId > 0) {
            $rows = Asset::with(['images'])
                ->where('user_id', (int) $task->getAttr('user_id'))
                ->where('series_id', $seriesId)
                ->order(['type' => 'asc', 'sort' => 'asc', 'id' => 'asc'])
                ->limit(100)
                ->select();
            foreach ($rows as $asset) {
                if (!$asset instanceof Asset) {
                    continue;
                }
                $imageUrl = '';
                foreach ($asset->images as $image) {
                    if ((string) $image->getAttr('view_type') === 'main' && trim((string) $image->getAttr('url')) !== '') {
                        $imageUrl = (string) $image->getAttr('url');
                        break;
                    }
                }
                $assets[] = [
                    'id' => (int) $asset->getAttr('id'),
                    'type' => (string) $asset->getAttr('type'),
                    'name' => (string) $asset->getAttr('name'),
                    'description' => (string) $asset->getAttr('description'),
                    'image_url' => $imageUrl,
                ];
            }
        }

        return [
            'checkpoint' => self::CHECKPOINT_ASSET_REVIEW,
            'message' => '资产图片已生成，请确认人物、场景和道具是否可以继续用于剧集生产。',
            'series_id' => $seriesId ?: null,
            'review_url' => $seriesId > 0 ? '/admin/assets?series_id=' . $seriesId : '',
            'asset_stats' => $stats,
            'assets' => $assets,
        ];
    }

    private function episodeStats(int $userId, int $seriesId): array
    {
        $total = Episode::where('user_id', $userId)->where('series_id', $seriesId)->count();
        $done = Episode::where('user_id', $userId)->where('series_id', $seriesId)->where('status', 'done')->count();
        $production = Episode::where('user_id', $userId)->where('series_id', $seriesId)->where('status', 'production')->count();
        return [
            'total' => (int) $total,
            'done' => (int) $done,
            'production' => (int) $production,
            'draft' => max(0, (int) $total - (int) $done - (int) $production),
        ];
    }

    private function videoStats(int $userId, int $seriesId): array
    {
        $total = VideoJob::where('user_id', $userId)->where('series_id', $seriesId)->count();
        $success = VideoJob::where('user_id', $userId)->where('series_id', $seriesId)->where('status', 'success')->count();
        $running = VideoJob::where('user_id', $userId)->where('series_id', $seriesId)->whereIn('status', ['queued', 'blocked', 'running'])->count();
        return [
            'total' => (int) $total,
            'success' => (int) $success,
            'queued' => (int) $running,
            'running' => (int) $running,
            'success_ratio' => (int) $total > 0 ? (float) $success / max(1, (int) $total) : 0.0,
        ];
    }

    private function resultPayload(AgentTask $task): array
    {
        $base = $task->getAttr('result_json') ?: [];
        if (!is_array($base)) {
            $base = [];
        }
        $seriesId = (int) ($task->getAttr('series_id') ?? 0);
        if ($seriesId <= 0) {
            return $base;
        }

        $episodes = [];
        $rows = Episode::where('user_id', (int) $task->getAttr('user_id'))
            ->where('series_id', $seriesId)
            ->order('number', 'asc')
            ->select();
        foreach ($rows as $episode) {
            if (!$episode instanceof Episode) {
                continue;
            }
            $videoUrls = Shot::where('episode_id', (int) $episode->getAttr('id'))
                ->where('storyboard_revision_id', (int) ($episode->getAttr('current_storyboard_revision_id') ?? 0))
                ->where('video_url', '<>', '')
                ->order('index', 'asc')
                ->column('video_url');
            $episodes[] = [
                'id' => (int) $episode->getAttr('id'),
                'number' => (int) $episode->getAttr('number'),
                'title' => (string) $episode->getAttr('title'),
                'status' => (string) $episode->getAttr('status'),
                'video_urls' => array_values(array_filter(array_map('strval', $videoUrls))),
            ];
        }

        $base['series_id'] = $seriesId;
        $base['episodes'] = $episodes;
        return $base;
    }

    private function normalizeEpisodeCount(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $count = (int) $value;
        return $count > 0 ? min(100, $count) : null;
    }

    private function publicRequestPayload(array $payload, string $sourceText): array
    {
        $copy = $payload;
        if ($sourceText !== '') {
            $copy['source_text_preview'] = mb_substr($sourceText, 0, 1000);
            unset($copy['source_text'], $copy['script_text']);
        }
        return $copy;
    }

    private function meta(AgentTask $task): array
    {
        $meta = $task->getAttr('meta_json') ?: [];
        return is_array($meta) ? $meta : [];
    }

    private function newTaskNo(): string
    {
        return 'agt_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
    }
}
