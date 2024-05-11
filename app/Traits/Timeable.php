<?php

namespace App\Traits;

use App\Models\Discussion;
use App\Models\Session;
use App\Models\Star;

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

    public function scopeWhereOnGoing($query)
    {
        return $query
            ->where(function ($query) {
                $query
                    ->where('start_time', '<=', now())
                    ->where('end_time', '>=', now());
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
        return $query
            ->when($startDate, function ($query) use ($startDate) {
                $query
                    ->where(function ($query) use ($startDate) {
                        $query->whereDateIsBetweenStartAndEndTimes($startDate->subMinutes(30));
                    });
            })
            ->when($endDate, function ($query) use ($endDate) {
                $query
                    ->orWhere(function ($query) use ($endDate) {
                        $query->whereDateIsBetweenStartAndEndTimes($endDate->addMinutes(30));
                    });
            });
    }

    public function isNotUpdateable()
    {
        $query = $this::class == Session::class 
            ? Session::query()
            : Discussion::query();
            
        return $query
            ->where('id', $this->id)
            ->where(function ($query) {
                $query->wherePastEndTime();
            })
            ->orWhere(function ($query) {
                $query->whereAboutToStart();
            })
            ->orWhere(function ($query) {
                $query->whereDateIsBetweenStartAndEndTimes(now());
            })
            ->exists();
    }

    public function isUpdateable()
    {
        return !$this->isNotUpdateable();
    }

    public function isNotDeleteable()
    {
        return $this
            ->where(function ($query) {
                $query->whereAboutToStart();
            })
            ->orWhere(function ($query) {
                $query->whereDateIsBetweenStartAndEndTimes(now());
            })
            ->exists();
    }

    public function isDeleteable()
    {
        return !$this->isNotDeleteable();
    }
}