<?php

namespace App\Actions\Help;

use App\Actions\Action;
use App\Events\HelpRequestRingingEvent;
use App\Jobs\EscalateHelpRequestJob;
use App\Models\Counsellor;
use App\Models\HelpRequest;
use App\Support\HelpBroadcast;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

// SCRUM-33: rings every counsellor on call for urgent help right now (Counsellor::availableForUrgentHelp)
// -- never the person asking (a counsellor may need help too), and never one of their guardians
// (security review: a ward's help chat isn't seen by guardians). Returns how many were rung.
// ringableFor() is also who may see the summary and accept.
class RingAvailableCounsellorsAction extends Action
{
    // SCRUM-395: $capBucket -- a new request's automatic ring counts against config help.rings_per_minute
    // in its bucket ('established' or 'new'), platform-wide. Past it the ring is skipped: the request is
    // marked for administrators and they're alerted at once. An administrator's ring (no bucket) clears it.
    public function execute(HelpRequest $request, ?string $capBucket = null): int
    {
        $ids = self::ringableFor($request);

        if ($ids && $capBucket && ! RateLimiter::attempt("help-rings:{$capBucket}", max(1, (int) config('help.rings_per_minute')), fn () => true, 60)) {
            Log::warning('Urgent help ring skipped: the platform-wide ring cap was reached.', ['helpRequest' => $request->id, 'bucket' => $capBucket]);
            $request->forceFill(['ring_skipped_at' => now()])->saveQuietly();
            HelpBroadcast::queueChanged($request->id);
            EscalateHelpRequestJob::dispatch($request->id);

            return 0;
        }

        if (! $capBucket && $request->ring_skipped_at) {
            $request->forceFill(['ring_skipped_at' => null])->saveQuietly();
        }

        if ($ids) {
            HelpBroadcast::send(new HelpRequestRingingEvent($request->id, $ids));
        }

        return count($ids);
    }

    // TT-4.4b: may $counsellor answer it -- rung as on call, or the one an administrator rang directly
    // (who needn't be on call, but must be able to take it).
    public static function mayAnswer(HelpRequest $request, Counsellor $counsellor): bool
    {
        if ((int) $request->pulled_counsellor_id === (int) $counsellor->id) {
            return self::eligibleForDirectRing($request, $counsellor);
        }

        return Counsellor::query()->whereKey($counsellor->id)->availableForUrgentHelp()->mayHelpWith($request)->exists();
    }

    public static function eligibleForDirectRing(HelpRequest $request, Counsellor $counsellor): bool
    {
        return Counsellor::query()->whereKey($counsellor->id)->ableToTakeUrgentHelp()->mayHelpWith($request)->exists();
    }

    /** @return list<int> On-call counsellors it rings. */
    public static function ringableFor(HelpRequest $request): array
    {
        return Counsellor::query()->availableForUrgentHelp()->mayHelpWith($request)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return list<int> Everyone it is ringing now -- on call, and the counsellor rung directly -- whose ring closes with it. */
    public static function ringingFor(HelpRequest $request): array
    {
        return array_values(array_unique([...self::ringableFor($request), ...($request->pulled_counsellor_id ? [(int) $request->pulled_counsellor_id] : [])]));
    }
}
