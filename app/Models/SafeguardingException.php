<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

// SCRUM-381: see the safeguarding_exceptions migration. Written only by the actions in
// app/Actions/SafeguardingException and SafeguardingExceptionService's sweeps. The one lock order
// for changing one: the minor's user row, then their exception rows, then any request rows.
class SafeguardingException extends Model
{
    public const END_REVOKED = 'revoked';

    public const END_RENEWED = 'renewed';

    // The longest one can run, and the shortest, from the moment it is granted.
    public const MAX_DAYS = 30;

    public const MIN_HOURS = 1;

    // The heads-up before it runs out.
    public const REMINDER_DAYS = 3;

    protected $guarded = ['id'];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'ended_at' => 'datetime',
        'end_processed_at' => 'datetime',
        'expiry_reminded_at' => 'datetime',
    ];

    // SCRUM-382 review: the one lock order, in one place (like Suspension::lockWithPerson()) -- the
    // minor's user row, then the exception. Returns [the exception or null (not found), the minor or
    // null (gone)]. Call inside a transaction.
    /** @return array{0: ?self, 1: ?User} */
    public static function lockWithWard(int $id): array
    {
        $ward = self::lockWard(self::query()->whereKey($id)->value('ward_id'));

        return [self::query()->lockForUpdate()->find($id), $ward];
    }

    // The minor's row, then the exception in force for them (the newest, if more than one), when
    // there is no exception id to start from (granting or renewing). Returns [the minor or null, the
    // exception in force or null].
    /** @return array{0: ?User, 1: ?self} */
    public static function lockActiveWithWard(int $wardId): array
    {
        $ward = self::lockWard($wardId);

        return [$ward, $ward ? self::query()->where('ward_id', $ward->id)->active()->lockForUpdate()->orderByDesc('ends_at')->first() : null];
    }

    private static function lockWard(?int $wardId): ?User
    {
        return $wardId ? User::query()->whereKey($wardId)->lockForUpdate()->first() : null;
    }

    public function ward()
    {
        return $this->belongsTo(User::class, 'ward_id')->withTrashed();
    }

    public function grantedBy()
    {
        return $this->belongsTo(User::class, 'granted_by')->withTrashed();
    }

    public function endedBy()
    {
        return $this->belongsTo(User::class, 'ended_by')->withTrashed();
    }

    public function request()
    {
        return $this->belongsTo(Request::class);
    }

    public function previous()
    {
        return $this->belongsTo(self::class, 'previous_id');
    }

    public function renewal()
    {
        return $this->hasOne(self::class, 'previous_id');
    }

    // In force right now: started, not ended early or renewed, not past its end.
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('ended_at')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now());
    }

    public function isActive(): bool
    {
        return is_null($this->ended_at) && $this->starts_at->lte(now()) && $this->ends_at->isFuture();
    }

    // How an end date is written in every notice (they are sent from the queue, so UTC, labelled).
    public static function formatEnd(CarbonInterface $at): string
    {
        return $at->copy()->utc()->toDayDateTimeString().' (UTC)';
    }

    // active | expired | revoked | renewed
    public function status(): string
    {
        if ($this->isActive()) {
            return 'active';
        }

        return $this->end_kind ?? 'expired';
    }
}
