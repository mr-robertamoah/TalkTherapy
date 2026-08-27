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
            $respondent->is($requestResponseDTO->user) ||
            $respondent->is($requestResponseDTO->user?->counsellor) ||
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
