<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use think\facade\Db;

class AdminProductionController extends BaseController
{
    public function overview()
    {
        $this->requireAdmin();

        $statusRows = Db::name('workflow_runs')
            ->fieldRaw('status, COUNT(*) AS total')
            ->group('status')
            ->select()
            ->toArray();
        $runStatusCounts = [
            'queued' => 0,
            'running' => 0,
            'success' => 0,
            'failed' => 0,
            'cancelled' => 0,
        ];
        foreach ($statusRows as $row) {
            $status = (string) ($row['status'] ?? '');
            if (array_key_exists($status, $runStatusCounts)) {
                $runStatusCounts[$status] = (int) ($row['total'] ?? 0);
            }
        }

        return successCode([
            'metrics' => [
                'users' => (int) Db::name('users')->count(),
                'series' => (int) Db::name('series')->count(),
                'episodes' => (int) Db::name('episodes')->count(),
                'runs' => array_sum($runStatusCounts),
                'queued_runs' => $runStatusCounts['queued'],
                'running_runs' => $runStatusCounts['running'],
                'failed_runs' => $runStatusCounts['failed'],
                'today_runs' => (int) Db::name('workflow_runs')
                    ->where('create_time', '>=', date('Y-m-d') . ' 00:00:00')
                    ->count(),
            ],
            'run_status_counts' => $runStatusCounts,
            'recent_series' => $this->querySeriesRows(1, 5, 0, '')['list'],
            'recent_runs' => $this->queryRunRows(1, 8, 0, '', '')['list'],
        ]);
    }

    public function series()
    {
        $this->requireAdmin();

        [$page, $limit] = $this->pageParams();
        $userId = (int) $this->request->param('user_id', 0);
        $keyword = trim((string) $this->request->param('keyword', ''));

        return successCode($this->querySeriesRows($page, $limit, $userId, $keyword));
    }

    public function runs()
    {
        $this->requireAdmin();

        [$page, $limit] = $this->pageParams();
        $userId = (int) $this->request->param('user_id', 0);
        $status = trim((string) $this->request->param('status', ''));
        $keyword = trim((string) $this->request->param('keyword', ''));
        if (!in_array($status, ['queued', 'running', 'success', 'failed', 'cancelled'], true)) {
            $status = '';
        }

        return successCode($this->queryRunRows($page, $limit, $userId, $status, $keyword));
    }

    private function querySeriesRows(int $page, int $limit, int $userId, string $keyword): array
    {
        $countQuery = $this->applySeriesFilters(Db::name('series')->alias('s'), $userId, $keyword);
        $total = (int) $countQuery->count('s.id');

        $query = $this->applySeriesFilters(Db::name('series')->alias('s'), $userId, $keyword)
            ->leftJoin('users u', 'u.id = s.user_id')
            ->leftJoin('episodes e', 'e.series_id = s.id')
            ->fieldRaw(implode(', ', [
                's.id',
                's.user_id',
                's.title',
                's.description',
                's.visual_style',
                's.visual_style_variant',
                's.region',
                's.series_workflow_id',
                's.create_time',
                's.update_time',
                "MAX(COALESCE(NULLIF(u.display_name, ''), u.username, CONCAT('用户#', s.user_id))) AS owner_name",
                'MAX(u.username) AS owner_username',
                'COUNT(e.id) AS episode_count',
                "SUM(CASE WHEN e.status = 'done' THEN 1 ELSE 0 END) AS done_episode_count",
                "SUM(CASE WHEN e.status = 'production' THEN 1 ELSE 0 END) AS production_episode_count",
            ]))
            ->group('s.id, s.user_id, s.title, s.description, s.visual_style, s.visual_style_variant, s.region, s.series_workflow_id, s.create_time, s.update_time')
            ->order('s.id', 'desc')
            ->page($page, $limit);

        $rows = $query->select()->toArray();
        $this->attachLatestRuns($rows);

        return [
            'list' => array_map(fn (array $row): array => $this->serializeSeriesRow($row), $rows),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    private function queryRunRows(int $page, int $limit, int $userId, string $status, string $keyword): array
    {
        $countQuery = $this->applyRunFilters(
            Db::name('workflow_runs')->alias('r')->leftJoin('series s', 's.id = r.series_id'),
            $userId,
            $status,
            $keyword
        );
        $total = (int) $countQuery->count('r.id');

        $rows = $this->applyRunFilters(
            Db::name('workflow_runs')->alias('r')
                ->leftJoin('series s', 's.id = r.series_id')
                ->leftJoin('users u', 'u.id = r.user_id'),
            $userId,
            $status,
            $keyword
        )
            ->fieldRaw(implode(', ', [
                'r.id',
                'r.user_id',
                'r.series_id',
                'r.workflow_id',
                'r.episode_workflow_id',
                'r.status',
                'r.target_episode_count',
                'r.progress',
                'r.current_node_label',
                'r.error_message',
                'r.started_at',
                'r.finished_at',
                'r.create_time',
                'r.update_time',
                's.title AS series_title',
                "COALESCE(NULLIF(u.display_name, ''), u.username, CONCAT('用户#', r.user_id)) AS owner_name",
                'u.username AS owner_username',
            ]))
            ->order('r.id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        return [
            'list' => array_map(fn (array $row): array => $this->serializeRunRow($row), $rows),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    private function applySeriesFilters($query, int $userId, string $keyword)
    {
        if ($userId > 0) {
            $query->where('s.user_id', $userId);
        }
        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $query->where(function ($q) use ($like) {
                $q->whereLike('s.title', $like)
                    ->whereOr('s.description', 'like', $like);
            });
        }
        return $query;
    }

    private function applyRunFilters($query, int $userId, string $status, string $keyword)
    {
        if ($userId > 0) {
            $query->where('r.user_id', $userId);
        }
        if ($status !== '') {
            $query->where('r.status', $status);
        }
        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $query->where(function ($q) use ($like) {
                $q->whereLike('s.title', $like)
                    ->whereOr('r.current_node_label', 'like', $like)
                    ->whereOr('r.error_message', 'like', $like);
            });
        }
        return $query;
    }

    private function attachLatestRuns(array &$rows): void
    {
        $seriesIds = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $seriesIds[] = $id;
            }
        }
        $seriesIds = array_values(array_unique($seriesIds));
        if ($seriesIds === []) {
            return;
        }

        $runRows = Db::name('workflow_runs')
            ->whereIn('series_id', $seriesIds)
            ->field(['id', 'series_id', 'status', 'progress', 'current_node_label', 'error_message', 'create_time'])
            ->order('id', 'desc')
            ->select()
            ->toArray();
        $latestBySeries = [];
        foreach ($runRows as $run) {
            $seriesId = (int) ($run['series_id'] ?? 0);
            if ($seriesId > 0 && !isset($latestBySeries[$seriesId])) {
                $latestBySeries[$seriesId] = $run;
            }
        }

        foreach ($rows as &$row) {
            $row['latest_run'] = $latestBySeries[(int) ($row['id'] ?? 0)] ?? null;
        }
        unset($row);
    }

    private function serializeSeriesRow(array $row): array
    {
        $latestRun = is_array($row['latest_run'] ?? null) ? $row['latest_run'] : null;
        return [
            'id' => (int) ($row['id'] ?? 0),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'owner_name' => (string) ($row['owner_name'] ?? ''),
            'owner_username' => (string) ($row['owner_username'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'visual_style' => (string) ($row['visual_style'] ?? ''),
            'visual_style_variant' => (string) ($row['visual_style_variant'] ?? ''),
            'region' => (string) ($row['region'] ?? ''),
            'series_workflow_id' => $row['series_workflow_id'] === null ? null : (int) $row['series_workflow_id'],
            'episode_count' => (int) ($row['episode_count'] ?? 0),
            'done_episode_count' => (int) ($row['done_episode_count'] ?? 0),
            'production_episode_count' => (int) ($row['production_episode_count'] ?? 0),
            'latest_run' => $latestRun === null ? null : [
                'id' => (int) ($latestRun['id'] ?? 0),
                'status' => (string) ($latestRun['status'] ?? ''),
                'progress' => (int) ($latestRun['progress'] ?? 0),
                'current_node_label' => (string) ($latestRun['current_node_label'] ?? ''),
                'error_message' => (string) ($latestRun['error_message'] ?? ''),
                'create_time' => $latestRun['create_time'] ?? null,
            ],
            'create_time' => $row['create_time'] ?? null,
            'update_time' => $row['update_time'] ?? null,
        ];
    }

    private function serializeRunRow(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'owner_name' => (string) ($row['owner_name'] ?? ''),
            'owner_username' => (string) ($row['owner_username'] ?? ''),
            'series_id' => (int) ($row['series_id'] ?? 0),
            'series_title' => (string) ($row['series_title'] ?? ''),
            'workflow_id' => (int) ($row['workflow_id'] ?? 0),
            'episode_workflow_id' => $row['episode_workflow_id'] === null ? null : (int) $row['episode_workflow_id'],
            'status' => (string) ($row['status'] ?? ''),
            'target_episode_count' => $row['target_episode_count'] === null ? null : (int) $row['target_episode_count'],
            'progress' => (int) ($row['progress'] ?? 0),
            'current_node_label' => (string) ($row['current_node_label'] ?? ''),
            'error_message' => (string) ($row['error_message'] ?? ''),
            'started_at' => $row['started_at'] ?? null,
            'finished_at' => $row['finished_at'] ?? null,
            'create_time' => $row['create_time'] ?? null,
            'update_time' => $row['update_time'] ?? null,
        ];
    }

    private function pageParams(): array
    {
        $page = max(1, (int) $this->request->param('page', 1));
        $limit = max(1, min(100, (int) $this->request->param('limit', 20)));
        return [$page, $limit];
    }
}
