<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// TT-7.10a/SCRUM-467: a period during which the platform takes no fee, [starts_at, ends_at) -- ends_at null runs
// until someone ends it. No counsellor: the whole platform; a counsellor: only their work (SCRUM-464). Which fee a
// payment or invoice line pays is decided once, through PlatformFeePolicy, and stored; changing a period later
// never rewrites what was stored.
class PlatformFeeWaiver extends Model
{
    use HasFactory;

    // TT-7.10b/SCRUM-468: a period's status, worked out from its timestamps (never stored). Cancelled: an admin ended
    // it early with "End now" (ended_by_id set); ended: it reached the end it was scheduled with.
    public const UPCOMING = 'upcoming';

    public const ACTIVE = 'active';

    public const ENDED = 'ended';

    public const CANCELLED = 'cancelled';

    // How far ahead an upcoming platform-wide period is announced to counsellors and organisations.
    public const NOTICE_HORIZON_DAYS = 30;

    // A new period's limits (TT-7.10b/SCRUM-468), checked by the FormRequests (ValidatesFeeWaiverPeriod) and again by
    // PlatformFeeWaiverService for every caller (TT-7.11e/SCRUM-475): a start at most this far in the past (moved up to
    // now), both ends within MAX_YEARS_AHEAD (also within what a MySQL timestamp holds), a reason up to REASON_MAX.
    public const BACKDATE_LEEWAY_SECONDS = 60;

    public const MAX_YEARS_AHEAD = 2;

    public const REASON_MAX = 500;

    // Who created or ended a period is set from the acting user (forceFill), never mass-assigned. counsellor_id isn't
    // fillable either: TT-7.11e/SCRUM-475 sets it only from the route's counsellor (a row without one is platform-wide).
    protected $fillable = [
        'starts_at', 'ends_at', 'reason',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function counsellor()
    {
        return $this->belongsTo(Counsellor::class)->withTrashed();
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function endedBy()
    {
        return $this->belongsTo(User::class, 'ended_by_id');
    }

    // TT-7.11e/SCRUM-475: the approved recognition a counsellor's period was given for, if any.
    public function recognition()
    {
        return $this->hasOne(CounsellorRecognition::class);
    }

    public function events()
    {
        return $this->hasMany(PlatformFeeWaiverEvent::class)->orderBy('id');
    }

    public function isPlatformWide(): bool
    {
        return $this->counsellor_id === null;
    }

    public function status(?CarbonInterface $at = null): string
    {
        $at ??= now();

        return match (true) {
            $this->ended_by_id !== null => self::CANCELLED,
            $this->starts_at->gt($at) => self::UPCOMING,
            $this->ends_at === null || $this->ends_at->gt($at) => self::ACTIVE,
            default => self::ENDED,
        };
    }

    public function scopePlatformWide(Builder $query): Builder
    {
        return $query->whereNull('counsellor_id');
    }

    // TT-7.11e/SCRUM-475: one scope's periods -- platform-wide ($counsellorId null) or that counsellor's own.
    public function scopeInScope(Builder $query, ?int $counsellorId): Builder
    {
        return $counsellorId === null ? $query->platformWide() : $query->where('counsellor_id', $counsellorId);
    }

    // The waivers in force at $at: started at or before it, not yet ended (the end itself isn't covered).
    public function scopeCoveringTime(Builder $query, CarbonInterface $at): Builder
    {
        return $query->where('starts_at', '<=', $at)
            ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $at));
    }
}
