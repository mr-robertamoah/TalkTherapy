<?php

namespace App\Traits;

use App\Models\Discussion;
use App\Models\Session;

trait Timeable
{
    public function scopeWherePastEndTime($query)
    {
        return $query->where('end_time', '<=', now());
    }

    public function scopeWhereStartsInTheFuture($query)
    {
        return $query->where('start_time', '>', now()->subMinutes(5));
    }

    public function scopeWhereDateIsBetweenStartAndEndTimes($query, $date)
    {
        return $query
            ->where('start_time', '<=', $date)
            ->where('end_time', '>=', $date);
    }

    public function scopeWhereAboutToStart($query)
    {
        return $query
            ->whereBetween('start_time', [now(), now()->addMinutes(30)]);
    }

    public function scopeWhereHasStartedAndNotEnded($query)
    {
        return $query
            ->where('start_time', '<=', now())
            ->where('end_time', '>', now());
    }

    public function scopeWhereFiveOrLessMinutesToStart($query)
    {
        return $query
            ->whereBetween('start_time', [now(), now()->addMinutes(5)]);
    }

    // A row is "within 30 minutes" of [$startDate, $endDate] if it overlaps that range widened by
    // 30 minutes on each side. SCRUM-211: this used to test only two points of the new range
    // (start - 30, end + 30) against each row, so a new range that fully contained a shorter row
    // (10:00-11:00 around 10:10-10:40) was never caught. Bounds stay inclusive, as before.
    //
    // ->copy(): Carbon instances are mutable, and callers reuse $startDate/$endDate in later checks.
    public function scopeWhereIsThirtyMinituesBeforeOrAfter($query, $startDate = null, $endDate = null)
    {
        $from = ($startDate ?? $endDate)?->copy()->subMinutes(30);
        $to = ($endDate ?? $startDate)?->copy()->addMinutes(30);

        return $query->when($from && $to, fn ($query) => $query->whereWithinRange($from, $to));
    }

    // A row overlaps [$start, $end] if it starts before the range ends AND ends after the range
    // starts -- catches a session that spans into, out of, or entirely across the requested
    // window, not just one whose start_time happens to fall inside it (SCRUM-212: a calendar week/
    // month view needs every session touching that window, including one that started the day
    // before and runs past midnight into it).
    public function scopeWhereWithinRange($query, $start, $end)
    {
        return $query
            ->where('start_time', '<=', $end)
            ->where('end_time', '>=', $start);
    }

    public function isNotUpdateable()
    {
        $query = $this::class == Session::class
            ? Session::query()
            : Discussion::query();

        return $query
            ->where('id', $this->id)
            ->where(function ($query) {
                $query
                    ->wherePastEndTime()
                    ->orWhere(function ($query) {
                        $query->whereAboutToStart();
                    })
                    ->orWhere(function ($query) {
                        $query->whereDateIsBetweenStartAndEndTimes(now());
                    });
            })
            ->exists();
    }

    public function isUpdateable()
    {
        return ! $this->isNotUpdateable();
    }

    public function isNotDeleteable()
    {
        return $this
            ->where('id', $this->id)
            ->where(function ($query) {
                $query
                    ->whereAboutToStart()
                    ->orWhere(function ($query) {
                        $query->whereDateIsBetweenStartAndEndTimes(now());
                    });
            })
            ->exists();
    }

    public function isDeleteable()
    {
        return ! $this->isNotDeleteable();
    }
}
