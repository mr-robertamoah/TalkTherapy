<?php

namespace App\Traits;

use App\Enums\ConstantsEnum;
use App\Enums\RequestTypeEnum;
use App\Enums\SessionStatusEnum;
use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\GroupTherapy;
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
use Illuminate\Support\Collection;

trait TherapyTrait
{
    // SCRUM-369: the session that is on now (or starts within five minutes) -- Session::whereActive,
    // the same rule getActiveSession() uses for the page. Through the sessions() relation, so a group
    // gets its own sessions: this used to filter on for_type = Therapy, so a group looked up
    // whichever individual therapy shared its id.
    public function getActiveSessionAttribute()
    {
        return $this->sessions()->whereActive()->first();
    }

    // SCRUM-369: the same for case discussions -- read by the scheduler's discussion.started
    // broadcast, which used to ask for this attribute when it didn't exist (always null).
    public function getActiveDiscussionAttribute()
    {
        return $this->discussions()->whereActive()->first();
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
        // SCRUM-386: a list loads this as a count (PreloadGroupTherapyListAction).
        if (isset($this->attributes['sessions_held_count'])) {
            return (int) $this->attributes['sessions_held_count'];
        }

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

    // SCRUM-392: resources send the raw status (e.g. IN_SESSION) for logic, and this for display.
    public function getStatusLabel(): string
    {
        return strtolower(str_replace('_', ' ', (string) $this->status));
    }

    public function getActiveDiscussion(Counsellor $counsellor)
    {
        return $this->discussions()
            ->where(function ($query) use ($counsellor) {
                $query
                    ->whereIsParticipant($counsellor)
                    ->whereActive();
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
                    ->whereActive();
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

    // SCRUM-114: the viewer's card state on a list (ResolveTherapyCardStateAction) -- never saved.
    public ?array $viewerCard = null;

    // SCRUM-180: who pays for it, for its client only (ResolveTherapySponsorAction).
    public ?array $viewerSponsor = null;

    public function pendingRequestFor(?Counsellor $counsellor)
    {
        if (is_null($counsellor)) {
            return null;
        }

        // SCRUM-389: or their own offer to help (an assistance request FROM them), so the page can
        // say "offer sent" -- the OR grouped, so it can't escape the pending/for filters.
        return Request::query()
            ->wherePending()
            ->whereFor($this)
            ->where(fn ($query) => $query
                ->whereTo($counsellor)
                ->orWhere(fn ($query) => $query
                    ->whereFrom($counsellor)
                    ->whereIn('type', Request::ASSISTANCE_TYPES)))
            ->latest()
            ->first();
    }

    // Unlike pendingRequestFor() above (assistance requests to or from the Counsellor), a session
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

    // SCRUM-351/354: only public therapies and groups can be liked or commented on -- and their
    // comments read. A private therapy's likes and comments don't exist as far as anyone can tell.
    public function acceptsPublicEngagement(): bool
    {
        return (bool) $this->public;
    }

    // SCRUM-354: whether $user is one of this therapy's own anonymous people (its anonymous client,
    // or an anonymous group member/creator) -- their comments show the anonymous label. Anyone else
    // commenting on a public therapy is never masked, even when the therapy itself is anonymous:
    // that includes someone who has left the group (hidesIdentityOf() alone would mask them, as a
    // former member, so the "one of its own people" check stays explicit). A comment written while
    // they were a member keeps its recorded `anonymous` snapshot (CreateCommentAction).
    // SCRUM-380: the masking half is hidesIdentityOf(), which agrees with the old rule for every
    // client, creator and member.
    public function isAnonymousParticipant(User $user): bool
    {
        $isClientOrMember = $this instanceof GroupTherapy
            ? $this->isUser($user) || $this->users->contains('id', $user->id)
            : (bool) $this->addedby?->is($user);

        return $isClientOrMember && $this->hidesIdentityOf($user);
    }

    // SCRUM-380: whether $subject's identity (their real user id in chat and on sessions, their name
    // in the assistance-accepted notification) is withheld from everyone else. isMaskedToOthers()
    // is the rule, and on top of it -- so that nothing the old isAnonymousFor() masked is shown now
    // -- everyone in a therapy whose own flag is anonymous, and anyone whose own membership row is
    // anonymous. Together that's exactly the old rule plus isMaskedToOthers()'s extra masking:
    // - a person who left a group, or anyone else with no row (an administrator), in a
    //   non-anonymous group is now masked (the old rule showed them);
    // - a counsellor acting through their own User account stays masked where they were (an
    //   anonymous therapy), though isMaskedToOthers() alone would show them.
    // Viewer-relative exemptions (the subject seeing themselves) are the caller's.
    public function hidesIdentityOf(User $subject): bool
    {
        if ($this->anonymous) {
            return true;
        }

        if (! $this instanceof GroupTherapy) {
            return $this->isMaskedToOthers($subject);
        }

        // A page of messages, sessions or comments shares one group instance: its members are
        // loaded once (as the old rule did), not one membership query per row.
        $this->loadMissing('users');

        return $this->isMaskedToOthers($subject) || (bool) $this->membershipOf($subject)?->anonymous;
    }

    // SCRUM-382: the one rule for whether this therapy is paused for a missing guardian as $viewer
    // sees it -- used by the write gate (EnsureMinorHasActiveGuardianAction) and by the page state
    // (the resources' viewerAccessPaused / clientAccessPaused), so the two can't disagree.
    //  - viewer: $viewer is a minor with no active guardian taking part as a client or member.
    //    Someone acting as this therapy's own counsellor is never the minor it is paused for, so
    //    their own age isn't asked.
    //  - client: only for someone acting as an individual therapy's counsellor (null for everyone
    //    else, and when $counterparty is false): whether its client is paused, which makes that
    //    counsellor read-only too (SCRUM-288 Q5).
    // $actsAsCounsellor: when the caller already knows (otherwise worked out here, and only when it
    // matters -- an adult who isn't paused costs no query). $fresh re-reads (inside a lock). No
    // viewer (a guest) is never paused.
    /** @return array{viewer: bool, client: ?bool} */
    public function pauseStateFor(?User $viewer, bool $fresh = false, ?bool $actsAsCounsellor = null, bool $counterparty = true): array
    {
        if (! $viewer) {
            return ['viewer' => false, 'client' => null];
        }

        $actsAs = function () use ($viewer, &$actsAsCounsellor): bool {
            return $actsAsCounsellor ??= (bool) ($viewer->counsellor && $this->isCounsellor($viewer->counsellor));
        };

        $client = null;
        if ($counterparty && ! $this instanceof GroupTherapy && $actsAs()) {
            $client = $this->addedby_type === User::class && $this->addedby && $this->addedby->isPausedForMissingGuardian($fresh);
        }

        return [
            'viewer' => $viewer->isPausedForMissingGuardian($fresh) && ! $actsAs(),
            'client' => $client,
        ];
    }

    // SCRUM-379: how $subject is labelled to everyone ELSE -- the presence channels
    // (routes/channels.php), the name minted into a video token (JoinVideoSessionAction) and the
    // name the call UI shows (GetVideoParticipantDisplayNameAction). The rule itself is
    // isMaskedToOthers(), defined on each model: it used to be copied in all three places.
    // SCRUM-379 (architect review): each model defines these; declared here so the polymorphic calls
    // (JoinVideoSessionAction, routes/channels.php, the lookup action) rely on a contract, not luck.
    abstract public function isMaskedToOthers(User $subject): bool;

    abstract public function allowsCameraFor(User $user): bool;

    // SCRUM-340/362/363/73, shared with SCRUM-32: someone too close to this therapy or group to oversee
    // anything about it -- a participant; for a group also a former member (allMemberships()) or one of
    // its counsellors; or a guardian of its client or of any member, former ones too (the cautious
    // choice: it only ever keeps more administrators away, never fewer). $wardIds: theirs, when the
    // caller has them already.
    public function involvesInOversight(User $user, ?Collection $wardIds = null): bool
    {
        if ($this->isParticipant($user)) {
            return true;
        }

        $isGroup = $this instanceof GroupTherapy;
        if ($isGroup && ($this->allMemberships()->whereKey($user->id)->exists()
            || ($user->counsellor && $this->counsellors()->whereKey($user->counsellor->id)->exists()))) {
            return true;
        }

        $wardIds ??= $user->wardIds();
        if ($wardIds->isEmpty()) {
            return false;
        }

        $client = $this->addedby;
        if ($client instanceof User && $wardIds->contains($client->id)) {
            return true;
        }

        return $isGroup && $this->allMemberships()->whereIn('users.id', $wardIds)->exists();
    }

    // The subject's name as everyone ELSE sees it (not viewer-relative, hence "Of").
    public function displayNameOf(User $subject): string
    {
        return $this->isMaskedToOthers($subject) ? ConstantsEnum::anonymousUserLabel->value : $subject->name;
    }

    // Extracted from four independent copies of this same ternary (TherapyResource,
    // GroupTherapyResource, TherapyMiniResource, GroupTherapyMiniResource) -- SCRUM-212: the
    // counsellor calendar aggregate is the first place needing this outside those four resources,
    // and a fifth inline copy wasn't warranted. Anonymity only ever applies to a User (client)
    // addedby, never a Counsellor one, and never masks the addedby's own view of their own record.
    // SCRUM-379: the creator's side of isMaskedToOthers(), the same rule every other label uses.
    public function addedByUserIsMaskedFor(?User $viewer): bool
    {
        if ($this->addedby_type !== User::class || ! $this->addedby) {
            return false;
        }

        return ! $this->addedby->is($viewer) && $this->isMaskedToOthers($this->addedby);
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
