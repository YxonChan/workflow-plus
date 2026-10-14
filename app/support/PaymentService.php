<?php

declare(strict_types=1);

namespace app\support;

use app\model\CreditLedger;
use app\model\PaymentOrder;
use app\model\User;
use GuzzleHttp\Exception\ClientException;
use think\facade\Db;
use think\facade\Log;

class PaymentService
{
    private static bool $schemaReady = false;

    public static function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }
        CreditService::ensureSchema();
        $sql = file_get_contents(dirname(__DIR__, 2) . '/database/sql/20261009_add_wechat_payment.sql');
        if (!is_string($sql)) {
            throw new \RuntimeException('支付订单迁移文件缺失');
        }
        Db::execute($sql);
        self::$schemaReady = true;
    }

    public static function packages(): array
    {
        return (array) config('payment.packages');
    }

    public static function available(): bool
    {
        try {
            (new WechatPayClient())->assertReady();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function createNativeOrder(int $userId, string $packageId, string $requestId, ?WechatPayClient $client = null): array
    {
        $package = self::packages()[$packageId] ?? null;
        if (!is_array($package) || !preg_match('/^[a-f0-9]{32}$/D', $requestId)) {
            abort(422, '积分套餐或下单请求无效');
        }
        $client = $client ?? new WechatPayClient();
        try {
            $client->assertReady();
        } catch (\Throwable) {
            abort(503, '微信支付暂未开通，请稍后再试');
        }
        self::ensureSchema();
        $existing = PaymentOrder::where('user_id', $userId)->where('request_id', $requestId)->find();
        if ($existing instanceof PaymentOrder) {
            if ($existing->getAttr('package_id') !== $packageId) {
                abort(422, '下单请求与原套餐不一致');
            }
            return self::serializeOrder($existing);
        }

        try {
            $order = PaymentOrder::create([
                'order_no' => 'WF' . date('ymdHis') . bin2hex(random_bytes(8)),
                'request_id' => $requestId,
                'user_id' => $userId,
                'package_id' => $packageId,
                'app_id' => $client->appId(),
                'merchant_id' => $client->merchantId(),
                'amount_fen' => (int) round((float) $package['amount_cny'] * 100),
                'credit_amount' => $package['credits'],
                'status' => 'pending',
                'expires_at' => date('Y-m-d H:i:s', time() + 900),
                'meta_json' => ['package_name' => $package['name']],
            ]);
        } catch (\Throwable $e) {
            // Concurrent retries share one order through the database unique key.
            $existing = PaymentOrder::where('user_id', $userId)->where('request_id', $requestId)->find();
            if ($existing instanceof PaymentOrder) {
                if ($existing->getAttr('package_id') !== $packageId) {
                    abort(422, '下单请求与原套餐不一致');
                }
                return self::serializeOrder($existing);
            }
            throw $e;
        }

        try {
            $codeUrl = $client->createNative($order->toArray() + [
                'description' => (string) config('payment.wechat.description_prefix') . ' - ' . $package['name'],
            ]);
            // A notification can arrive while the upstream request is running.
            PaymentOrder::where('id', $order->getAttr('id'))->update(['code_url' => $codeUrl]);
        } catch (\Throwable $e) {
            if ($e instanceof ClientException && !in_array(self::providerErrorCode($e), ['SYSTEMERROR', 'ORDER_EXIST'], true)) {
                self::markClosed((int) $order->getAttr('id'));
            }
            // Timeouts and response-verification failures leave the result unknown.
            // Keep the order recoverable; only a verified query may settle it.
            Log::warning('[Payment] native submission requires reconciliation: order=' . $order->getAttr('order_no')
                . ' exception=' . get_class($e));
        }
        return self::serializeOrder(PaymentOrder::find($order->getAttr('id')));
    }

    public static function orderStatus(int $userId, int $orderId, ?WechatPayClient $client = null): array
    {
        self::ensureSchema();
        $order = PaymentOrder::where('id', $orderId)->where('user_id', $userId)->find();
        if (!$order instanceof PaymentOrder) {
            abort(404, '支付订单不存在');
        }
        $warning = '';
        if ($order->getAttr('status') === 'pending') {
            // Claim a query slot without holding an SQL lock during network I/O.
            $claimed = PaymentOrder::where('id', $orderId)->where('status', 'pending')
                ->where('last_checked_at', '<=', date('Y-m-d H:i:s', time() - 5))
                ->update(['last_checked_at' => date('Y-m-d H:i:s')]);
            if ($claimed) {
                $client = $client ?? new WechatPayClient();
                try {
                    $transaction = $client->query($order->getAttr('order_no'), $order->getAttr('merchant_id'));
                    if (($transaction['out_trade_no'] ?? '') !== $order->getAttr('order_no')
                        || ($transaction['mchid'] ?? '') !== $order->getAttr('merchant_id')) {
                        throw new \RuntimeException('查单返回的订单信息不一致');
                    }
                    if (($transaction['trade_state'] ?? '') === 'SUCCESS') {
                        self::settle($transaction);
                    } elseif (in_array($transaction['trade_state'] ?? '', ['CLOSED', 'REVOKED', 'PAYERROR'], true)) {
                        self::markClosed($orderId);
                    } elseif (($transaction['trade_state'] ?? '') === 'NOTPAY' && strtotime($order->getAttr('expires_at')) <= time()) {
                        $client->close($order->getAttr('order_no'), $order->getAttr('merchant_id'));
                        self::markClosed($orderId);
                    }
                } catch (\Throwable $e) {
                    $providerCode = self::providerErrorCode($e);
                    if ($providerCode === 'ORDER_NOT_EXIST' && strtotime($order->getAttr('expires_at')) <= time()) {
                        self::markClosed($orderId);
                    } else {
                        $warning = '支付状态暂未确认，请稍后刷新';
                    }
                }
                $order = PaymentOrder::find($orderId);
            }
        }
        return self::serializeOrder($order) + ['sync_warning' => $warning];
    }

    public static function recentOrders(int $userId): array
    {
        self::ensureSchema();
        $orders = PaymentOrder::where('user_id', $userId)->order('id', 'desc')->limit(10)->select();
        return array_map(static fn (PaymentOrder $order): array => self::serializeOrder($order), iterator_to_array($orders));
    }

    public static function handleNotify(string $body, array $headers, ?WechatPayClient $client = null): void
    {
        $client = $client ?? new WechatPayClient();
        $transaction = $client->notification($body, $headers);
        self::settle($transaction);
    }

    /** Only call with a transaction authenticated by the SDK (query or callback). */
    private static function settle(array $transaction): void
    {
        self::ensureSchema();
        $orderNo = (string) ($transaction['out_trade_no'] ?? '');
        $transactionId = (string) ($transaction['transaction_id'] ?? '');
        if (($transaction['trade_state'] ?? '') !== 'SUCCESS' || $orderNo === ''
            || $transactionId === '' || strlen($transactionId) > 64) {
            throw new \RuntimeException('支付交易信息无效');
        }
        Db::transaction(function () use ($orderNo, $transactionId, $transaction): void {
            $order = PaymentOrder::where('order_no', $orderNo)->lock(true)->find();
            if (!$order instanceof PaymentOrder) {
                throw new \RuntimeException('支付订单不存在');
            }
            if (($transaction['appid'] ?? '') !== $order->getAttr('app_id')
                || ($transaction['mchid'] ?? '') !== $order->getAttr('merchant_id')
                || ($transaction['amount']['currency'] ?? '') !== 'CNY'
                || !is_int($transaction['amount']['total'] ?? null)
                || $transaction['amount']['total'] !== (int) $order->getAttr('amount_fen')) {
                throw new \RuntimeException('支付商户或金额校验失败');
            }
            if ($order->getAttr('status') === 'paid') {
                if ($order->getAttr('transaction_id') !== $transactionId) {
                    throw new \RuntimeException('支付交易号不一致');
                }
                return;
            }
            $paidTime = strtotime((string) ($transaction['success_time'] ?? ''));
            if (!$paidTime) {
                throw new \RuntimeException('支付成功时间无效');
            }
            $userId = (int) $order->getAttr('user_id');
            $user = User::where('id', $userId)->lock(true)->find();
            if (!$user instanceof User) {
                throw new \RuntimeException('支付用户不存在');
            }
            $credits = round((float) $order->getAttr('credit_amount'), 2);
            $before = round((float) $user->getAttr('credit_balance'), 2);
            $after = round($before + $credits, 2);
            $user->save(['credit_balance' => $after]);
            CreditLedger::create([
                'user_id' => $userId,
                'entry_type' => 'topup',
                'amount' => $credits,
                'balance_after' => $after,
                'ref_type' => 'payment_order',
                'ref_id' => (int) $order->getAttr('id'),
                'description' => '微信支付充值 ' . number_format((int) $order->getAttr('amount_fen') / 100, 2) . ' 元',
                'meta_json' => ['order_no' => $orderNo, 'transaction_id' => $transactionId, 'before' => $before, 'after' => $after],
            ]);
            // A late authenticated success must be credited even after local closure.
            // Order lock + transaction make balance, ledger and status atomic.
            $order->save([
                'status' => 'paid',
                'transaction_id' => $transactionId,
                'paid_at' => date('Y-m-d H:i:s', $paidTime),
            ]);
        });
    }

    private static function markClosed(int $orderId): void
    {
        PaymentOrder::where('id', $orderId)->where('status', 'pending')->update(['status' => 'closed']);
    }

    private static function providerErrorCode(\Throwable $error): string
    {
        if ($error instanceof ClientException && $error->hasResponse()) {
            $body = json_decode((string) $error->getResponse()->getBody(), true);
            return (string) ($body['code'] ?? '');
        }
        return '';
    }

    private static function serializeOrder(PaymentOrder $order): array
    {
        return [
            'id' => (int) $order->getAttr('id'),
            'order_no' => (string) $order->getAttr('order_no'),
            'package_id' => (string) $order->getAttr('package_id'),
            'amount_cny' => (int) $order->getAttr('amount_fen') / 100,
            'credit_amount' => round((float) $order->getAttr('credit_amount'), 2),
            'status' => (string) $order->getAttr('status'),
            'code_url' => (string) $order->getAttr('code_url'),
            'paid_at' => $order->getAttr('paid_at'),
            'expires_at' => date(DATE_RFC3339, strtotime((string) $order->getAttr('expires_at'))),
            'create_time' => $order->getAttr('create_time'),
        ];
    }
}
