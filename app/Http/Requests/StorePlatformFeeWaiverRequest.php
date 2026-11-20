<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;

// TT-7.10b/SCRUM-468: a new platform-wide commission-free period, [starts_at, ends_at), in UTC (the form says so). No
// backdating (a minute's leeway for the time it took to submit), an end is required and after the start, and nothing
// more than MAX_YEARS_AHEAD out (also keeps the dates within what a MySQL timestamp holds). counsellor_id is accepted
// only as absent or null for now -- a counsellor's own waiver is SCRUM-464's. Overlaps are checked by
// PlatformFeeWaiverService, under a lock.
class StorePlatformFeeWaiverRequest extends ManagePlatformFeeWaiverRequest
{
    public const REASON_MAX = 500;

    public const MAX_YEARS_AHEAD = 2;

    public function rules(): array
    {
        $now = CarbonImmutable::now('UTC');

        return [
            'counsellor_id' => ['prohibited'],
            'starts_at' => ['required', 'date', 'after_or_equal:'.$now->subMinute()->toDateTimeString(), 'before:'.$now->addYears(self::MAX_YEARS_AHEAD)->toDateTimeString()],
            'ends_at' => ['required', 'date', 'after:starts_at', 'after:now', 'before_or_equal:'.$now->addYears(self::MAX_YEARS_AHEAD)->toDateTimeString()],
            'reason' => ['required', 'string', 'max:'.self::REASON_MAX],
        ];
    }

    public function messages(): array
    {
        return [
            'counsellor_id.prohibited' => 'Only platform-wide periods can be created here.',
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
