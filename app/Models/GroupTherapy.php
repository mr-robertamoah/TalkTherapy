<?php

namespace App\Models;

use App\Traits\Alertable;
use App\Traits\Commentable;
use App\Traits\Likeable;
use App\Traits\Starreable;
use App\Traits\TherapyTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class GroupTherapy extends Model
{
    use HasFactory,
    Likeable,
    Commentable,
    Starreable,
    TherapyTrait,
    Alertable,
    SoftDeletes;

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

    public function getIsGroupTherapyAttribute()
    {
        return true;
    }

    public function getTherapyTypeAttribute()
    {
        return 'Group Therapy';
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
            ->withPivot(['state', 'role'])
            ->withTimestamps();
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'group_therapy_user', 'group_therapy_id', 'user_id')
            ->withPivot(['background_story', 'anonymous'])
            ->withTimestamps();
    }

    public function discussions()
    {
        return $this->morphMany(Discussion::class, 'for');
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

    public function getCounsellors()
    {
        $users = [];
       
        // TODO

        return $users;
    }

    public function getOtherCounsellors(Counsellor $counsellor)
    {
        $counsellors = [];
       
        // TODO

        return $counsellors;
    }

    public function getActiveSession(User $user)    
    {
        // TODO
        return $this->sessions()
            ->where(function ($query) use ($user) {
                $query
                    ->whereIsParticipant($user)
                    ->whereIsNotUserWhoConfirmedHeld($user)
                    ->whereIsOngoing();
            })
            ->first();
    }

    public function getActiveDiscussion(Counsellor $counsellor)
    {
        // TODO
        return $this->discussions()
            ->where(function ($query) use ($counsellor) {
                $query
                    ->whereIsParticipant($counsellor)
                    ->whereIsOngoing();
            })
            ->first();
    }

    public function pendingRequestFor(?Counsellor $counsellor)
    {
        // TODO
        if (is_null($counsellor)) return null;

        return Request::query()
            ->wherePending()
            ->whereFor($this)
            ->whereTo($counsellor)
            ->latest()
            ->first();
    }

    public function scopeWhereNotCounsellor($query, Counsellor $counsellor)
    {
        // TODO test this
        return $query
            ->where(function ($query) use ($counsellor) {
                $query
                    ->whereNot('addedby_id', $counsellor->id)
                    ->where('addedby_type', Counsellor::class);
            })->orWhere(function ($query) use ($counsellor) {
                $query
                    ->whereDoesntHave('counsellors', function ($query) use ($counsellor) {
                        $query->where('counsellor_id', $counsellor->id);
                    });
            });
    }

    public function scopeWhereCounsellor($query, Counsellor $counsellor)
    {
        return $query
            ->where(function ($query) use ($counsellor) {
                $query
                    ->where('addedby_id', $counsellor->id)
                    ->where('addedby_type', Counsellor::class);
            })->orWhere(function ($query) use ($counsellor) {
                $query
                    ->whereHas('counsellors', function ($query) use ($counsellor) {
                        $query->where('counsellor_id', $counsellor->id);
                    });
            });
    }

    public function scopeWhereHasNoCounsellor($query)
    {
        return $query
            ->where(function ($query) {
                $query
                    ->whereDoesntHave('counsellors', function ($query) {
                        $query->where('group_therapy_id', $this->id);
                    });
            });
    }

    public function scopeWhereUser($query, User $user)
    {
        return $query->where(function ($query) use ($user) {
            $query
                ->whereHas('users', function ($query) use ($user) {
                    $query->where('user_id', $user->id);
                });
        });
    }

    public function scopeWhereNotUser($query, User $user)
    {
        return $query->where(function ($query) use ($user) {
            $query
                ->whereDoesntHave('users', function ($query) use ($user) {
                    $query->where('user_id', $user->id);
                });
        });
    }

    public function scopeWhereParticipant($query, User $user)
    {
        return $query
            ->where(function ($query) use ($user) {
                $query->whereUser($user);
            })
            ->when($user->counsellor, function ($query) use ($user) {
                $query->orWhere(function ($query) use ($user) {
                    $query->whereCounsellor($user->counsellor);
                });
            });
    }
}
