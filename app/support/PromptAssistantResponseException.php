<?php

declare(strict_types=1);

namespace app\support;

use RuntimeException;

class PromptAssistantResponseException extends RuntimeException
{
    public readonly string $output;

    public function __construct(string $output, bool $truncated = false)
    {
        $this->output = mb_substr($output, 0, 30000);
        parent::__construct($truncated
            ? '模型输出达到长度上限，内容不完整，请重试本次消息'
            : '模型返回的 JSON 格式不正确，请重试本次消息');
    }
}
