<?php

namespace App\Models;

use App\Enums\SessionStatusEnum;
use App\Enums\TherapyStatusEnum;
use App\Traits\Alertable;
use App\Traits\Commentable;
use App\Traits\Likeable;
use App\Traits\Starreable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Therapy extends Model
{
    use HasFactory,
    Starreable,
    Alertable,
    Likeable,
    Commentable,
    SoftDeletes;

    protected $fillable = [
        'session_type', 'payment_type', 'background_story', 'allow_in_person', 'name',
        'public', 'anonymous', 'payment_data', 'status', 'max_sessions', 'counsellor_id'
    ];

    protected $casts = [
        'payment_data' => 'array'
    ];

    public function getActiveSessionAttribute()
    {
        return Session::query()
            ->whereTherapyId($this->id)
            ->whereInSession()
            ->orWhere(function ($query) {
                $query->whereTherapyId($this->id);
                $query->whereFiveOrLessMinutesToStart();
            })
            ->orWhere(function ($query) {
                $query->whereTherapyId($this->id);
                $query->whereOnGoing();
            })
            ->first();
    }

    public function getSessionsHeldAttribute()
    {
        return $this->sessions()->whereHeld()->count();
    }

    public function getIsTherapyAttribute()
    {
        return true;
    }

    public function getSessionsCreatedAttribute()
    {
        return $this->sessions()->count();
    }

    public function getPaidSessionsAttribute()
    {
        return $this->sessions()->wherePaid()->count();
    }

    public function getFreeSessionsAttribute()
    {
        return $this->sessions()->whereFree()->count();
    }

    public function getStatus()
    {
        if ($this->status == TherapyStatusEnum::in_session->value)
            return str_replace('_', ' ', TherapyStatusEnum::in_session->value);
        
        return $this->status;
    }

    public function addedby()
    {
        return $this->morphTo('addedby');
    }

    public function therapyTopics()
    {
        return $this->morphMany(TherapyTopic::class, 'for');
    }

    public function messages()
    {
        return $this->morphMany(Message::class, 'for');
    }

    public function counsellor()
    {
        return $this->belongsTo(Counsellor::class);
    }

    public function sessions()
    {
        return $this->morphMany(Session::class, 'for');
    }

    public function cases(): MorphToMany
    {
        return $this
            ->morphToMany(TherapyCase::class, 'caseable', 'caseables', relatedPivotKey: 'case_id')
            ->withTimestamps();
    }

    public function languages(): MorphToMany
    {
        return $this
            ->morphToMany(Language::class, 'languageable', 'languageables')
            ->withTimestamps();
    }

    public function religions(): MorphToMany
    {
        return $this
            ->morphToMany(Religion::class, 'religionable', 'religionables')
            ->withTimestamps();
    }

    public function isParticipant(User $user)
    {
        return $this->addedby->is($user) || $this->counsellor?->user->is($user);
    }

    public function isNotParticipant(User $user)
    {
        return !$this->isParticipant($user);
    }

    public function pendingRequestFor(?Counsellor $counsellor)
    {
        if (is_null($counsellor)) return null;

        return Request::query()
            ->wherePending()
            ->whereFor($this)
            ->whereTo($counsellor)
            ->latest()
            ->first();
    }

    public function scopeWhereAddedby($query, Model $model)
    {
        return $query->where(function ($query) use ($model) {
            $query
                ->where('addedby_type', $model::class)
                ->where('addedby_id', $model->id);
        });
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

    public function scopeWhereNoCounsellor($query)
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

    public function scopeWherePublic($query)
    {
        return $query->where('public', true);
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

    public function requests()
    {
        return $this->morphMany(Request::class, 'for');
    }

    public function hasAssistance()
    {
        return $this->counsellor()->exists();
    }

    public function doesNotHaveAssistance()
    {
        return !$this->hasAssistance();
    }

    public function discussions()
    {
        return $this->morphMany(Discussion::class, 'for');
    }

    public function endSessions()
    {
        $this->sessions()
            ->wherePending()
            ->update(['status' => SessionStatusEnum::failed->value]);

        $this->sessions()
            ->wherePastEndTime()
            ->update(['status' => SessionStatusEnum::held->value]);
        
        $this->sessions()
            ->whereStatusIn([
                SessionStatusEnum::held_confirmation->value,
                SessionStatusEnum::in_session->value,
                SessionStatusEnum::in_session_confirmation->value
            ])
            ->update(['status' => SessionStatusEnum::abandoned->value]);
    }

    public function getTopicsCountAttribute()
    {
        return $this->topics()->count();
    }

    public function topics()
    {
        return $this->hasMany(TherapyTopic::class);
    }

    public function isUser(User $user)
    {
        return $this->addedby->is($user);
    }

    public function getUsers()
    {
        $users = [];
        if ($this->addedby_type == User::class)
            $users[] = $this->addedby;

        if ($this->counsellor)
            $users[] = $this->counsellor->user;

        if (
            $this->addedby_type == User::class &&
            !$this->addedby->isAdult() && 
            $this->addedby->guardians()->count()
        ) $users = array_merge($users, User::query()->whereWard($this->addedby)->get()->toArray());

        return $users;
    }

    public function scopeWhereWard($query, $user)
    {
        return $query->whereHas('guardians', function ($query) use ($user) {
            $query->where('ward_id', $user->id);
        });
    }

    public function getOtherUsers(User $user)
    {
        $users = [];
        if ($this->addedby_type == User::class && $this->addedby_id !== $user->id)
            $users[] = $this->addedby;

        if (!$this->counsellor->user->is($user))
            $users[] = $this->counsellor->user;

        if (!$this->addedby->isAdult() && $this->addedby->guardians()->count())
            $users = array_merge($users, User::query()->whereNot('id', $user->id)
                ->whereWard($this->addedby)->get()->toArray());

        return collect($users);
    }

    public function isCounsellor(Counsellor $counsellor)
    {
        return $this->counsellor->is($counsellor);
    }
}