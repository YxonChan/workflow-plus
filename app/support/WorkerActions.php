<?php

declare(strict_types=1);

namespace app\support;

use app\model\AssetImageJob;
use app\model\ModelConfig;
use app\model\VideoJob;
use think\facade\Db;

/**
 * 数字员工的直连写操作（重试/取消），不经过 LLM。
 * 供 WorkerAgentService 的对话工具与 WorkerStatusController 的一键按钮共用，
 * 保证两条入口行为一致；全部按 userId 隔离。
 */
class WorkerActions
{
    /** 把失败的资产生图任务按资产去重后新建重试任务，返回新建条数。 */
    public static function retryFailedImageJobs(int $userId, int $seriesId = 0, int $limit = 0, array $jobIds = []): int
    {
        if ($jobIds === [] && $limit <= 0) {
            $jobIds = self::latestFailedImageJobIdsPerAsset($userId, $seriesId);
        }

        $jobs = self::selectAssetImageJobs($userId, 'failed', [
            'series_id' => $seriesId,
            'limit' => $limit,
            'job_ids' => $jobIds,
        ]);
        if ($jobs === []) {
            return 0;
        }

        $backupModelId = self::resolveRetryBackupImageModelId($userId);
        $representatives = self::latestFailedImageJobsPerRetryKey($jobs);
        $count = 0;
        foreach ($representatives as $job) {
            if (!$job instanceof AssetImageJob) {
                continue;
            }

            if (self::hasPendingImageRetryJob($userId, $job)) {
                continue;
            }

            $nextModelId = self::resolveRetryModelConfigId($job, $backupModelId);
            AssetImageJob::create([
                'user_id' => $userId,
                'asset_id' => (int) ($job->getAttr('asset_id') ?: 0) ?: null,
                'asset_image_id' => (int) ($job->getAttr('asset_image_id') ?: 0) ?: null,
                'model_config_id' => $nextModelId > 0 ? $nextModelId : (int) $job->getAttr('model_config_id'),
                'status' => 'queued',
                'prompt' => (string) $job->getAttr('prompt'),
                'final_prompt' => (string) $job->getAttr('final_prompt'),
                'description' => (string) $job->getAttr('description'),
                'view_type' => (string) $job->getAttr('view_type'),
                'main_image_url' => (string) $job->getAttr('main_image_url'),
                'result_url' => '',
                'error_message' => '',
                'attempts' => 0,
                'started_at' => null,
                'finished_at' => null,
            ]);
            $count++;
        }
        if ($count > 0) {
            RedisCache::bumpVersion('assets');
        }

        return $count;
    }

    /**
     * 同一个资产一次可能会有多个候选图失败。全量重试时每个资产只取最新一条，
     * 避免少数资产的多个失败候选反复霸占队列，导致其他场景/道具排不上。
     *
     * @return array<int, int>
     */
    private static function latestFailedImageJobIdsPerAsset(int $userId, int $seriesId = 0): array
    {
        $assetIds = self::validAssetIdsForImageJobs($userId, $seriesId);
        if ($assetIds === []) {
            return [];
        }

        $rows = Db::name('asset_image_jobs')
            ->where('user_id', $userId)
            ->where('status', 'failed')
            ->where('view_type', '<>', 'look')
            ->whereIn('asset_id', $assetIds)
            ->field('MAX(id) AS id')
            ->group('asset_id')
            ->select();

        $ids = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * 同一资产的多次失败只保留最新一条作为重试模板，避免一次重试把历史失败任务全部恢复。
     *
     * @param array<int, AssetImageJob> $jobs
     * @return array<int, AssetImageJob>
     */
    private static function latestFailedImageJobsPerRetryKey(array $jobs): array
    {
        $latest = [];
        foreach ($jobs as $job) {
            if (!$job instanceof AssetImageJob) {
                continue;
            }

            $key = self::imageRetryKey($job);
            $current = $latest[$key] ?? null;
            if (!$current instanceof AssetImageJob || (int) $job->getAttr('id') > (int) $current->getAttr('id')) {
                $latest[$key] = $job;
            }
        }

        return array_values($latest);
    }

    private static function hasPendingImageRetryJob(int $userId, AssetImageJob $job): bool
    {
        $query = AssetImageJob::where('user_id', $userId)
            ->whereIn('status', ImageJobStatus::active())
            ->where('asset_id', (int) $job->getAttr('asset_id'))
            ->where('view_type', (string) $job->getAttr('view_type'));

        $assetImageId = (int) ($job->getAttr('asset_image_id') ?? 0);
        if ($assetImageId > 0) {
            $query->where('asset_image_id', $assetImageId);
        } else {
            $query->whereNull('asset_image_id');
        }

        return $query->count() > 0;
    }

    private static function imageRetryKey(AssetImageJob $job): string
    {
        $assetId = (int) ($job->getAttr('asset_id') ?? 0);
        $assetImageId = (int) ($job->getAttr('asset_image_id') ?? 0);
        $viewType = (string) ($job->getAttr('view_type') ?? 'main');

        return $assetId . ':' . $assetImageId . ':' . $viewType;
    }

    /**
     * 取消排队中的资产生图任务（仅 status=queued；running/waiting 不动），返回受影响条数。
     *
     * @param array<int, int|string> $jobIds
     */
    public static function cancelQueuedImageJobs(
        int $userId,
        int $seriesId = 0,
        int $limit = 0,
        array $jobIds = [],
        int $assetId = 0,
        string $errorMessage = '任务已由数字员工取消',
    ): int {
        $message = trim($errorMessage) !== '' ? trim($errorMessage) : '任务已取消';
        $values = [
            'status' => ImageJobStatus::CANCELLED,
            'error_message' => $message,
            'finished_at' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s'),
        ];
        $filters = [
            'series_id' => $seriesId,
            'limit' => $limit,
            'job_ids' => $jobIds,
            'asset_id' => $assetId,
        ];
        $count = self::updateAssetImageJobs($userId, ImageJobStatus::QUEUED, $filters, $values);
        if ($count > 0) {
            RedisCache::bumpVersion('assets');
        }

        return $count;
    }

    /** 把失败的视频任务重新排队，返回受影响条数。 */
    public static function retryFailedVideoJobs(int $userId, int $episodeId = 0): int
    {
        $query = VideoJob::where('user_id', $userId)->where('status', 'failed');
        if ($episodeId > 0) {
            $query->where('episode_id', $episodeId);
        }

        $count = (int) $query->update([
            'status' => 'queued',
            'error_message' => '',
            'update_time' => date('Y-m-d H:i:s'),
        ]);
        if ($count > 0) {
            RedisCache::bumpVersion('series');
        }

        return $count;
    }

    /** 取消排队/阻塞中的视频任务，返回受影响条数。 */
    public static function cancelQueuedVideoJobs(int $userId, int $episodeId = 0): int
    {
        $query = VideoJob::where('user_id', $userId)->whereIn('status', ['queued', 'blocked']);
        if ($episodeId > 0) {
            $query->where('episode_id', $episodeId);
        }

        $count = (int) $query->update([
            'status' => 'cancelled',
            'error_message' => '任务已由数字员工取消',
            'update_time' => date('Y-m-d H:i:s'),
        ]);
        if ($count > 0) {
            RedisCache::bumpVersion('series');
        }

        return $count;
    }

    /**
     * @param array{series_id?: int, limit?: int, job_ids?: array<int, int|string>} $filters
     * @param array<string, mixed> $values
     */
    private static function updateAssetImageJobs(int $userId, string $status, array $filters, array $values): int
    {
        $query = self::buildAssetImageJobQuery($userId, $status, $filters);
        if ($query === null) {
            return 0;
        }

        return (int) $query->update($values);
    }

    /**
     * @param array{series_id?: int, limit?: int, job_ids?: array<int, int|string>} $filters
     * @return array<int, AssetImageJob>
     */
    private static function selectAssetImageJobs(int $userId, string $status, array $filters): array
    {
        $query = self::buildAssetImageJobQuery($userId, $status, $filters);
        if ($query === null) {
            return [];
        }

        $rows = $query->select();
        $jobs = [];
        foreach ($rows as $row) {
            if ($row instanceof AssetImageJob) {
                $jobs[] = $row;
            }
        }

        return $jobs;
    }

    /**
     * @param array{series_id?: int, asset_id?: int, limit?: int, job_ids?: array<int, int|string>} $filters
     */
    private static function buildAssetImageJobQuery(int $userId, string $status, array $filters)
    {
        $query = AssetImageJob::where('user_id', $userId)->where('status', $status);
        if ($status === 'failed') {
            $query->where('view_type', '<>', 'look');
        }

        $seriesId = (int) ($filters['series_id'] ?? 0);
        $assetIds = self::validAssetIdsForImageJobs($userId, $seriesId);
        if ($assetIds === []) {
            return null;
        }

        $assetId = (int) ($filters['asset_id'] ?? 0);
        if ($assetId > 0) {
            if (!in_array($assetId, $assetIds, true)) {
                return null;
            }
            $query->where('asset_id', $assetId);
        } else {
            $query->whereIn('asset_id', $assetIds);
        }

        $jobIds = self::normalizeIds($filters['job_ids'] ?? []);
        if ($jobIds !== []) {
            $query->whereIn('id', $jobIds);
        }

        $limit = max(0, (int) ($filters['limit'] ?? 0));
        if ($limit > 0) {
            $ids = self::normalizeIds($query->order('id', 'desc')->limit($limit)->column('id'));
            if ($ids === []) {
                return null;
            }
            $query = AssetImageJob::where('user_id', $userId)->where('status', $status)->whereIn('id', $ids);
            if ($status === 'failed') {
                $query->where('view_type', '<>', 'look');
            }
        }

        return $query->order('id', 'desc');
    }

    /**
     * 失败任务重试时优先切到当前健康的 ToAPIs 备份模型。
     * 仅影响这次重试，不改变默认模型选择逻辑。
     */
    private static function resolveRetryBackupImageModelId(int $userId): int
    {
        foreach (ImageGenerationOptions::PREFERRED_MODEL_IDS as $candidateModelId) {
            $model = ModelConfig::where('type', 'image')
                ->where('model_id', $candidateModelId)
                ->where('enabled', 1)
                ->whereRaw("((`scope` = 'global' AND `user_id` = 0) OR (`scope` = 'user' AND `user_id` = ?))", [$userId])
                ->orderRaw("CASE WHEN `scope` = 'user' THEN 0 ELSE 1 END ASC")
                ->order(['is_default' => 'desc', 'sort' => 'asc', 'id' => 'asc'])
                ->find();

            if ($model instanceof ModelConfig) {
                return (int) $model->getAttr('id');
            }
        }

        return 0;
    }

    private static function resolveRetryModelConfigId(AssetImageJob $job, int $backupModelId): int
    {
        $currentModelId = (int) $job->getAttr('model_config_id');
        if ($backupModelId <= 0 || $currentModelId <= 0) {
            return $currentModelId;
        }

        $currentModel = ModelConfig::where('id', $currentModelId)->find();
        if (!$currentModel instanceof ModelConfig) {
            return $currentModelId;
        }

        $currentModelCode = trim((string) $currentModel->getAttr('model_id'));
        if (!in_array($currentModelCode, ['gpt-image-2', 'gpt-image-2-high'], true)) {
            return $currentModelId;
        }

        return $backupModelId;
    }

    /**
     * 只返回仍挂在当前用户有效作品下的资产 id。
     * 旧作品被删后残留的 asset_image_jobs 不能再参与统计、重试或取消。
     *
     * @return array<int, int>
     */
    public static function validAssetIdsForImageJobs(int $userId, int $seriesId = 0): array
    {
        $query = Db::name('assets')
            ->alias('a')
            ->join('series s', 's.id = a.series_id')
            ->where('a.user_id', $userId)
            ->where('s.user_id', $userId);

        if ($seriesId > 0) {
            $query->where('a.series_id', $seriesId);
        }

        return self::normalizeIds($query->column('a.id'));
    }

    /** @param mixed $ids */
    private static function normalizeIds(mixed $ids): array
    {
        if (!is_array($ids)) {
            return [];
        }

        $normalized = [];
        foreach ($ids as $id) {
            $intId = (int) $id;
            if ($intId > 0) {
                $normalized[$intId] = $intId;
            }
        }

        return array_values($normalized);
    }
}
