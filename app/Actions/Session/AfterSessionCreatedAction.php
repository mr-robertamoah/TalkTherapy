<?php

namespace App\Actions\Session;

use App\Actions\Action;
use App\Models\Session;
use App\Models\User;
use App\Notifications\SessionCreatedNotification;
use Illuminate\Support\Facades\Notification;

class AfterSessionCreatedAction extends Action
{
    // Shared by SessionService::createSession() and AcceptSessionScheduleProposalAction (SCRUM-207)
    // -- both create a real Session and must send the same notification, previously duplicated verbatim between the
    // two call sites. SCRUM-471: creating a session no longer earns a star; holding it does (AwardSessionHeldStarAction).
    public function execute(Session $session, User $actor)
    {
        Notification::send(
            $session->otherParticipants($actor),
            new SessionCreatedNotification($session)
        );
    }
}
