<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\model\CreditLedger;
use app\model\User;
use app\support\CreditService;

class AdminUserController extends BaseController
{
    public function index()
    {
        $this->requireAdmin();
        CreditService::ensureSchema();

        $keyword = trim((string) $this->request->param('keyword', ''));
        $query = User::order(['role' => 'asc', 'id' => 'asc']);
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('username', '%' . $keyword . '%')
                    ->whereOr('display_name', 'like', '%' . $keyword . '%');
            });
        }

        return successCode([
            'list' => $query->select()->map(fn (User $user): array => $this->serializeUser($user))->toArray(),
        ]);
    }

    public function save()
    {
        $this->requireAdmin();
        CreditService::ensureSchema();

        $payload = $this->request->param();
        $username = trim((string) ($payload['username'] ?? ''));
        $displayName = trim((string) ($payload['display_name'] ?? ''));
        $password = (string) ($payload['password'] ?? 'Aa123456');
        $role = $this->normalizeRole((string) ($payload['role'] ?? 'user'));

        if ($username === '') {
            abort(422, '账号不能为空');
        }
        if (!$this->isValidUsername($username)) {
            abort(422, '账号只能包含字母、数字、下划线和短横线，长度 3-64 位');
        }
        if (User::where('username', $username)->find()) {
            abort(422, '账号已存在');
        }
        if ($password === '') {
            abort(422, '密码不能为空');
        }
        if (mb_strlen($password) < 6) {
            abort(422, '密码至少 6 位');
        }
        if (mb_strlen($displayName) > 120) {
            abort(422, '显示名称不能超过 120 个字符');
        }

        $user = User::create([
            'username' => $username,
            'display_name' => $displayName !== '' ? $displayName : $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
            'status' => (int) ($payload['status'] ?? 1) ? 1 : 0,
            'credit_balance' => 0,
        ]);

        return successCode($this->serializeUser($user), 'success', 201);
    }

    public function update()
    {
        $this->requireAdmin();
        CreditService::ensureSchema();

        $payload = $this->request->param();
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            abort(422, '用户 id 不能为空');
        }

        $user = User::where('id', $id)->find();
        if (!$user instanceof User) {
            abort(404, '用户不存在');
        }

        $updates = [];
        if (array_key_exists('display_name', $payload)) {
            $updates['display_name'] = trim((string) $payload['display_name']);
        }
        if (array_key_exists('role', $payload)) {
            $nextRole = $this->normalizeRole((string) $payload['role']);
            if ((string) $user->getAttr('role') === 'admin' && $nextRole !== 'admin' && $this->enabledAdminCount((int) $user->getAttr('id')) <= 0) {
                abort(422, '不能移除最后一个管理员');
            }
            $updates['role'] = $nextRole;
        }
        if (array_key_exists('status', $payload)) {
            $nextStatus = (int) $payload['status'] ? 1 : 0;
            $nextRole = (string) ($updates['role'] ?? $user->getAttr('role'));
            if ($nextStatus === 0 && $nextRole === 'admin' && $this->enabledAdminCount((int) $user->getAttr('id')) <= 0) {
                abort(422, '不能禁用最后一个管理员');
            }
            $updates['status'] = $nextStatus;
        }
        if (array_key_exists('password', $payload) && (string) $payload['password'] !== '') {
            $password = (string) $payload['password'];
            if (mb_strlen($password) < 6) {
                abort(422, '密码至少 6 位');
            }
            $updates['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }

        if ($updates !== []) {
            $user->save($updates);
        }

        $fresh = User::where('id', $id)->find();
        return successCode($this->serializeUser($fresh instanceof User ? $fresh : $user));
    }

    public function resetPassword()
    {
        $this->requireAdmin();

        $payload = $this->request->param();
        $id = (int) ($payload['id'] ?? 0);
        $password = (string) ($payload['password'] ?? '');
        if ($id <= 0 || $password === '') {
            abort(422, '用户 id 和新密码不能为空');
        }
        if (mb_strlen($password) < 6) {
            abort(422, '密码至少 6 位');
        }

        $user = User::where('id', $id)->find();
        if (!$user instanceof User) {
            abort(404, '用户不存在');
        }
        $user->save(['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);

        return successCode();
    }

    public function topUpCredit()
    {
        $this->requireAdmin();
        CreditService::ensureSchema();

        $payload = $this->request->param();
        $id = (int) ($payload['id'] ?? 0);
        $amount = round((float) ($payload['amount'] ?? 0), 2);
        $note = trim((string) ($payload['note'] ?? ''));

        $user = User::where('id', $id)->find();
        if (!$user instanceof User) {
            abort(404, '用户不存在');
        }

        $before = round((float) ($user->getAttr('credit_balance') ?? 0), 2);
        $result = CreditService::topUp($id, $amount, $this->currentUserId(), $note);
        $fresh = User::where('id', $id)->find();

        $this->writeAdminOperationLog([
            'action' => 'user.credit_topup',
            'target_type' => 'user',
            'target_id' => $id,
            'target_name_snapshot' => (string) $user->getAttr('username'),
            'result' => 'success',
            'meta_json' => [
                'amount' => $amount,
                'note' => $note,
                'ledger_id' => $result['ledger_id'],
            ],
            'before_json' => ['credit_balance' => $before],
            'after_json' => ['credit_balance' => $result['balance']],
        ]);

        return successCode([
            'user' => $this->serializeUser($fresh instanceof User ? $fresh : $user),
            'ledger_id' => $result['ledger_id'],
            'balance' => $result['balance'],
        ]);
    }

    public function creditLedger()
    {
        $this->requireAdmin();
        CreditService::ensureSchema();

        $payload = $this->request->param();
        $id = (int) ($payload['id'] ?? 0);
        $limit = max(1, min(200, (int) ($payload['limit'] ?? 50)));
        if ($id <= 0) {
            abort(422, '用户 id 不能为空');
        }

        $user = User::where('id', $id)->find();
        if (!$user instanceof User) {
            abort(404, '用户不存在');
        }

        $list = CreditLedger::where('user_id', $id)
            ->order('id', 'desc')
            ->limit($limit)
            ->select()
            ->map(static function (CreditLedger $row): array {
                return [
                    'id' => (int) $row->getAttr('id'),
                    'entry_type' => (string) $row->getAttr('entry_type'),
                    'amount' => round((float) $row->getAttr('amount'), 2),
                    'balance_after' => round((float) $row->getAttr('balance_after'), 2),
                    'modality' => (string) $row->getAttr('modality'),
                    'model_id' => (string) $row->getAttr('model_id'),
                    'ref_type' => (string) $row->getAttr('ref_type'),
                    'ref_id' => (int) $row->getAttr('ref_id'),
                    'operator_id' => (int) $row->getAttr('operator_id'),
                    'description' => (string) $row->getAttr('description'),
                    'create_time' => $row->getAttr('create_time'),
                ];
            })
            ->toArray();

        return successCode([
            'user' => $this->serializeUser($user),
            'list' => $list,
        ]);
    }

    private function serializeUser(User $user): array
    {
        CreditService::ensureSchema();

        return [
            'id' => (int) $user->getAttr('id'),
            'username' => (string) $user->getAttr('username'),
            'display_name' => (string) $user->getAttr('display_name'),
            'role' => $this->normalizeRole((string) $user->getAttr('role')),
            'status' => (int) $user->getAttr('status'),
            'credit_balance' => round((float) ($user->getAttr('credit_balance') ?? 0), 2),
            'create_time' => $user->getAttr('create_time'),
            'update_time' => $user->getAttr('update_time'),
        ];
    }

    private function normalizeRole(string $role): string
    {
        return $role === 'admin' ? 'admin' : 'user';
    }

    private function isValidUsername(string $username): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{3,64}$/', $username) === 1;
    }

    private function enabledAdminCount(int $excludingId = 0): int
    {
        $query = User::where('role', 'admin')->where('status', 1);
        if ($excludingId > 0) {
            $query->where('id', '<>', $excludingId);
        }

        return (int) $query->count();
    }
}
