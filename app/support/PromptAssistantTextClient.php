<?php

declare(strict_types=1);

namespace app\support;

use app\model\AiRequestLog;
use app\model\ModelConfig;
use RuntimeException;

class PromptAssistantTextClient
{
    private int $calls = 0;

    public function complete(ModelConfig $model, int $userId, string $stage, string $system, array $input, int $maxTokens = 4096): array
    {
        return $this->request($model, $userId, $stage, $system, $input, $maxTokens, []);
    }

    public function completeWithImages(ModelConfig $model, int $userId, string $stage, string $system, array $input, array $images, int $maxTokens = 4096): array
    {
        if (!self::supportsVision($model) || $images === [] || count($images) > 6) {
            throw new RuntimeException('图片解析需要支持图片输入的多模态模型');
        }
        return $this->request($model, $userId, $stage, $system, $input, $maxTokens, $images);
    }

    public static function supportsVision(ModelConfig $model): bool
    {
        return ($model->getAttr('options')['supports_vision'] ?? false) === true;
    }

    private function request(ModelConfig $model, int $userId, string $stage, string $system, array $input, int $maxTokens, array $images): array
    {
        if (++$this->calls > 6) {
            throw new RuntimeException('本次模型调用已达到上限');
        }
        $endpoint = rtrim(trim((string) $model->getAttr('endpoint')), '/');
        if ($endpoint === '' || trim((string) $model->getAttr('model_id')) === '') {
            throw new RuntimeException('文本模型配置不完整');
        }
        if (!str_contains($endpoint, '/chat/completions')) {
            $endpoint .= '/chat/completions';
        }
        $messages = [
            ['role' => 'system', 'content' => $system . "\n只返回合法 JSON 对象，不输出思考过程或 Markdown 包裹。"],
            ['role' => 'user', 'content' => json_encode($input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
        ];
        $textMessages = $messages;
        if ($images !== []) {
            $messages[1]['content'] = array_merge([['type' => 'text', 'text' => $messages[1]['content']]],
                array_map(static fn (string $url): array => ['type' => 'image_url', 'image_url' => ['url' => $url]], $images));
        }
        $payload = ['model' => (string) $model->getAttr('model_id'), 'messages' => $messages,
            'temperature' => 0.4, 'max_tokens' => max(512, min(8192, $maxTokens)), 'stream' => false];
        $options = $model->getAttr('options');
        $options = is_array($options) ? $options : [];
        $isDeepSeek = str_contains(strtolower($endpoint), 'api.deepseek.com') || str_starts_with(strtolower($payload['model']), 'deepseek');
        if ($isDeepSeek && !str_contains(strtolower($payload['model']), 'reasoner')) {
            $payload['thinking'] = ['type' => 'disabled'];
        }
        if ($isDeepSeek || !empty($options['supports_json_mode'])) {
            $payload['response_format'] = ['type' => 'json_object'];
        }
        $estimatedTokens = CreditService::estimatePromptTokensFromMessages($textMessages) + count($images) * 4096;
        CreditService::assertTextAffordable($userId, $estimatedTokens, $payload['max_tokens'], $payload['model']);
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        $apiKey = trim((string) $model->getAttr('api_key'));
        if ($apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }
        $started = microtime(true);
        $curl = curl_init($endpoint);
        if ($curl === false) {
            throw new RuntimeException('无法初始化模型请求');
        }
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 100, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        $raw = curl_exec($curl);
        $errno = curl_errno($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $content = $decoded['choices'][0]['message']['content'] ?? '';
        $ok = $errno === 0 && $status >= 200 && $status < 300 && is_string($content) && trim($content) !== '';
        // Log and bill through the same request-log hook as other text features.
        try {
            $logPayload = $payload;
            if ($images !== []) {
                $logPayload['messages'][1]['content'] = [['type' => 'text', 'text' => $messages[1]['content'][0]['text']],
                    ['type' => 'text', 'text' => '[Attached images: ' . count($images) . ']']];
            }
            AiRequestLog::create([
                'user_id' => $userId, 'source' => 'prompt_assistant', 'model_config_id' => (int) $model->getAttr('id'),
                'llm_model' => $payload['model'], 'endpoint' => mb_substr($endpoint, 0, 500),
                'context_json' => json_encode(['stage' => $stage], JSON_UNESCAPED_UNICODE),
                'request_json' => json_encode($logPayload, JSON_UNESCAPED_UNICODE),
                'http_status' => $status, 'response_body' => mb_substr(is_string($raw) ? $raw : '', 0, 60000),
                'usage_json' => is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [],
                'curl_errno' => $errno, 'curl_error' => $errno ? 'Transport error ' . $errno : '',
                'request_ok' => $ok ? 1 : 0, 'error_message' => $ok ? '' : 'Model request failed',
                'duration_ms' => (int) round((microtime(true) - $started) * 1000), 'max_tokens' => $payload['max_tokens'],
                'finish_reason' => mb_substr((string) ($decoded['choices'][0]['finish_reason'] ?? ''), 0, 32),
                'content_preview' => mb_substr(is_string($content) ? $content : '', 0, 900),
            ]);
        } catch (\Throwable $error) {
            \think\facade\Log::error('[PromptAssistant] request log failed: ' . $error->getMessage());
        }
        if (!$ok) {
            throw new RuntimeException(self::requestError($errno, $status));
        }
        if (($decoded['choices'][0]['finish_reason'] ?? '') === 'length') {
            throw new PromptAssistantResponseException($content, true);
        }
        $text = trim($content);
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $text);
        }
        $result = json_decode($text, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($result) || !str_starts_with(ltrim($text), '{')) {
            throw new PromptAssistantResponseException($content);
        }
        return $result;
    }

    private static function requestError(int $errno, int $status): string
    {
        if ($errno !== 0) {
            return $errno === 28 ? '模型响应超时，请重试本次消息' : '无法连接模型服务，请检查后端网络或稍后重试';
        }
        // Use known status codes; provider diagnostics can contain credentials or private request data.
        return match ($status) {
            401 => '模型鉴权失败（HTTP 401）：API Key 无效或已失效，请联系管理员更新模型配置',
            402 => '模型账户余额不足（HTTP 402），请充值后重试',
            403 => '模型访问被拒绝（HTTP 403），请联系管理员检查模型权限',
            404 => '模型或接口地址不存在（HTTP 404），请联系管理员检查模型配置',
            400, 422 => '模型拒绝了请求参数（HTTP ' . $status . '），请联系管理员检查模型兼容性',
            429 => '模型请求被限流（HTTP 429），请稍后重试',
            default => $status >= 200 && $status < 300
                ? '模型未返回可用正文，请检查模型输出格式与输出上限'
                : '模型服务请求失败（HTTP ' . $status . '），请稍后重试',
        };
    }
}
