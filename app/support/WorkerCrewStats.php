<?php

declare(strict_types=1);

namespace app\support;

use app\model\AgentTask;
use app\model\Asset;
use app\model\AssetImageJob;
use app\model\Episode;
use app\model\Series;
use app\model\VideoJob;
use app\model\WorkflowRun;

/**
 * 数字员工状态聚合（按用户隔离）。
 * 供 WorkerStatusController（前端悬浮组件轮询）与 WorkerAgentService（员工对话的
 * get_status 工具）复用，保持两边看到的状态一致。
 */
class WorkerCrewStats
{
    /** AgentTask 的终态，不算"在岗工作"。 */
    public const AGENT_TERMINAL_STATUSES = ['completed', 'failed', 'cancelled'];

    /** @return array<int, array<string, mixed>> */
    public static function all(int $userId): array
    {
        $todayStart = date('Y-m-d 00:00:00');

        return [self::assistant($userId, $todayStart)];
    }

    /** @return array<string, mixed>|null */
    public static function worker(int $userId, string $key): ?array
    {
        $todayStart = date('Y-m-d 00:00:00');

        return match ($key) {
            'assistant' => self::assistant($userId, $todayStart),
            'producer' => self::producer($userId, $todayStart),
            'asset' => self::asset($userId, $todayStart),
            'video' => self::video($userId, $todayStart),
            default => null,
        };
    }

    /**
     * 制作助手：汇总原制片、资产、视频三类后台任务。
     * 领域统计继续复用原实现，避免合并入口时改变任务口径。
     */
    public static function assistant(int $userId, string $todayStart): array
    {
        $domains = [
            self::producer($userId, $todayStart),
            self::asset($userId, $todayStart),
            self::video($userId, $todayStart),
        ];

        $queued = 0;
        $running = 0;
        $failedToday = 0;
        $doneToday = 0;
        $currentItems = [];
        $recent = [];

        foreach ($domains as $domain) {
            $queued += (int) ($domain['queued'] ?? 0);
            $running += (int) ($domain['running'] ?? 0);
            $failedToday += (int) ($domain['failed_today'] ?? 0);
            $doneToday += (int) ($domain['done_today'] ?? 0);

            $current = trim((string) ($domain['current'] ?? ''));
            if ($current !== '') {
                $currentItems[] = $current;
            }
            foreach (($domain['recent'] ?? []) as $item) {
                if (is_array($item)) {
                    $recent[] = $item;
                }
            }
        }

        return self::payload(
            'assistant',
            '制作助手',
            '/admin/series',
            $queued,
            $running,
            $failedToday,
            $doneToday,
            implode('；', array_slice($currentItems, 0, 2)),
            array_slice($recent, 0, 6),
        );
    }

    /**
     * 制片助理：剧本工作流（拆解/写入）+ Agent 全流程任务。
     */
    public static function producer(int $userId, string $todayStart): array
    {
        $seriesQueued = self::seriesWorkflowRunQuery($userId)->where('status', 'queued')->count();
        $seriesRunning = self::seriesWorkflowRunQuery($userId)->where('status', 'running')->count();
        $episodeQueued = self::episodeWorkflowRunQuery($userId)->where('status', 'queued')->count();
        $episodeRunning = self::episodeWorkflowRunQuery($userId)->where('status', 'running')->count();
        $agentActive = AgentTask::where('user_id', $userId)
            ->whereNotIn('status', self::AGENT_TERMINAL_STATUSES)
            ->count();

        $failedToday = self::seriesWorkflowRunQuery($userId)
            ->where('status', 'failed')
            ->where('update_time', '>=', $todayStart)
            ->count()
            + self::episodeWorkflowRunQuery($userId)
                ->where('status', 'failed')
                ->where('update_time', '>=', $todayStart)
                ->count()
            + AgentTask::where('user_id', $userId)
                ->where('status', 'failed')
                ->where('update_time', '>=', $todayStart)
                ->count();
        $doneToday = self::seriesWorkflowRunQuery($userId)
            ->where('status', 'success')
            ->where('update_time', '>=', $todayStart)
            ->count()
            + self::episodeWorkflowRunQuery($userId)
                ->where('status', 'success')
                ->where('update_time', '>=', $todayStart)
                ->count();

        $current = '';
        $run = self::seriesWorkflowRunQuery($userId)->where('status', 'running')->order('id', 'desc')->find();
        if (!$run instanceof WorkflowRun) {
            $run = self::episodeWorkflowRunQuery($userId)->where('status', 'running')->order('id', 'desc')->find();
        }
        if (!$run instanceof WorkflowRun) {
            $run = self::seriesWorkflowRunQuery($userId)->where('status', 'queued')->order('id', 'asc')->find();
        }
        if (!$run instanceof WorkflowRun) {
            $run = self::episodeWorkflowRunQuery($userId)->where('status', 'queued')->order('id', 'asc')->find();
        }
        if ($run instanceof WorkflowRun) {
            $isEpisodeRun = self::isEpisodeWorkflowRun($run);
            $title = $isEpisodeRun
                ? self::episodeRunTitle($userId, $run)
                : self::seriesTitle($userId, (int) $run->getAttr('series_id'));
            $nodeLabel = trim((string) ($run->getAttr('current_node_label') ?? ''));
            if ((string) $run->getAttr('status') === 'running') {
                $current = $isEpisodeRun
                    ? '正在生成《' . $title . '》' . ($nodeLabel !== '' ? "（{$nodeLabel}）" : '')
                    : '正在推进《' . $title . '》' . ($nodeLabel !== '' ? "（{$nodeLabel}）" : '');
            } else {
                $current = '《' . $title . '》排队等待开工';
            }
        } elseif ($agentActive > 0) {
            $task = AgentTask::where('user_id', $userId)
                ->whereNotIn('status', self::AGENT_TERMINAL_STATUSES)
                ->order('id', 'desc')
                ->find();
            if ($task instanceof AgentTask) {
                $current = '正在跟进任务「' . (string) $task->getAttr('title') . '」';
            }
        }

        return self::payload(
            'producer',
            '制片助理',
            '/admin/series',
            $seriesQueued + $episodeQueued + $agentActive,
            $seriesRunning + $episodeRunning,
            $failedToday,
            $doneToday,
            $current,
            self::producerRecent($userId),
        );
    }

    /** @return array<int, array{title: string, status: string, time: string}> */
    private static function producerRecent(int $userId): array
    {
        $rows = WorkflowRun::where('user_id', $userId)
            ->order('update_time', 'desc')
            ->limit(3)
            ->select();

        $items = [];
        foreach ($rows as $run) {
            if (!$run instanceof WorkflowRun) {
                continue;
            }
            $isEpisodeRun = self::isEpisodeWorkflowRun($run);
            $title = $isEpisodeRun
                ? self::episodeRunTitle($userId, $run)
                : self::seriesTitle($userId, (int) $run->getAttr('series_id'));
            $items[] = self::recentItem($title, (string) $run->getAttr('status'), $run->getAttr('update_time'));
        }

        return $items;
    }

    private static function seriesWorkflowRunQuery(int $userId)
    {
        return WorkflowRun::where('user_id', $userId)
            ->whereRaw("(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.scope')) IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.scope')) <> 'episode')");
    }

    private static function episodeWorkflowRunQuery(int $userId)
    {
        return WorkflowRun::where('user_id', $userId)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.scope')) = 'episode'");
    }

    private static function isEpisodeWorkflowRun(WorkflowRun $run): bool
    {
        $payload = $run->getAttr('payload_json');
        return is_array($payload) && (string) ($payload['scope'] ?? '') === 'episode';
    }

    /**
     * 资产画师：资产参考图生成任务。
     */
    public static function asset(int $userId, string $todayStart): array
    {
        $validAssetIds = WorkerActions::validAssetIdsForImageJobs($userId);
        if ($validAssetIds === []) {
            return self::payload('asset', '资产画师', '/admin/assets', 0, 0, 0, 0, '', []);
        }

        $visibleJobs = static function () use ($userId, $validAssetIds) {
            return AssetImageJob::where('user_id', $userId)
                ->whereIn('asset_id', $validAssetIds)
                ->where('description', 'not like', '%[look_toapis_ingest]%')
                ->where('description', 'not like', '%[look_video_pencil]%');
        };
        $queued = $visibleJobs()->where('status', ImageJobStatus::QUEUED)->count();
        $running = $visibleJobs()->whereIn('status', ImageJobStatus::inFlight())->count();
        $failedToday = $visibleJobs()
            ->where('status', 'failed')
            ->where('update_time', '>=', $todayStart)
            ->count();
        $doneToday = $visibleJobs()
            ->where('status', 'success')
            ->where('update_time', '>=', $todayStart)
            ->count();

        $current = '';
        $job = $visibleJobs()->whereIn('status', ImageJobStatus::inFlight())->order('id', 'asc')->find();
        if (!$job instanceof AssetImageJob) {
            $job = $visibleJobs()->where('status', ImageJobStatus::QUEUED)->order('id', 'asc')->find();
        }
        if ($job instanceof AssetImageJob) {
            $assetName = '';
            $assetId = (int) ($job->getAttr('asset_id') ?? 0);
            if ($assetId > 0) {
                $asset = Asset::where('id', $assetId)->where('user_id', $userId)->find();
                $assetName = $asset instanceof Asset ? trim((string) $asset->getAttr('name')) : '';
            }
            if ($assetName !== '') {
                $status = (string) $job->getAttr('status');
                $current = ImageJobStatus::isBusy($status)
                    ? "正在绘制「{$assetName}」的参考图"
                    : "「{$assetName}」的参考图排队中";
            } else {
                $current = '资产参考图任务进行中';
            }
        }

        return self::payload('asset', '资产画师', '/admin/assets', $queued, $running, $failedToday, $doneToday, $current, self::assetRecent($userId, $validAssetIds));
    }

    /** @return array<int, array{title: string, status: string, time: string}> */
    private static function assetRecent(int $userId, array $validAssetIds): array
    {
        if ($validAssetIds === []) {
            return [];
        }

        $rows = AssetImageJob::where('user_id', $userId)
            ->whereIn('asset_id', $validAssetIds)
            ->where('description', 'not like', '%[look_toapis_ingest]%')
            ->where('description', 'not like', '%[look_video_pencil]%')
            ->order('update_time', 'desc')
            ->limit(3)
            ->select();

        $items = [];
        foreach ($rows as $job) {
            if (!$job instanceof AssetImageJob) {
                continue;
            }
            $assetId = (int) ($job->getAttr('asset_id') ?? 0);
            $assetName = '';
            if ($assetId > 0) {
                $asset = Asset::where('id', $assetId)->where('user_id', $userId)->find();
                $assetName = $asset instanceof Asset ? trim((string) $asset->getAttr('name')) : '';
            }
            $title = $assetName !== '' ? "{$assetName} 参考图" : '参考图任务';
            $items[] = self::recentItem($title, (string) $job->getAttr('status'), $job->getAttr('update_time'));
        }

        return $items;
    }

    /**
     * 视频剪辑师：分镜视频生成任务。
     */
    public static function video(int $userId, string $todayStart): array
    {
        $queued = VideoJob::where('user_id', $userId)->whereIn('status', ['queued', 'blocked'])->count();
        $running = VideoJob::where('user_id', $userId)->where('status', 'running')->count();
        $failedToday = VideoJob::where('user_id', $userId)
            ->where('status', 'failed')
            ->where('update_time', '>=', $todayStart)
            ->count();
        $doneToday = VideoJob::where('user_id', $userId)
            ->where('status', 'success')
            ->where('update_time', '>=', $todayStart)
            ->count();

        $current = '';
        $job = VideoJob::where('user_id', $userId)->where('status', 'running')->order('id', 'asc')->find();
        if (!$job instanceof VideoJob) {
            $job = VideoJob::where('user_id', $userId)->whereIn('status', ['queued', 'blocked'])->order('id', 'asc')->find();
        }
        if ($job instanceof VideoJob) {
            $episodeTitle = self::episodeTitle($userId, (int) ($job->getAttr('episode_id') ?? 0));
            $shotIndex = (int) ($job->getAttr('shot_index') ?? 0);
            $totalShots = (int) ($job->getAttr('total_shots') ?? 0);
            $shotPart = $shotIndex > 0 ? "第 {$shotIndex}" . ($totalShots > 0 ? "/{$totalShots}" : '') . ' 镜' : '';
            if ($episodeTitle !== '') {
                $current = (string) $job->getAttr('status') === 'running'
                    ? "正在剪辑《{$episodeTitle}》{$shotPart}"
                    : "《{$episodeTitle}》{$shotPart}排队中";
            } else {
                $current = '视频生成任务进行中';
            }
        }

        return self::payload('video', '视频剪辑师', '/admin/series', $queued, $running, $failedToday, $doneToday, $current, self::videoRecent($userId));
    }

    /** @return array<int, array{title: string, status: string, time: string}> */
    private static function videoRecent(int $userId): array
    {
        $rows = VideoJob::where('user_id', $userId)
            ->order('update_time', 'desc')
            ->limit(3)
            ->select();

        $items = [];
        foreach ($rows as $job) {
            if (!$job instanceof VideoJob) {
                continue;
            }
            $episodeTitle = self::episodeTitle($userId, (int) ($job->getAttr('episode_id') ?? 0));
            $shotIndex = (int) ($job->getAttr('shot_index') ?? 0);
            $shotPart = $shotIndex > 0 ? " 第 {$shotIndex} 镜" : '';
            $title = $episodeTitle !== '' ? "《{$episodeTitle}》{$shotPart}" : '视频任务';
            $items[] = self::recentItem($title, (string) $job->getAttr('status'), $job->getAttr('update_time'));
        }

        return $items;
    }

    /**
     * 统一员工状态结构；state 优先级：working > queued > alert > idle。
     */
    private static function payload(
        string $key,
        string $name,
        string $link,
        int $queued,
        int $running,
        int $failedToday,
        int $doneToday,
        string $current,
        array $recent = [],
    ): array {
        if ($running > 0) {
            $state = 'working';
        } elseif ($queued > 0) {
            $state = 'queued';
        } elseif ($failedToday > 0) {
            $state = 'alert';
        } else {
            $state = 'idle';
        }

        return [
            'key' => $key,
            'name' => $name,
            'link' => $link,
            'state' => $state,
            'queued' => $queued,
            'running' => $running,
            'failed_today' => $failedToday,
            'done_today' => $doneToday,
            'current' => $current,
            'recent' => $recent,
        ];
    }

    /**
     * 把一条任务摘要标准化为 recent 项：标题 + 状态 + 相对时间。
     * @return array{title: string, status: string, time: string}
     */
    private static function recentItem(string $title, string $status, mixed $updateTime): array
    {
        return [
            'title' => $title !== '' ? $title : '未命名任务',
            'status' => $status,
            'time' => self::relativeTime((string) $updateTime),
        ];
    }

    /** 把数据库时间转成"x分钟前"等相对描述；解析失败时回退原值。 */
    private static function relativeTime(string $datetime): string
    {
        $datetime = trim($datetime);
        if ($datetime === '') {
            return '';
        }
        $ts = strtotime($datetime);
        if ($ts === false) {
            return $datetime;
        }
        $diff = time() - $ts;
        if ($diff < 0) {
            return '刚刚';
        }
        if ($diff < 60) {
            return '刚刚';
        }
        if ($diff < 3600) {
            return (int) floor($diff / 60) . ' 分钟前';
        }
        if ($diff < 86400) {
            return (int) floor($diff / 3600) . ' 小时前';
        }
        if ($diff < 86400 * 7) {
            return (int) floor($diff / 86400) . ' 天前';
        }

        return date('m-d', $ts);
    }

    private static function seriesTitle(int $userId, int $seriesId): string
    {
        if ($seriesId <= 0) {
            return '未命名剧本';
        }
        $series = Series::where('id', $seriesId)->where('user_id', $userId)->find();
        $title = $series instanceof Series ? trim((string) $series->getAttr('title')) : '';

        return $title !== '' ? $title : '未命名剧本';
    }

    private static function episodeRunTitle(int $userId, WorkflowRun $run): string
    {
        $seriesTitle = self::seriesTitle($userId, (int) $run->getAttr('series_id'));
        $payload = $run->getAttr('payload_json');
        $episodeId = is_array($payload) ? (int) ($payload['episode_id'] ?? 0) : 0;
        if ($episodeId <= 0) {
            return $seriesTitle;
        }

        $episode = Episode::where('id', $episodeId)->where('user_id', $userId)->find();
        if (!$episode instanceof Episode) {
            return $seriesTitle;
        }

        $number = (int) ($episode->getAttr('number') ?? 0);
        $title = trim((string) ($episode->getAttr('title') ?? ''));
        $episodeLabel = $number > 0 ? "第 {$number} 集" : '当前剧集';
        if ($title !== '') {
            $episodeLabel .= " · {$title}";
        }

        return "{$seriesTitle} / {$episodeLabel}";
    }

    private static function episodeTitle(int $userId, int $episodeId): string
    {
        if ($episodeId <= 0) {
            return '';
        }
        $episode = Episode::where('id', $episodeId)->where('user_id', $userId)->find();

        return $episode instanceof Episode ? trim((string) $episode->getAttr('title')) : '';
    }
}
