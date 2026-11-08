<?php

namespace App\Actions\Help;

use App\Actions\Action;
use App\Enums\HelpRequestStatusEnum;
use App\Events\HelpRequestUpdatedEvent;
use App\Exceptions\HelpException;
use App\Models\HelpRequest;
use App\Models\User;
use App\Support\HelpBroadcast;

// SCRUM-33 (TT-4.4b): an administrator closes a waiting request (e.g. they reached the person another
// way), with a short note for the record. The person is told it was closed and shown the emergency
// number; any ring for it closes.
class CloseHelpRequestByAdminAction extends Action
{
    public function execute(?User $admin, ?HelpRequest $request, ?string $note): HelpRequest
    {
        $request = EnsureCanHandleHelpRequestAction::new()->execute($admin, $request);
        $ringing = RingAvailableCounsellorsAction::ringingFor($request);
        $note = trim((string) $note);

        // Who and why in the same update as the status (review), so the queue never sees it half-closed.
        if (! CloseHelpRequestAction::new()->execute($request, HelpRequestStatusEnum::waiting, HelpRequestStatusEnum::closed, 'admin', [
            'closed_by_id' => $admin->id, 'closed_note' => $note === '' ? null : mb_substr($note, 0, 300),
        ])) {
            throw new HelpException(EnsureCanHandleHelpRequestAction::NOT_WAITING, 409);
        }
        HelpBroadcast::send(new HelpRequestUpdatedEvent($request->id, HelpRequestStatusEnum::closed->value, $ringing));

        return $request->refresh();
    }
}
