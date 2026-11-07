<?php

namespace App\Models;

use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Enums\RequestTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Traits\Alertable;
use App\Traits\Commentable;
use App\Traits\Likeable;
use App\Traits\Starreable;
use App\Traits\TherapyTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

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
        'allow_in_person', 'client_was_minor_at_creation',
    ];

    // Memoized (SCRUM-212), same rationale as TherapyTrait::getSessionsHeldAttribute() -- a
    // shared instance (via morphTo eager loading) re-ran this COUNT query once per sibling Session
    // rendered through GroupTherapyMiniResource instead of once per distinct GroupTherapy.
    // SCRUM-386: a list's loadCount('counsellors') (PreloadGroupTherapyListAction) wins when present.
    protected ?int $counsellorsCountCache = null;

    public function getCounsellorsCountAttribute()
    {
        if (isset($this->attributes['counsellors_count'])) {
            return (int) $this->attributes['counsellors_count'];
        }

        return $this->counsellorsCountCache ??= $this->counsellors()->count();
    }

    protected $casts = [
        'payment_data' => 'array',
        'client_was_minor_at_creation' => 'boolean',
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

    // TT-7.5b-b1/SCRUM-265 persisted this; TT-7.5b-b3/SCRUM-267 is the first reader. Defaults to
    // true (matches CreateGroupTherapyAction/UpdateGroupTherapyAction's own default) for any group
    // predating this feature, or whose payment_data is currently null (e.g. a FREE group).
    // Deliberately not on TherapyTrait -- this setting only exists for GroupTherapy.
    public function getAllowFreeHistoricalAccessAttribute()
    {
        return (bool) data_get($this->payment_data, 'allowFreeHistoricalAccess', true);
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

    // SCRUM-386 (review): the group's ACTIVE counsellors, id and user id only -- what a list preloads
    // (PreloadGroupTherapyListAction) for isCounsellor(), so a cut-down copy never stands in for the
    // full `counsellors` relation above.
    public function activeCounsellorLinks()
    {
        return $this->belongsToMany(Counsellor::class, 'counsellor_group_therapy', 'group_therapy_id', 'counsellor_id')
            ->wherePivot('state', CounsellorGroupTherapyStateEnum::active->value)
            // SCRUM-114: and their verification, so a list can tell whether the group admits members.
            ->select(['counsellors.id', 'counsellors.user_id', 'counsellors.verified_at', 'counsellors.verification_expires_at', 'counsellors.verification_revoked_at']);
    }

    // The group's current members. SCRUM-73: a member who left keeps their row, stamped with
    // left_at, so this relation filters them out. Don't write through it to a row that may have left
    // (updateExistingPivot()/detach() carry the same filter and would silently match nothing) -- use
    // allMemberships().
    public function users()
    {
        return $this->membershipRelation('users')->wherePivotNull('left_at');
    }

    // SCRUM-73: everyone who has ever been a member, former members included -- for history (report
    // involvement, the dob-change rule) and for writing to a membership row (AddGroupTherapyMemberAction).
    public function allMemberships()
    {
        return $this->membershipRelation('allMemberships');
    }

    // Named explicitly: belongsToMany() otherwise guesses the relation name from its caller.
    private function membershipRelation(string $name)
    {
        return $this->belongsToMany(User::class, 'group_therapy_user', 'group_therapy_id', 'user_id', relation: $name)
            ->withPivot(['id', 'background_story', 'anonymous', 'was_minor_at_join', 'left_at'])
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

        // SCRUM-386: a list preloads its active counsellors (PreloadGroupTherapyListAction), like
        // membershipOf() reads a loaded `users`. Only that relation: whatever `counsellors` a caller
        // loaded may be filtered or cut down in ways this can't know.
        if ($this->relationLoaded('activeCounsellorLinks')) {
            return $this->activeCounsellorLinks->contains(fn (Counsellor $loaded) => $loaded->getKey() == $counsellor->getKey());
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

    // SCRUM-389: the person who created it -- the User, or a counsellor creator's User. They answer
    // a counsellor's offer to help and its membership requests.
    public function creatorUser(): ?User
    {
        return $this->addedby instanceof Counsellor ? $this->addedby->user : ($this->addedby instanceof User ? $this->addedby : null);
    }

    // A counsellor creator, or null for a group a user created.
    public function creatorCounsellor(): ?Counsellor
    {
        return $this->addedby instanceof Counsellor ? $this->addedby : null;
    }

    // SCRUM-114/389: the rules the cards show and the actions enforce, in one place. Each takes a
    // count already loaded for a list (PreloadGroupTherapyListAction), else counts now.
    public function isFull(?int $members = null): bool
    {
        return ($members ?? $this->users()->count()) >= $this->max_users;
    }

    // Takes another counsellor: not ended, and below its counsellor places (active counsellors and
    // a counsellor creator, as AddCounsellorToGroupTherapyAction counts them).
    public function canTakeCounsellor(?int $activeCounsellors = null): bool
    {
        return $this->status !== TherapyStatusEnum::ended->value
            && ($activeCounsellors ?? $this->activeCounsellors()->count()) < $this->max_counsellors;
    }

    // SCRUM-391: canTakeCounsellor() as a query -- not ended, and its active counsellors (not
    // soft-deleted) plus a live counsellor creator, counted once, below max_counsellors. Counted as
    // activeCounsellors() counts them; keep the two together.
    public function scopeWhereCanTakeCounsellor($query)
    {
        $counsellors = (new Counsellor)->getTable();

        return $query
            ->where($this->qualifyColumn('status'), '!=', TherapyStatusEnum::ended->value)
            ->whereRaw(
                "(select count(*) from counsellor_group_therapy cgt join {$counsellors} c on c.id = cgt.counsellor_id"
                .' where cgt.group_therapy_id = group_therapies.id and cgt.state = ? and c.deleted_at is null'
                .' and not (group_therapies.addedby_type = ? and group_therapies.addedby_id = c.id))'
                ." + (case when group_therapies.addedby_type = ? and exists (select 1 from {$counsellors} cc"
                .' where cc.id = group_therapies.addedby_id and cc.deleted_at is null) then 1 else 0 end)'
                .' < group_therapies.max_counsellors',
                [CounsellorGroupTherapyStateEnum::active->value, Counsellor::class, Counsellor::class]
            );
    }

    // SCRUM-31: admits new members unless it has counsellors and none of them is currently verified
    // (one still waiting for its first counsellor does). $counsellors: the active ones, if loaded.
    public function admitsNewMembers(?Collection $counsellors = null): bool
    {
        $counsellors ??= $this->activeCounsellors();

        return $counsellors->isEmpty() || $counsellors->contains(fn (Counsellor $counsellor) => $counsellor->isVerified());
    }

    // SCRUM-382/384: whether the group's creator is a minor paused for a missing guardian -- then
    // nobody is let in through its links. A group created through a counsellor profile is the
    // counsellor's, who is never the minor it would be paused for.
    public function creatorIsPausedForMissingGuardian(bool $fresh = false): bool
    {
        return $this->addedby instanceof User && $this->addedby->isPausedForMissingGuardian(fresh: $fresh);
    }

    // SCRUM-73: someone who was a member and left (their row is kept, stamped left_at) -- not a
    // current member.
    public function isFormerMember(User $user): bool
    {
        return $this->allMemberships()->whereKey($user->id)->wherePivotNotNull('left_at')->exists();
    }

    // SCRUM-73: a current or former member -- for history (who may report the group, who an
    // administrator may name as the reported person).
    public function hasEverBeenMember(User $user): bool
    {
        return $this->allMemberships()->whereKey($user->id)->exists();
    }

    // TT-3.2b/SCRUM-309 review finding: the "is $user an active counsellor of THIS group" check
    // (isCounsellor() above, but starting from a User rather than a Counsellor) had drifted into
    // three separate inline copies (here in isParticipant(), and once each in EndVideoSessionAction
    // and RemoveParticipantFromVideoSessionAction) -- consolidated into one place.
    public function isCounsellorUser(User $user): bool
    {
        return (bool) ($user->counsellor && $this->isCounsellor($user->counsellor));
    }

    // SCRUM-263: the group-wide payment status ("some member's latest transaction") is only for
    // the group's own counsellors and administrators (Therapy::showsPaymentStatusTo is the
    // individual-therapy counterpart, SCRUM-368). A member sees only their own status
    // (viewerPaymentStatus): next to it, the group-wide one would tell them whether a specific
    // co-member has paid, failed or been refunded.
    // Remembered per viewer on this instance: a session list shares one eager-loaded group.
    private array $showsPaymentStatusCache = [];

    public function showsPaymentStatusTo(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $this->showsPaymentStatusCache[$user->id] ??= $this->isCounsellorUser($user) || $user->isAdmin();
    }

    // TT-3.2f-f/SCRUM-323: an ordinary member (not an active counsellor, not the creator) only
    // ever joins this group's video receive-only, and is the only kind of participant hand-raise
    // and grant/revoke apply to -- moved here from JoinVideoSessionAction's own private
    // isReceiveOnly() so the join path and the moderation actions can't drift apart. NOT an
    // authorization check on its own -- true for any non-member too; every caller must already have
    // established membership (in practice: the user has joined the call, which checked it).
    public function isReceiveOnlyVideoParticipant(User $user): bool
    {
        return ! $this->isCounsellorUser($user) && ! $this->isUser($user);
    }

    // TT-3.2f-b/SCRUM-319: a distinct question from clientIsMinor() (which answers "is the
    // group's own creator a minor") -- this is "is this ORDINARY member a minor," using the
    // was_minor_at_join snapshot recorded on their own group_therapy_user pivot row at join time
    // (JoinGroupTherapyAction/RespondToGroupTherapyMembershipRequestAction). Null-fallback to a
    // live isAdult() re-check for any pre-existing membership row that predates this column,
    // mirroring TherapyTrait::clientIsMinor()'s identical fallback pattern -- never assumed false
    // for an unknown past state.
    public function memberIsMinor(User $user): bool
    {
        $membership = $this->users()->where('users.id', $user->id)->first();

        if ($membership && ! is_null($membership->pivot->was_minor_at_join)) {
            return (bool) $membership->pivot->was_minor_at_join;
        }

        return ! $user->isAdult();
    }

    public function isParticipant(User $user)
    {
        if ($this->isUser($user)) {
            return true;
        }

        if ($this->isCounsellorUser($user)) {
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
    // existing counsellor-assistance flow. SCRUM-388: a request addressed to the viewer's counsellor
    // profile (a counsellor-created group's creator) is theirs to decide too. New requests are
    // addressed to a User (JoinGroupTherapyAction), so this catches older rows; the OR stays inside
    // the group so it can't escape the pending/type filters.
    public function pendingMembershipRequestFor(User $user)
    {
        return $this->requests()
            ->wherePending()
            ->whereType(RequestTypeEnum::groupTherapyMembership->value)
            ->where(function ($query) use ($user) {
                $query->whereFrom($user)->orWhereTo($user)
                    ->when($user->counsellor, fn ($query, Counsellor $counsellor) => $query->orWhereTo($counsellor));
            })
            ->latest()
            ->first();
    }

    public function isNotParticipant(User $user)
    {
        return ! $this->isParticipant($user);
    }

    // SCRUM-379: the one masking rule for a group -- whether $subject is shown as Anonymous to
    // everyone else (TherapyTrait::displayNameOf(), addedByUserIsMaskedFor(), the presence channel,
    // and both video names). In order:
    // - the creator: masked by the group flag (or their own membership row's flag, if they have one);
    // - a counsellor of the group: never masked;
    // - a member: masked by the group flag OR their own membership row's flag;
    // - anyone else (no longer a member -- SCRUM-334's security review): always masked, so their
    //   real name can't surface just because the row that held their anonymity is gone.
    // Reads only $subject's own membership row, never the whole `users` relation (unless it's
    // already loaded) -- a call UI looks up every participant's label (SCRUM-379).
    public function isMaskedToOthers(User $subject): bool
    {
        $isCreator = $this->isUser($subject);

        if (! $isCreator && $this->isCounsellorUser($subject)) {
            return false;
        }

        if ($this->anonymous) {
            return true;
        }

        $membership = $this->membershipOf($subject);

        return $membership ? (bool) $membership->anonymous : ! $isCreator;
    }

    // SCRUM-332: an anonymous participant (anyone isMaskedToOthers() hides) never gets a camera --
    // the creator of an anonymous group joins audio-only, matching the audio-only rule SCRUM-324
    // applies to anonymous members' speaking grants (GrantSpeakingPermissionAction). Ordinary members
    // join receive-only anyway. Decided per join: turning anonymity OFF mid-call leaves the creator
    // audio-only until they rejoin -- the safe direction.
    public function allowsCameraFor(User $user): bool
    {
        return ! $this->isMaskedToOthers($user);
    }

    // $user's own group_therapy_user row (null if they have none): from the `users` relation when a
    // caller has eager-loaded it for a list, otherwise one single-row query.
    public function membershipOf(User $user): ?Pivot
    {
        $member = $this->relationLoaded('users')
            ? $this->users->firstWhere('id', $user->id)
            : $this->users()->whereKey($user->id)->first();

        return $member?->pivot;
    }

    // Mirrors Therapy::scopeWhereIsParticipant() -- without this, Session::scopeWhereIsParticipant()'s
    // whereHasMorph('for', '*', ...) falls back to a bare `where('is_participant', ...)` column
    // lookup for the GroupTherapy branch (since there's no matching local scope), which throws a
    // SQL error for *every* Session lookup as soon as any GroupTherapy-type session exists at all.
    // SCRUM-144: one definition of "participant" -- this delegates to scopeWhereParticipant().
    public function scopeWhereIsParticipant($query, User $user)
    {
        return $query->whereParticipant($user);
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

        // TT-4.10b/SCRUM-291: was `! $this->addedby->isAdult()` -- prefers the stable
        // client_was_minor_at_creation snapshot (TT-4.10a) over a live re-check, closing the
        // self-editable-dob bypass SCRUM-287 found. clientIsMinor() already returns false when
        // addedby isn't a User (a Counsellor-created group), so the addedby_type/addedby-truthy
        // checks above are still needed for the OTHER pushes in this method but no longer for
        // this condition.
        if (
            $this->clientIsMinor() &&
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

        // TT-4.10b/SCRUM-291: was `! $this->addedby->isAdult()` -- see getUsers()'s identical
        // comment above.
        if (
            $this->clientIsMinor() &&
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

    // SCRUM-114: the same set from a list's preloaded links (PreloadGroupTherapyListAction -- id,
    // user and verification columns only), so a card counts and checks them without a query. Falls
    // back to activeCounsellors() when the links aren't loaded.
    public function activeCounsellorsForList(): Collection
    {
        if (! $this->relationLoaded('activeCounsellorLinks')) {
            return $this->activeCounsellors();
        }

        $counsellors = collect($this->activeCounsellorLinks->all());
        $creator = $this->creatorCounsellor();
        if ($creator && ! $creator->trashed()) {
            $counsellors->push($creator);
        }

        return $counsellors->unique('id')->values();
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
        return $query->where(function ($query) use ($counsellor) {
            $query
                ->where(function ($query) use ($counsellor) {
                    $query
                        ->where('addedby_id', $counsellor->id)
                        ->where('addedby_type', Counsellor::class);
                })
                ->orWhereHas('counsellors', function ($query) use ($counsellor) {
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

    // SCRUM-144: the single definition of "is this user a participant": they created the group
    // (as themselves or as their counsellor profile), or are on its member or counsellor list.
    // Grouped in one where() so it can't widen a caller's other conditions.
    public function scopeWhereParticipant($query, User $user)
    {
        return $query->where(function ($query) use ($user) {
            $query
                ->whereUser($user)
                ->when($user->counsellor, function ($query) use ($user) {
                    $query->orWhere(function ($query) use ($user) {
                        $query->whereCounsellor($user->counsellor);
                    });
                });
        });
    }
}
