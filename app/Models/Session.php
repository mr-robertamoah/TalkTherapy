<?php

namespace App\Models;

use App\Enums\SessionStatusEnum;
use App\Enums\SessionTypeEnum;
use App\Enums\TherapyPaymentTypeEnum;
use App\Traits\Starreable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Session extends Model
{
    use HasFactory,
    Starreable,
    SoftDeletes;

    protected $fillable = [
        'name', 'about', 'start_time', 'end_time', 'payment_type',
        'type', 'status', 'longitude', 'latitude', 'landmark', 'therapy_id',
        'updatedby_type', 'updatedby_id'
    ];
    
    protected $casts = [
        'end_time' => 'datetime',
        'start_time' => 'datetime',
    ];

    public function therapy()
    {
        return $this->for::class == Therapy::class
            ? $this->for
            : null;
    }

    public function groupTherapy()
    {
        return $this->for::class == GroupTherapy::class
            ? $this->for
            : null;
    }

    public function for()
    {
        return $this->morphTo();
    }

    public function topics()
    {
        return $this->belongsToMany(TherapyTopic::class, 'therapy_topic_session')
            ->withTimestamps();
    }

    public function messages()
    {
        return $this->morphMany(Message::class, 'for');
    }

    public function addedby()
    {
        return $this->morphTo('addedby');
    }

    public function updatedby()
    {
        return $this->morphTo('updatedby');
    }

    public function cases(): MorphToMany
    {
        return $this
            ->morphToMany(TherapyCase::class, 'caseable', 'caseables', relatedPivotKey: 'case_id')
            ->withTimestamps();
    }

    public function getUsersAttribute()
    {
        $users = [];

        if ($this->for_type == Therapy::class) {
            $users[] = $this->for->addedby;
            $users[] = $this->for->counsellor->user;
        } else {

        }

        return $users;
    }

    public function scopeWhereInSession($query)
    {
        return $query
            ->whereStatusIn([
                SessionStatusEnum::pending->value,
                SessionStatusEnum::in_session->value,
                SessionStatusEnum::in_session_confirmation->value,
            ]);
    }

    public function isParticipant(User $user)
    {
        return $this->for?->isParticipant($user);
    }

    public function isNotParticipant(User $user)
    {
        return $this->for?->isNotParticipant($user);
    }

    public function scopeWhereName($query, $name)
    {
        return $query->where('name', $name);
    }

    public function scopeWhereHeld($query)
    {
        return $query->where('status', SessionStatusEnum::held->value);
    }

    public function scopeWherePending($query)
    {
        return $query->where('status', SessionStatusEnum::pending->value);
    }

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

    public function doesNotAcceptMessage()
    {
        return !$this->acceptsMessage();
    }

    public function acceptsMessage()
    {
        return $this->type == SessionTypeEnum::online->value &&
            in_array($this->status, [
                SessionStatusEnum::pending->value,
                SessionStatusEnum::in_session->value,
                SessionStatusEnum::in_session_confirmation->value,
            ]);
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

    public function scopeWhereStatusIn($query, $statuses)
    {
        return $query->whereIn('status', $statuses);
    }

    public function scopeWhereStatusNotIn($query, $statuses)
    {
        return $query->whereNotIn('status', $statuses);
    }

    public function scopeWherePaid($query)
    {
        return $query->where('payment_type', TherapyPaymentTypeEnum::paid->value);
    }

    public function scopeWhereFree($query)
    {
        return $query->where('payment_type', TherapyPaymentTypeEnum::free->value);
    }

    public function scopeWhereTherapyId($query, $therapyId)
    {
        return $query
            ->whereTherapy()
            ->where('for_id', $therapyId);
    }

    public function scopeWhereTherapy($query)
    {
        return $query
            ->where('for_type', Therapy::class);
    }

    public function scopeWhereGroupTherapyId($query, $groupTherapyId)
    {
        return $query
            ->whereGroupTherapy()
            ->where('for_id', $groupTherapyId);
    }

    public function scopeWhereGroupTherapy($query)
    {
        return $query->where(function ($query) {
            $query->where('for_type', GroupTherapy::class);
        });
    }

    public function scopeWhereNameLike($query, $name)
    {
        return $query->where('name', 'LIKE', "%{$name}%");
    }

    public function scopeWhereOnline($query)
    {
        return $query->where('type', SessionTypeEnum::online->value);
    }

    public function scopeWhereNotPending($query)
    {
        return $query->whereNot('status', SessionStatusEnum::pending->value);
    }

    public function scopeWhereInPerson($query)
    {
        return $query->where('type', SessionTypeEnum::in_person->value);
    }

    public function isNotUpdateable()
    {
        return Session::query()
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

    public function getNotificationActionData()
    {
        if ($this->for->isTherapy) {
            $type = 'Therapy';
            $url = url("therapies/{$this->for->id}");
        }
        else {
            $type = 'Group Therapy';
            $url = url("group_therapies/{$this->for->id}");
        }
        
        return [$type, $url];
    }

    public function getForChannelName()
    {
        if ($this->for_type == Therapy::class)
            return "therapies.{$this->for_id}";
        
        return "grouptherapies.{$this->for_id}";
    }
}
