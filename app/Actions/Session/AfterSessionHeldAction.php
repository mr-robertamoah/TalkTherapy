<?php

namespace App\Actions\Session;

use App\Actions\Action;
use App\Actions\Star\AwardCounsellorStarAction;
use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Enums\StarSourceEnum;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;
use Illuminate\Support\Collection;

// TT-7.11b/SCRUM-471: what follows a session being held for the first time -- its counsellor(s) earn one star for it.
// A 1:1 session (a help chat's included) stars its therapy's counsellor; a group session stars every counsellor
// currently active in the group. The award key is per session and unique per counsellor, so calling this again for
// the same session gives nobody a second star. Callers: ChangeSessionStatusAction (first-time held) and
// CloseHelpRequestAction (a connected help chat closing).
class AfterSessionHeldAction extends Action
{
    public function execute(Session $session): void
    {
        foreach ($this->counsellors($session) as $counsellor) {
            AwardCounsellorStarAction::new()->execute(
                $counsellor, StarSourceEnum::sessionHeld, $session, "session_held:{$session->id}",
            );
        }
    }

    // A counsellor who has since deleted their account (soft-deleted) earns nothing.
    private function counsellors(Session $session): Collection
    {
        $for = $session->for;

        if ($for instanceof Therapy) {
            $counsellor = $for->counsellor;

            return $counsellor && ! $counsellor->trashed() ? collect([$counsellor]) : collect();
        }

        if ($for instanceof GroupTherapy) {
            return $for->counsellors()
                ->wherePivot('state', CounsellorGroupTherapyStateEnum::active->value)
                ->get();
        }

        return collect();
    }
}
