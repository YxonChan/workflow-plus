<?php

declare(strict_types=1);

namespace app\support;

use app\model\AiRequestLog;
use app\model\CreditLedger;
use app\model\User;
use think\facade\Db;
use think\facade\Log;

class CreditService
{
    private static bool $schemaReady = false;

    private static ?array $pricingCache = null;

    public static function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }

        if (!self::columnExists('users', 'credit_balance')) {
            Db::execute(
                "ALTER TABLE `users` ADD COLUMN `credit_balance` DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT '积分余额，1积分=0.01元' AFTER `status`"
            );
        }

        if (self::tableExists('ai_request_logs') && !self::columnExists('ai_request_logs', 'credits_charged')) {
            Db::execute(
                "ALTER TABLE `ai_request_logs` ADD COLUMN `credits_charged` DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT '本次成功扣费积分' AFTER `total_tokens`"
            );
        }

        Db::execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS `credit_ledger` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `entry_type` varchar(32) NOT NULL COMMENT 'topup|consume|refund|adjust',
  `amount` decimal(14,2) NOT NULL COMMENT '入账为正，扣费为负',
  `balance_after` decimal(14,2) NOT NULL,
  `modality` varchar(16) NOT NULL DEFAULT '' COMMENT 'text|image|video|',
  `model_config_id` int unsigned DEFAULT NULL,
  `model_id` varchar(120) NOT NULL DEFAULT '',
  `ref_type` varchar(32) NOT NULL DEFAULT '' COMMENT 'ai_request_log|video_job|asset_image_job|admin',
  `ref_id` bigint unsigned NOT NULL DEFAULT 0,
  `operator_id` int unsigned NOT NULL DEFAULT 0,
  `description` varchar(255) NOT NULL DEFAULT '',
  `meta_json` json DEFAULT NULL,
  `create_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_credit_ledger_user_time` (`user_id`, `create_time`),
  KEY `idx_credit_ledger_ref` (`ref_type`, `ref_id`),
  KEY `idx_credit_ledger_type_time` (`entry_type`, `create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='积分流水'
SQL);

        self::$schemaReady = true;
    }

    public static function isEnabled(): bool
    {
        $pricing = self::pricing();
        return (bool) ($pricing['enabled'] ?? true);
    }

    /**
     * @return array<string, mixed>
     */
    public static function pricing(): array
    {
        if (self::$pricingCache !== null) {
            return self::$pricingCache;
        }

        $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'credit_pricing.php';
        if (!is_file($path) && function_exists('root_path')) {
            $path = root_path() . 'config' . DIRECTORY_SEPARATOR . 'credit_pricing.php';
        }
        $data = is_file($path) ? include $path : [];
        self::$pricingCache = is_array($data) ? $data : [];

        return self::$pricingCache;
    }

    /**
     * @return array<string, mixed>
     */
    public static function pricingCatalog(): array
    {
        $pricing = self::pricing();

        return [
            'enabled' => (bool) ($pricing['enabled'] ?? true),
            'pricing_version' => (string) ($pricing['pricing_version'] ?? ''),
            'updated_at' => (string) ($pricing['updated_at'] ?? ''),
            'credit_unit_cny' => (float) ($pricing['credit_unit_cny'] ?? 0.01),
            'usd_cny' => (float) ($pricing['usd_cny'] ?? 7.2),
            'markup' => (float) ($pricing['markup'] ?? 1.25),
            'notes' => is_array($pricing['notes'] ?? null) ? array_values($pricing['notes']) : [],
            'text' => $pricing['text'] ?? [],
            'image' => $pricing['image'] ?? [],
            'video' => $pricing['video'] ?? [],
            'editable' => false,
        ];
    }

    public static function getBalance(int $userId): float
    {
        self::ensureSchema();
        if ($userId <= 0) {
            return 0.0;
        }

        return round((float) User::where('id', $userId)->value('credit_balance'), 2);
    }

    public static function quoteText(int $promptTokens, int $completionTokens, string $modelId = ''): float
    {
        $rule = self::textRule($modelId);
        $inputRate = (float) ($rule['input_credits_per_1k'] ?? 0.40);
        $outputRate = (float) ($rule['output_credits_per_1k'] ?? 1.19);
        $min = (float) ($rule['min_credits'] ?? 0.01);
        $credits = ($promptTokens / 1000) * $inputRate + ($completionTokens / 1000) * $outputRate;
        $credits = round(max(0, $credits), 2);
        if ($credits > 0 && $credits < $min) {
            return $min;
        }

        return $credits;
    }

    public static function quoteImage(string $quality = '', string $modelId = '', int $referenceImageCount = 0): float
    {
        $rule = self::imageRule($modelId);
        $quality = self::normalizeQuality($quality !== '' ? $quality : (string) ($rule['default_quality'] ?? ImageGenerationOptions::defaultQuality()));
        $byQuality = is_array($rule['by_quality'] ?? null) ? $rule['by_quality'] : [];
        $credits = (float) ($byQuality[$quality] ?? $byQuality['high'] ?? 197.88);

        $referenceImageCount = max(0, min(9, $referenceImageCount));
        $referenceFee = $referenceImageCount * (float) ($rule['reference_image_credits'] ?? 12.5);

        return round(max(0, $credits + $referenceFee), 2);
    }

    public static function quoteVideo(int $durationSeconds, string $resolution = '', string $modelId = ''): float
    {
        $rule = self::videoRule($modelId);
        $resolution = self::normalizeResolution($resolution !== '' ? $resolution : (string) ($rule['default_resolution'] ?? '480p'));
        $byResolution = is_array($rule['by_resolution'] ?? null) ? $rule['by_resolution'] : [];
        if (!isset($byResolution[$resolution])) {
            abort(422, '当前视频分辨率「' . $resolution . '」暂无报价，请改选 480p / 720p / 1080p');
        }
        $perSecond = (float) $byResolution[$resolution];
        $seconds = max(1, $durationSeconds);

        return round(max(0, $perSecond * $seconds), 2);
    }

    public static function assertAffordable(int $userId, float $estimatedCredits, string $actionLabel = 'AI 请求'): void
    {
        if (!self::isEnabled() || $userId <= 0) {
            return;
        }

        self::ensureSchema();
        $estimatedCredits = round(max(0, $estimatedCredits), 2);
        if ($estimatedCredits <= 0) {
            return;
        }

        $balance = self::getBalance($userId);
        if ($balance + 0.00001 >= $estimatedCredits) {
            return;
        }

        abort(422, sprintf(
            '积分不足，无法%s。当前余额 %.2f，预计需要 %.2f。请联系管理员充值。',
            $actionLabel,
            $balance,
            $estimatedCredits
        ));
    }

    public static function assertTextAffordable(int $userId, int $promptTokens, int $maxCompletionTokens, string $modelId = ''): void
    {
        self::assertAffordable(
            $userId,
            self::quoteText(max(0, $promptTokens), max(0, $maxCompletionTokens), $modelId),
            '发起文本 AI 请求'
        );
    }

    public static function assertImageAffordable(int $userId, string $quality = '', string $modelId = '', int $referenceImageCount = 0): void
    {
        self::assertAffordable($userId, self::quoteImage($quality, $modelId, $referenceImageCount), '发起图片生成');
    }

    public static function assertVideoAffordable(int $userId, int $durationSeconds, string $resolution = '', string $modelId = ''): void
    {
        self::assertAffordable(
            $userId,
            self::quoteVideo($durationSeconds, $resolution, $modelId),
            '发起视频生成'
        );
    }

    /**
     * @return array{balance: float, ledger_id: int}
     */
    public static function topUp(int $userId, float $amount, int $operatorId = 0, string $note = ''): array
    {
        self::ensureSchema();
        $amount = round($amount, 2);
        if ($userId <= 0) {
            abort(422, '用户 id 无效');
        }
        if ($amount <= 0) {
            abort(422, '充值积分必须大于 0');
        }
        if ($amount > 100000000) {
            abort(422, '单次充值积分过大');
        }

        return Db::transaction(function () use ($userId, $amount, $operatorId, $note): array {
            $user = User::where('id', $userId)->lock(true)->find();
            if (!$user instanceof User) {
                abort(404, '用户不存在');
            }

            $before = round((float) $user->getAttr('credit_balance'), 2);
            $after = round($before + $amount, 2);
            $user->save(['credit_balance' => $after]);

            $ledger = CreditLedger::create([
                'user_id' => $userId,
                'entry_type' => 'topup',
                'amount' => $amount,
                'balance_after' => $after,
                'modality' => '',
                'model_config_id' => null,
                'model_id' => '',
                'ref_type' => 'admin',
                'ref_id' => $operatorId,
                'operator_id' => $operatorId,
                'description' => $note !== '' ? mb_substr($note, 0, 255) : '管理员手动充值',
                'meta_json' => [
                    'before' => $before,
                    'after' => $after,
                ],
            ]);

            return [
                'balance' => $after,
                'ledger_id' => (int) $ledger->getAttr('id'),
            ];
        });
    }

    /**
     * @param array<string, mixed> $meta
     * @return array{charged: float, balance: float, ledger_id: int}|null
     */
    public static function consume(int $userId, float $amount, array $meta = []): ?array
    {
        if (!self::isEnabled() || $userId <= 0) {
            return null;
        }

        self::ensureSchema();
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return null;
        }

        $refType = (string) ($meta['ref_type'] ?? '');
        $refId = (int) ($meta['ref_id'] ?? 0);
        if ($refType !== '' && $refId > 0 && self::hasConsumeForRef($refType, $refId)) {
            return null;
        }

        try {
            return Db::transaction(function () use ($userId, $amount, $meta, $refType, $refId): ?array {
                if ($refType !== '' && $refId > 0 && self::hasConsumeForRef($refType, $refId)) {
                    return null;
                }

                $user = User::where('id', $userId)->lock(true)->find();
                if (!$user instanceof User) {
                    return null;
                }

                $before = round((float) $user->getAttr('credit_balance'), 2);
                if ($before + 0.00001 < $amount) {
                    // 预检应已拦截；此处不阻断已成功的上游 AI，改为扣到 0 并记负差额说明。
                    $amount = $before;
                    if ($amount <= 0) {
                        return null;
                    }
                }

                $after = round($before - $amount, 2);
                $user->save(['credit_balance' => $after]);

                $ledger = CreditLedger::create([
                    'user_id' => $userId,
                    'entry_type' => 'consume',
                    'amount' => -1 * $amount,
                    'balance_after' => $after,
                    'modality' => (string) ($meta['modality'] ?? ''),
                    'model_config_id' => isset($meta['model_config_id']) ? (int) $meta['model_config_id'] : null,
                    'model_id' => (string) ($meta['model_id'] ?? ''),
                    'ref_type' => $refType,
                    'ref_id' => $refId,
                    'operator_id' => (int) ($meta['operator_id'] ?? 0),
                    'description' => mb_substr((string) ($meta['description'] ?? 'AI 请求扣费'), 0, 255),
                    'meta_json' => is_array($meta['meta_json'] ?? null) ? $meta['meta_json'] : $meta,
                ]);

                return [
                    'charged' => $amount,
                    'balance' => $after,
                    'ledger_id' => (int) $ledger->getAttr('id'),
                ];
            });
        } catch (\Throwable $e) {
            Log::error('[CreditService] consume failed: ' . $e->getMessage());
            return null;
        }
    }

    public static function chargeFromAiRequestLog(AiRequestLog $log): void
    {
        if (!self::isEnabled()) {
            return;
        }

        try {
            self::ensureSchema();
            if ((int) $log->getAttr('request_ok') !== 1) {
                return;
            }

            $logId = (int) $log->getAttr('id');
            $userId = (int) $log->getAttr('user_id');
            if ($logId <= 0 || $userId <= 0) {
                return;
            }
            if ((float) $log->getAttr('credits_charged') > 0) {
                return;
            }
            if (self::hasConsumeForRef('ai_request_log', $logId)) {
                return;
            }

            $modality = self::detectModality($log);
            $modelId = trim((string) $log->getAttr('llm_model'));
            $modelConfigId = (int) ($log->getAttr('model_config_id') ?? 0);
            $amount = 0.0;
            $detail = [];

            if ($modality === 'image') {
                $quality = self::extractImageQuality($log);
                $referenceImageCount = self::extractReferenceImageCount($log);
                $amount = self::quoteImage($quality, $modelId, $referenceImageCount);
                $detail = ['quality' => $quality, 'reference_image_count' => $referenceImageCount];
            } elseif ($modality === 'video') {
                [$duration, $resolution] = self::extractVideoBillingDims($log);
                $amount = self::quoteVideo($duration, $resolution, $modelId);
                $detail = ['duration_seconds' => $duration, 'resolution' => $resolution];
            } else {
                $promptTokens = (int) $log->getAttr('prompt_tokens');
                $completionTokens = (int) $log->getAttr('completion_tokens');
                if ($promptTokens <= 0 && $completionTokens <= 0) {
                    $usage = $log->getAttr('usage_json');
                    if (is_array($usage)) {
                        $promptTokens = (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0);
                        $completionTokens = (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0);
                    }
                }
                $amount = self::quoteText($promptTokens, $completionTokens, $modelId);
                $detail = [
                    'prompt_tokens' => $promptTokens,
                    'completion_tokens' => $completionTokens,
                ];
            }

            if ($amount <= 0) {
                return;
            }

            $result = self::consume($userId, $amount, [
                'modality' => $modality,
                'model_config_id' => $modelConfigId > 0 ? $modelConfigId : null,
                'model_id' => $modelId,
                'ref_type' => 'ai_request_log',
                'ref_id' => $logId,
                'description' => sprintf('AI %s 扣费（log#%d）', $modality, $logId),
                'meta_json' => $detail + [
                    'source' => (string) $log->getAttr('source'),
                ],
            ]);

            if ($result === null) {
                return;
            }

            try {
                AiRequestLog::where('id', $logId)->update([
                    'credits_charged' => $result['charged'],
                ]);
            } catch (\Throwable) {
                // 列可能尚未迁移完成；流水已写入即可。
            }
        } catch (\Throwable $e) {
            Log::error('[CreditService] chargeFromAiRequestLog failed: ' . $e->getMessage());
        }
    }

    /**
     * 粗估提示词 token：按 UTF-8 字符数 / 2，下限 500。
     *
     * @param array<int, mixed> $messages
     */
    public static function estimatePromptTokensFromMessages(array $messages): int
    {
        $chars = 0;
        foreach ($messages as $message) {
            if (is_string($message)) {
                $chars += mb_strlen($message);
                continue;
            }
            if (!is_array($message)) {
                continue;
            }
            $content = $message['content'] ?? '';
            if (is_string($content)) {
                $chars += mb_strlen($content);
            } elseif (is_array($content)) {
                $chars += mb_strlen(json_encode($content, JSON_UNESCAPED_UNICODE) ?: '');
            }
        }

        return max(500, (int) ceil($chars / 2));
    }

    public static function estimatePromptTokensFromText(string $text): int
    {
        return max(500, (int) ceil(mb_strlen($text) / 2));
    }

    private static function hasConsumeForRef(string $refType, int $refId): bool
    {
        return CreditLedger::where('ref_type', $refType)
            ->where('ref_id', $refId)
            ->where('entry_type', 'consume')
            ->count() > 0;
    }

    private static function detectModality(AiRequestLog $log): string
    {
        $source = strtolower((string) $log->getAttr('source'));
        $endpoint = strtolower((string) $log->getAttr('endpoint'));
        if (
            str_contains($source, 'video')
            || str_contains($endpoint, '/videos/')
            || str_contains($endpoint, 'generations/tasks')
            || str_contains($endpoint, 'contents/generations')
        ) {
            return 'video';
        }
        if (
            str_contains($source, 'image')
            || str_contains($endpoint, '/images/')
        ) {
            return 'image';
        }

        return 'text';
    }

    private static function extractImageQuality(AiRequestLog $log): string
    {
        $request = self::decodeJsonAttr($log->getAttr('request_json'));
        $body = is_array($request['body'] ?? null) ? $request['body'] : $request;
        $quality = '';
        if (is_array($body)) {
            $quality = (string) ($body['quality'] ?? '');
            if ($quality === '') {
                $payload = is_array($body['payload'] ?? null) ? $body['payload'] : [];
                $modelVersion = strtolower(trim((string) ($payload['ModelVersion'] ?? '')));
                if (str_ends_with($modelVersion, '_low') || $modelVersion === 'image2_low') {
                    $quality = 'low';
                } elseif (str_ends_with($modelVersion, '_medium') || $modelVersion === 'image2_medium') {
                    $quality = 'medium';
                } elseif (str_ends_with($modelVersion, '_high') || $modelVersion === 'image2_high') {
                    $quality = 'high';
                }
            }
        }
        $context = self::decodeJsonAttr($log->getAttr('context_json'));
        if ($quality === '' && is_array($context)) {
            $quality = (string) ($context['quality'] ?? '');
        }
        if ($quality === '') {
            $llmModel = strtolower(trim((string) $log->getAttr('llm_model')));
            if (str_contains($llmModel, 'image2_low') || str_ends_with($llmModel, '_low')) {
                $quality = 'low';
            } elseif (str_contains($llmModel, 'image2_medium') || str_ends_with($llmModel, '_medium')) {
                $quality = 'medium';
            }
        }

        return self::normalizeQuality($quality);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private static function extractVideoBillingDims(AiRequestLog $log): array
    {
        $duration = 0;
        $resolution = '';
        $context = self::decodeJsonAttr($log->getAttr('context_json'));
        $request = self::decodeJsonAttr($log->getAttr('request_json'));
        $body = is_array($request['body'] ?? null) ? $request['body'] : $request;

        if (is_array($context)) {
            $duration = (int) ($context['duration_seconds'] ?? $context['duration'] ?? $context['video_duration'] ?? 0);
            $resolution = (string) ($context['resolution'] ?? '');
            $videoOptions = is_array($context['video_options'] ?? null) ? $context['video_options'] : [];
            if ($duration <= 0) {
                $duration = (int) ($videoOptions['duration'] ?? 0);
            }
            if ($resolution === '') {
                $resolution = (string) ($videoOptions['resolution'] ?? '');
            }
        }

        if (is_array($body)) {
            if ($duration <= 0) {
                $duration = (int) ($body['duration'] ?? $body['seconds'] ?? 0);
            }
            if ($resolution === '') {
                $resolution = (string) ($body['resolution'] ?? '');
            }
        }

        if ($duration <= 0) {
            $duration = 15;
        }

        return [$duration, self::normalizeResolution($resolution)];
    }

    /**
     * @return array<string, mixed>
     */
    private static function textRule(string $modelId): array
    {
        $pricing = self::pricing();
        $text = is_array($pricing['text'] ?? null) ? $pricing['text'] : [];
        $models = is_array($text['models'] ?? null) ? $text['models'] : [];
        $modelId = trim($modelId);
        if ($modelId !== '' && isset($models[$modelId]) && is_array($models[$modelId])) {
            return $models[$modelId];
        }

        return is_array($text['fallback'] ?? null) ? $text['fallback'] : [
            'input_credits_per_1k' => 0.40,
            'output_credits_per_1k' => 1.19,
            'min_credits' => 0.01,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function imageRule(string $modelId): array
    {
        $pricing = self::pricing();
        $image = is_array($pricing['image'] ?? null) ? $pricing['image'] : [];
        $models = is_array($image['models'] ?? null) ? $image['models'] : [];
        $modelId = self::normalizeImageModelId($modelId);
        if ($modelId !== '' && isset($models[$modelId]) && is_array($models[$modelId])) {
            return $models[$modelId];
        }

        $defaultModelId = trim((string) ($image['default_model_id'] ?? ''));
        if ($defaultModelId !== '' && isset($models[$defaultModelId]) && is_array($models[$defaultModelId])) {
            return $models[$defaultModelId];
        }

        return is_array($image['fallback'] ?? null) ? $image['fallback'] : [
            'by_quality' => ['low' => 5.63, 'medium' => 49.75, 'high' => 197.88],
            'reference_image_credits' => 12.5,
            'default_quality' => ImageGenerationOptions::defaultQuality(),
        ];
    }

    private static function extractReferenceImageCount(AiRequestLog $log): int
    {
        $context = $log->getAttr('context_json');
        if (is_string($context)) {
            $context = json_decode($context, true);
        }
        if (!is_array($context)) {
            return 0;
        }
        $urls = $context['main_image_urls'] ?? $context['image_urls'] ?? [];
        if (!is_array($urls)) {
            $single = trim((string) ($context['reference_image_url'] ?? ''));
            $urls = $single !== '' ? [$single] : [];
        }
        $urls = array_values(array_unique(array_filter(array_map(
            static fn ($url): string => trim((string) $url),
            $urls,
        ), static fn (string $url): bool => $url !== '')));
        return min(9, count($urls));
    }

    /**
     * 将腾讯云点播日志别名归一到物价表主键 OG/image2。
     */
    private static function normalizeImageModelId(string $modelId): string
    {
        $modelId = trim($modelId);
        if ($modelId === '') {
            return '';
        }

        $lower = strtolower($modelId);
        if (
            $lower === 'og'
            || $lower === 'og/image2'
            || str_starts_with($lower, 'og/image2_')
            || str_starts_with($lower, 'og/')
            || str_contains($lower, 'tencent')
            || str_contains($lower, 'vod_aigc')
        ) {
            return 'OG/image2';
        }

        return $modelId;
    }

    /**
     * @return array<string, mixed>
     */
    private static function videoRule(string $modelId): array
    {
        $pricing = self::pricing();
        $video = is_array($pricing['video'] ?? null) ? $pricing['video'] : [];
        $models = is_array($video['models'] ?? null) ? $video['models'] : [];
        $modelId = strtolower(trim($modelId));
        if ($modelId !== '' && isset($models[$modelId]) && is_array($models[$modelId])) {
            return $models[$modelId];
        }
        if (in_array($modelId, ['doubao-seedance-2-0', 'doubao-seedance-2-0-fast', 'doubao-seedance-2-0-260128'], true)
            && isset($models['seedance-2'])
            && is_array($models['seedance-2'])
        ) {
            return $models['seedance-2'];
        }

        return is_array($video['fallback'] ?? null) ? $video['fallback'] : [
            'by_resolution' => ['480p' => 57.75, '720p' => 124.25, '1080p' => 309.75],
            'default_resolution' => '480p',
        ];
    }

    private static function normalizeQuality(string $quality): string
    {
        $quality = strtolower(trim($quality));
        return in_array($quality, ['low', 'medium', 'high'], true) ? $quality : ImageGenerationOptions::defaultQuality();
    }

    private static function normalizeResolution(string $resolution): string
    {
        $resolution = strtolower(trim($resolution));
        $resolution = str_replace([' ', '_'], '', $resolution);
        if (in_array($resolution, ['4k', '2160p', '2160'], true)) {
            abort(422, '当前不支持 4K 视频计费/生成报价，请改选 480p / 720p / 1080p');
        }
        if (in_array($resolution, ['1080p', '1080'], true)) {
            return '1080p';
        }
        if (in_array($resolution, ['720p', '720'], true)) {
            return '720p';
        }
        if (in_array($resolution, ['480p', '480'], true)) {
            return '480p';
        }

        return '480p';
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeJsonAttr(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function tableExists(string $table): bool
    {
        $table = preg_replace('/[^A-Za-z0-9_]/', '', $table) ?? '';
        if ($table === '') {
            return false;
        }

        return Db::query("SHOW TABLES LIKE '{$table}'") !== [];
    }

    private static function columnExists(string $table, string $column): bool
    {
        $table = preg_replace('/[^A-Za-z0-9_]/', '', $table) ?? '';
        $column = preg_replace('/[^A-Za-z0-9_]/', '', $column) ?? '';
        if ($table === '' || $column === '') {
            return false;
        }
        if (!self::tableExists($table)) {
            return false;
        }

        return Db::query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'") !== [];
    }
}
