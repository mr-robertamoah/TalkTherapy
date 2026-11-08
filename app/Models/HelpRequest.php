<?php

namespace App\Models;

use App\Enums\HelpRequestStatusEnum;
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
}
