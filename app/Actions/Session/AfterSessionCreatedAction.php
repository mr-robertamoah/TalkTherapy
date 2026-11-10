<?php

namespace App\Actions\Session;

use App\Actions\Action;
use App\Actions\Star\CreateStarAction;
use App\DTOs\CreateStarDTO;
use App\Enums\StarTypeEnum;
use App\Models\Session;
use App\Models\User;
use App\Notifications\SessionCreatedNotification;
use Illuminate\Support\Facades\Notification;

class AfterSessionCreatedAction extends Action
{
    // Shared by SessionService::createSession() and AcceptSessionScheduleProposalAction (SCRUM-207)
    // -- both create a real Session and must award the same participation star and send the same
    // notification, previously duplicated verbatim between the two call sites.
    public function execute(Session $session, User $actor)
    {
        CreateStarAction::new()->execute(
            CreateStarDTO::fromArray([
                'starredby' => null,
                'starred' => $actor,
                'starreable' => $session,
                'type' => StarTypeEnum::participation->value,
            ])
        );

        Notification::send(
            $session->for->getOtherUsers($actor),
            new SessionCreatedNotification($session)
        );
    }
}
