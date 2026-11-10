<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

// SCRUM-373: the scheduler records each minute that it ran (routes/console.php), in its own cache
// store so a deploy's cache:clear doesn't make it look dead.
class SchedulerHeartbeat
{
    private const KEY = 'scheduler:heartbeat';

    // The scheduler runs every minute; allow for a slow run or a restart.
    public const STALE_AFTER_MINUTES = 5;

    public static function beat(): void
    {
        Cache::store(config('cache.scheduler_store'))->forever(self::KEY, now()->timestamp);
    }

    public static function lastRunAt(): ?Carbon
    {
        $timestamp = Cache::store(config('cache.scheduler_store'))->get(self::KEY);

        return $timestamp ? Carbon::createFromTimestamp($timestamp) : null;
    }

    public static function isHealthy(): bool
    {
        $lastRunAt = self::lastRunAt();

        return $lastRunAt !== null && $lastRunAt->gt(now()->subMinutes(self::STALE_AFTER_MINUTES));
    }
}
