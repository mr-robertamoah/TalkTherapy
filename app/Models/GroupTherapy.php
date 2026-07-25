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

class GroupTherapy extends Model
{
    use Alertable,
        Commentable,
        HasFactory,
        Likeable,
        SoftDeletes,
        Starreable,
        TherapyTrait;

    protected $fillable = [
        'session_type', 'payment_type', 'max_users', 'allow_anyone', 'about', 'name',
        'public', 'anonymous', 'payment_data', 'status', 'max_sessions',
    ];

    protected $casts = [
        'payment_data' => 'array',
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
        if ($this->addedby_type === Counsellor::class && $this->addedby?->is($counsellor)) {
            return true;
        }

        return $this->counsellors()->whereKey($counsellor->id)->exists();
    }

    public function isUser(User $user)
    {
        return $this->addedby_type === User::class && $this->addedby?->is($user);
    }

    public function isParticipant(User $user)
    {
        if ($this->isUser($user)) {
            return true;
        }

        return $user->counsellor && $this->isCounsellor($user->counsellor);
    }

    public function isNotParticipant(User $user)
    {
        return ! $this->isParticipant($user);
    }

    public function getUsers()
    {
        $users = collect();

        if ($this->addedby_type === User::class && $this->addedby) {
            $users->push($this->addedby);
        }

        if ($this->addedby_type === Counsellor::class && $this->addedby?->user) {
            $users->push($this->addedby->user);
        }

        $this->counsellors->each(function ($counsellor) use ($users) {
            if ($counsellor->user) {
                $users->push($counsellor->user);
            }
        });

        if (
            $this->addedby_type === User::class &&
            $this->addedby &&
            ! $this->addedby->isAdult() &&
            $this->addedby->guardians()->count()
        ) {
            $users = $users->merge(User::query()->whereWard($this->addedby)->get());
        }

        return $users->filter();
    }

    public function getOtherUsers(User $user)
    {
        $users = collect();

        if ($this->addedby_type === User::class && $this->addedby && $this->addedby_id !== $user->id) {
            $users->push($this->addedby);
        }

        if ($this->addedby_type === Counsellor::class && $this->addedby?->user && ! $this->addedby->user->is($user)) {
            $users->push($this->addedby->user);
        }

        $this->counsellors->each(function ($counsellor) use ($users, $user) {
            if ($counsellor->user && ! $counsellor->user->is($user)) {
                $users->push($counsellor->user);
            }
        });

        if (
            $this->addedby_type === User::class &&
            $this->addedby &&
            ! $this->addedby->isAdult() &&
            $this->addedby->guardians()->count()
        ) {
            $users = $users->merge(
                User::query()->whereNot('id', $user->id)->whereWard($this->addedby)->get()
            );
        }

        return $users->filter();
    }

    public function getCounsellors()
    {
        $counsellors = collect($this->counsellors);

        if ($this->addedby_type === Counsellor::class && $this->addedby) {
            $counsellors->push($this->addedby);
        }

        return $counsellors->unique('id');
    }

    public function getOtherCounsellors(Counsellor $counsellor)
    {
        return $this->getCounsellors()->reject(fn ($other) => $other->is($counsellor));
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
