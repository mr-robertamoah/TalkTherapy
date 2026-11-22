<?php

namespace App\Models;

use App\Enums\CounsellorRecognitionConsentEnum;
use App\Enums\CounsellorRecognitionDecisionEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// TT-7.11d1/SCRUM-473: a super administrator's decision about a counsellor for a month, and the counsellor's featuring
// consent (see the counsellor_recognitions migration). Written only by CounsellorRecognitionService and
// RespondToCounsellorRecognitionRequestAction, so nothing is fillable.
//
// `month` is kept as the plain 'Y-m-01' string the date column holds (not cast), so equality queries match on MySQL
// and SQLite alike -- monthStart() gives the Carbon.
class CounsellorRecognition extends Model
{
    protected $guarded = ['*'];

    protected $casts = [
        'decision' => CounsellorRecognitionDecisionEnum::class,
        'consent' => CounsellorRecognitionConsentEnum::class,
        'consent_changed_at' => 'datetime',
        'decided_at' => 'datetime',
        'added_manually' => 'boolean',
        'stars_count' => 'integer',
        'rank' => 'integer',
    ];

    public const MONTH_RULE = 'Choose this month or last month (YYYY-MM).';

    // The month a recognition can be decided for: 'YYYY-MM', and only the current UTC month or the one before -- never
    // further back (security review: a back-dated recognition would be a route to a waiver). Its first moment, in
    // UTC; null for anything else.
    public static function parseMonth(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $value, $parts)) {
            return null;
        }

        $month = CarbonImmutable::create((int) $parts[1], (int) $parts[2], 1, 0, 0, 0, 'UTC');
        $current = CarbonImmutable::now('UTC')->startOfMonth();

        return $month->equalTo($current) || $month->equalTo($current->subMonth()) ? $month : null;
    }

    // The stored value for a month: its 1st, as 'Y-m-d'.
    public static function monthKey(CarbonInterface $month): string
    {
        return $month->format('Y-m-01');
    }

    public function monthStart(): CarbonImmutable
    {
        return CarbonImmutable::parse(substr((string) $this->month, 0, 10), 'UTC')->startOfMonth();
    }

    // "November 2026".
    public function monthLabel(): string
    {
        return $this->monthStart()->format('F Y');
    }

    public function isApproved(): bool
    {
        return $this->decision === CounsellorRecognitionDecisionEnum::approved;
    }

    public function scopeForMonth(Builder $query, CarbonInterface $month): Builder
    {
        return $query->where('month', self::monthKey($month));
    }

    public function counsellor(): BelongsTo
    {
        return $this->belongsTo(Counsellor::class)->withTrashed();
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id')->withTrashed();
    }

    public function events(): HasMany
    {
        return $this->hasMany(CounsellorRecognitionEvent::class, 'recognition_id')->orderBy('id');
    }

    // The featuring consent requests sent for it (a new one each time it is approved).
    public function consentRequests(): HasMany
    {
        return $this->hasMany(Request::class, 'for_id')->where('for_type', self::class);
    }

    // For SCRUM-475's per-counsellor waiver.
    public function platformFeeWaiver(): BelongsTo
    {
        return $this->belongsTo(PlatformFeeWaiver::class);
    }
}
