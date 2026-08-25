<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\Actions\User\AlertGuardianAction;
use App\DTOs\GuardianAlertDTO;
use App\DTOs\RequestResponseDTO;
use App\Enums\RequestStatusEnum;
use App\Enums\TherapyStatusEnum;
use App\Models\Counsellor;
use App\Models\Request;
use App\Models\Therapy;
use App\Notifications\TherapyAssistanceRequestAcceptedGuardianNotification;
use App\Notifications\TherapyAssistanceRequestAcceptedNotification;
use Illuminate\Support\Facades\DB;

class RespondToTherapyAssistanceRequestAction extends Action
{
    public function execute(RequestResponseDTO $requestResponseDTO)
    {
        // Locking the request row and re-checking its status inside the lock closes the
        // double-submission race SCRUM-80 fixed for group therapy membership requests. But a
        // therapy can have several other pending assistance requests (from other counsellors)
        // at once, so the *therapy* row -- not just this request -- is the shared mutable
        // resource: two different pending requests for the same therapy being accepted at
        // nearly the same time could otherwise both see no counsellor assigned yet and both
        // "win", losing one of the two counsellor_id writes and notifying both requesters that
        // their acceptance succeeded (SCRUM-91). Locking the therapy row FIRST, before the
        // request row, keeps every path through here using the same lock order as the
        // sibling-invalidation update below (which itself locks other Request rows) -- taking
        // the request lock first here instead would let a blocked second call hold its own
        // request row while waiting on the therapy lock, while the first call (holding the
        // therapy lock) waits on that same request row for the sibling update: a deadlock.
        [$request, $accepted] = DB::transaction(function () use ($requestResponseDTO) {
            $therapy = Therapy::query()->lockForUpdate()->findOrFail($requestResponseDTO->request->for_id);
            $request = Request::query()->lockForUpdate()->findOrFail($requestResponseDTO->request->id);

            if ($request->status != RequestStatusEnum::pending->value) {
                return [$request, false];
            }

            // TODO counsellor must have a certain number of free therapies
            $response = is_null($requestResponseDTO->response)
                ? RequestStatusEnum::rejected->value
                : strtoupper($requestResponseDTO->response);

            if ($therapy->counsellor) {
                $response = RequestStatusEnum::rejected->value;
            }

            $request->update(['status' => $response]);

            if ($response == RequestStatusEnum::accepted->value) {
                $therapy->update([
                    'counsellor_id' => $this->getCounsellorId($requestResponseDTO),
                    'status' => TherapyStatusEnum::in_session->value,
                ]);

                Request::query()
                    ->whereNot('id', $request->id)
                    ->wherePending()
                    ->whereFor($therapy)
                    ->update([
                        'status' => RequestStatusEnum::inconsequencial->value,
                        'data' => [
                            'reason' => 'A similar request for therapy assistance has been accepted by someone else.',
                        ],
                    ]);
            }

            return [$request->refresh(), $response == RequestStatusEnum::accepted->value];
        });

        if ($accepted) {
            // TODO dispatch counsellor to frontend
            $request->from->notify(
                new TherapyAssistanceRequestAcceptedNotification($request)
            );

            AlertGuardianAction::new()->execute(
                GuardianAlertDTO::new()->fromArray([
                    'user' => $request->from::class == Counsellor::class
                        ? $request->from->user
                        : $request->from,
                    'notification' => new TherapyAssistanceRequestAcceptedGuardianNotification(
                        $request->for
                    ),
                ])
            );
        }

        return $request;
    }

    private function getCounsellorId(RequestResponseDTO $requestResponseDTO)
    {
        if (
            $requestResponseDTO->user->counsellor &&
            $requestResponseDTO->user->counsellor->is($requestResponseDTO->request->to)
        ) {
            return $requestResponseDTO->user->counsellor->id;
        }

        if (
            $requestResponseDTO->request->to->is($requestResponseDTO->user) &&
            $requestResponseDTO->request->from_type == Counsellor::class
        ) {
            return $requestResponseDTO->request->from->id;
        }

        return null;
    }
}
