<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\DTOs\OrganizationDTO;
use App\Enums\PaginationEnum;
use App\Models\OrganizationMember;
use Illuminate\Pagination\LengthAwarePaginator;

class GetOrganizationMembersAction extends Action
{
    public function execute(OrganizationDTO $dto): LengthAwarePaginator
    {
        return OrganizationMember::query()
            ->where('organization_id', $dto->organization->id)
            ->with(['user', 'latestBillingConfig'])
            ->latest()
            ->paginate(PaginationEnum::preferencesPagination->value);
    }
}
