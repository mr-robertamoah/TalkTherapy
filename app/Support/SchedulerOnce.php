<?php

namespace App\Support;

use App\Models\Discussion;
use App\Models\Session;
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

        return Cache::store(config('cache.scheduler_store'))
            ->add("scheduler-once:{$kind}:{$model->id}:{$startedAt}", true, $until);
    }
}
