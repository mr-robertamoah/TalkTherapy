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
        // Null-safe: a deleted client used to 500 here -- now on every alert request (SCRUM-352).
        if ($this->addedby?->is($user)) {
            return true;
        }

        if (! $this->counsellor?->user) {
            return false;
        }

        return $this->counsellor->user->is($user);
    }

    // SCRUM-368: an individual therapy's payment and refund status is the client's own payment --
    // shown to the client, the therapy's counsellor and administrators, never to someone browsing
    // a public therapy (GroupTherapy::showsPaymentStatusTo is the group counterpart).
    // Remembered per viewer on this instance: a session list shares one parent therapy.
    private array $showsPaymentStatusCache = [];

    public function showsPaymentStatusTo(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $this->showsPaymentStatusCache[$user->id] ??= $this->isParticipant($user) || $user->isAdmin();
    }

    public function isNotParticipant(User $user)
    {
        return ! $this->isParticipant($user);
    }

    // SCRUM-379: the one masking rule for a 1:1 therapy (TherapyTrait::displayNameOf() and
    // addedByUserIsMaskedFor() use it). Only the client can be anonymous; the counsellor never is.
    // A Therapy is only ever created by its client (CreateTherapyAction), so `addedby` is a User.
    public function isMaskedToOthers(User $subject): bool
    {
        return (bool) $this->anonymous && $this->addedby_type === User::class && $this->isUser($subject);
    }

    // SCRUM-332/379: an anonymous 1:1 client keeps their camera -- the counsellor is the only other
    // person in the call. GroupTherapy::allowsCameraFor() is the rule that withholds it.
    public function allowsCameraFor(User $user): bool
    {
        return true;
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
        // Null-safe: a deleted client used to make callers 500 (security review, SCRUM-350).
        return (bool) $this->addedby?->is($user);
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
        return (bool) $this->counsellor?->is($counsellor);
    }
}
