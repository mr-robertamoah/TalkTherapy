<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\DTOs\OrganizationDTO;
use App\Enums\LinkStateEnum;
use App\Enums\LinkTypeEnum;
use App\Exceptions\LinkException;
use App\Models\Link;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

// SCRUM-415: an admin switches off one of the organisation's join links -- say, one sent to the wrong
// person. Locked like using it (PerformOrganizationSelfApplyLinkAction::applyByLink), so it is either used
// or revoked, never both. A revoked link is never switched back on (ChangeLinkStatusAction).
class RevokeOrganizationJoinLinkAction extends Action
{
    public function execute(OrganizationDTO $dto, mixed $linkId): Link
    {
        return DB::transaction(function () use ($dto, $linkId) {
            $link = Link::query()->lockForUpdate()->find($linkId);

            // Another organisation's link, or another kind of link, is told the same as a missing one.
            if (
                ! $link ||
                $link->type !== LinkTypeEnum::organizationSelfApply->value ||
                $link->for_type !== Organization::class ||
                (int) $link->for_id !== $dto->organization->id
            ) {
                throw new LinkException('This join link could not be found.', 404);
            }

            if ($link->state !== LinkStateEnum::active->value) {
                throw LinkException::inactive();
            }

            $link->deactivate();

            return $link;
        });
    }
}
