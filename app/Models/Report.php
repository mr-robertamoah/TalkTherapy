<?php

namespace App\Models;

use App\Enums\ReportOutcomeEnum;
use App\Enums\ReportStatusEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Report extends Model
{
    use HasFactory;

    // SCRUM-340: status/outcome/resolved_* are deliberately not fillable -- only
    // TransitionReportStatusAction changes them.
    protected $fillable = ['description', 'data'];

    protected $casts = [
        'data' => 'array',
        'status' => ReportStatusEnum::class,
        'outcome' => ReportOutcomeEnum::class,
        'resolved_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'open',
    ];

    public function histories()
    {
        return $this->hasMany(ReportStatusHistory::class)->latest('id');
    }

    public function resolvedBy()
    {
        return $this->belongsTo(User::class, 'resolved_by')->withTrashed();
    }

    public function suspensions()
    {
        return $this->hasMany(Suspension::class)->latest('id');
    }

    public function confidentialAccesses()
    {
        return $this->hasMany(ConfidentialMessageAccess::class)->latest('id');
    }

    // Restricted content was actually shown on this report -- not merely a session opened.
    public function hasConfidentialReads(): bool
    {
        return $this->confidentialAccesses()->whereNotNull('message_id')->exists();
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    // SCRUM-340: the therapy a report is about -- a session's therapy, or the therapy itself.
    // Null for a post or anything else.
    public function therapy(): Therapy|GroupTherapy|null
    {
        $reportable = $this->reportable instanceof Session ? $this->reportable->for : $this->reportable;

        return $reportable instanceof Therapy || $reportable instanceof GroupTherapy ? $reportable : null;
    }

    private bool $reportedPartyResolved = false;

    private User|Counsellor|null $reportedPartyCache = null;

    // SCRUM-340: THE one answer to "who is this report about" -- the admin page, the unlock scope,
    // involves() and the close notifications all use it (review finding: they used to disagree).
    // Whoever data.userId / data.counsellorId names, minus the reporter themselves (reports made
    // before SCRUM-347 had to name both parties, the reporter included). Exactly one person, or
    // null: nobody named, or still ambiguous -- an admin then records who it is.
    public function reportedParty(): User|Counsellor|null
    {
        if ($this->reportedPartyResolved) {
            return $this->reportedPartyCache;
        }

        $data = is_array($this->data) ? $this->data : [];
        $named = array_filter([
            ($data['userId'] ?? null) ? User::withTrashed()->find($data['userId']) : null,
            ($data['counsellorId'] ?? null) ? Counsellor::withTrashed()->with('user')->find($data['counsellorId']) : null,
        ]);
        $named = array_values(array_filter(
            $named,
            fn (User|Counsellor $person) => ! $this->isReportedBy($person instanceof Counsellor ? $person->user : $person)
        ));

        $this->reportedPartyResolved = true;

        return $this->reportedPartyCache = count($named) == 1 ? $named[0] : null;
    }

    // The User behind the reported party (a counsellor's own account).
    public function reportedUser(): ?User
    {
        $party = $this->reportedParty();

        return $party instanceof Counsellor ? $party->user : $party;
    }

    // SCRUM-340: an admin who is the reporter, the reported person, the author of a reported post,
    // or a participant (past counsellors included) of the reported therapy can't review the report
    // or see it beyond its existence. SCRUM-362/363: nor a guardian of the reporter, of the
    // reported person, of the reported therapy's client, or of any member of a reported group. Former group members are covered by
    // isParticipant(): nothing removes a group_therapy_user row -- a future "leave group" must
    // change the row's state, not delete it, or this check (and the report history) loses them.
    public function involves(User $user): bool
    {
        if ($this->isReportedBy($user) || $this->reportedUser()?->is($user)) {
            return true;
        }

        if ($this->isGuardianOfSomeoneIn($user)) {
            return true;
        }

        $therapy = $this->therapy();
        if ($therapy) {
            return (bool) $therapy->isParticipant($user)
                || ($therapy instanceof GroupTherapy && $user->counsellor && $therapy->counsellors()->whereKey($user->counsellor->id)->exists());
        }

        $author = $this->reportable?->addedby;

        return (bool) ($author instanceof Counsellor ? $author->user?->is($user) : $author?->is($user));
    }

    private function isGuardianOfSomeoneIn(User $user): bool
    {
        $wardIds = $user->wardIds();

        if ($wardIds->isEmpty()) {
            return false;
        }

        $therapy = $this->therapy();
        $client = $therapy?->addedby;

        $named = collect([$this->reporterUser(), $this->reportedUser(), $client instanceof User ? $client : null])
            ->filter()
            ->contains(fn (User $person) => $wardIds->contains($person->id));

        // SCRUM-363: in a group, a guardian of any member -- the cautious choice: it only ever
        // keeps more admins away from a case, never fewer.
        return $named || ($therapy instanceof GroupTherapy && $therapy->users()->whereIn('users.id', $wardIds)->exists());
    }

    // SCRUM-340/362: all an admin who is part of a report sees of it in any list -- that it exists.
    public function involvedSummary(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'involved' => true,
            'createdAt' => $this->created_at,
        ];
    }

    // The User behind the reporter (a reporting counsellor's own account).
    public function reporterUser(): ?User
    {
        return $this->addedby instanceof Counsellor ? $this->addedby->user : $this->addedby;
    }

    public function addedby()
    {
        // withTrashed: see Therapy::counsellor() -- the reporter (User or Counsellor) may have
        // since deleted their account.
        return $this->morphTo()->withTrashed();
    }

    // SCRUM-337: the reporter -- a User, or the User behind a reporting Counsellor.
    public function isReportedBy(?User $user): bool
    {
        if (! $user || ! $this->addedby) {
            return false;
        }

        return $this->addedby instanceof Counsellor
            ? (bool) $this->addedby->user?->is($user)
            : $this->addedby->is($user);
    }

    // SCRUM-337: reports (and their evidence) are for admins and the reporter only. SCRUM-340: not
    // for an admin who is part of the report -- that would show the reported person who reported them.
    public function isViewableBy(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $this->isReportedBy($user) || ($user->isAdmin() && ! $this->involves($user));
    }

    // Reports a non-admin may list: their own, as a User or as their Counsellor.
    public function scopeWhereReportedBy($query, User $user)
    {
        return $query->where(function ($query) use ($user) {
            $query->where(fn ($q) => $q->where('addedby_type', User::class)->where('addedby_id', $user->id));

            if ($user->counsellor) {
                $query->orWhere(fn ($q) => $q->where('addedby_type', Counsellor::class)->where('addedby_id', $user->counsellor->id));
            }
        });
    }

    public function reportable()
    {
        return $this->morphTo();
    }

    public function scopeWhereAddedby($query, Counsellor|User $addedby)
    {
        return $query
            ->where('addedby_type', $addedby::class)
            ->where('addedby_id', $addedby->id);
    }

    public function scopeWhereReportable($query, Model $reportable)
    {
        return $query
            ->where('reportable_type', $reportable::class)
            ->where('reportable_id', $reportable->id);
    }

    public function files(): MorphToMany
    {
        return $this
            ->morphToMany(File::class, 'fileable', 'fileables')
            ->withTimestamps();
    }
}
