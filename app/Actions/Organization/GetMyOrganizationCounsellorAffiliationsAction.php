<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\Enums\PaginationEnum;
use App\Exceptions\CounsellorNotFoundException;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class GetMyOrganizationCounsellorAffiliationsAction extends Action
{
    public function execute(User $user): LengthAwarePaginator
    {
        $counsellor = $user->counsellor;

        if (is_null($counsellor)) {
            throw new CounsellorNotFoundException('You do not have a counsellor account.', 422);
        }

        return $counsellor->organizationCounsellors()
            ->with(['organization', 'latestCompensation'])
            ->latest()
            ->paginate(PaginationEnum::preferencesPagination->value);
    }
}
