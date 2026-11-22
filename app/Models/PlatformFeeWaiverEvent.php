<?php

namespace App\Models;

use App\Enums\PlatformFeeWaiverEventEnum;
use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// TT-7.11e/SCRUM-475: see the platform_fee_waiver_events migration. Written only by PlatformFeeWaiverService; outlives
// the period it is about (no foreign key), so `waiver` is null once that period has been deleted.
class PlatformFeeWaiverEvent extends Model
{
    use AppendOnly;

    protected $guarded = ['id'];

    protected $casts = [
        'event' => PlatformFeeWaiverEventEnum::class,
        'data' => 'array',
    ];

    public function waiver(): BelongsTo
    {
        return $this->belongsTo(PlatformFeeWaiver::class, 'platform_fee_waiver_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
