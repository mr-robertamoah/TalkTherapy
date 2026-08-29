<?php

namespace App\Actions\Link;

use App\Actions\Action;
use App\DTOs\CreateLinkDTO;
use App\Enums\LinkTypeEnum;
use App\Exceptions\LinkException;
use App\Models\Organization;

class EnsureUserCanCreateOrganizationSelfApplyLinkAction extends Action
{
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
    }
}
