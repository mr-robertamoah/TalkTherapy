<?php

namespace App\Support;

use App\Models\Discussion;
use App\Models\Session;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;

// SCRUM-372/374: true the first time a scheduled sweep asks for a kind of notice about a session
// or discussion -- the sweeps run repeatedly over the same window (every minute or five), and a
// reminder sends email. The start time is part of the key, so a rescheduled session is announced
// again when it really starts. Kept until an hour after the end.
//
// SCRUM-373: in the scheduler's own cache store, which the deploy's `cache:clear` leaves alone.
class SchedulerOnce
{
    public static function first(string $kind, Session|Discussion $model): bool
    {
        $until = $model->end_time?->isFuture() ? $model->end_time->copy()->addHour() : now()->addHour();
        $startedAt = $model->start_time?->timestamp ?? 0;

        return self::key("{$kind}:{$model->id}:{$startedAt}", $until);
    }

    // SCRUM-378: the same once-only guard for anything else a scheduled job should do at most once
    // per key (e.g. the stuck org-charge alert, once a day per charge).
    public static function key(string $key, DateTimeInterface|int $until): bool
    {
        return Cache::store(config('cache.scheduler_store'))->add("scheduler-once:{$key}", true, $until);
    }

    // SCRUM-382 review: gives a claimed key back, when what it guarded couldn't be done (so a re-run
    // tries again).
    public static function forget(string $key): void
    {
        Cache::store(config('cache.scheduler_store'))->forget("scheduler-once:{$key}");
    }
}
