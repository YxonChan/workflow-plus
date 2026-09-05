<?php

declare(strict_types=1);

namespace app\support;

final class ImageProviderTaskState
{
    // toapis: tsk_xxx；电信等: cgt-...；腾讯云点播 AIGC: 1500067999-AigcImageTask-...
    private const TASK_ID_PATTERN = '/\b(tsk_[A-Za-z0-9_-]+|cgt-\d{14}-[A-Za-z0-9_-]+|\d+-AigcImage(?:Task)?-[A-Za-z0-9]+)\b/';

    public static function extractTaskId(string $message): string
    {
        if (preg_match(self::TASK_ID_PATTERN, $message, $matches) !== 1) {
            return '';
        }

        return trim((string) ($matches[1] ?? ''));
    }

    public static function pendingMessage(string $taskId): string
    {
        $taskId = trim($taskId);

        return $taskId === '' ? '' : '图片平台处理中，正在等待结果：' . $taskId;
    }

    public static function isLegacyTimeout(string $message): bool
    {
        return str_contains($message, 'AI 图片任务超时：')
            && self::extractTaskId($message) !== '';
    }
}
