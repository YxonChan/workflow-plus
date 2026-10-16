<?php

declare(strict_types=1);

namespace app\support;

use app\model\AiRequestLog;
use app\model\ModelConfig;
use think\facade\Log;

final class ToapisImageBilling
{
    public static function reconcile(int $limit = 3): void
    {
        $logs = AiRequestLog::where('source', 'image_generation')->where('request_ok', 1)
            ->whereRaw("JSON_VALID(context_json) AND JSON_EXTRACT(context_json, '$.image_billing_pending') = true")
            ->whereRaw("COALESCE(JSON_EXTRACT(context_json, '$.billing_checked_at'), 0) <= ?", [time() - 60])
            ->orderRaw("COALESCE(JSON_EXTRACT(context_json, '$.billing_checked_at'), 0) ASC, id ASC")
            ->limit(max(1, min(10, $limit)))->select();
        foreach ($logs as $log) {
            $context = $log->getAttr('context_json');
            $context = is_array($context) ? $context : (json_decode((string) $context, true) ?: []);
            if ((int) ($context['billing_checked_at'] ?? 0) > time() - 60) continue;
            $context['billing_checked_at'] = time();
            $log->save(['context_json' => json_encode($context, JSON_UNESCAPED_UNICODE)]);
            try {
                // Settlement must use the original credentials even if the model was later disabled.
                $model = ModelConfig::where('id', (int) $log->getAttr('model_config_id'))->where('type', 'image')
                    ->whereRaw("((scope = 'global' AND user_id = 0) OR (scope = 'user' AND user_id = ?))", [(int) $log->getAttr('user_id')])->find();
                $taskId = trim((string) ($context['provider_task_id'] ?? ''));
                $endpoint = (string) $log->getAttr('endpoint');
                if ($model === null || $taskId === '' || !ImageGenerationOptions::isOfficial25((string) $log->getAttr('llm_model'))
                    || !ToapisPrivateAvatarService::isToapisEndpoint($endpoint)
                    || parse_url($endpoint, PHP_URL_HOST) !== parse_url((string) $model->getAttr('endpoint'), PHP_URL_HOST)) continue;
                $url = rtrim($endpoint, '/') . '/' . rawurlencode($taskId);
                $handle = curl_init($url);
                curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
                    CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $model->getAttr('api_key')]]);
                $raw = curl_exec($handle);
                $http = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
                curl_close($handle);
                $response = is_string($raw) ? json_decode($raw, true) : null;
                if ($http !== 200 || !is_array($response) || ($response['status'] ?? '') !== 'completed') continue;
                $billing = is_array($response['billing'] ?? null) ? $response['billing'] : [];
                if (CreditService::settledImageCredits($billing) === null) continue;
                $context['provider_billing'] = $billing;
                // Persist the settlement before charging; a failed charge can be retried safely.
                $log->save(['context_json' => json_encode($context, JSON_UNESCAPED_UNICODE),
                    'usage_json' => is_array($response['usage'] ?? null) ? $response['usage'] : []]);
                CreditService::chargeFromAiRequestLog($log);
                if (CreditService::settledImageCredits($billing) === 0.0
                    || (float) AiRequestLog::where('id', $log->getAttr('id'))->value('credits_charged') > 0) {
                    $context['image_billing_pending'] = false;
                    $log->save(['context_json' => json_encode($context, JSON_UNESCAPED_UNICODE)]);
                }
            } catch (\Throwable $error) {
                Log::warning('[ToapisImageBilling] log#' . $log->getAttr('id') . ': ' . $error->getMessage());
            }
        }
    }
}
