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
    use Alertable,
        Commentable,
        HasFactory,
        Likeable,
        SoftDeletes,
        Starreable,
        TherapyTrait;

    protected $fillable = [
        'session_type', 'payment_type', 'background_story', 'allow_in_person', 'name',
        'public', 'anonymous', 'payment_data', 'status', 'max_sessions', 'counsellor_id',
        'video_consent_mode', 'client_was_minor_at_creation',
    ];

    protected $casts = [
        'payment_data' => 'array',
        'client_was_minor_at_creation' => 'boolean',
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
        // withTrashed: a therapy's counsellor may have since deleted their account (which
        // soft-deletes the Counsellor too) -- isParticipant()/notifications/etc. below all
        // assume this relation resolves rather than crashing on a null counsellor.
        return $this->belongsTo(Counsellor::class)->withTrashed();
    }

    // TT-3.1e-a/SCRUM-280: PER_THERAPY-scoped video consent grants for this therapy -- a
    // PER_SESSION grant lives on the Session's own morphMany instead (see Session model).
    public function videoConsents()
    {
        return $this->morphMany(VideoConsent::class, 'consentable');
    }

    public function isParticipant(User $user)
    {
        if ($this->addedby->is($user)) {
            return true;
        }

        if (! $this->counsellor?->user) {
            return false;
        }

        return $this->counsellor->user->is($user);
    }

    public function isNotParticipant(User $user)
    {
        return ! $this->isParticipant($user);
    }

    // Individual therapy has a single, therapy-wide anonymity flag -- who the given sender
    // actually is doesn't change whether it applies, unlike GroupTherapy's per-member pivot.
    public function isAnonymousFor(User $sender): bool
    {
        return (bool) $this->anonymous;
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
        return ! $this->hasAssistance();
    }

    public function isUser(User $user)
    {
        return $this->addedby->is($user);
    }

    public function getUsers()
    {
        $users = collect();
        if ($this->addedby_type == User::class) {
            $users->push($this->addedby);
        }

        if ($this->counsellor) {
            $users->push($this->counsellor->user);
        }

        // TT-4.10b/SCRUM-291: was `! $this->addedby->isAdult()` -- prefers the stable
        // client_was_minor_at_creation snapshot (TT-4.10a) over a live re-check, closing the
        // self-editable-dob bypass SCRUM-287 found.
        //
        // Also fixes a pre-existing bug surfaced while writing this ticket's own regression
        // tests: Collection::merge() returns a NEW collection rather than mutating in place, so
        // the bare `$users->merge(...)` below silently discarded its result -- a minor client's
        // guardians were never actually being added to this list at all, regardless of the
        // isAdult() check this replaces. GroupTherapy's own identical method already reassigns
        // correctly (`$users = $users->merge(...)`); this brings Therapy in line with it.
        if (
            $this->clientIsMinor() &&
            $this->addedby->guardians()->count()
        ) {
            $users = $users->merge(User::query()->whereWard($this->addedby)->get());
        }

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
        if ($this->addedby_type == User::class && $this->addedby_id !== $user->id) {
            $users->push($this->addedby);
        }

        if (! $this->counsellor->user->is($user)) {
            $users->push($this->counsellor->user);
        }

        // TT-4.10b/SCRUM-291: was `! $this->addedby->isAdult()` -- see getUsers()'s identical
        // comment above (including the pre-existing missing-reassignment bug fix).
        if ($this->clientIsMinor() && $this->addedby->guardians()->count()) {
            $users = $users->merge(User::query()->whereNot('id', $user->id)
                ->whereWard($this->addedby)->get());
        }

        return $users;
    }

    public function isCounsellor(Counsellor $counsellor)
    {
        return $this->counsellor->is($counsellor);
    }
}
