<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\DTOs\RequestResponseDTO;
use App\Exceptions\CannotRespondToRequestException;
use App\Models\Organization;

class EnsureUserCanRespondToRequestAction extends Action
{
    public function execute(RequestResponseDTO $requestResponseDTO)
    {
        $respondent = $requestResponseDTO->request->to;
        if (
            $requestResponseDTO->user->isAdmin() ||
            // TT-7.7a/SCRUM-249 (security-engineer finding): `to` is null for a refund request
            // (RequestTypeEnum::refund's own deliberate design -- any admin may respond, matched
            // above) -- guarded here too so a NON-admin caller against a null-`to` request throws
            // the intended exception below instead of an uncaught "call to a member function on
            // null" error.
            ($respondent && $respondent->is($requestResponseDTO->user)) ||
            ($respondent && $respondent->is($requestResponseDTO->user?->counsellor)) ||
            // SCRUM-120: an organizationCounsellorApplication request is addressed `to` the
            // Organization itself (it has no single admin), not a specific User/Counsellor --
            // any of that org's admins may respond on its behalf.
            ($respondent instanceof Organization && $respondent->isAdministeredBy($requestResponseDTO->user))
        ) {
            return;
        }

        throw new CannotRespondToRequestException('You are not allowed to respond to this request.', 422);
    }
}
