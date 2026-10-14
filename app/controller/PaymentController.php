<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\support\PaymentService;

class PaymentController extends BaseController
{
    public function packages()
    {
        $packages = [];
        foreach (PaymentService::packages() as $id => $package) {
            $packages[] = [
                'id' => (string) $id,
                'name' => (string) ($package['name'] ?? $id),
                'amount_cny' => round((float) ($package['amount_cny'] ?? 0), 2),
                'credit_amount' => round((float) ($package['credits'] ?? 0), 2),
            ];
        }
        return successCode(['enabled' => PaymentService::available(), 'packages' => $packages]);
    }

    public function create()
    {
        $packageId = trim((string) $this->request->param('package_id', ''));
        return successCode(PaymentService::createNativeOrder(
            $this->currentUserId(), $packageId, (string) $this->request->param('request_id', '')
        ), 'success', 201);
    }

    public function status()
    {
        return successCode(PaymentService::orderStatus(
            $this->currentUserId(),
            (int) $this->request->param('id', 0),
        ));
    }

    public function notify()
    {
        $rawBody = (string) $this->request->getContent();
        try {
            PaymentService::handleNotify(
                $rawBody,
                [
                    'timestamp' => (string) $this->request->header('wechatpay-timestamp', ''),
                    'nonce' => (string) $this->request->header('wechatpay-nonce', ''),
                    'signature' => (string) $this->request->header('wechatpay-signature', ''),
                    'serial' => (string) $this->request->header('wechatpay-serial', ''),
                ],
            );
            return response('', 204);
        } catch (\Throwable $e) {
            \think\facade\Log::error('[Payment] notify failed: ' . get_class($e));
            return json(['code' => 'FAIL', 'message' => '失败'], 500);
        }
    }

    public function orders()
    {
        return successCode(['orders' => PaymentService::recentOrders($this->currentUserId())]);
    }
}
