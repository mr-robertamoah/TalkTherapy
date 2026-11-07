<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\Actions\GroupTherapy\EnsureCanSendGroupTherapyAssistanceRequestAction;
use App\DTOs\CreateRequestDTO;
use App\DTOs\TherapyAssistanceRequestDTO;
use App\Enums\RequestTypeEnum;
use App\Exceptions\TherapyException;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Notifications\GroupTherapyAssistanceRequestSentNotification;
use App\Notifications\TherapyAssistanceRequestSentNotification;

class SendTherapyAssistanceRequestAction extends Action
{
    public function execute(TherapyAssistanceRequestDTO $therapyAssistanceRequestDTO)
    {
        if (
            is_null($therapyAssistanceRequestDTO->from) ||
            is_null($therapyAssistanceRequestDTO->for) ||
            is_null($therapyAssistanceRequestDTO->to)
        ) {
            return null;
        }

        $isGroup = $therapyAssistanceRequestDTO->for::class == GroupTherapy::class;

        // A group's creator inviting counsellors (a list of ids); SCRUM-389: a counsellor's offer to
        // help a group is a single request to its creator, below.
        if ($isGroup && is_array($therapyAssistanceRequestDTO->to)) {
            return $this->sendMultipleRequests($therapyAssistanceRequestDTO);
        }

        $request = CreateRequestAction::new()->execute(
            CreateRequestDTO::new()->fromArray([
                'from' => $therapyAssistanceRequestDTO->from,
                'to' => $therapyAssistanceRequestDTO->to,
                'for' => $therapyAssistanceRequestDTO->for,
                'type' => $isGroup ? RequestTypeEnum::groupTherapy->value : RequestTypeEnum::therapy->value,
                'data' => $therapyAssistanceRequestDTO->for->payment_data ?? [], // null for a therapy with no payment terms; requests.data is NOT NULL (SCRUM-344)
            ])
        );

        $therapyAssistanceRequestDTO->to->notify($isGroup
            ? new GroupTherapyAssistanceRequestSentNotification($request)
            : new TherapyAssistanceRequestSentNotification($request));

        return $request;
    }

    private function sendMultipleRequests(TherapyAssistanceRequestDTO $therapyAssistanceRequestDTO)
    {
        $requests = [];

        foreach ($therapyAssistanceRequestDTO->to as $key => $value) {
            $counsellor = Counsellor::find($value);

            // Never a silent partial send (security review): the caller's transaction undoes the rest.
            if (! $counsellor) {
                throw new TherapyException(EnsureCanSendGroupTherapyAssistanceRequestAction::COUNSELLOR_UNAVAILABLE, 422);
            }

            $request = CreateRequestAction::new()->execute(
                CreateRequestDTO::new()->fromArray([
                    'from' => $therapyAssistanceRequestDTO->from,
                    'to' => $counsellor,
                    'for' => $therapyAssistanceRequestDTO->for,
                    'type' => RequestTypeEnum::groupTherapy->value,
                    'data' => $therapyAssistanceRequestDTO->for->payment_data ?? [], // null for a therapy with no payment terms; requests.data is NOT NULL (SCRUM-344)
                ])
            );

            $request->to->notify(
                new GroupTherapyAssistanceRequestSentNotification($request)
            );

            $requests[] = $request;
        }

        return $requests;
    }
}
