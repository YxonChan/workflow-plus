<?php

declare(strict_types=1);

namespace app\support;

final class PendingImageTaskException extends \RuntimeException
{
    private string $taskId;

    public function __construct(string $taskId)
    {
        $this->taskId = $taskId;
        parent::__construct(ImageProviderTaskState::pendingMessage($taskId));
    }

    public function taskId(): string
    {
        return $this->taskId;
    }
}
