<?php

namespace App\Models;

use App\Traits\Alertable;
use App\Traits\Commentable;
use App\Traits\Likeable;
use App\Traits\Starreable;
use App\Traits\TherapyTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Therapy extends Model
{
    use HasFactory,
    Starreable,
    Alertable,
    Likeable,
    Commentable,
    SoftDeletes,
    TherapyTrait;

    protected $fillable = [
        'session_type', 'payment_type', 'background_story', 'allow_in_person', 'name',
        'public', 'anonymous', 'payment_data', 'status', 'max_sessions', 'counsellor_id'
    ];

    protected $casts = [
        'payment_data' => 'array'
    ];

    public function getIsTherapyAttribute()
    {
        return true;
    }

    public function getIsGroupTherapyAttribute()
    {
        return false;
    }

    public function getTherapyTypeAttribute()
    {
        return 'Therapy';
    }

    public function counsellor()
    {
        return $this->belongsTo(Counsellor::class);
    }

    public function isParticipant(User $user)
    {
        if ($this->addedby->is($user)) return true;

        if (!$this->counsellor?->user) return false;

        return $this->counsellor->user->is($user);
    }

    public function isNotParticipant(User $user)
    {
        return !$this->isParticipant($user);
    }

    public function scopeWhereCounsellor($query, Counsellor $counsellor)
    {
        return $query->where(function ($query) use ($counsellor) {
            $query->where('counsellor_id', $counsellor->id);
        });
    }

    public function scopeWhereNotCounsellor($query, Counsellor $counsellor)
    {
        return $query->where(function ($query) use ($counsellor) {
            $query->whereNot('counsellor_id', $counsellor->id);
        });
    }

    public function scopeWhereHasNoCounsellor($query)
    {
        return $query
            ->where(function ($query) {
                $query->where('counsellor_id', null);
            });
    }

    public function scopeWhereUser($query, User $user)
    {
        return $query->where(function ($query) use ($user) {
            $query
                ->where('addedby_id', $user->id)
                ->where('addedby_type', $user::class);
        });
    }

    public function scopeWhereNotUser($query, User $user)
    {
        return $query->where(function ($query) use ($user) {
            $query
                ->whereNot('addedby_id', $user->id)
                ->where('addedby_type', $user::class);
        });
    }

    public function scopeWhereParticipant($query, User $user)
    {
        return $query
            ->whereAddedby($user)
            ->when($user->counsellor, function ($query) use ($user) {
                $query->orWhere(function ($query) use ($user) {
                    $query->whereCounsellor($user->counsellor);
                });
            });
    }

    public function hasAssistance()
    {
        return $this->counsellor()->exists();
    }

    public function doesNotHaveAssistance()
    {
        return !$this->hasAssistance();
    }

    public function isUser(User $user)
    {
        return $this->addedby->is($user);
    }

    public function getUsers()
    {
        $users = collect();
        if ($this->addedby_type == User::class)
            $users->push($this->addedby);

        if ($this->counsellor)
            $users->push($this->counsellor->user);

        if (
            $this->addedby_type == User::class &&
            !$this->addedby->isAdult() && 
            $this->addedby->guardians()->count()
        ) $users->merge(User::query()->whereWard($this->addedby)->get());

        return $users;
    }

    public function scopeWhereWard($query, $user)
    {
        return $query->whereHas('guardians', function ($query) use ($user) {
            $query->where('ward_id', $user->id);
        });
    }

    public function scopeWhereIsParticipant($query, $user)
    {
        return $query
            ->where(function ($query) use ($user) {
                $query
                    ->where('addedby_type', $user::class)
                    ->where('addedby_id', $user->id);
            })
            ->when($user->counsellor, function ($query) use ($user) {
                $query->orWhere(function ($query) use ($user) {
                    $query->where('counsellor_id', $user->counsellor->id);
                });
            });
    }

    public function getOtherUsers(User $user)
    {
        $users = collect();
        if ($this->addedby_type == User::class && $this->addedby_id !== $user->id)
            $users->push($this->addedby);

        if (!$this->counsellor->user->is($user))
            $users->push($this->counsellor->user);

        if (!$this->addedby->isAdult() && $this->addedby->guardians()->count())
            $users->merge(User::query()->whereNot('id', $user->id)
                ->whereWard($this->addedby)->get());

        return $users;
    }

    public function isCounsellor(Counsellor $counsellor)
    {
        return $this->counsellor->is($counsellor);
    }
}