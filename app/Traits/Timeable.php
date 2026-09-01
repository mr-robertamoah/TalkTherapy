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

    public function scopeWhereIsOngoing($query)
    {
        return $query
            ->where(function ($query) {
                $query
                    ->whereHasStartedAndNotEnded();
            })
            ->orWhere(function ($query) {
                $query
                    ->wherePastEndTime()
                    ->whereInSession();
            });
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

    public function scopeWhereIsThirtyMinituesBeforeOrAfter($query, $startDate = null, $endDate = null)
    {
        // ->copy() below: Carbon instances are mutable, and $startDate/$endDate are often the
        // same objects the caller reuses in later checks (see EnsureSessionDataIsValidAction /
        // EnsureDiscussionDataIsValidAction). Mutating them in place here would shift the
        // caller's notion of the proposed start/end time on every subsequent call.
        return $query
            ->when($startDate, function ($query) use ($startDate) {
                $query
                    ->where(function ($query) use ($startDate) {
                        $query->whereDateIsBetweenStartAndEndTimes($startDate->copy()->subMinutes(30));
                    });
            })
            ->when($endDate, function ($query) use ($endDate) {
                $query
                    ->orWhere(function ($query) use ($endDate) {
                        $query->whereDateIsBetweenStartAndEndTimes($endDate->copy()->addMinutes(30));
                    });
            });
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
