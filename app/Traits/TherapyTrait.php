<?php

namespace App\Traits;

use App\Enums\RequestTypeEnum;
use App\Enums\SessionStatusEnum;
use App\Enums\TherapyStatusEnum;
use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\Language;
use App\Models\Message;
use App\Models\Religion;
use App\Models\Request;
use App\Models\Session;
use App\Models\TherapyCase;
use App\Models\TherapyTopic;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

trait TherapyTrait
{
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
                $query->whereIsOngoing();
            })
            ->first();
    }

    // Memoized (SCRUM-212): Eloquent's morphTo eager loading shares one model instance across
    // every sibling that references the same parent row (confirmed: $session1->for === $session2->for
    // for two sessions on the same Therapy), but this accessor itself isn't cached by Eloquent --
    // rendering N sessions for the same therapy via TherapyMiniResource re-ran this COUNT query N
    // times against the identical, already-known-same object. The counsellor calendar aggregate is
    // the first caller to render many sessions (and therefore many `for` accesses) in one response.
    protected ?int $sessionsHeldCache = null;

    public function getSessionsHeldAttribute()
    {
        return $this->sessionsHeldCache ??= $this->sessions()->whereHeld()->count();
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
        if ($this->status == TherapyStatusEnum::in_session->value) {
            return str_replace('_', ' ', TherapyStatusEnum::in_session->value);
        }

        return $this->status;
    }

    public function getActiveDiscussion(Counsellor $counsellor)
    {
        return $this->discussions()
            ->where(function ($query) use ($counsellor) {
                $query
                    ->whereIsParticipant($counsellor)
                    ->whereIsOngoing();
            })
            ->first();
    }

    public function getActiveSession(User $user)
    {
        return $this->sessions()
            ->where(function ($query) use ($user) {
                $query
                    ->whereIsParticipant($user)
                    ->whereIsNotUserWhoConfirmedHeld($user)
                    ->whereIsOngoing();
            })
            ->first();
    }

    public function addedby()
    {
        // withTrashed: see Therapy::counsellor() -- addedby (User or Counsellor) may have
        // since deleted their account.
        return $this->morphTo('addedby')->withTrashed();
    }

    public function topics()
    {
        return $this->morphMany(TherapyTopic::class, 'topicable');
    }

    public function messages()
    {
        return $this->morphMany(Message::class, 'for');
    }

    public function sessions()
    {
        return $this->morphMany(Session::class, 'for');
    }

    public function transactions()
    {
        return $this->morphMany(Transaction::class, 'for');
    }

    // Latest across ALL eligible payers for this model, not scoped to the current viewer -- a
    // GroupTherapy with multiple members each attempting to pay can surface one member's
    // pending/failed attempt to a different member. Explicit 'created_at' column: latestOfMany()
    // defaults to ordering by 'id', which coincides with insertion order today (every Transaction
    // row is created once via InitiatePaystackChargeAction and only ever updated afterwards) but
    // is not the same guarantee as this codebase's usual `->latest()` (created_at-based)
    // convention -- keep it explicit so a future insert path can't silently break "latest".
    public function latestTransaction()
    {
        return $this->morphOne(Transaction::class, 'for')->latestOfMany('created_at');
    }

    // TT-7.4d-a/SCRUM-258: the viewer-scoped counterpart to latestTransaction() above -- promoted
    // from SessionResource's own identical inline `viewerTransaction` closure (TT-7.7b/SCRUM-250,
    // TT-7.7e/SCRUM-253) so GroupTherapyResource/SessionResource/TT-7.4d-d's roster all share one
    // implementation instead of three near-identical copies. For an individual Therapy/Session
    // this degenerates to the same row latestTransaction() already returns (exactly one payer);
    // for a GroupTherapy it's the difference between "the group's own latest attempt by ANYONE"
    // and "MY OWN latest attempt" -- the two must never be conflated on a multi-payer model.
    public function latestTransactionFor(User $user)
    {
        return $this->transactions()->where('user_id', $user->id)->latest('created_at')->first();
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

    public function pendingRequestFor(?Counsellor $counsellor)
    {
        if (is_null($counsellor)) {
            return null;
        }

        return Request::query()
            ->wherePending()
            ->whereFor($this)
            ->whereTo($counsellor)
            ->latest()
            ->first();
    }

    // Unlike pendingRequestFor() above (assistance requests, always `to` a Counsellor), a session
    // schedule proposal's `to` alternates between the client User and the Counsellor across
    // counter-offer rounds (SCRUM-207/TT-2.5b) -- so this is scoped by `for`/`type` only, not `to`.
    public function pendingSessionScheduleProposal()
    {
        return $this->requests()
            ->wherePending()
            ->whereType(RequestTypeEnum::sessionScheduleProposal->value)
            ->latest()
            ->first();
    }

    // Extracted from four independent copies of this same ternary (TherapyResource,
    // GroupTherapyResource, TherapyMiniResource, GroupTherapyMiniResource) -- SCRUM-212: the
    // counsellor calendar aggregate is the first place needing this outside those four resources,
    // and a fifth inline copy wasn't warranted. Anonymity only ever applies to a User (client)
    // addedby, never a Counsellor one, and never masks the addedby's own view of their own record.
    public function addedByUserIsMaskedFor(?User $viewer): bool
    {
        if ($this->addedby_type !== User::class || ! $this->addedby) {
            return false;
        }

        return $this->isAnonymousFor($this->addedby) && ! $this->addedby->is($viewer);
    }

    public function scopeWhereAddedby($query, Model $model)
    {
        return $query->where(function ($query) use ($model) {
            $query
                ->where('addedby_type', $model::class)
                ->where('addedby_id', $model->id);
        });
    }

    public function scopeWherePublic($query)
    {
        return $query->where('public', true);
    }

    public function requests()
    {
        return $this->morphMany(Request::class, 'for');
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
                SessionStatusEnum::in_session_confirmation->value,
            ])
            ->update(['status' => SessionStatusEnum::abandoned->value]);
    }

    public function getTopicsCountAttribute()
    {
        return $this->topics()->count();
    }

    // SCRUM-217/TT-7.5a: defaults to false (trust-based) for any therapy predating this feature,
    // or whose payment_data is currently null (e.g. a FREE therapy).
    //
    // TT-7.5b-b2/SCRUM-266 (architect finding, mandatory): moved here from Therapy itself so
    // GroupTherapy (which also uses this trait) picks it up too -- reading
    // `$therapy->strictPaymentGate` off a GroupTherapy previously silently resolved to `null` via
    // Eloquent's "no such attribute/accessor" fallback, and `! null` is `true`, so
    // EnsureStrictPaymentGateSatisfiedAction's own guard (`! $therapy->strictPaymentGate`) would
    // have short-circuited to "gate satisfied, let them in" for EVERY GroupTherapy regardless of
    // its actual setting -- a silent bypass, not a crash, and the reason this had to move before
    // that action's own type-hint could be widened.
    public function getStrictPaymentGateAttribute()
    {
        return (bool) data_get($this->payment_data, 'strictPaymentGate', false);
    }

    // TT-4.10b/SCRUM-291: prefers the stable client_was_minor_at_creation snapshot (TT-4.10a) --
    // captured once, at creation, from the client's live isAdult() at that exact moment -- over
    // a live isAdult() re-check, which is exactly what SCRUM-287 found could be bypassed by a
    // later self-service dob edit. Falls back to a live check only for a row with no snapshot at
    // all (this column predates every existing row) or a genuinely not-applicable one (a
    // Counsellor-created GroupTherapy has no single "client" this applies to -- addedby isn't
    // even a User to check isAdult() on).
    public function clientIsMinor(): bool
    {
        if (! is_null($this->client_was_minor_at_creation)) {
            return $this->client_was_minor_at_creation;
        }

        return $this->addedby_type === User::class && $this->addedby && ! $this->addedby->isAdult();
    }
}
