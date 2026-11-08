<?php

namespace App\Actions\Help;

use App\Actions\Action;
use App\Events\HelpRequestRingingEvent;
use App\Models\Counsellor;
use App\Models\Guardianship;
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

    /** @return list<int> */
    public static function ringableFor(HelpRequest $request): array
    {
        $guardianIds = Guardianship::query()->where('ward_id', $request->user_id)->pluck('guardian_id');

        return Counsellor::query()->availableForUrgentHelp()
            ->where('user_id', '!=', $request->user_id)
            ->whereNotIn('user_id', $guardianIds)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
