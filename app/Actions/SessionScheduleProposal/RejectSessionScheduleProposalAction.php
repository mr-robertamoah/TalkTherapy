<?php

namespace App\Actions\SessionScheduleProposal;

use App\Actions\Action;
use App\DTOs\RequestResponseDTO;
use App\Enums\RequestStatusEnum;
use App\Models\Request;
use App\Models\User;
use App\Notifications\SessionScheduleProposalRejectedNotification;
use Illuminate\Support\Facades\DB;

class RejectSessionScheduleProposalAction extends Action
{
    // Covers both a bare reject and "Option C"'s third choice (reject with a reason such as
    // "please propose a new time") -- $requestResponseDTO->reason is optional and, when present,
    // is merged into data.reason so the client-side UI can show it, mirroring
    // RespondToGroupTherapyMembershipRequestAction's identical data.reason pattern for an
    // auto-rejected request.
    public function execute(RequestResponseDTO $requestResponseDTO): Request
    {
        $request = DB::transaction(function () use ($requestResponseDTO) {
            $request = Request::query()->lockForUpdate()->findOrFail($requestResponseDTO->request->id);

            if ($request->status !== RequestStatusEnum::pending->value) {
                return $request;
            }

            $request->update([
                'status' => RequestStatusEnum::rejected->value,
                'data' => $requestResponseDTO->reason
                    ? array_merge($request->data, ['reason' => $requestResponseDTO->reason])
                    : $request->data,
            ]);

            return $request->refresh();
        });

        $proposer = User::find($request->data['proposedById'] ?? null);
        $proposer?->notify(new SessionScheduleProposalRejectedNotification($request));

        return $request;
    }
}
