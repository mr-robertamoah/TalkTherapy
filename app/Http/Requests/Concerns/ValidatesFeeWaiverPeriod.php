<?php

namespace App\Http\Requests\Concerns;

use Carbon\CarbonImmutable;

// TT-7.10b/SCRUM-468, shared since TT-7.11e/SCRUM-475: a new commission-free period, [starts_at, ends_at), in UTC --
// platform-wide (StorePlatformFeeWaiverRequest) or one counsellor's (StoreCounsellorFeeWaiverRequest). No backdating
// (a minute's leeway for the time it took to submit), an end is required and after the start and now, and nothing more
// than MAX_YEARS_AHEAD out (also keeps the dates within what a MySQL timestamp holds). counsellor_id is never taken from
// the body: a platform-wide period has none, and a counsellor's comes from the route. Overlaps are checked by
// PlatformFeeWaiverService, under a lock, which also re-checks the end against the (possibly moved-up) start.
trait ValidatesFeeWaiverPeriod
{
    public const REASON_MAX = 500;

    public const MAX_YEARS_AHEAD = 2;

    protected function periodRules(): array
    {
        $now = CarbonImmutable::now('UTC');

        return [
            'counsellor_id' => ['prohibited'],
            'starts_at' => ['required', 'date', 'after_or_equal:'.$now->subMinute()->toDateTimeString(), 'before:'.$now->addYears(self::MAX_YEARS_AHEAD)->toDateTimeString()],
            'ends_at' => ['required', 'date', 'after:starts_at', 'after:now', 'before_or_equal:'.$now->addYears(self::MAX_YEARS_AHEAD)->toDateTimeString()],
            'reason' => ['required', 'string', 'max:'.self::REASON_MAX],
        ];
    }

    protected function periodMessages(): array
    {
        return [
            'starts_at.after_or_equal' => 'The start cannot be in the past.',
            'ends_at.after' => 'The end must be after the start, and in the future.',
        ];
    }

    /**
     * @return array{starts_at: CarbonImmutable, ends_at: CarbonImmutable, reason: string}
     */
    public function values(): array
    {
        $validated = $this->validated();

        // A value with no offset is UTC, as the form says; one with an offset is converted to UTC before it's stored.
        // A start within the minute's leeway is moved up to now, so nothing already paid is ever covered.
        return [
            'starts_at' => CarbonImmutable::parse($validated['starts_at'], 'UTC')->utc()->max(CarbonImmutable::now('UTC')),
            'ends_at' => CarbonImmutable::parse($validated['ends_at'], 'UTC')->utc(),
            'reason' => trim($validated['reason']),
        ];
    }
}
