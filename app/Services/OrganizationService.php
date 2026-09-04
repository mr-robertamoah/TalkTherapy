<?php

namespace App\Services;

use App\Actions\Organization\CreateOrganizationAction;
use App\Actions\Organization\EnsureCanRegisterOrganizationPaymentInstrumentAction;
use App\Actions\Organization\EnsureOrganizationDataIsValidAction;
use App\Actions\Organization\EnsureUserIsOrganizationAdminAction;
use App\Actions\Organization\GetMyAdministeredOrganizationsAction;
use App\Actions\Organization\GetMyOrganizationCounsellorAffiliationsAction;
use App\Actions\Organization\GetMyOrganizationMembershipsAction;
use App\Actions\Organization\GetMyOrganizationRequestQueueAction;
use App\Actions\Organization\GetOrganizationCounsellorsAction;
use App\Actions\Organization\GetOrganizationDirectoryAction;
use App\Actions\Organization\GetOrganizationMembersAction;
use App\Actions\Organization\GetOrganizationRequestQueueAction;
use App\Actions\Organization\InitiateOrganizationPaymentInstrumentRegistrationAction;
use App\Actions\Organization\UpdateOrganizationAction;
use App\DTOs\GetOrganizationDirectoryDTO;
use App\DTOs\OrganizationDTO;
use App\DTOs\OrganizationPaymentInstrumentDTO;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class OrganizationService extends Service
{
    public function createOrganization(OrganizationDTO $dto): Organization
    {
        EnsureOrganizationDataIsValidAction::new()->execute($dto);

        return CreateOrganizationAction::new()->execute($dto);
    }

    // TT-7.3b-a/SCRUM-231: mirrors PayoutService::onboardDestination()'s shape exactly -- the
    // ensure-check is called both here AND (defense in depth) inside the action itself, so a
    // future caller of the action directly (e.g. a queued job) stays safe even without going
    // through this service first.
    public function registerPaymentInstrument(OrganizationPaymentInstrumentDTO $dto): array
    {
        EnsureCanRegisterOrganizationPaymentInstrumentAction::new()->execute($dto);

        return InitiateOrganizationPaymentInstrumentRegistrationAction::new()->execute($dto);
    }

    public function updateOrganization(OrganizationDTO $dto): Organization
    {
        // SCRUM-170: no preceding EnsureOrganizationExistsAction here -- that would throw a
        // distinct 404 before EnsureUserIsOrganizationAdminAction's own 403, reopening the
        // existence-enumeration oracle that action's own null-organization check now closes.
        EnsureUserIsOrganizationAdminAction::new()->execute($dto);

        EnsureOrganizationDataIsValidAction::new()->execute($dto);

        return UpdateOrganizationAction::new()->execute($dto);
    }

    // Admin-only for now, matching this ticket's "admin CRUD" scope -- a broader
    // public/authenticated directory view (with a trimmed, public-safe field set) is a
    // separate, deliberate product decision, not an accidental side effect of only gating
    // writes.
    public function getOrganization(OrganizationDTO $dto): Organization
    {
        // SCRUM-170: see the comment on updateOrganization() above.
        EnsureUserIsOrganizationAdminAction::new()->execute($dto);

        return $dto->organization;
    }

    // Org-scoped lists, admin-only (TT-6.6a) -- the affiliated counsellors/members themselves
    // aren't in a position to browse everyone else affiliated with the org via this endpoint.
    public function getOrganizationMembers(OrganizationDTO $dto): LengthAwarePaginator
    {
        // SCRUM-170: see the comment on updateOrganization() above.
        EnsureUserIsOrganizationAdminAction::new()->execute($dto);

        return GetOrganizationMembersAction::new()->execute($dto);
    }

    public function getOrganizationCounsellors(OrganizationDTO $dto): LengthAwarePaginator
    {
        // SCRUM-170: see the comment on updateOrganization() above.
        EnsureUserIsOrganizationAdminAction::new()->execute($dto);

        return GetOrganizationCounsellorsAction::new()->execute($dto);
    }

    // Pending applications/invites/negotiations addressed to/from this org (TT-6.5a's request
    // queue, TT-6.6d) -- admin-only, same guard as the lists above.
    public function getOrganizationRequestQueue(OrganizationDTO $dto): LengthAwarePaginator
    {
        // SCRUM-170: see the comment on updateOrganization() above -- this method shares the
        // exact same pre-fix pattern as the ticket's 4 named endpoints, even though it wasn't
        // itself named there.
        EnsureUserIsOrganizationAdminAction::new()->execute($dto);

        return GetOrganizationRequestQueueAction::new()->execute($dto);
    }

    // The "separate, deliberate product decision" flagged above -- any authenticated user (not
    // just an org's own admins) may browse this, since it's how a counsellor/member discovers an
    // org to apply to in the first place (TT-6.6c, SCRUM-111 planning).
    public function getOrganizationDirectory(GetOrganizationDirectoryDTO $dto): LengthAwarePaginator
    {
        return GetOrganizationDirectoryAction::new()->execute($dto);
    }

    // "My organizations" (TT-6.6b) -- self-scoped, no cross-user data; no admin-gating action
    // needed since the query is already scoped to $user's own relations.
    public function getMyOrganizationCounsellorAffiliations(User $user): LengthAwarePaginator
    {
        return GetMyOrganizationCounsellorAffiliationsAction::new()->execute($user);
    }

    public function getMyOrganizationMemberships(User $user): LengthAwarePaginator
    {
        return GetMyOrganizationMembershipsAction::new()->execute($user);
    }

    public function getMyAdministeredOrganizations(User $user): LengthAwarePaginator
    {
        return GetMyAdministeredOrganizationsAction::new()->execute($user);
    }

    // Counsellor-facing counterpart to getOrganizationRequestQueue() above (SCRUM-167/TT-6.5b) --
    // self-scoped, so no admin-gating action needed here either.
    public function getMyOrganizationRequestQueue(User $user): LengthAwarePaginator
    {
        return GetMyOrganizationRequestQueueAction::new()->execute($user);
    }
}
