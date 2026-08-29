<?php

namespace App\Models;

use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Enums\RequestTypeEnum;
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
        'public', 'anonymous', 'payment_data', 'status', 'max_sessions', 'max_counsellors',
        'allow_in_person',
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
        // withTrashed: see Therapy::counsellor() -- addedby (User or Counsellor) may have
        // since deleted their account.
        return $this->morphTo('addedby')->withTrashed();
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

        return $this->counsellors()
            ->whereKey($counsellor->id)
            ->wherePivot('state', CounsellorGroupTherapyStateEnum::active->value)
            ->exists();
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

        if ($user->counsellor && $this->isCounsellor($user->counsellor)) {
            return true;
        }

        // A user attached via the group_therapy_user pivot (immediate-join or an accepted
        // membership request) is a participant too -- SCRUM-69/SCRUM-72.
        return $this->users()->whereKey($user->id)->exists();
    }

    // Server-side anti-bypass rule: the group's own anonymous flag always wins over whatever
    // anonymity value was requested (at join-time or at membership-request-accept-time).
    public function resolveMembershipAnonymity(bool $requested): bool
    {
        return $this->anonymous ? true : $requested;
    }

    // The latest pending membership request (SCRUM-72) involving this user for this group,
    // whether they're the one who requested to join (`from`) or the creator deciding on it
    // (`to`) -- mirrors TherapyTrait::pendingRequestFor()'s single-latest-request UX for the
    // existing counsellor-assistance flow.
    public function pendingMembershipRequestFor(User $user)
    {
        return $this->requests()
            ->wherePending()
            ->whereType(RequestTypeEnum::groupTherapyMembership->value)
            ->where(function ($query) use ($user) {
                $query->whereFrom($user)->orWhereTo($user);
            })
            ->latest()
            ->first();
    }

    public function isNotParticipant(User $user)
    {
        return ! $this->isParticipant($user);
    }

    // OR logic: masked if either the group itself defaults everyone to anonymous, or this
    // specific member opted into anonymity via their own group_therapy_user pivot row.
    public function isAnonymousFor(User $sender): bool
    {
        if ($this->anonymous) {
            return true;
        }

        return (bool) $this->users->firstWhere('id', $sender->id)?->pivot->anonymous;
    }

    // Mirrors Therapy::scopeWhereIsParticipant() -- without this, Session::scopeWhereIsParticipant()'s
    // whereHasMorph('for', '*', ...) falls back to a bare `where('is_participant', ...)` column
    // lookup for the GroupTherapy branch (since there's no matching local scope), which throws a
    // SQL error for *every* Session lookup as soon as any GroupTherapy-type session exists at all.
    public function scopeWhereIsParticipant($query, User $user)
    {
        return $query
            ->where(function ($query) use ($user) {
                $query
                    ->where('addedby_type', User::class)
                    ->where('addedby_id', $user->id);
            })
            ->orWhere(function ($query) use ($user) {
                $query->whereHas('users', function ($query) use ($user) {
                    $query->where('user_id', $user->id);
                });
            })
            ->when($user->counsellor, function ($query) use ($user) {
                $query
                    ->orWhere(function ($query) use ($user) {
                        $query
                            ->where('addedby_type', Counsellor::class)
                            ->where('addedby_id', $user->counsellor->id);
                    })
                    ->orWhereHas('counsellors', function ($query) use ($user) {
                        $query->where('counsellor_id', $user->counsellor->id);
                    });
            });
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

        // Users attached via the group_therapy_user pivot (immediate-join or an accepted
        // membership request) -- SCRUM-69/SCRUM-72.
        $users = $users->merge($this->users);

        if (
            $this->addedby_type === User::class &&
            $this->addedby &&
            ! $this->addedby->isAdult() &&
            $this->addedby->guardians()->count()
        ) {
            $users = $users->merge(User::query()->whereWard($this->addedby)->get());
        }

        return $users->filter()->unique('id');
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

        $users = $users->merge($this->users->reject(fn ($pivotUser) => $pivotUser->is($user)));

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

        return $users->filter()->unique('id');
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

    // Unlike getCounsellors(), filters the pivot to state=active -- for callers (e.g. org-as-payer
    // eligibility, SCRUM-48) that need "who is currently a live counsellor on this GroupTherapy",
    // not every counsellor ever attached regardless of pivot state. A soft-deleted addedby
    // counsellor is excluded (unlike getCounsellors()/isCounsellor()'s unconditional include) --
    // "currently active" cannot be true of an account that no longer exists, and requiring org
    // coverage for one would permanently block org-as-payer for this GroupTherapy since a deleted
    // counsellor can never again satisfy an active organization_counsellors row.
    public function activeCounsellors()
    {
        $counsellors = $this->counsellors()
            ->wherePivot('state', CounsellorGroupTherapyStateEnum::active->value)
            ->get();

        if ($this->addedby_type === Counsellor::class && $this->addedby && ! $this->addedby->trashed()) {
            $counsellors->push($this->addedby);
        }

        return $counsellors->unique('id');
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
                ->where(function ($query) use ($user) {
                    $query
                        ->where('addedby_type', User::class)
                        ->where('addedby_id', $user->id);
                })
                ->orWhereHas('users', function ($query) use ($user) {
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
