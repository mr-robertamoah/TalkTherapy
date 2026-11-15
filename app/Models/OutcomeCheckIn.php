<?php

namespace App\Models;

use App\Enums\OutcomeMeasureEnum;
use App\Support\OutcomeMeasures\OutcomeMeasure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// SCRUM-37 (TT-5.2): one outcome check-in -- a questionnaire a therapy's counsellor sent its client, and the client's
// answers. Only the therapy's counsellor and its client ever see it (App\Actions\OutcomeCheckIn); no guardian,
// organisation or administrator path. Answers, score and level are encrypted at rest and never in a notification.
class OutcomeCheckIn extends Model
{
    public const REQUESTED = 'requested';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    protected $fillable = [];

    protected $casts = [
        'requested_at' => 'datetime',
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'attention_acknowledged_at' => 'datetime',
        'answers' => 'encrypted:array',
        'total_score' => 'encrypted',
        'band' => 'encrypted',
        'needs_attention' => 'boolean',
        'measure_version' => 'integer',
    ];

    protected $hidden = ['answers', 'total_score', 'band', 'open_key'];

    public static function openKeyFor(int $therapyId, string $measure): string
    {
        return "{$therapyId}:{$measure}";
    }

    public function therapy(): BelongsTo
    {
        return $this->belongsTo(Therapy::class)->withTrashed();
    }

    public function counsellor(): BelongsTo
    {
        return $this->belongsTo(Counsellor::class)->withTrashed();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_user_id')->withTrashed();
    }

    // The questionnaire (not `measure`, which is its column).
    public function definition(): OutcomeMeasure
    {
        return OutcomeMeasureEnum::from($this->getAttribute('measure'))->definition();
    }

    public function isExpired(): bool
    {
        return $this->status === self::REQUESTED && $this->expires_at->isPast();
    }

    public function isOpen(): bool
    {
        return $this->status === self::REQUESTED && ! $this->isExpired();
    }

    // requested | completed | cancelled | expired
    public function state(): string
    {
        return $this->isExpired() ? 'expired' : $this->status;
    }
}
