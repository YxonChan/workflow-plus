<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\support\CreditService;
use think\db\Query;
use think\facade\Db;

class AdminStatsController extends BaseController
{
    /**
     * 管理员积分消耗统计（只计 consume，不含充值）。
     */
    public function credits()
    {
        $this->requireAdmin();
        CreditService::ensureSchema();

        $groupBy = trim((string) $this->request->param('group_by', 'day'));
        if (!in_array($groupBy, ['day', 'user', 'model', 'modality'], true)) {
            $groupBy = 'day';
        }
        $userId = (int) $this->request->param('user_id', 0);
        $modality = strtolower(trim((string) $this->request->param('modality', '')));
        if ($modality !== '' && !in_array($modality, ['text', 'image', 'video'], true)) {
            $modality = '';
        }
        $startDate = trim((string) $this->request->param('start_date', ''));
        $endDate = trim((string) $this->request->param('end_date', ''));

        $keySql = match ($groupBy) {
            'user' => 'CAST(c.user_id AS CHAR)',
            'model' => "COALESCE(NULLIF(c.model_id, ''), '未知模型')",
            'modality' => "COALESCE(NULLIF(c.modality, ''), 'unknown')",
            default => 'DATE(c.create_time)',
        };
        $labelSql = match ($groupBy) {
            'user' => "COALESCE(NULLIF(u.display_name, ''), u.username, CONCAT('用户#', c.user_id))",
            'model' => "COALESCE(NULLIF(c.model_id, ''), '未知模型')",
            'modality' => "COALESCE(NULLIF(c.modality, ''), 'unknown')",
            default => 'DATE(c.create_time)',
        };

        $rows = $this->creditConsumeQuery($userId, $modality, $startDate, $endDate)
            ->fieldRaw(implode(', ', [
                "{$keySql} AS stat_key",
                "{$labelSql} AS stat_label",
                'COUNT(*) AS consume_count',
                'ROUND(SUM(ABS(c.amount)), 2) AS credits_spent',
                "ROUND(SUM(CASE WHEN c.modality = 'text' THEN ABS(c.amount) ELSE 0 END), 2) AS credits_text",
                "ROUND(SUM(CASE WHEN c.modality = 'image' THEN ABS(c.amount) ELSE 0 END), 2) AS credits_image",
                "ROUND(SUM(CASE WHEN c.modality = 'video' THEN ABS(c.amount) ELSE 0 END), 2) AS credits_video",
            ]))
            ->group('stat_key, stat_label')
            ->orderRaw('credits_spent DESC, stat_key ASC')
            ->select()
            ->toArray();

        $summaryRow = $this->creditConsumeQuery($userId, $modality, $startDate, $endDate)
            ->fieldRaw(implode(', ', [
                'COUNT(*) AS consume_count',
                'ROUND(COALESCE(SUM(ABS(c.amount)), 0), 2) AS credits_spent',
                "ROUND(COALESCE(SUM(CASE WHEN c.modality = 'text' THEN ABS(c.amount) ELSE 0 END), 0), 2) AS credits_text",
                "ROUND(COALESCE(SUM(CASE WHEN c.modality = 'image' THEN ABS(c.amount) ELSE 0 END), 0), 2) AS credits_image",
                "ROUND(COALESCE(SUM(CASE WHEN c.modality = 'video' THEN ABS(c.amount) ELSE 0 END), 0), 2) AS credits_video",
            ]))
            ->find();

        $summary = is_array($summaryRow) ? $summaryRow : [];
        $spent = round((float) ($summary['credits_spent'] ?? 0), 2);

        $list = array_map(static function (array $row): array {
            return [
                'key' => (string) ($row['stat_key'] ?? ''),
                'label' => (string) ($row['stat_label'] ?? ''),
                'consume_count' => (int) ($row['consume_count'] ?? 0),
                'credits_spent' => round((float) ($row['credits_spent'] ?? 0), 2),
                'credits_text' => round((float) ($row['credits_text'] ?? 0), 2),
                'credits_image' => round((float) ($row['credits_image'] ?? 0), 2),
                'credits_video' => round((float) ($row['credits_video'] ?? 0), 2),
            ];
        }, $rows);

        return successCode([
            'group_by' => $groupBy,
            'summary' => [
                'consume_count' => (int) ($summary['consume_count'] ?? 0),
                'credits_spent' => $spent,
                'credits_text' => round((float) ($summary['credits_text'] ?? 0), 2),
                'credits_image' => round((float) ($summary['credits_image'] ?? 0), 2),
                'credits_video' => round((float) ($summary['credits_video'] ?? 0), 2),
                'credit_unit_cny' => 0.01,
                'spent_cny' => round($spent * 0.01, 4),
            ],
            'list' => $list,
        ]);
    }

    private function creditConsumeQuery(int $userId, string $modality, string $startDate, string $endDate): Query
    {
        $query = Db::name('credit_ledger')->alias('c')
            ->leftJoin('users u', 'u.id = c.user_id')
            ->where('c.entry_type', 'consume');

        if ($userId > 0) {
            $query->where('c.user_id', $userId);
        }
        if ($modality !== '') {
            $query->where('c.modality', $modality);
        }
        if ($startDate !== '') {
            $query->where('c.create_time', '>=', $startDate . ' 00:00:00');
        }
        if ($endDate !== '') {
            $query->where('c.create_time', '<=', $endDate . ' 23:59:59');
        }

        return $query;
    }
}
