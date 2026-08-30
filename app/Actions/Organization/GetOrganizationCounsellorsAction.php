<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\DTOs\OrganizationDTO;
use App\Enums\PaginationEnum;
use App\Models\OrganizationCounsellor;
use Illuminate\Pagination\LengthAwarePaginator;

class GetOrganizationCounsellorsAction extends Action
{
    public function execute(OrganizationDTO $dto): LengthAwarePaginator
    {
        return OrganizationCounsellor::query()
            ->where('organization_id', $dto->organization->id)
            ->with(['counsellor.user', 'latestCompensation'])
            ->latest()
            ->paginate(PaginationEnum::preferencesPagination->value);
    }
}
