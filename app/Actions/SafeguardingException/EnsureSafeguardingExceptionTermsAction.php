<?php

namespace App\Actions\SafeguardingException;

use App\Actions\Action;
use App\Exceptions\SafeguardingExceptionRefusedException;
use App\Models\SafeguardingException;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Throwable;

// SCRUM-381: the terms of a safeguarding exception (granted, renewed, or proposed by a counsellor),
// checked when they are used: a reason (up to 2000 characters), and an end more than an hour and at
// most 30 days from now (UTC). Returns them cleaned up.
class EnsureSafeguardingExceptionTermsAction extends Action
{
    public const MAX_REASON = 2000;

    /** @return array{0: string, 1: Carbon} */
    public function execute(?string $reason, mixed $endsAt, string $what = 'the exception'): array
    {
        $reason = trim((string) $reason);

        if ($reason === '') {
            throw new SafeguardingExceptionRefusedException("Give a reason for {$what}.", 422);
        }

        if (mb_strlen($reason) > self::MAX_REASON) {
            throw new SafeguardingExceptionRefusedException('The reason must not be longer than '.self::MAX_REASON.' characters.', 422);
        }

        return [$reason, $this->endsAt($endsAt)];
    }

    public function endsAt(mixed $endsAt): Carbon
    {
        try {
            $at = $endsAt instanceof CarbonInterface ? Carbon::instance($endsAt) : Carbon::parse((string) $endsAt, 'UTC');
        } catch (Throwable) {
            $at = null;
        }

        if (! $at || ! $endsAt) {
            throw new SafeguardingExceptionRefusedException('Choose when the exception ends.', 422);
        }

        // Whole seconds, as stored (a DATETIME column would round, not drop, the fraction), so what
        // the notices say (continuesUntil) is exactly the stored ends_at.
        $at = $at->utc()->startOfSecond();

        if ($at->lte(now()->addHours(SafeguardingException::MIN_HOURS))) {
            throw new SafeguardingExceptionRefusedException('The exception must end more than an hour from now.', 422);
        }

        if ($at->gt(now()->addDays(SafeguardingException::MAX_DAYS))) {
            throw new SafeguardingExceptionRefusedException('An exception can last at most '.SafeguardingException::MAX_DAYS.' days.', 422);
        }

        return $at;
    }
}
