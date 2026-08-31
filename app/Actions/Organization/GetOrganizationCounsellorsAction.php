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
            // counsellor.avatarFile: CounsellorMiniResource reads $counsellor->avatar, and unlike
            // the old nullable avatar_id belongsTo (which skipped the query entirely when null),
            // the tagged fileables MorphToMany always queries unless eager-loaded (SCRUM-182/TT-10.2).
            ->with(['counsellor.user', 'counsellor.avatarFile', 'latestCompensation'])
            ->latest()
            ->paginate(PaginationEnum::preferencesPagination->value);
    }
}
