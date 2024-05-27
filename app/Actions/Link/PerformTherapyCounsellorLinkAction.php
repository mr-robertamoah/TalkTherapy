<?php

namespace App\Actions\Link;

use App\Actions\Action;
use App\Actions\User\AlertGuardianAction;
use App\DTOs\CreateLinkDTO;
use App\DTOs\GuardianAlertDTO;
use App\Enums\RequestStatusEnum;
use App\Models\Request;
use App\Notifications\TherapyAssistanceLinkNotification;
use App\Notifications\TherapyAssistanceRequestAcceptedGuardianNotification;
use Illuminate\Support\Facades\Redirect;

class PerformTherapyCounsellorLinkAction extends Action
{
    public function execute(CreateLinkDTO $createLinkDTO)
    {
        $createLinkDTO->link->for->update([
            'counsellor_id' => $createLinkDTO->user->counsellor->id
        ]);

        Request::query()
            ->wherePending()
            ->whereFor($createLinkDTO->link->for)
            ->update([
                'status' => RequestStatusEnum::inconsequencial->value,
                'data' => [
                    'reason' => 'A similar request for therapy assistance has been accepted by someone else.'
                ]
            ]);

        $createLinkDTO->link->addedby->notify(
            new TherapyAssistanceLinkNotification($createLinkDTO->link->for)
        );

        AlertGuardianAction::new()->execute(
            GuardianAlertDTO::new()->fromArray([
                'user' => $createLinkDTO->link->addedby,
                'notification' => new TherapyAssistanceRequestAcceptedGuardianNotification(
                    $createLinkDTO->link->for
                )
            ])
        );

        return Redirect::route('therapies.get', ['therapyId' => $createLinkDTO->link->for->id]);
    }
}