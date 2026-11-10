<?php

namespace App\Support;

use App\Jobs\SendHelpPushJob;
use Illuminate\Support\Facades\DB;

// SCRUM-399: web push for urgent help rings -- best effort, never in the way of the ring itself: scheduled
// after the database work commits, sent from the `urgent` queue, and a failure is reported, never thrown.
// Only ids travel; the device shows fixed, generic words (public/sw.js), nothing about the person.
final class HelpPush
{
    public const QUEUE = 'urgent';

    public static function enabled(): bool
    {
        return (bool) config('help.push.enabled')
            && filled(config('help.push.vapid_public_key'))
            && filled(config('help.push.vapid_private_key'));
    }

    /** @param list<int> $counsellorIds */
    public static function ring(int $helpRequestId, array $counsellorIds): void
    {
        self::schedule(SendHelpPushJob::RING, $helpRequestId, $counsellorIds);
    }

    /** @param list<int> $counsellorIds */
    public static function withdraw(int $helpRequestId, array $counsellorIds): void
    {
        self::schedule(SendHelpPushJob::WITHDRAW, $helpRequestId, $counsellorIds);
    }

    private static function schedule(string $kind, int $helpRequestId, array $counsellorIds): void
    {
        if (! self::enabled()) {
            return;
        }

        DB::afterCommit(fn () => rescue(
            fn () => SendHelpPushJob::dispatch($kind, $helpRequestId, array_values(array_map('intval', $counsellorIds)))->onQueue(self::QUEUE),
            report: true,
        ));
    }
}
