<?php

declare(strict_types=1);

namespace app\support;

/**
 * 腾讯云点播 AIGC 生图（CreateAigcImageTask + DescribeTaskDetail）。
 * 使用 API 3.0 TC3-HMAC-SHA256，不依赖完整 SDK。
 */
final class TencentVodAigcImageClient
{
    private const HOST = 'vod.tencentcloudapi.com';

    private const SERVICE = 'vod';

    private const VERSION = '2018-07-17';

    /**
     * @param array<string, mixed> $options model_configs.options
     * @return array{task_id: string, request_id: string, raw: string}
     */
    public function createTask(
        string $secretId,
        string $secretKey,
        string $prompt,
        array $options = [],
        array $referenceImageUrls = [],
    ): array {
        $subAppId = (int) ($options['sub_app_id'] ?? $options['SubAppId'] ?? 0);
        if ($subAppId <= 0) {
            throw new \InvalidArgumentException('腾讯云生图缺少 sub_app_id');
        }

        $modelName = trim((string) ($options['model_name'] ?? $options['ModelName'] ?? 'OG'));
        if ($modelName === '') {
            $modelName = 'OG';
        }
        $modelVersion = $this->resolveModelVersion($options);

        $payload = [
            'SubAppId' => $subAppId,
            'ModelName' => $modelName,
            'ModelVersion' => $modelVersion,
            'Prompt' => $prompt,
        ];

        $fileInfos = $this->buildFileInfos($referenceImageUrls, $options);
        if ($fileInfos !== []) {
            $payload['FileInfos'] = $fileInfos;
        }

        // 当前 CreateAigcImageTask（OG/image2）不接受 AspectRatio，比例由模型默认或后续官方参数扩展。

        $outputConfig = $this->buildOutputConfig($options);
        if ($outputConfig !== []) {
            $payload['OutputConfig'] = $outputConfig;
        }

        $raw = $this->request($secretId, $secretKey, 'CreateAigcImageTask', $payload);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('腾讯云生图返回非 JSON');
        }
        if (isset($decoded['Response']['Error'])) {
            $err = $decoded['Response']['Error'];
            $code = (string) ($err['Code'] ?? '');
            $msg = (string) ($err['Message'] ?? '未知错误');
            throw new \RuntimeException('腾讯云生图创建失败：' . ($code !== '' ? "[{$code}] " : '') . $msg);
        }

        $taskId = trim((string) ($decoded['Response']['TaskId'] ?? ''));
        if ($taskId === '') {
            throw new \RuntimeException('腾讯云生图未返回 TaskId：' . mb_substr($raw, 0, 400));
        }

        return [
            'task_id' => $taskId,
            'request_id' => (string) ($decoded['Response']['RequestId'] ?? ''),
            'raw' => $raw,
            'payload' => $payload,
        ];
    }

    /**
     * @return array{status: string, image_url: string, message: string, raw: string, finished: bool}
     */
    public function describeTask(string $secretId, string $secretKey, string $taskId, int $subAppId = 0): array
    {
        $payload = ['TaskId' => $taskId];
        if ($subAppId > 0) {
            $payload['SubAppId'] = $subAppId;
        }

        $raw = $this->request($secretId, $secretKey, 'DescribeTaskDetail', $payload);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('腾讯云任务查询返回非 JSON');
        }
        if (isset($decoded['Response']['Error'])) {
            $err = $decoded['Response']['Error'];
            $code = (string) ($err['Code'] ?? '');
            $msg = (string) ($err['Message'] ?? '未知错误');
            throw new \RuntimeException('腾讯云任务查询失败：' . ($code !== '' ? "[{$code}] " : '') . $msg);
        }

        $response = is_array($decoded['Response'] ?? null) ? $decoded['Response'] : [];
        $status = strtoupper((string) ($response['Status'] ?? ''));
        $aigc = is_array($response['AigcImageTask'] ?? null) ? $response['AigcImageTask'] : [];
        if ($status === '' && $aigc !== []) {
            $status = strtoupper((string) ($aigc['Status'] ?? ''));
        }

        $message = trim((string) ($aigc['Message'] ?? $response['Message'] ?? ''));
        $errCode = (int) ($aigc['ErrCode'] ?? $response['ErrCode'] ?? 0);
        $errCodeExt = trim((string) ($aigc['ErrCodeExt'] ?? ''));
        $imageUrl = $this->extractImageUrl($aigc, $response);

        $finished = in_array($status, ['FINISH', 'DONE', 'SUCCESS'], true)
            || ($imageUrl !== '' && $errCode === 0 && $errCodeExt === '');
        $failed = in_array($status, ['FAIL', 'FAILED', 'ERROR'], true)
            || ($errCode !== 0 && $status === 'FINISH')
            || ($errCodeExt !== '' && $status === 'FINISH' && $imageUrl === '');

        if ($failed) {
            return [
                'status' => $status !== '' ? $status : 'FAIL',
                'image_url' => '',
                'message' => $message !== '' ? $message : ($errCodeExt !== '' ? $errCodeExt : '任务失败'),
                'raw' => $raw,
                'finished' => true,
            ];
        }

        return [
            'status' => $status !== '' ? $status : 'PROCESSING',
            'image_url' => $imageUrl,
            'message' => $message,
            'raw' => $raw,
            'finished' => $finished && $imageUrl !== '',
        ];
    }

    /**
     * 画质优先：速创/节点传入的 quality 必须覆盖模型默认 model_version（如写死的 image2_high）。
     *
     * @param array<string, mixed> $options
     */
    public function resolveModelVersion(array $options): string
    {
        $quality = strtolower(trim((string) ($options['quality'] ?? '')));
        if (in_array($quality, ['low', 'medium', 'high'], true)) {
            return ImageGenerationOptions::defaultModelVersion($quality);
        }

        $explicit = trim((string) ($options['model_version'] ?? $options['ModelVersion'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        return ImageGenerationOptions::defaultModelVersion();
    }

    /**
     * @param array<int, string> $urls
     * @param array<string, mixed> $options
     * @return array<int, array<string, mixed>>
     */
    private function buildFileInfos(array $urls, array $options): array
    {
        $fileInfos = [];
        foreach ($urls as $url) {
            $normalized = $this->normalizeInputFileInfo(['Type' => 'Url', 'Url' => $url]);
            if ($normalized !== null) {
                $fileInfos[] = $normalized;
            }
        }

        $configured = $options['file_infos'] ?? $options['FileInfos'] ?? null;
        if (is_array($configured)) {
            foreach ($configured as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $normalized = $this->normalizeInputFileInfo($item);
                if ($normalized !== null) {
                    $fileInfos[] = $normalized;
                }
            }
        }

        return $fileInfos;
    }

    /**
     * 官方输入结构 AigcImageTaskInputFileInfo：仅 Type + Url|FileId|Base64。
     * FileUrl 是输出字段，绝不能出现在 CreateAigcImageTask 请求里。
     *
     * @param array<string, mixed> $item
     * @return array<string, string>|null
     */
    private function normalizeInputFileInfo(array $item): ?array
    {
        $type = strtolower(trim((string) ($item['Type'] ?? $item['type'] ?? 'url')));
        if ($type === 'file') {
            $fileId = trim((string) ($item['FileId'] ?? $item['file_id'] ?? ''));
            if ($fileId === '') {
                return null;
            }

            return [
                'Type' => 'File',
                'FileId' => $fileId,
            ];
        }

        if ($type === 'base64') {
            $base64 = trim((string) ($item['Base64'] ?? $item['base64'] ?? ''));
            if ($base64 === '') {
                return null;
            }

            return [
                'Type' => 'Base64',
                'Base64' => $base64,
            ];
        }

        // 兼容误写入的 FileUrl：只取其值，仍按官方 Url 字段发出。
        $url = trim((string) ($item['Url'] ?? $item['url'] ?? $item['FileUrl'] ?? $item['file_url'] ?? ''));
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return null;
        }

        return [
            'Type' => 'Url',
            'Url' => $url,
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function buildOutputConfig(array $options): array
    {
        $configured = $options['output_config'] ?? $options['OutputConfig'] ?? null;
        if (is_array($configured) && $configured !== []) {
            return $configured;
        }

        $storageMode = trim((string) ($options['storage_mode'] ?? 'Temporary'));
        if ($storageMode === '') {
            return [];
        }

        return ['StorageMode' => $storageMode];
    }

    private function isSupportedAspectRatio(string $ratio): bool
    {
        $ratio = str_replace(' ', '', $ratio);
        return in_array($ratio, ['1:1', '16:9', '9:16', '4:3', '3:4', '3:2', '2:3'], true);
    }

    /**
     * @param array<string, mixed> $aigc
     * @param array<string, mixed> $response
     */
    private function extractImageUrl(array $aigc, array $response): string
    {
        $output = is_array($aigc['Output'] ?? null) ? $aigc['Output'] : [];
        $fileInfos = is_array($output['FileInfos'] ?? null) ? $output['FileInfos'] : [];
        foreach ($fileInfos as $info) {
            if (!is_array($info)) {
                continue;
            }
            $url = trim((string) ($info['FileUrl'] ?? ''));
            if ($url !== '') {
                return $url;
            }
        }

        $urls = $response['ImageUrls'] ?? $aigc['ImageUrls'] ?? null;
        if (is_array($urls)) {
            foreach ($urls as $url) {
                $url = trim((string) $url);
                if ($url !== '') {
                    return $url;
                }
            }
        }

        return trim((string) ($output['FileUrl'] ?? $aigc['FileUrl'] ?? ''));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function request(string $secretId, string $secretKey, string $action, array $payload): string
    {
        $secretId = trim($secretId);
        $secretKey = trim($secretKey);
        if ($secretId === '' || $secretKey === '') {
            throw new \InvalidArgumentException('腾讯云 SecretId/SecretKey 不能为空');
        }

        $timestamp = time();
        $date = gmdate('Y-m-d', $timestamp);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payloadJson)) {
            throw new \RuntimeException('腾讯云请求体编码失败');
        }

        $canonicalHeaders = "content-type:application/json; charset=utf-8\nhost:" . self::HOST . "\n";
        $signedHeaders = 'content-type;host';
        $hashedPayload = hash('sha256', $payloadJson);
        $canonicalRequest = "POST\n/\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$hashedPayload}";

        $credentialScope = "{$date}/" . self::SERVICE . '/tc3_request';
        $stringToSign = "TC3-HMAC-SHA256\n{$timestamp}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

        $secretDate = hash_hmac('sha256', $date, 'TC3' . $secretKey, true);
        $secretService = hash_hmac('sha256', self::SERVICE, $secretDate, true);
        $secretSigning = hash_hmac('sha256', 'tc3_request', $secretService, true);
        $signature = hash_hmac('sha256', $stringToSign, $secretSigning);

        $authorization = 'TC3-HMAC-SHA256 Credential=' . $secretId . '/' . $credentialScope
            . ', SignedHeaders=' . $signedHeaders
            . ', Signature=' . $signature;

        $headers = [
            'Content-Type: application/json; charset=utf-8',
            'Host: ' . self::HOST,
            'X-TC-Action: ' . $action,
            'X-TC-Timestamp: ' . $timestamp,
            'X-TC-Version: ' . self::VERSION,
            'Authorization: ' . $authorization,
            'X-TC-Region: ',
        ];

        $ch = curl_init('https://' . self::HOST . '/');
        if ($ch === false) {
            throw new \RuntimeException('初始化腾讯云请求失败');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $payloadJson,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new \RuntimeException('腾讯云请求失败：' . $error);
        }
        if (!is_string($response) || $response === '') {
            throw new \RuntimeException('腾讯云返回为空（HTTP ' . $httpStatus . '）');
        }

        return $response;
    }
}
