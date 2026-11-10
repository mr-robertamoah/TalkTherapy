<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\Enums\PaginationEnum;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class GetMyOrganizationMembershipsAction extends Action
{
    public function execute(User $user): LengthAwarePaginator
    {
        return $user->organizationMemberships()
            ->with(['organization', 'latestBillingConfig'])
            ->latest()
            ->paginate(PaginationEnum::preferencesPagination->value);
    }
}
