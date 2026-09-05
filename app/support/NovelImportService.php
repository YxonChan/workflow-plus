<?php

declare(strict_types=1);

namespace app\support;

use app\model\AiRequestLog;
use app\model\ModelConfig;
use think\facade\Log;

class NovelImportService
{
    public function resolveTextFromPayload(array $payload): string
    {
        $sourceText = trim((string) ($payload['source_text'] ?? ''));
        if ($sourceText !== '' && !$this->isPlaceholderText($sourceText)) {
            return $sourceText;
        }

        $token = trim((string) ($payload['source_file_token'] ?? ''));
        if ($token === '') {
            return '';
        }

        $userId = (int) ($payload['user_id'] ?? 1);
        $meta = $this->readFileTokenMeta($token);
        $metaUserId = (int) ($meta['user_id'] ?? 1);
        if ($metaUserId !== ($userId > 0 ? $userId : 1)) {
            throw new \RuntimeException('导入文件不属于当前用户，请重新上传');
        }
        $path = (string) ($meta['path'] ?? '');
        $extension = strtolower((string) ($meta['extension'] ?? pathinfo($path, PATHINFO_EXTENSION)));
        $rawText = match ($extension) {
            'txt' => $this->readTxtNovel($path),
            'pdf' => $this->readPdfNovel($path),
            default => '',
        };
        $rawText = $this->normalizeText($rawText);
        if ($rawText === '') {
            $message = $extension === 'pdf'
                ? '未能从 PDF 中读取到可交给模型处理的文本内容。扫描版 PDF 需要 OCR 模型。'
                : '未能从 TXT 中读取到文本内容，请确认文件不是空文件。';
            throw new \RuntimeException($message);
        }

        $model = $this->resolveTextModel($userId);
        if (!$model instanceof ModelConfig) {
            throw new \RuntimeException('未找到可用文本模型，请联系管理员配置');
        }

        return $this->normalizeText($this->cleanByModel($model, $rawText, (string) ($meta['filename'] ?? 'novel'), $userId));
    }

    private function isPlaceholderText(string $text): bool
    {
        return preg_match('/^\[已上传文件：.+，将在创建并排队后解析\]$/u', trim($text)) === 1;
    }

    private function readFileTokenMeta(string $token): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            throw new \RuntimeException('导入文件 token 无效');
        }
        $root = app()->getRuntimePath() . 'novel_imports';
        $files = glob($root . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . $token . '.json') ?: [];
        if ($files === []) {
            throw new \RuntimeException('导入文件已失效，请重新上传');
        }
        $metaRaw = file_get_contents((string) $files[0]);
        $meta = is_string($metaRaw) ? json_decode($metaRaw, true) : null;
        if (!is_array($meta)) {
            throw new \RuntimeException('导入文件元信息损坏，请重新上传');
        }
        $path = (string) ($meta['path'] ?? '');
        if ($path === '' || !is_file($path)) {
            throw new \RuntimeException('导入文件不存在，请重新上传');
        }
        return $meta;
    }

    private function readTxtNovel(string $path): string
    {
        $content = file_get_contents($path);
        if (!is_string($content) || $content === '') {
            return '';
        }

        foreach (['UTF-8', 'GB18030', 'GBK', 'BIG5'] as $encoding) {
            $converted = @mb_convert_encoding($content, 'UTF-8', $encoding);
            if (is_string($converted) && mb_check_encoding($converted, 'UTF-8')) {
                return $converted;
            }
        }

        return $content;
    }

    private function readPdfNovel(string $path): string
    {
        $pdftotext = $this->findPdfToTextBinary();
        if ($pdftotext !== '') {
            $outputPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'malulu-novel-' . bin2hex(random_bytes(8)) . '.txt';
            $command = escapeshellcmd($pdftotext) . ' -layout -enc UTF-8 ' . escapeshellarg($path) . ' ' . escapeshellarg($outputPath);
            @exec($command, $output, $code);
            if ($code === 0 && is_file($outputPath)) {
                $text = file_get_contents($outputPath);
                @unlink($outputPath);
                if (is_string($text) && trim($text) !== '') {
                    return $text;
                }
            }
        }

        return '';
    }

    private function findPdfToTextBinary(): string
    {
        foreach (['pdftotext', '/usr/bin/pdftotext', '/usr/local/bin/pdftotext'] as $binary) {
            $check = stripos(PHP_OS_FAMILY, 'Windows') === 0
                ? 'where ' . escapeshellarg($binary)
                : 'command -v ' . escapeshellarg($binary);
            @exec($check, $output, $code);
            if ($code === 0) {
                return $binary;
            }
        }
        return '';
    }

    private function normalizeText(string $text): string
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+$/m', '', $text) ?? $text;
        $text = preg_replace("/\n{4,}/", "\n\n\n", $text) ?? $text;
        return trim($text);
    }

    private function resolveTextModel(int $userId): ?ModelConfig
    {
        return ModelConfigResolver::resolve('text', $userId > 0 ? $userId : 1);
    }

    private function cleanByModel(ModelConfig $model, string $rawText, string $filename, int $userId = 0): string
    {
        $chunks = $this->splitText($rawText, 4500);
        $cleaned = [];
        $total = count($chunks);
        $billingUserId = $userId > 0 ? $userId : (int) ($model->getAttr('user_id') ?? 1);
        foreach ($chunks as $index => $chunk) {
            $messages = [
                [
                    'role' => 'system',
                    'content' => '你是小说和短剧剧本正文清洗器。只输出清洗后的正文，不要总结，不要改写剧情，不要补充解释，不要输出标题、简介、JSON、Markdown 或代码块。',
                ],
                [
                    'role' => 'user',
                    'content' => "文件名：{$filename}\n当前分段：" . ($index + 1) . "/{$total}\n\n请清理下面这段从 TXT/PDF 导入的文本：去掉页眉页脚、页码、重复空行、乱码控制字符，保留原有剧情正文、对白、章节名和段落顺序。只返回清洗后的正文。\n\n{$chunk}",
                ],
            ];
            $context = [
                'source' => 'novel_import',
                'user_id' => $billingUserId,
                'filename' => $filename,
                'chunk_index' => $index + 1,
                'chunk_count' => $total,
                'raw_chars' => mb_strlen($chunk),
            ];
            try {
                $cleaned[] = $this->callChatCompletions($model, $messages, $context);
            } catch (\RuntimeException $e) {
                if (!str_contains($e->getMessage(), '小说导入模型未返回正文')) {
                    throw $e;
                }
                Log::warning('[NovelImport] model returned empty content, fallback to raw chunk: ' . json_encode($context, JSON_UNESCAPED_UNICODE));
                $cleaned[] = $chunk;
            }
        }

        return implode("\n\n", array_filter(array_map('trim', $cleaned)));
    }

    private function splitText(string $text, int $maxChars): array
    {
        $paragraphs = preg_split("/\n{2,}/", $text) ?: [$text];
        $chunks = [];
        $current = '';
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim((string) $paragraph);
            if ($paragraph === '') {
                continue;
            }
            if (mb_strlen($paragraph) > $maxChars) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }
                $length = mb_strlen($paragraph);
                for ($offset = 0; $offset < $length; $offset += $maxChars) {
                    $chunks[] = mb_substr($paragraph, $offset, $maxChars);
                }
                continue;
            }
            $candidate = $current === '' ? $paragraph : $current . "\n\n" . $paragraph;
            if (mb_strlen($candidate) > $maxChars && $current !== '') {
                $chunks[] = $current;
                $current = $paragraph;
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') {
            $chunks[] = $current;
        }
        return $chunks !== [] ? $chunks : [$text];
    }

    private function callChatCompletions(ModelConfig $model, array $messages, array $context): string
    {
        $startedAt = microtime(true);
        $endpoint = '';
        $httpStatus = 0;
        $rawBody = '';
        $curlErrno = 0;
        $curlError = '';
        $requestOk = 0;
        $errorMessage = '';
        $contentPreview = '';
        $payload = [];

        try {
            $endpoint = trim((string) $model->getAttr('endpoint'));
            if ($endpoint === '') {
                throw new \RuntimeException('文本模型 endpoint 为空');
            }
            if (!str_contains($endpoint, '/chat/completions')) {
                $endpoint = rtrim($endpoint, '/') . '/chat/completions';
            }

            $apiKey = trim((string) $model->getAttr('api_key'));
            $modelId = trim((string) $model->getAttr('model_id'));
            if ($modelId === '') {
                throw new \RuntimeException('文本模型 model_id 为空');
            }
            $options = $model->getAttr('options') ?: [];
            if (!is_array($options)) {
                $options = [];
            }
            $payload = [
                'model' => $modelId,
                'messages' => $messages,
                'temperature' => 0,
                'max_tokens' => max(2048, min(8192, (int) ($options['max_tokens'] ?? 4096))),
            ];
            if ($this->shouldDisableDeepSeekThinking($endpoint, $modelId)) {
                $payload['thinking'] = ['type' => 'disabled'];
            }

            CreditService::assertTextAffordable(
                (int) ($context['user_id'] ?? 0),
                CreditService::estimatePromptTokensFromMessages($messages),
                (int) $payload['max_tokens'],
                $modelId
            );

            $headers = ['Content-Type: application/json'];
            if ($apiKey !== '') {
                $headers[] = 'Authorization: Bearer ' . $apiKey;
            }

            $ch = curl_init($endpoint);
            if ($ch === false) {
                throw new \RuntimeException('初始化小说导入模型请求失败');
            }
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 600);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));

            $response = curl_exec($ch);
            $curlErrno = curl_errno($ch);
            $curlError = (string) curl_error($ch);
            $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($curlErrno !== 0) {
                $errorMessage = '小说导入模型请求失败：' . $curlError;
                throw new \RuntimeException($errorMessage);
            }
            if (!is_string($response) || $response === '') {
                $errorMessage = '小说导入模型返回为空';
                throw new \RuntimeException($errorMessage);
            }
            $rawBody = $response;
            if ($httpStatus < 200 || $httpStatus >= 300) {
                $errorMessage = '小说导入模型服务异常：HTTP ' . $httpStatus . '，响应：' . mb_substr($response, 0, 300);
                throw new \RuntimeException($errorMessage);
            }
            $decoded = json_decode($response, true);
            if (!is_array($decoded)) {
                $errorMessage = '小说导入模型返回非 JSON';
                throw new \RuntimeException($errorMessage);
            }
            $message = is_array($decoded['choices'][0]['message'] ?? null)
                ? $decoded['choices'][0]['message']
                : [];
            $content = $this->extractAssistantTextContent($message);
            if ($content === '') {
                $errorMessage = '小说导入模型未返回正文';
                throw new \RuntimeException($errorMessage);
            }

            $requestOk = 1;
            $contentPreview = mb_substr($content, 0, 1000);
            return $content;
        } finally {
            try {
                AiRequestLog::create([
                    'user_id' => (int) ($context['user_id'] ?? $model->getAttr('user_id') ?? 1),
                    'source' => (string) ($context['source'] ?? 'novel_import'),
                    'model_config_id' => (int) ($model->getAttr('id') ?? 0) ?: null,
                    'llm_model' => (string) ($payload['model'] ?? ''),
                    'endpoint' => $endpoint,
                    'context_json' => $this->encodeLogJson($context),
                    'request_json' => $this->encodeLogJson($payload),
                    'http_status' => $httpStatus,
                    'response_body' => $this->truncateLogText($rawBody, 400000),
                    'curl_errno' => $curlErrno,
                    'curl_error' => mb_substr($curlError, 0, 512),
                    'request_ok' => $requestOk,
                    'error_message' => mb_substr($errorMessage, 0, 2000),
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'content_preview' => $contentPreview,
                ]);
            } catch (\Throwable $e) {
                Log::error('[AiRequestLog] novel import insert failed: ' . $e->getMessage());
            }
        }
    }

    private function shouldDisableDeepSeekThinking(string $endpoint, string $modelId): bool
    {
        $endpoint = strtolower($endpoint);
        $modelId = strtolower($modelId);

        return str_contains($endpoint, 'api.deepseek.com')
            || str_starts_with($modelId, 'deepseek-v4')
            || str_starts_with($modelId, 'deepseek-chat')
            || str_starts_with($modelId, 'deepseek-reasoner');
    }

    private function extractAssistantTextContent(array $message): string
    {
        $content = $message['content'] ?? '';
        if (is_array($content)) {
            $parts = [];
            foreach ($content as $chunk) {
                if (is_array($chunk) && isset($chunk['text'])) {
                    $parts[] = (string) $chunk['text'];
                } elseif (is_string($chunk)) {
                    $parts[] = $chunk;
                }
            }
            $content = implode("\n", $parts);
        }
        $content = trim((string) $content);
        if ($content !== '') {
            return $content;
        }

        $reasoning = $message['reasoning_content'] ?? ($message['reasoning'] ?? '');
        if (is_array($reasoning)) {
            $parts = [];
            foreach ($reasoning as $chunk) {
                if (is_array($chunk) && isset($chunk['text'])) {
                    $parts[] = (string) $chunk['text'];
                } elseif (is_string($chunk)) {
                    $parts[] = $chunk;
                }
            }
            $reasoning = implode("\n", $parts);
        }

        return trim((string) $reasoning);
    }

    private function encodeLogJson(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        return is_string($json) ? $this->truncateLogText($json, 400000) : '';
    }

    private function truncateLogText(string $text, int $maxBytes): string
    {
        if (strlen($text) <= $maxBytes) {
            return $text;
        }
        return mb_substr($text, 0, (int) ($maxBytes / 4)) . "\n...[truncated]";
    }
}
