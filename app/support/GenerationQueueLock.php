<?php

declare(strict_types=1);

namespace app\support;

use think\facade\Db;

final class GenerationQueueLock
{
    public static function acquire(): bool
    {
        EcommerceService::ensureSchema();
        return (int) (Db::query("SELECT GET_LOCK('generation_queue_claim', 0) AS acquired")[0]['acquired'] ?? 0) === 1;
    }

    public static function release(): void
    {
        Db::query("SELECT RELEASE_LOCK('generation_queue_claim')");
    }

    public static function ecommerceInFlight(?string $mode = null): int
    {
        EcommerceService::ensureSchema();
        $query = Db::name('ecommerce_jobs')->whereRaw("(status = 'running' OR (status = 'queued' AND provider_task_id <> ''))");
        if ($mode !== null) $query->where('mode', $mode);
        return (int) $query->count();
    }
}
