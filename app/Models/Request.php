<?php

namespace App\Models;

use App\Actions\Admin\GetAdminToDoCountsAction;
use App\Enums\FileTagEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Support\AdminRequestQueue;
use App\Traits\HasTaggedFiles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Request extends Model
{
    use HasFactory, HasTaggedFiles;

    protected $fillable = ['data', 'type', 'status', 'expires_at', 'round', 'reminder_sent_at'];

    protected $casts = [
        'data' => 'array',
        'expires_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    // SCRUM-387: who is answering which request right now (answeredBy()), for the saving hook.
    private static ?array $answering = null;

    // SCRUM-362: stamp the moment a request first leaves PENDING (accepted, rejected, superseded or
    // made moot) -- once, so a later change to the row never moves it. Query-builder updates skip
    // this hook, so those few call sites set responded_at themselves.
    // SCRUM-387: when that change is someone answering it (answeredBy()), who answered, in the same
    // write -- so only the save that actually decides it is stamped, never a no-op re-answer.
    protected static function booted(): void
    {
        static::saving(function (Request $request) {
            // A new row without a status is PENDING (the column's default).
            if ($request->status && $request->status !== RequestStatusEnum::pending->value && is_null($request->responded_at)) {
                $request->responded_at = now();

                if (self::$answering && (int) $request->getKey() === self::$answering['request'] && is_null($request->responded_by_id)) {
                    $request->responded_by_id = self::$answering['user'];
                }
            }
        });

        // SCRUM-387: the admin area's cached to-do counts include every admin queue request, so a
        // new one or a change of status starts them again -- after commit, so a read in between
        // can't cache the old figures under the new generation, and best effort, so a cache
        // failure never undoes the change. Query-builder updates skip this, as above.
        static::saved(function (Request $request) {
            if (! AdminRequestQueue::includes($request->type) || ! ($request->wasRecentlyCreated || $request->wasChanged('status'))) {
                return;
            }

            self::adminQueueChanged(['request' => $request->id]);
        });
    }

    // SCRUM-387/388: the admin area's cached to-do counts start again once the current transaction
    // commits -- best effort, so a cache failure never undoes the change. The `saved` hook above
    // calls it; so must any write to admin queue requests that skips that hook (a query-builder
    // update, saveQuietly()): DeleteCounsellorAction, AnonymiseClosedAccountAction. The other bulk
    // "made moot" updates only ever touch therapy, group and membership requests, never queue types.
    public static function adminQueueChanged(array $context = []): void
    {
        GetAdminToDoCountsAction::forgetAfterCommit($context);
    }

    // SCRUM-387: runs $answer with $user recorded as the one answering $request (booted()).
    public static function answeredBy(User $user, self $request, callable $answer): mixed
    {
        $previous = self::$answering;
        self::$answering = ['user' => $user->id, 'request' => (int) $request->getKey()];

        try {
            return $answer();
        } finally {
            self::$answering = $previous;
        }
    }

    // SCRUM-387: who answered it (accepted or rejected it), when someone did. Null for a request
    // closed by the system (superseded, made moot) or answered before this was recorded.
    public function respondedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by_id');
    }

    public function from()
    {
        return $this->morphTo();
    }

    // SCRUM-389: an assistance request (a therapy's or a group's) is either an invitation to a
    // counsellor (`to` is the Counsellor) or a counsellor's own offer to help (from the Counsellor,
    // to the client or the group's creator -- a User). Named here, so nothing works it out again.
    public function isAssistanceOffer(): bool
    {
        return in_array($this->type, [RequestTypeEnum::therapy->value, RequestTypeEnum::groupTherapy->value], true)
            && $this->from_type === Counsellor::class
            && $this->to_type !== Counsellor::class;
    }

    // The counsellor an assistance request would add: the one invited, or the one offering.
    public function assistanceCounsellor(): ?Counsellor
    {
        if (! in_array($this->type, [RequestTypeEnum::therapy->value, RequestTypeEnum::groupTherapy->value], true)) {
            return null;
        }

        $counsellor = $this->isAssistanceOffer() ? $this->from : $this->to;

        return $counsellor instanceof Counsellor ? $counsellor : null;
    }

    // TT-4.11a/SCRUM-302: mirrors License::files() exactly -- introduced for identity-verification
    // document attachments (age/identity verification, SCRUM-289), always uploaded to the private
    // 'identity_documents' disk (never the public default), retrieved only through the dedicated
    // authorized route/action, never File::url/getUrlFor(). Generic on the Request model itself
    // (not type-specific) since nothing about the relation depends on `type`.
    public function files(): MorphToMany
    {
        return $this
            ->morphToMany(File::class, 'fileable', 'fileables')
            ->withTimestamps();
    }

    // TT-4.11b/SCRUM-303: tag-scoped, mirrors User::avatarFile()'s own withPivotValue() shape --
    // lets a re-submission simply ->sync() a new document in place of an old one (at most one
    // per request, per the fileables table's own unique(fileable_type, fileable_id, tag) index)
    // rather than managing the tag manually against the generic files() relation above.
    public function identityDocument(): MorphToMany
    {
        return $this->taggedFile(FileTagEnum::identityDocument);
    }

    public function to()
    {
        return $this->morphTo();
    }

    public function for()
    {
        return $this->morphTo();
    }

    public function scopeWhereFor($query, $for)
    {
        return $query->where(function ($q) use ($for) {

            $q->where('for_id', $for->id);
            $q->where('for_type', $for::class);
        });
    }

    public function scopeWhereTo($query, $to)
    {
        return $query->where(function ($q) use ($to) {

            $q->where('to_id', $to->id);
            $q->where('to_type', $to::class);
        });
    }

    public function scopeWherePending($query)
    {
        return $query->where('status', RequestStatusEnum::pending->value);
    }

    // SCRUM-381: counsellors' requests for a safeguarding exception -- for one minor ($ward, stored
    // as data.wardId) and/or in one Therapy or GroupTherapy ($for), when given.
    public function scopeSafeguardingException($query, User|int|null $ward = null, $for = null)
    {
        return $query
            ->where('type', RequestTypeEnum::safeguardingException->value)
            ->when(! is_null($ward), fn ($query) => $query->where('data->wardId', $ward instanceof User ? $ward->id : $ward))
            ->when($for, fn ($query) => $query->whereFor($for));
    }

    // SCRUM-384: the id of the minor a safeguarding exception request is about (data.wardId), or
    // null (any other type, or no id stored).
    public function safeguardingWardId(): ?int
    {
        $wardId = $this->type === RequestTypeEnum::safeguardingException->value ? ($this->data['wardId'] ?? null) : null;

        return is_numeric($wardId) ? (int) $wardId : null;
    }

    // SCRUM-384: that minor, deleted accounts included -- the one a list preloaded
    // (PreloadRequestListAction, as `safeguardingWard`), otherwise looked up. Null when the account
    // is gone for good, or for any other type.
    public function safeguardingWard(): ?User
    {
        if ($this->relationLoaded('safeguardingWard')) {
            return $this->getRelation('safeguardingWard');
        }

        $wardId = $this->safeguardingWardId();

        return $wardId ? User::withTrashed()->find($wardId) : null;
    }

    // The ones still waiting for the super administrator.
    public function scopePendingSafeguardingException($query, User|int|null $ward = null, $for = null)
    {
        return $query->safeguardingException($ward, $for)->wherePending();
    }

    // The ones an admin's personal Requests list (RequestService::getRequests()) adds for them:
    // unaddressed requests nobody else would list. Unchanged by SCRUM-387.
    public const ADMIN_PERSONAL_LIST_TYPES = [
        RequestTypeEnum::dobChange->value,
        RequestTypeEnum::ageVerification->value,
        RequestTypeEnum::safeguardingException->value,
    ];

    // SCRUM-387: the requests waiting on an administrator that $viewer may see, of $types (default:
    // the whole queue, App\Support\AdminRequestQueue). Any status -- callers filter it. Matches
    // nothing for a non-admin, or for a type outside what the viewer may see.
    public function scopeAwaitingAdmin(Builder $query, User $viewer, ?array $types = null): Builder
    {
        return $query->ofAdminQueueTypes(array_values(array_intersect($types ?? AdminRequestQueue::types(), AdminRequestQueue::typesFor($viewer))));
    }

    // SCRUM-387: requests of these admin queue types that wait on an administrator, whoever is
    // looking (scopeAwaitingAdmin() adds that). A dob change only counts with no `to` (no guardian);
    // every other queue type counts whoever its `to` is. Matches nothing for no types.
    public function scopeOfAdminQueueTypes(Builder $query, array $types): Builder
    {
        $types = array_values(array_filter($types, fn (string $type) => AdminRequestQueue::includes($type)));

        if (! $types) {
            return $query->whereRaw('1 = 0');
        }

        $unaddressed = AdminRequestQueue::unaddressedOnly($types);
        $anyRecipient = array_values(array_diff($types, $unaddressed));

        return $query->where(function (Builder $query) use ($anyRecipient, $unaddressed) {
            if ($unaddressed) {
                $query->orWhere(fn (Builder $query) => $query->whereIn('type', $unaddressed)->whereNull('to_id'));
            }

            if ($anyRecipient) {
                $query->orWhereIn('type', $anyRecipient);
            }
        });
    }

    // SCRUM-391: the pending requests a counsellor answers -- addressed to their counsellor profile
    // (a therapy or group invitation, a discussion invitation, a schedule proposal), or to their
    // account about a group their counsellor profile created (a membership request, a counsellor's
    // offer to help). Matches on `to`, as EnsureUserCanRespondToRequestAction does. A dashboard view,
    // not the authority: a new request type a counsellor answers needs adding here too.
    public function scopeAwaitingCounsellor(Builder $query, Counsellor $counsellor): Builder
    {
        return $query->wherePending()->where(fn (Builder $query) => $query
            ->where(fn (Builder $query) => $query
                ->whereTo($counsellor)
                ->whereIn('type', [
                    RequestTypeEnum::therapy->value,
                    RequestTypeEnum::groupTherapy->value,
                    RequestTypeEnum::discussion->value,
                    RequestTypeEnum::sessionScheduleProposal->value,
                ]))
            ->orWhere(fn (Builder $query) => $query
                ->where('to_type', User::class)
                ->where('to_id', $counsellor->user_id)
                ->whereIn('type', [RequestTypeEnum::groupTherapyMembership->value, RequestTypeEnum::groupTherapy->value])
                ->where('for_type', GroupTherapy::class)
                ->whereIn('for_id', GroupTherapy::query()->select('id')->where('addedby_type', Counsellor::class)->where('addedby_id', $counsellor->id))));
    }

    // SCRUM-387: $user's own age verification, which another administrator must review.
    public function isOwnAgeVerificationOf(User $user): bool
    {
        return $this->type === RequestTypeEnum::ageVerification->value
            && $this->for_type === User::class
            && (int) $this->for_id === $user->id;
    }

    // SCRUM-387: whether $user raised this request themselves, or it is about them -- sent from their
    // account or their counsellor profile, or (a dob change / age verification) for their account.
    // An administrator may not answer their own: the point of the admin queue is an independent check.
    public function isOwnRequestOf(User $user): bool
    {
        $fromSelf = ($this->from_type === User::class && (int) $this->from_id === $user->id)
            || ($user->counsellor && $this->from_type === Counsellor::class && (int) $this->from_id === $user->counsellor->id);
        $aboutSelf = $this->for_type === User::class && (int) $this->for_id === $user->id;

        return $fromSelf || $aboutSelf;
    }

    public function scopeOwnAgeVerificationOf(Builder $query, User $user): Builder
    {
        return $query
            ->where('type', RequestTypeEnum::ageVerification->value)
            ->where('for_type', User::class)
            ->where('for_id', $user->id);
    }

    public function scopeWhereType($query, $type)
    {
        return $query->where('type', $type);
    }

    public function scopeWhereFrom($query, $from)
    {
        return $query->where(function ($q) use ($from) {

            $q->where('from_id', $from->id);
            $q->where('from_type', $from::class);
        });
    }

    public function scopeOrWhereTo($query, $to)
    {
        return $query->orWhere(function ($q) use ($to) {

            $q->where('to_id', $to->id);
            $q->where('to_type', $to::class);
        });
    }

    public function scopeOrWhereFrom($query, $from)
    {
        return $query->orWhere(function ($q) use ($from) {

            $q->where('from_id', $from->id);
            $q->where('from_type', $from::class);
        });
    }
}
