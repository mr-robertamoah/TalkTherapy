<?php

namespace App\Actions\Help;

use App\Actions\Action;
use App\Actions\Star\AwardCounsellorStarAction;
use App\Enums\HelpRequestStatusEnum;
use App\Enums\StarSourceEnum;
use App\Models\HelpRequest;
use App\Support\SessionParticipation;

// TT-7.11b/SCRUM-471: a help chat that really took place (SessionParticipation::helpChatQualifies -- both sides wrote,
// it lasted long enough, and it wasn't cut short by the counsellor's suspension or revoked verification) earns the
// counsellor who answered it one star, from the person (the per-client daily cap), under the help-chat daily cap. Key
// help_chat_held:{session}. Called after commit by CloseHelpRequestAction when a connected chat ends.
class AwardHelpChatStarAction extends Action
{
    public function execute(HelpRequest $request): void
    {
        $request = $request->fresh(['session', 'counsellor', 'user']);

        if (! $request || $request->status !== HelpRequestStatusEnum::ended || ! $request->counsellor || ! $request->user) {
            return;
        }

        if (! SessionParticipation::helpChatQualifies($request)) {
            return;
        }

        AwardCounsellorStarAction::new()->execute(
            $request->counsellor, StarSourceEnum::helpChatHeld, $request->session, "help_chat_held:{$request->session_id}", $request->user,
        );
    }
}
