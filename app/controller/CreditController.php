<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\model\CreditLedger;
use app\model\User;
use app\support\CreditService;
use think\facade\Db;

/**
 * 当前登录用户的积分清单（仅本人流水，不含他人）。
 */
class CreditController extends BaseController
{
    /**
     * 本人积分消耗汇总 + 分页流水。
     */
    public function ledger()
    {
        CreditService::ensureSchema();
        $userId = $this->currentUserId();
        $user = $this->currentUser();

        $payload = $this->request->param();
        $page = max(1, (int) ($payload['page'] ?? 1));
        $pageSize = max(1, min(100, (int) ($payload['page_size'] ?? 20)));
        $entryType = strtolower(trim((string) ($payload['entry_type'] ?? '')));
        if ($entryType !== '' && !in_array($entryType, ['consume', 'topup', 'refund', 'adjust'], true)) {
            $entryType = '';
        }
        $modality = strtolower(trim((string) ($payload['modality'] ?? '')));
        if ($modality !== '' && !in_array($modality, ['text', 'image', 'video'], true)) {
            $modality = '';
        }
        $startDate = trim((string) ($payload['start_date'] ?? ''));
        $endDate = trim((string) ($payload['end_date'] ?? ''));

        $query = CreditLedger::where('user_id', $userId);
        if ($entryType !== '') {
            $query->where('entry_type', $entryType);
        }
        if ($modality !== '') {
            $query->where('modality', $modality);
        }
        if ($startDate !== '') {
            $query->where('create_time', '>=', $startDate . ' 00:00:00');
        }
        if ($endDate !== '') {
            $query->where('create_time', '<=', $endDate . ' 23:59:59');
        }

        $total = (int) (clone $query)->count();
        $rows = (clone $query)
            ->order('id', 'desc')
            ->page($page, $pageSize)
            ->select();

        $list = [];
        foreach ($rows as $row) {
            if (!$row instanceof CreditLedger) {
                continue;
            }
            $list[] = $this->serializeLedgerRow($row);
        }

        return successCode([
            'balance' => round((float) ($user->getAttr('credit_balance') ?? 0), 2),
            'summary' => $this->buildSummary($userId, $modality, $startDate, $endDate),
            'list' => $list,
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
            ],
        ]);
    }

    /**
     * @return array<string, float|int>
     */
    private function buildSummary(int $userId, string $modality, string $startDate, string $endDate): array
    {
        $query = Db::name('credit_ledger')
            ->where('user_id', $userId)
            ->where('entry_type', 'consume');
        if ($modality !== '') {
            $query->where('modality', $modality);
        }
        if ($startDate !== '') {
            $query->where('create_time', '>=', $startDate . ' 00:00:00');
        }
        if ($endDate !== '') {
            $query->where('create_time', '<=', $endDate . ' 23:59:59');
        }

        $row = $query->fieldRaw(implode(', ', [
            'COUNT(*) AS consume_count',
            'ROUND(COALESCE(SUM(ABS(amount)), 0), 2) AS credits_spent',
            "ROUND(COALESCE(SUM(CASE WHEN modality = 'text' THEN ABS(amount) ELSE 0 END), 0), 2) AS credits_text",
            "ROUND(COALESCE(SUM(CASE WHEN modality = 'image' THEN ABS(amount) ELSE 0 END), 0), 2) AS credits_image",
            "ROUND(COALESCE(SUM(CASE WHEN modality = 'video' THEN ABS(amount) ELSE 0 END), 0), 2) AS credits_video",
        ]))->find();

        $summary = is_array($row) ? $row : [];
        $spent = round((float) ($summary['credits_spent'] ?? 0), 2);

        return [
            'consume_count' => (int) ($summary['consume_count'] ?? 0),
            'credits_spent' => $spent,
            'credits_text' => round((float) ($summary['credits_text'] ?? 0), 2),
            'credits_image' => round((float) ($summary['credits_image'] ?? 0), 2),
            'credits_video' => round((float) ($summary['credits_video'] ?? 0), 2),
            'credit_unit_cny' => 0.01,
            'spent_cny' => round($spent * 0.01, 4),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeLedgerRow(CreditLedger $row): array
    {
        $amount = round((float) $row->getAttr('amount'), 2);

        return [
            'id' => (int) $row->getAttr('id'),
            'entry_type' => (string) $row->getAttr('entry_type'),
            'amount' => $amount,
            'amount_abs' => abs($amount),
            'balance_after' => round((float) $row->getAttr('balance_after'), 2),
            'modality' => (string) $row->getAttr('modality'),
            'model_id' => (string) $row->getAttr('model_id'),
            'ref_type' => (string) $row->getAttr('ref_type'),
            'ref_id' => (int) $row->getAttr('ref_id'),
            'description' => (string) $row->getAttr('description'),
            'create_time' => $row->getAttr('create_time'),
        ];
    }
}
