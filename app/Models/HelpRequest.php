<?php

namespace App\Models;

use App\Enums\HelpRequestStatusEnum;
use App\Enums\ReportStatusEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Enums\TherapyStatusEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// SCRUM-33: one "I need help now". Its status is only changed by the Help actions, through
// conditional updates (so a cancel, an expiry and an accept can't overwrite each other).
class HelpRequest extends Model
{
    protected $fillable = ['user_id', 'active_user_id', 'status', 'anonymous', 'was_minor', 'was_suspended', 'country_code', 'note'];

    protected $casts = [
        'status' => HelpRequestStatusEnum::class,
        'anonymous' => 'boolean',
        'was_minor' => 'boolean',
        'was_suspended' => 'boolean',
        'accepted_at' => 'datetime',
        'ended_at' => 'datetime',
        'escalated_at' => 'datetime',
        'escalated_again_at' => 'datetime',
        'pulled_at' => 'datetime',
        'invited_at' => 'datetime',
        'transcript_deleted_at' => 'datetime',
    ];

    // TT-4.4b: an administrator is involved -- they asked, they're the person's guardian, or they're the
    // counsellor in it, or one who could answer it (rung directly, or on call while it waits -- security
    // review: they mustn't see who asked and then answer). They never see who asked, nor act on it.
    // $wardIds: the administrator's wards, when a list has already loaded them (one query, not one a row).
    public function involvesAdmin(User $admin, ?array $wardIds = null): bool
    {
        if ($this->isFor($admin)) {
            return true;
        }

        $counsellor = $admin->counsellor;
        if ($counsellor && in_array((int) $counsellor->id, [(int) $this->counsellor_id, (int) $this->pulled_counsellor_id], true)) {
            return true;
        }

        if ($counsellor && $this->status === HelpRequestStatusEnum::waiting && $counsellor->isAvailableForUrgentHelp()) {
            return true;
        }

        return $wardIds !== null
            ? in_array((int) $this->user_id, $wardIds, true)
            : Guardianship::query()->where('guardian_id', $admin->id)->where('ward_id', $this->user_id)->exists();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function counsellor(): BelongsTo
    {
        return $this->belongsTo(Counsellor::class)->withTrashed();
    }

    public function pulledCounsellor(): BelongsTo
    {
        return $this->belongsTo(Counsellor::class, 'pulled_counsellor_id')->withTrashed();
    }

    public function therapy(): BelongsTo
    {
        return $this->belongsTo(Therapy::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }

    public function scopeWhereStatus(Builder $query, HelpRequestStatusEnum $status): Builder
    {
        return $query->where('status', $status->value);
    }

    // The person's live request (waiting or connected), if any -- at most one (active_user_id).
    public function scopeLiveFor(Builder $query, User $user): Builder
    {
        return $query->where('active_user_id', $user->id);
    }

    public function isFor(User $user): bool
    {
        return (int) $this->user_id === (int) $user->id;
    }

    // The counsellor connected to it, as a person.
    public function isAnsweredBy(User $user): bool
    {
        return $this->counsellor_id !== null && (int) $user->counsellor?->id === (int) $this->counsellor_id;
    }

    public function isParticipant(User $user): bool
    {
        return $this->isFor($user) || $this->isAnsweredBy($user);
    }

    // TT-4.4c (QA): the person has already asked this counsellor for a regular therapy -- one assigned to
    // them, or a request to them still waiting -- so the invitation has done its job.
    public function personAlreadyAskedCounsellor(): bool
    {
        if (! $this->counsellor_id) {
            return false;
        }

        $theirTherapies = Therapy::query()->withoutHelpChats()
            ->where('addedby_type', User::class)->where('addedby_id', $this->user_id)
            ->where('status', '!=', TherapyStatusEnum::ended->value);

        return (clone $theirTherapies)->where('counsellor_id', $this->counsellor_id)->exists()
            || Request::query()->where('type', RequestTypeEnum::therapy->value)->where('status', RequestStatusEnum::pending->value)
                ->where('to_type', Counsellor::class)->where('to_id', $this->counsellor_id)
                ->where('for_type', Therapy::class)->whereIn('for_id', $theirTherapies->select('id'))
                ->exists();
    }

    // TT-4.4c: a report about the chat -- its therapy, its session or one of its messages -- is open or
    // under review. Its messages are then kept past the retention period (the evidence), and nobody can
    // invite the person to continue with that counsellor meanwhile (security review).
    public function hasOpenReport(): bool
    {
        if (! $this->session_id && ! $this->therapy_id) {
            return false;
        }

        return Report::query()->whereIn('status', [ReportStatusEnum::open->value, ReportStatusEnum::under_review->value])
            ->where(fn ($query) => $query
                ->when($this->therapy_id, fn ($query) => $query->orWhere(fn ($query) => $query->where('reportable_type', Therapy::class)->where('reportable_id', $this->therapy_id)))
                ->when($this->session_id, fn ($query) => $query
                    ->orWhere(fn ($query) => $query->where('reportable_type', Session::class)->where('reportable_id', $this->session_id))
                    ->orWhere(fn ($query) => $query->where('reportable_type', Message::class)
                        ->whereIn('reportable_id', Message::withTrashed()->where('for_type', Session::class)->where('for_id', $this->session_id)->select('id')))))
            ->exists();
    }

    // SCRUM-32 (TT-4.3c): a safety flag about this chat still needs someone (open, being handled, or
    // withdrawn after an administrator took it on) -- its messages are kept past the retention period.
    public function hasUnresolvedSafetyFlag(): bool
    {
        return $this->therapy_id !== null && SafetyFlag::query()
            ->where('flaggable_type', Therapy::class)->where('flaggable_id', $this->therapy_id)
            ->whereUnresolved()->exists();
    }

    // TT-4.4c: the person can still be invited to a regular therapy with the counsellor -- not yet
    // invited, after a chat that took place and is still on record, with a counsellor who can take on
    // clients and a person who still has an account. $suspendedCounsellorUserIds: when a list has
    // already looked up which of its counsellors are suspended (one query, not one a row).
    public function canInviteToTherapy(?array $suspendedCounsellorUserIds = null): bool
    {
        $counsellor = $this->counsellor;
        if ($this->invited_at || $this->status !== HelpRequestStatusEnum::ended || ! $this->session_id
            || $this->transcript_deleted_at || ! $counsellor?->isVerified() || $counsellor->trashed()
            || ! $this->user || $this->user->trashed()) {
            return false;
        }

        $suspended = $suspendedCounsellorUserIds === null
            ? $counsellor->isSuspended()
            : in_array((int) $counsellor->user_id, $suspendedCounsellorUserIds, true);

        return ! $suspended && ! $this->hasOpenReport();
    }
}
