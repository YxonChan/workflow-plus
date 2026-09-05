<?php

declare(strict_types=1);

namespace app\support;

use app\model\AiRequestLog;
use app\model\ModelConfig;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class CallSheetGenerator
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
You are a professional script breakdown supervisor and script coordinator. You have extensive experience in film/TV production, script analysis, and creating scene breakdown sheets. Parse the provided script and generate a detailed scene-by-scene production notice / continuity breakdown sheet.

Strictly follow the script. Do not invent characters, costumes, props, scenes, or plot information. If a detail is ambiguous, make only a reasonable production inference and mark it as "[inferred]".

Language requirements:
- Output the entire generated notice in English only.
- Even if the input script is Chinese or any other non-English language, translate and normalize all extracted breakdown content into English.
- Do not output Chinese characters or other non-English script characters in the final HTML.

Continuity rules:
- Never use placeholder continuity phrases such as "same as previous", "same as above", "same as Scene 1", "same as Scene 2", "same as Scene N", "ditto", "as before", "unchanged", or equivalent shorthand in any source language.
- If continuity clearly implies the same costume or prop from a prior scene, repeat the concrete detail in full.
- Never use standalone vague timing labels such as "CONTINUOUS", "EVENING", "LATER", "MOMENTS LATER", "SAME", "SAME TIME", "same night", or "same day". Expand them into concrete production wording.

Your final response must be a complete, valid HTML document only. Do not wrap it in Markdown fences. Do not include explanations before or after the HTML.

Mandatory table headers:
Seq, Episode, Scene, Int/Ext, Time, Story Time, Characters, Costume, Props, Summary.

Design requirements:
- The generated document must look like a polished production schedule, not a plain HTML dump.
- Include a location color legend above the table.
- Scenes in the same physical location must share the same color marker.
- Use print-friendly CSS, strong table hierarchy, compact readable cells, and high contrast.
- No JavaScript is needed in the generated HTML.
PROMPT;

    public function generateHtml(ModelConfig $model, string $script, array $context = [], string $extraInstruction = ''): string
    {
        $script = trim($script);
        if ($script === '') {
            throw new \InvalidArgumentException('通告单生成需要剧本正文或上游节点内容');
        }

        $messages = [
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
            [
                'role' => 'user',
                'content' => trim($extraInstruction) . "\n\nBreak down the following complete script and output a complete English HTML call sheet. If the source script is Chinese or any other non-English language, translate the breakdown content into English:\n\n" . $script,
            ],
        ];

        $html = $this->callChatCompletions($model, $messages, $context + ['source' => 'call_sheet']);
        $html = $this->stripMarkdownFence($html);
        if (!preg_match('/<(?:!doctype|html|table|body)\b/i', $html)) {
            throw new \RuntimeException('AI 未返回有效通告单 HTML');
        }

        return $html;
    }

    public function renderPdf(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            throw new \InvalidArgumentException('HTML 内容不能为空');
        }

        $ownerSuffix = function_exists('posix_geteuid') ? (string) posix_geteuid() : substr(md5(__DIR__), 0, 8);
        $tempDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'malulu-mpdf-' . $ownerSuffix;
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0777, true);
        }
        if (!is_writable($tempDir)) {
            $tempDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'malulu-mpdf-' . bin2hex(random_bytes(4));
            mkdir($tempDir, 0777, true);
        }

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A3-L',
            'margin_left' => 6,
            'margin_right' => 6,
            'margin_top' => 6,
            'margin_bottom' => 6,
            'tempDir' => $tempDir,
        ]);
        $mpdf->shrink_tables_to_fit = 1;
        $mpdf->WriteHTML($this->injectPrintCss($html));

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    private function callChatCompletions(ModelConfig $model, array $messages, array $context): string
    {
        $startedAt = microtime(true);
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

        $payload = [
            'model' => $modelId,
            'messages' => $messages,
            'temperature' => 0.2,
        ];

        $options = $model->getAttr('options') ?: [];
        if (is_array($options)) {
            foreach (['reasoning_effort', 'thinking', 'max_tokens'] as $key) {
                if (array_key_exists($key, $options)) {
                    $payload[$key] = $options[$key];
                }
            }
        }
        // DeepSeek V4 默认 thinking 会占满 max_tokens 并把正文塞进 reasoning_content。
        if ($this->shouldDisableDeepSeekThinking($endpoint, $modelId)) {
            $payload['thinking'] = ['type' => 'disabled'];
        }

        $maxTokens = (int) ($payload['max_tokens'] ?? 8192);
        CreditService::assertTextAffordable(
            (int) ($context['user_id'] ?? 0),
            CreditService::estimatePromptTokensFromMessages($messages),
            max(512, $maxTokens),
            $modelId
        );

        $headers = ['Content-Type: application/json'];
        if ($apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        $response = '';
        $httpStatus = 0;
        $curlErrno = 0;
        $curlError = '';
        $content = '';
        $errorMessage = '';

        try {
            $ch = curl_init($endpoint);
            if ($ch === false) {
                throw new \RuntimeException('初始化通告单 AI 请求失败');
            }
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 900);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));

            $response = curl_exec($ch);
            $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErrno = curl_errno($ch);
            $curlError = (string) curl_error($ch);
            curl_close($ch);

            if ($curlErrno !== 0) {
                throw new \RuntimeException('通告单 AI 请求失败：' . $curlError);
            }
            if (!is_string($response) || $response === '') {
                throw new \RuntimeException('通告单 AI 返回为空');
            }
            if ($httpStatus < 200 || $httpStatus >= 300) {
                throw new \RuntimeException('通告单 AI 服务异常：HTTP ' . $httpStatus . '，响应：' . mb_substr($response, 0, 500));
            }

            $decoded = json_decode($response, true);
            $message = is_array($decoded['choices'][0]['message'] ?? null)
                ? $decoded['choices'][0]['message']
                : [];
            $content = $this->extractAssistantTextContent($message);
            if ($content === '') {
                throw new \RuntimeException('通告单 AI 未返回 content');
            }

            return $content;
        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage();
            throw $e;
        } finally {
            AiRequestLog::create([
                'user_id' => (int) ($context['user_id'] ?? 1),
                'source' => (string) ($context['source'] ?? 'call_sheet'),
                'model_config_id' => (int) $model->getAttr('id'),
                'llm_model' => $payload['model'] ?? '',
                'endpoint' => $endpoint,
                'context_json' => json_encode($context, JSON_UNESCAPED_UNICODE),
                'request_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'http_status' => $httpStatus,
                'response_body' => mb_substr((string) $response, 0, 400000),
                'curl_errno' => $curlErrno,
                'curl_error' => mb_substr($curlError, 0, 512),
                'request_ok' => $content !== '' ? 1 : 0,
                'error_message' => mb_substr($errorMessage, 0, 2000),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
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
        $content = trim((string) ($message['content'] ?? ''));
        if ($content !== '') {
            return $content;
        }

        return trim((string) ($message['reasoning_content'] ?? ($message['reasoning'] ?? '')));
    }

    private function stripMarkdownFence(string $text): string
    {
        $text = trim($text);
        if (preg_match('/^```(?:html)?\s*(.*?)\s*```$/is', $text, $matches)) {
            return trim($matches[1]);
        }

        return $text;
    }

    private function injectPrintCss(string $html): string
    {
        $css = <<<'CSS'
<style data-mpdf-call-sheet="true">
@page { size: A3 landscape; margin: 6mm; }
body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 8px; line-height: 1.25; color: #1f2933; }
table { width: 100%; border-collapse: collapse; table-layout: fixed; }
th, td { border: .2mm solid #ccd3db; padding: 1.3mm 1.4mm; vertical-align: top; word-break: break-word; overflow-wrap: anywhere; }
th { background: #202833; color: #fff; font-weight: 700; }
h1 { font-size: 18px; margin: 0 0 4mm; }
h2 { font-size: 10px; margin: 3mm 0 2mm; }
.location-legend, [data-location-legend] { border: .2mm solid #d7dce2; padding: 2.5mm; margin-bottom: 4mm; }
</style>
CSS;

        if (preg_match('/<\/head>/i', $html)) {
            return preg_replace('/<\/head>/i', $css . "\n</head>", $html, 1) ?? $html;
        }
        if (preg_match('/<html[^>]*>/i', $html)) {
            return preg_replace('/<html[^>]*>/i', '$0<head>' . $css . '</head>', $html, 1) ?? $html;
        }

        return '<!doctype html><html><head>' . $css . '</head><body>' . $html . '</body></html>';
    }
}
