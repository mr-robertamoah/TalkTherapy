<?php

namespace App\Actions\Help;

use App\Actions\Action;
use App\Events\HelpRequestRingingEvent;
use App\Models\Counsellor;
use App\Models\HelpRequest;
use App\Support\HelpBroadcast;

// SCRUM-33: rings every counsellor on call for urgent help right now (Counsellor::availableForUrgentHelp)
// -- never the person asking (a counsellor may need help too), and never one of their guardians
// (security review: a ward's help chat isn't seen by guardians). Returns how many were rung.
// ringableFor() is also who may see the summary and accept.
class RingAvailableCounsellorsAction extends Action
{
    public function execute(HelpRequest $request): int
    {
        $ids = self::ringableFor($request);

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
