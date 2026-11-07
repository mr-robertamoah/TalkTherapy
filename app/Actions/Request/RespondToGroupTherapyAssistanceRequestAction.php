<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\Actions\GroupTherapy\AddCounsellorToGroupTherapyAction;
use App\Actions\Suspension\EnsureCanActAsCounsellorAction;
use App\DTOs\RequestResponseDTO;
use App\Enums\RequestStatusEnum;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Request;
use App\Notifications\TherapyAssistanceRequestAcceptedNotification;
use Illuminate\Support\Facades\DB;

// SCRUM-358: a group creator invites counsellors when they set up the group (one group-therapy
// assistance request each). Accepting one used to go through the individual-therapy action, which
// loads a Therapy and so always failed. Product decision (2026-11-01): accepting adds the counsellor
// as an active, normal-role counsellor -- unless the group is already full, which is refused with a
// clear message and leaves the invite pending. Once the group fills up, the remaining invites close.
// SCRUM-389: also the other direction -- a counsellor's offer to help (from them, to the creator's
// User), accepted by the creator. The counsellor is locked after the group and the request (the
// SCRUM-31 lock order) and re-checked (verification, suspension); a counsellor creator accepting must
// not be suspended either.
class RespondToGroupTherapyAssistanceRequestAction extends Action
{
    public const GROUP_FULL = AddCounsellorToGroupTherapyAction::GROUP_FULL;

    public const NOT_ALLOWED = AddCounsellorToGroupTherapyAction::NOT_ALLOWED;

    public function execute(RequestResponseDTO $requestResponseDTO): Request
    {
        // Same lock order as RespondToTherapyAssistanceRequestAction: the group first (the shared
        // resource two simultaneous accepts compete for), then the request.
        [$request, $accepted] = DB::transaction(function () use ($requestResponseDTO) {
            $group = GroupTherapy::query()->lockForUpdate()->findOrFail($requestResponseDTO->request->for_id);
            $request = Request::query()->lockForUpdate()->findOrFail($requestResponseDTO->request->id);

            if ($request->status != RequestStatusEnum::pending->value) {
                return [$request, false];
            }

            $response = is_null($requestResponseDTO->response)
                ? RequestStatusEnum::rejected->value
                : strtoupper($requestResponseDTO->response);

            if ($response != RequestStatusEnum::accepted->value) {
                $request->update(['status' => $response]);

                return [$request, false];
            }

            // SCRUM-383: the same step a counsellor invitation link uses.
            $isOffer = $request->isAssistanceOffer();
            $counsellorId = $request->assistanceCounsellor()?->id;
            $counsellor = $counsellorId ? Counsellor::query()->lockForUpdate()->find($counsellorId) : null;

            if ($isOffer) {
                EnsureCanActAsCounsellorAction::new()->execute($counsellor, asTarget: true);
                EnsureCanActAsCounsellorAction::new()->execute($group->creatorCounsellor());
            }

            AddCounsellorToGroupTherapyAction::new()->execute(
                $group,
                $counsellor,
                $requestResponseDTO->user,
                exceptRequestId: $request->id,
                offer: $isOffer ? $request : null,
            );

            $request->update(['status' => $response]);

            return [$request->refresh(), true];
        });

        if ($accepted) {
            $request->from->notify(new TherapyAssistanceRequestAcceptedNotification($request));
        }

        return $request;
    }
}
