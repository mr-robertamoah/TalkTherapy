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

    // Who created or ended a period is set from the acting user (forceFill), never mass-assigned. counsellor_id isn't
    // fillable either until SCRUM-464 decides how a counsellor's own waiver is made (a row without one is
    // platform-wide).
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

    // The waivers in force at $at: started at or before it, not yet ended (the end itself isn't covered).
    public function scopeCoveringTime(Builder $query, CarbonInterface $at): Builder
    {
        return $query->where('starts_at', '<=', $at)
            ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $at));
    }
}
