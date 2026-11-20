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

    // Who created or ended a period is set from the acting user (forceFill), never mass-assigned.
    protected $fillable = [
        'counsellor_id', 'starts_at', 'ends_at', 'reason',
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

    // The waivers in force at $at: started at or before it, not yet ended (the end itself isn't covered).
    public function scopeCoveringTime(Builder $query, CarbonInterface $at): Builder
    {
        return $query->where('starts_at', '<=', $at)
            ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $at));
    }
}
