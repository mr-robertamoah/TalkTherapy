<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\DTOs\OrganizationDTO;
use App\Enums\LinkTypeEnum;
use App\Enums\PaginationEnum;
use App\Models\Link;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

// SCRUM-415: every join link made for the organisation, by any of its admins, newest first.
class GetOrganizationJoinLinksAction extends Action
{
    public function execute(OrganizationDTO $dto): LengthAwarePaginator
    {
        return Link::query()
            ->whereType(LinkTypeEnum::organizationSelfApply->value)
            ->whereFor($dto->organization)
            ->with('addedby')
            ->latest()
            ->latest('id')
            ->paginate(PaginationEnum::preferencesPagination->value);
    }
}
