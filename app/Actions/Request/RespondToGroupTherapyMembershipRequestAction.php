<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\Actions\User\AlertGuardianAction;
use App\DTOs\GuardianAlertDTO;
use App\DTOs\RequestResponseDTO;
use App\Enums\RequestStatusEnum;
use App\Notifications\GroupTherapyMembershipRequestAcceptedGuardianNotification;
use App\Notifications\GroupTherapyMembershipRequestAcceptedNotification;
use App\Notifications\GroupTherapyMembershipRequestRejectedNotification;

class RespondToGroupTherapyMembershipRequestAction extends Action
{
    public function execute(RequestResponseDTO $requestResponseDTO)
    {
        $request = $requestResponseDTO->request;
        $groupTherapy = $request->for;

        $response = is_null($requestResponseDTO->response)
            ? RequestStatusEnum::rejected->value
            : strtoupper($requestResponseDTO->response);

        // A group could have filled up between the join request being sent and it being
        // responded to -- re-check capacity at accept-time too, not just at request-time
        // (unlike RespondToTherapyAssistanceRequestAction's same-slot race, this is a capacity
        // check, so the request is simply rejected rather than marked inconsequencial).
        if ($response == RequestStatusEnum::accepted->value && $groupTherapy->users()->count() >= $groupTherapy->max_users) {
            $request->update([
                'status' => RequestStatusEnum::rejected->value,
                'data' => array_merge($request->data ?? [], [
                    'reason' => 'This group therapy has since reached its maximum number of members.',
                ]),
            ]);

            $request->from->notify(
                new GroupTherapyMembershipRequestRejectedNotification($request)
            );

            return $request->refresh();
        }

        $request->update(['status' => $response]);

        if ($response == RequestStatusEnum::accepted->value) {
            $groupTherapy->users()->attach($request->from->id, [
                'anonymous' => $groupTherapy->resolveMembershipAnonymity((bool) ($request->data['anonymous'] ?? false)),
            ]);

            $request->from->notify(
                new GroupTherapyMembershipRequestAcceptedNotification($request)
            );

            AlertGuardianAction::new()->execute(
                GuardianAlertDTO::new()->fromArray([
                    'user' => $request->from,
                    'notification' => new GroupTherapyMembershipRequestAcceptedGuardianNotification($groupTherapy),
                ])
            );

            return $request->refresh();
        }

        $request->from->notify(
            new GroupTherapyMembershipRequestRejectedNotification($request)
        );

        return $request->refresh();
    }
}
