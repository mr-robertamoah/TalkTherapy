<?php

namespace App\Actions\Help;

use App\Actions\Action;
use App\Enums\HelpRequestStatusEnum;
use App\Events\HelpRequestRingingEvent;
use App\Events\HelpRequestUpdatedEvent;
use App\Exceptions\HelpException;
use App\Models\Counsellor;
use App\Models\HelpRequest;
use App\Models\User;
use App\Support\HelpBroadcast;

// SCRUM-33 (TT-4.4b): an administrator rings one counsellor for a waiting request -- one who needn't be
// on call, but must be able to take it (RingAvailableCounsellorsAction::eligibleForDirectRing). They're
// rung and may accept (the first to accept still wins); it's never assigned to them unasked.
class RingCounsellorForHelpRequestAction extends Action
{
    public const NOT_ELIGIBLE = 'This counsellor can\'t take it: they need a current verification, no suspension, and no other help chat open.';

    public function execute(?User $admin, ?HelpRequest $request, ?Counsellor $counsellor): HelpRequest
    {
        $request = EnsureCanHandleHelpRequestAction::new()->execute($admin, $request);

        // Never their own counsellor profile (security review), and only someone who could take it.
        if (! $counsellor || (int) $counsellor->user_id === (int) $admin->id || ! RingAvailableCounsellorsAction::eligibleForDirectRing($request, $counsellor)) {
            throw new HelpException(self::NOT_ELIGIBLE, 422);
        }

        $previous = (int) $request->pulled_counsellor_id;

        // Only while it still waits (review): an accept or a close at the same moment wins.
        $rung = HelpRequest::query()->whereKey($request->id)->where('status', HelpRequestStatusEnum::waiting->value)
            ->update(['pulled_counsellor_id' => $counsellor->id, 'pulled_by_id' => $admin->id, 'pulled_at' => now(), 'updated_at' => now()]);
        if (! $rung) {
            throw new HelpException(EnsureCanHandleHelpRequestAction::NOT_WAITING, 409);
        }

        // Only the latest pick may answer: the one rung before is told their ring has closed.
        if ($previous && $previous !== (int) $counsellor->id) {
            HelpBroadcast::send(new HelpRequestUpdatedEvent($request->id, HelpRequestStatusEnum::waiting->value, [$previous]));
        }
        HelpBroadcast::send(new HelpRequestRingingEvent($request->id, [(int) $counsellor->id]));
        HelpBroadcast::queueChanged($request->id);

        return $request->refresh();
    }
}
