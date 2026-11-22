<?php

namespace App\Actions\Session;

use App\Actions\Action;
use App\Actions\Star\AwardCounsellorStarAction;
use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Enums\SessionStatusEnum;
use App\Enums\StarSourceEnum;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;
use App\Support\SessionParticipation;
use App\Support\SessionPayments;
use Illuminate\Support\Collection;

// TT-7.11b/SCRUM-471: a session held for the first time earns its counsellor(s) one star -- a 1:1 session its
// therapy's counsellor, a group session every counsellor currently active in the group -- if both sides took part
// (SessionParticipation) and its payment, if it had one, wasn't already refunded (SessionPayments). Free sessions earn
// the same as paid ones. The star's starredby is a 1:1 session's client, for the per-client daily cap. Key
// session_held:{session}, unique per counsellor, so a second call gives nobody a second star. Help chats are starred by
// AwardHelpChatStarAction instead. Called after commit by ChangeSessionStatusAction (first time held).
class AwardSessionHeldStarAction extends Action
{
    public function execute(Session $session): void
    {
        $session = $session->fresh();

        if (! $session || $session->is_help_chat || $session->status !== SessionStatusEnum::held->value) {
            return;
        }

        if (! SessionParticipation::bothSidesTookPart($session) || SessionPayments::refunded($session)) {
            return;
        }

        $client = SessionParticipation::clientOf($session);

        foreach ($this->counsellors($session) as $counsellor) {
            AwardCounsellorStarAction::new()->execute(
                $counsellor, StarSourceEnum::sessionHeld, $session, "session_held:{$session->id}", $client,
            );
        }
    }

    // AwardCounsellorStarAction skips any who are gone, suspended or not verified.
    private function counsellors(Session $session): Collection
    {
        $for = $session->for;

        if ($for instanceof Therapy) {
            return collect([$for->counsellor])->filter();
        }

        if ($for instanceof GroupTherapy) {
            return $for->counsellors()
                ->wherePivot('state', CounsellorGroupTherapyStateEnum::active->value)
                ->get();
        }

        return collect();
    }
}
