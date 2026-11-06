<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\Actions\SafeguardingException\EnsureCanManageSafeguardingExceptionAction;
use App\DTOs\RequestResponseDTO;
use App\Enums\RequestTypeEnum;
use App\Exceptions\CannotRespondToRequestException;
use App\Models\Organization;
use App\Models\Request as ModelsRequest;
use App\Models\User;
use App\Support\AdminRequestQueue;

class EnsureUserCanRespondToRequestAction extends Action
{
    public const NOT_ALLOWED = 'You are not allowed to respond to this request.';

    public function execute(RequestResponseDTO $requestResponseDTO)
    {
        if ($this->userCanRespond($requestResponseDTO->user, $requestResponseDTO->request)) {
            return;
        }

        throw new CannotRespondToRequestException(self::NOT_ALLOWED, 422);
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
    // SCRUM-384: $manage lets a list share one remembering() instance (RequestResource), so the
    // super administrator's "do they counsel this minor" is read once per minor, not per row.
    public function userCanRespond(User $user, ModelsRequest $request, ?EnsureCanManageSafeguardingExceptionAction $manage = null): bool
    {
        // SCRUM-381 (the user's decision): only the super administrator answers a counsellor's
        // request for a safeguarding exception -- never another administrator (the admin queue
        // branch below), never the counsellor who sent it, and (SCRUM-382 review) never one who
        // counsels the minor: the same rule as granting one (EnsureCanManageSafeguardingExceptionAction).
        if ($request->type === RequestTypeEnum::safeguardingException->value) {
            $manage ??= EnsureCanManageSafeguardingExceptionAction::new();

            // allowsClosing(): a request whose minor's row is gone can still be rejected (accepting
            // it is then refused by GrantSafeguardingExceptionAction).
            return $manage->allowsClosing($user, $request->safeguardingWard())
                && ! $manage->sentBy($request, $user);
        }

        // SCRUM-387 (security review): an administrator answers only what waits on an
        // administrator (App\Support\AdminRequestQueue) -- never, as before, any request at all on
        // someone's behalf (a guardianship, a group membership, an organisation flow, a schedule
        // proposal...). Every other type needs its real party, administrator or not.
        if ($user->isAdmin() && $this->adminMayAnswer($user, $request)) {
            return true;
        }

        $respondent = $request->to;
        // TT-4.10f/SCRUM-295 security-review finding: a dobChange request's `to` is fixed, at
        // creation time, to whichever ONE guardian happened to be picked -- an identity match
        // against it alone (the generic branch below) would let a guardian who has since had
        // their guardianship REVOKED keep responding forever, since nothing about that generic
        // check re-verifies the relationship still exists. dobChange is therefore authorized
        // entirely by a live `isGuardianOf` re-check instead of participating in the generic
        // identity-match branch at all -- this also covers a ward with more than one active
        // guardian (mirrors GrantVideoConsentAction's identical "any one guardian, no unanimity,
        // checked live" precedent), not just the one `to` happens to name.
        $isDobChange = $request->type === RequestTypeEnum::dobChange->value;

        return ($isDobChange && $request->for instanceof User && $user->isGuardianOf($request->for)) ||
            (! $isDobChange && $respondent && $respondent->is($user)) ||
            // TT-7.7a/SCRUM-249 (security-engineer finding): `to` is null for a refund request
            // (any admin may respond, adminMayAnswer() above) -- guarded here too so a NON-admin
            // caller against a null-`to` request is refused instead of an uncaught "call to a
            // member function on null" error.
            (! $isDobChange && $respondent && $respondent->is($user->counsellor)) ||
            // SCRUM-120: an organizationCounsellorApplication request is addressed `to` the
            // Organization itself (it has no single admin), not a specific User/Counsellor --
            // any of that org's admins may respond on its behalf.
            (! $isDobChange && $respondent instanceof Organization && $respondent->isAdministeredBy($user));
    }

    // The admin queue's types, as the queue lists them: a dob change only with no guardian (`to`),
    // and (TT-4.11d/SCRUM-305 review finding) never an administrator's own request, e.g. their own age verification -- the
    // whole point of that type is an INDEPENDENT check on a user's self-attestation. The safeguarding
    // exception is decided above, by its own rule.
    private function adminMayAnswer(User $user, ModelsRequest $request): bool
    {
        if (! AdminRequestQueue::includes($request->type)) {
            return false;
        }

        if (AdminRequestQueue::unaddressedOnly([$request->type]) && ! is_null($request->to_id)) {
            return false;
        }

        // SCRUM-387 security re-check: never their own -- not just an age verification, but a
        // counsellor verification, refund or dob change they raised or that is about them.
        return ! $request->isOwnAgeVerificationOf($user) && ! $request->isOwnRequestOf($user);
    }
}
