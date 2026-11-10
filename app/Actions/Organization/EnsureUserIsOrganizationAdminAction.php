<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\DTOs\OrganizationDTO;
use App\Exceptions\OrganizationException;

class EnsureUserIsOrganizationAdminAction extends Action
{
    public function execute(OrganizationDTO $dto): void
    {
        if (is_null($dto->user)) {
            throw new OrganizationException('You must be signed in to manage an organization.', 401);
        }

        // SCRUM-170: previously paired with a preceding EnsureOrganizationExistsAction call that
        // threw a distinct 404 for "organization doesn't exist" vs this action's own 403 for
        // "exists, caller isn't its admin" -- since these routes only require plain auth (not
        // org-specific membership), that let any authenticated user enumerate real organization
        // ids by walking sequential ids and reading 404 vs 403. Folding the existence check in
        // here, behind the same 403, closes that: a nonexistent org and a real one the caller
        // can't administer are now indistinguishable. EnsureOrganizationExistsAction itself is
        // untouched and still used standalone (with its own distinct 404) by the invite/apply/
        // admin-management flows, which aren't reachable by just any authenticated user the way
        // these admin-gated GET/PATCH routes are.
        if (is_null($dto->organization) || ! $dto->organization->isAdministeredBy($dto->user)) {
            throw new OrganizationException('You are not authorized to manage this organization.', 403);
        }
    }
}
