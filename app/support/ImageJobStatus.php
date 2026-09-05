<?php

declare(strict_types=1);

namespace app\support;

final class ImageJobStatus
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const WAITING = 'waiting';

    /** 历史状态：写实造型曾等待彩铅。新代码不再写入，Worker 会取消残留。 */
    public const HOLDING = 'holding';

    public const SUCCESS = 'success';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    /**
     * 尚未结束、前端应继续轮询的状态。
     *
     * @return list<string>
     */
    public static function active(): array
    {
        return [self::QUEUED, self::RUNNING, self::WAITING, self::HOLDING];
    }

    /**
     * 已占用上游在途名额（进程内执行或平台处理中）。
     *
     * @return list<string>
     */
    public static function inFlight(): array
    {
        return [self::RUNNING, self::WAITING];
    }

    public static function isActive(string $status): bool
    {
        return in_array($status, self::active(), true);
    }

    public static function isBusy(string $status): bool
    {
        return in_array($status, self::inFlight(), true);
    }
}
