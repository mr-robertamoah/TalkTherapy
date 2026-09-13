<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\DTOs\RequestResponseDTO;
use App\Enums\RequestTypeEnum;
use App\Exceptions\CannotRespondToRequestException;
use App\Models\Organization;
use App\Models\Request as ModelsRequest;
use App\Models\User;

class EnsureUserCanRespondToRequestAction extends Action
{
    public function execute(RequestResponseDTO $requestResponseDTO)
    {
        if ($this->userCanRespond($requestResponseDTO->user, $requestResponseDTO->request)) {
            return;
        }

        throw new CannotRespondToRequestException('You are not allowed to respond to this request.', 422);
    }

    // TT-4.10f/SCRUM-295: extracted so RequestResource can expose the SAME authorization answer
    // to the frontend (`dobChange.isRespondent`) instead of the frontend re-deriving its own,
    // separate "can I act on this" logic -- duplicated authority checks across layers drifting out
    // of sync is exactly how the bug this method fixes (a stale `to`-identity match not
    // re-verifying a revoked guardianship) went unnoticed in the first place.
    //
    // This is the single source of truth for "can $user respond to $request" -- every other
    // layer (RequestResource's `isRespondent`, RequestService::getRequests()'s listing query,
    // any future caller) must call this method rather than reimplement its own copy of this
    // logic, however small the copy looks at the time.
    public function userCanRespond(User $user, ModelsRequest $request): bool
    {
        $respondent = $request->to;
        // TT-4.10f/SCRUM-295 security-review finding: a dobChange request's `to` is fixed, at
        // creation time, to whichever ONE guardian happened to be picked -- an identity match
        // against it alone (the generic branch below) would let a guardian who has since had
        // their guardianship REVOKED keep responding forever, since nothing about that generic
        // check re-verifies the relationship still exists. dobChange is therefore authorized
        // entirely by a live `isGuardianOf` re-check (plus admin) instead of participating in the
        // generic identity-match branch at all -- this also covers a ward with more than one
        // active guardian (mirrors GrantVideoConsentAction's identical "any one guardian, no
        // unanimity, checked live" precedent), not just the one `to` happens to name.
        $isDobChange = $request->type === RequestTypeEnum::dobChange->value;

        return $user->isAdmin() ||
            ($isDobChange && $request->for instanceof User && $user->isGuardianOf($request->for)) ||
            (! $isDobChange && $respondent && $respondent->is($user)) ||
            // TT-7.7a/SCRUM-249 (security-engineer finding): `to` is null for a refund request
            // (RequestTypeEnum::refund's own deliberate design -- any admin may respond, matched
            // above) -- guarded here too so a NON-admin caller against a null-`to` request throws
            // the intended exception below instead of an uncaught "call to a member function on
            // null" error.
            (! $isDobChange && $respondent && $respondent->is($user->counsellor)) ||
            // SCRUM-120: an organizationCounsellorApplication request is addressed `to` the
            // Organization itself (it has no single admin), not a specific User/Counsellor --
            // any of that org's admins may respond on its behalf.
            (! $isDobChange && $respondent instanceof Organization && $respondent->isAdministeredBy($user));
    }
}
