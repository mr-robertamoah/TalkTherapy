<?php

namespace App\Models;

use App\Traits\Commentable;
use App\Traits\Likeable;
use App\Traits\Starreable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class GroupTherapy extends Model
{
    use HasFactory,
    Likeable,
    Commentable,
    Starreable;

    protected $fillable = [
        'session_type', 'payment_type', 'max_users', 'allow_anyone', 'about', 'name',
        'public', 'anonymous', 'payment_data', 'status', 'max_sessions'
    ];

    protected $casts = [
        'payment_data' => 'array'
    ];

    public function getIsTherapyAttribute()
    {
        return false;
    }

    public function addedby()
    {
        return $this->morphTo('addedby');
    }

    public function sessions()
    {
        return $this->morphMany(Session::class, 'for');
    }

    public function counsellors()
    {
        return $this->belongsToMany(Counsellor::class, 'counsellor_group_therapy', 'group_therapy_id', 'counsellor_id')
            ->withPivot(['state'])
            ->withTimestamps();
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'group_therapy_user', 'group_therapy_id', 'user_id')
            ->withPivot(['background_story'])
            ->withTimestamps();
    }

    public function therapyTopics()
    {
        return $this->morphMany(TherapyTopic::class, 'for');
    }

    public function cases(): MorphToMany
    {
        return $this
            ->morphToMany(TherapyCase::class, 'caseable', 'caseables', relatedPivotKey: 'case_id')
            ->withTimestamps();
    }

    public function discussions()
    {
        return $this->morphMany(Discussion::class, 'for');
    }

    public function getActiveSessionAttribute()
    {
        return $this
            ->sessions()
            ->whereInSession()
            ->whereFiveOrLessMinutesToStart()
            ->orWhere(function ($query) {
                $query->whereIsOngoing();
            })
            ->first();
    }

    public function isCounsellor(Counsellor $counsellor)
    {
        return false; // TODO
    }

    public function isUser(User $user)
    {
        return false; // TODO
    }

    public function isParticipant(User $user)
    {
        return false; // TODO
    }

    public function getUsers()
    {
        $users = [];
       
        // TODO

        return $users;
    }

    public function getOtherUsers(User $user)
    {
        $users = [];
       
        // TODO

        return $users;
    }
}
