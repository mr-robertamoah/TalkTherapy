<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\Actions\Organization\CreateOrganizationCounsellorCompensationAction;
use App\DTOs\OrganizationCounsellorCompensationDTO;
use App\DTOs\RequestResponseDTO;
use App\Enums\OrganizationCounsellorStatusEnum;
use App\Enums\RequestStatusEnum;
use App\Exceptions\OrganizationException;
use App\Models\Request;
use App\Models\User;
use App\Notifications\OrganizationCounsellorCompensationChangeAcceptedNotification;
use App\Notifications\OrganizationCounsellorCompensationChangeRejectedNotification;
use Illuminate\Support\Facades\DB;

class RespondToOrganizationCounsellorCompensationRequestAction extends Action
{
    // Who may respond, and that a pending request even exists to respond to, is already fully
    // gated upstream by EnsureUserCanRespondToRequestAction (its `to`-party + Organization-admin
    // checks already cover this type's `to` being a Counsellor) -- no bespoke authorization
    // action needed here, mirroring RespondToOrganizationCounsellorRequestAction's shape.
    public function execute(RequestResponseDTO $requestResponseDTO)
    {
        return DB::transaction(function () use ($requestResponseDTO) {
            $request = Request::query()->lockForUpdate()->findOrFail($requestResponseDTO->request->id);

            if ($request->status != RequestStatusEnum::pending->value) {
                return $request;
            }

            $status = is_null($requestResponseDTO->response)
                ? RequestStatusEnum::rejected->value
                : strtoupper($requestResponseDTO->response);

            $request->update(['status' => $status]);
            $request = $request->refresh();

            // The proposer of the accepted/rejected round, not whoever clicks accept/reject --
            // preserves SCRUM-123's "who set these terms" accountability through a negotiation
            // that may later flip direction via a counter-offer. Null-safe below for reject --
            // a decline must still succeed even if the proposer's account is since gone.
            $proposer = User::find($request->data['proposedById'] ?? null);

            if ($status === RequestStatusEnum::accepted->value) {
                $affiliation = $request->for;

                // Verified/eligible at propose time only -- the affiliation could have ended
                // since (mirrors RespondToOrganizationCounsellorRequestAction's own re-check of
                // the organization's eligibility at accept time, for the same reason).
                if ($affiliation->status === OrganizationCounsellorStatusEnum::ended->value) {
                    throw new OrganizationException('This affiliation has ended and can no longer accept compensation changes.', 422);
                }

                if (is_null($proposer)) {
                    throw new OrganizationException('The original proposer of these terms no longer exists; this proposal can no longer be accepted.', 422);
                }

                CreateOrganizationCounsellorCompensationAction::new()->execute(
                    OrganizationCounsellorCompensationDTO::new()->fromArray([
                        'user' => $proposer,
                        'organizationCounsellor' => $affiliation,
                        'type' => $request->data['type'] ?? null,
                        'amount' => $request->data['amount'] ?? null,
                        'currency' => $request->data['currency'] ?? null,
                        'percentage' => $request->data['percentage'] ?? null,
                        'basis' => $request->data['basis'] ?? null,
                    ])
                );

                $proposer->notify(new OrganizationCounsellorCompensationChangeAcceptedNotification($request));
            }

            // Reject is a flat decline -- the affiliation's status and any existing compensation
            // terms are never touched, at any round, in either direction (fairness-critical).
            if ($status === RequestStatusEnum::rejected->value) {
                $proposer?->notify(new OrganizationCounsellorCompensationChangeRejectedNotification($request));
            }

            return $request;
        });
    }
}
