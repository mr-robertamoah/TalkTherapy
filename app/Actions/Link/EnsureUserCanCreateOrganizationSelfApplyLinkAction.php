<?php

namespace App\Actions\Link;

use App\Actions\Action;
use App\DTOs\CreateLinkDTO;
use App\Enums\LinkTypeEnum;
use App\Exceptions\LinkException;
use App\Models\Organization;

class EnsureUserCanCreateOrganizationSelfApplyLinkAction extends Action
{
    public const NOT_ACCEPTING = 'Join links need a verified consumer organization with "Allow members to self-apply" turned on.';

    // A no-op for every other link type -- the generic createLink() flow has no per-type
    // authorization hook of its own (EnsureAddedbyIsValidAction only checks the addedby
    // identity, not whether the acting user may create a link FOR the given target), so without
    // this any authenticated user could otherwise generate a working self-apply link for an
    // organization they don't administer.
    public function execute(CreateLinkDTO $dto): void
    {
        if ($dto->type !== LinkTypeEnum::organizationSelfApply->value) {
            return;
        }

        if (! $dto->for instanceof Organization || ! $dto->for->isAdministeredBy($dto->user)) {
            throw new LinkException('You are not authorized to generate a self-apply link for this organization.', 403);
        }

        // SCRUM-214: in the admin's own name. EnsureAddedbyIsValidAction lets a platform administrator name anyone,
        // and the generic "addedby is the requester" check (EnsureUserCanCreateLinkForAction) skips this type.
        if ($dto->addedby && ! $dto->addedby->is($dto->user)) {
            throw new LinkException('You are not authorized to generate a self-apply link for this organization.', 403);
        }

        // SCRUM-214: never a link that can't work -- using one needs a verified consumer organization with self-apply
        // turned on (OrganizationMemberRequestService::applyAsMember), so say so to the admin now, not to whoever
        // later opens the link.
        if (! $dto->for->acceptsMemberApplications()) {
            throw new LinkException(self::NOT_ACCEPTING, 422);
        }
    }
}
