<?php

namespace App\Models;

use App\Actions\Admin\NotifyUninvolvedAdminsAction;
use App\Enums\SafetyFlagDiscussedEnum;
use App\Enums\SafetyFlagKindEnum;
use App\Enums\SafetyFlagNoticeStateEnum;
use App\Enums\SafetyFlagOutcomeEnum;
use App\Enums\SafetyFlagStatusEnum;
use App\Enums\SafetyFlagUrgencyEnum;
use App\Events\SafetyFlagsChangedEvent;
use App\Support\AdminNotifyResult;
use App\Support\BestEffortBroadcast;
use App\Support\Countries;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// SCRUM-32: a counsellor's safety flag -- they think a client needs more care than talk therapy can
// give (specialist or inpatient care). It alerts the administrators who aren't involved, one of whom
// acknowledges and handles it; only that administrator sees who the client is and the reason, and
// each look is recorded. It never changes anything the client sees (the notices come in TT-4.3c).
// Never deleted; its history is in safety_flag_events.
class SafetyFlag extends Model
{
    protected $guarded = ['id'];

    // Never sent anywhere by accident: the client and the reason only through their own, logged actions.
    // Everything but the public id and the status is hidden (security review): it names no one, and
    // nothing about where it was raised or by whom.
    protected $visible = ['public_id', 'status', 'urgency', 'kind', 'created_at'];

    protected $casts = [
        'kind' => SafetyFlagKindEnum::class,
        'outcome' => SafetyFlagOutcomeEnum::class,
        'client_notice_state' => SafetyFlagNoticeStateEnum::class,
        'guardian_notice_state' => SafetyFlagNoticeStateEnum::class,
        'urgency' => SafetyFlagUrgencyEnum::class,
        'status' => SafetyFlagStatusEnum::class,
        'discussed_with_client' => SafetyFlagDiscussedEnum::class,
        'reason' => 'encrypted',
        'emergency_services_note' => 'encrypted',
        'withdrawn_reason' => 'encrypted',
        'outcome_note' => 'encrypted',
        'client_notice_hold_reason' => 'encrypted',
        'guardian_withhold_reason' => 'encrypted',
        'emergency_services_contacted' => 'boolean',
        'was_minor' => 'boolean',
        'had_guardian' => 'boolean',
        'anonymous' => 'boolean',
        'acknowledged_at' => 'datetime',
        'realerted_at' => 'datetime',
        'withdrawn_at' => 'datetime',
        'resolved_at' => 'datetime',
        'client_notice_sent_at' => 'datetime',
        'guardian_notice_sent_at' => 'datetime',
    ];

    public static function label(): string
    {
        return (string) config('safety.label');
    }

    protected static function booted(): void
    {
        static::creating(fn (SafetyFlag $flag) => $flag->public_id ??= (string) Str::ulid());
    }

    // What a flag records about the client when it's raised (snapshots, so the list never loads them),
    // and where their notices start (TT-4.3c): held for the handling administrator; a minor's guardians
    // only for imminent and urgent flags. One place, for the action and the seeder.
    public static function snapshotFor(User $client, SafetyFlagUrgencyEnum $urgency): array
    {
        $minor = ! $client->isAdult();

        return [
            'was_minor' => $minor,
            'had_guardian' => $client->hasActiveGuardian(),
            'country_code' => Countries::codeFor($client->country),
            'client_notice_state' => SafetyFlagNoticeStateEnum::pending,
            'guardian_notice_state' => $minor && $urgency !== SafetyFlagUrgencyEnum::nonUrgent
                ? SafetyFlagNoticeStateEnum::pending : SafetyFlagNoticeStateEnum::notApplicable,
        ];
    }

    public static function findPublic(?string $publicId): ?self
    {
        return $publicId ? self::query()->where('public_id', $publicId)->first() : null;
    }

    // The key that makes "one open flag per client per therapy" a database rule.
    public static function openKeyFor(Model $flaggable, User $client): string
    {
        return $flaggable::class.':'.$flaggable->getKey().':'.$client->id;
    }

    public function flaggable(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    public function client()
    {
        return $this->belongsTo(User::class, 'client_user_id')->withTrashed();
    }

    public function counsellor()
    {
        return $this->belongsTo(Counsellor::class)->withTrashed();
    }

    public function handler()
    {
        return $this->belongsTo(User::class, 'handler_user_id')->withTrashed();
    }

    public function events()
    {
        return $this->hasMany(SafetyFlagEvent::class)->orderBy('id');
    }

    public function notes()
    {
        return $this->hasMany(SafetyFlagNote::class)->orderBy('id');
    }

    // TT-4.3b: being handled -- acknowledged, or withdrawn after an administrator took it on (they can
    // still note, hand on and resolve it). One rule (reviews); TT-4.3c's help-chat hold uses
    // scopeWhereUnresolved too.
    public function isBeingHandled(): bool
    {
        return $this->handler_user_id !== null
            && in_array($this->status, [SafetyFlagStatusEnum::acknowledged, SafetyFlagStatusEnum::withdrawn], true);
    }

    // Still needs someone: open, being handled, or withdrawn after an administrator took it on.
    public function scopeWhereUnresolved(Builder $query): Builder
    {
        return $query->where(fn ($query) => $query
            ->whereIn('status', [SafetyFlagStatusEnum::open->value, SafetyFlagStatusEnum::acknowledged->value])
            ->orWhere(fn ($query) => $query->where('status', SafetyFlagStatusEnum::withdrawn->value)->whereNotNull('handler_user_id')));
    }

    public static function beingHandledStatuses(): array
    {
        return [SafetyFlagStatusEnum::acknowledged->value, SafetyFlagStatusEnum::withdrawn->value];
    }

    // TT-4.3b: the fallback mailbox, when one is set (one place: reviews).
    public static function fallbackMailbox(): ?string
    {
        $mailbox = config('safety.fallback_mailbox');

        return is_string($mailbox) && filter_var($mailbox, FILTER_VALIDATE_EMAIL) ? $mailbox : null;
    }

    // TT-4.3b: tells the (super) administrators who aren't involved, falling back to the mailbox, and
    // records a fallback in the history only when it really went out.
    public function alertUninvolved(Notification $notification, string $about, bool $superOnly = false): AdminNotifyResult
    {
        $result = NotifyUninvolvedAdminsAction::new()->execute(
            fn (User $admin) => $this->involvesAdmin($admin), $notification,
            superOnly: $superOnly, about: $about, fallbackMailbox: self::fallbackMailbox(),
        );

        if ($result->fellBack) {
            SafetyFlagEvent::query()->create(['safety_flag_id' => $this->id, 'action' => SafetyFlagEvent::FALLBACK_ALERTED]);
        }

        return $result;
    }

    // A value as the model's `encrypted` cast stores it -- for conditional query-builder updates, which
    // bypass casts (reviews: one named place for the trick).
    public static function encryptedValue(string $column, ?string $value): ?string
    {
        return (new self)->forceFill([$column => $value])->getAttributes()[$column];
    }

    // TT-4.3b: when nobody has acknowledged it by then, the super administrators are alerted again.
    // Fails closed: a missing, non-numeric or non-positive setting falls back to the default (logged),
    // never "don't re-alert".
    public static function realertMinutes(SafetyFlagUrgencyEnum $urgency): int
    {
        $defaults = ['IMMINENT' => 60, 'URGENT' => 240, 'NON_URGENT' => 1440];
        $configured = config("safety.realert_minutes.{$urgency->value}");

        if (! is_numeric($configured) || (int) $configured < 1) {
            Log::error('Safety flag re-alert time is missing or invalid; using the default.', ['urgency' => $urgency->value, 'configured' => $configured]);

            return $defaults[$urgency->value];
        }

        return (int) $configured;
    }

    // Open or acknowledged: still being dealt with.
    public function scopeWhereOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [SafetyFlagStatusEnum::open->value, SafetyFlagStatusEnum::acknowledged->value]);
    }

    public function isHandledBy(?User $user): bool
    {
        return $user !== null && $this->handler_user_id !== null && (int) $this->handler_user_id === (int) $user->id;
    }

    // Tells the administrators who aren't involved that the safety alerts changed (best effort).
    public function broadcastChanged(): void
    {
        $ids = NotifyUninvolvedAdminsAction::recipients(fn (User $admin) => $this->involvesAdmin($admin))->modelKeys();
        if ($ids) {
            BestEffortBroadcast::send(new SafetyFlagsChangedEvent($ids));
        }
    }

    // An administrator too close to it to see or act on it: the client; the counsellor who flagged;
    // anyone involved in the therapy or group (its participants, former members, counsellors, and the
    // guardians of its client or members -- TherapyTrait::involvesInOversight, the rule reports use);
    // or the client's guardian. They don't see it, aren't alerted, and it isn't in their badge.
    // $wardIds: theirs, when a list has already loaded them.
    public function involvesAdmin(User $admin, ?Collection $wardIds = null): bool
    {
        if ((int) $this->client_user_id === (int) $admin->id) {
            return true;
        }

        if ($this->counsellor && (int) $this->counsellor->user_id === (int) $admin->id) {
            return true;
        }

        $wardIds ??= $admin->wardIds();
        if ($wardIds->contains($this->client_user_id)) {
            return true;
        }

        return (bool) $this->flaggable?->involvesInOversight($admin, $wardIds);
    }
}
