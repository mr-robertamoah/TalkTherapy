<?php

namespace App\Models;

use App\Enums\SessionStatusEnum;
use App\Enums\TherapyPaymentTypeEnum;
use App\Traits\Starreable;
use Carbon\Carbon;
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
        'type', 'status', 'longitude', 'latitude', 'landmark', 'therapy_id'
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

    public function updatedBy()
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

        if ($this->isTherapy)
        {
            $users = 
        }

        return $users;
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
        return $query->whereDate('end_time', '<=', now()->toTimeString());
    }

    public function scopeWhereDateFallsBetween($query, $date)
    {
        return $query
            ->whereDate('start_time', '<=', $date)
            ->whereDate('end_time', '>=', $date);
    }

    public function scopeWhereIsNot30MinituesBeforeOrAfter($query, $startDate, $endDate)
    {
        return $query
            ->where(function ($query) use ($startDate) {
                $query->whereDateFallsBetween((new Carbon($startDate))->subMinutes(30)->toTimeString());
            })
            ->orWhere(function ($query) use ($endDate) {
                $query->whereDateFallsBetween((new Carbon($endDate))->addMinutes(30)->toTimeString());
            });
    }

    public function scopeWhereStatusIn($query, $statuses)
    {
        return $query->whereIn('status', $statuses);
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
        return $query->where('therapy_id', $therapyId);
    }

    public function scopeWhereNameLike($query, $name)
    {
        return $query->where('name', 'LIKE', "%{$name}%");
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
}
