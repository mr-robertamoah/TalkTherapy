<?php

namespace App\Actions\Help;

use App\Actions\Action;
use App\Enums\HelpRequestStatusEnum;
use App\Events\HelpRequestUpdatedEvent;
use App\Models\Counsellor;
use App\Models\HelpRequest;
use App\Support\HelpBroadcast;

// SCRUM-33 (security review): a counsellor who is suspended or loses their verification mid-chat is
// taken out of any help chat they're in -- the chat ends for both (still readable), and their "available
// for urgent help" is switched off. A person in crisis is never left with someone who may not help.
class EndCounsellorHelpChatsAction extends Action
{
    public function execute(Counsellor $counsellor, string $reason): void
    {
        $counsellor->forceFill(['urgent_help_available_until' => null])->save();

        HelpRequest::query()->whereStatus(HelpRequestStatusEnum::connected)->where('counsellor_id', $counsellor->id)
            ->get()
            ->each(function (HelpRequest $request) use ($reason) {
                if (CloseHelpRequestAction::new()->execute($request, HelpRequestStatusEnum::connected, HelpRequestStatusEnum::ended, $reason)) {
                    HelpBroadcast::send(new HelpRequestUpdatedEvent($request->id, HelpRequestStatusEnum::ended->value));
                }
            });
    }
}
