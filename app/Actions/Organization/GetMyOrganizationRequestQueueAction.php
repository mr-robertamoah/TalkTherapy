<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\Enums\PaginationEnum;
use App\Enums\RequestTypeEnum;
use App\Exceptions\CounsellorNotFoundException;
use App\Models\Request;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class GetMyOrganizationRequestQueueAction extends Action
{
    // Unlike GetOrganizationRequestQueueAction's Organization-scoped equivalent, an explicit
    // type filter is required here: a Counsellor is also the polymorphic from/to party for
    // unrelated request types (therapy assistance, discussion invites, counsellor verification),
    // so "from/to is this counsellor" alone would leak those into this org-specific queue too.
    public function execute(User $user): LengthAwarePaginator
    {
        $counsellor = $user->counsellor;

        if (is_null($counsellor)) {
            throw new CounsellorNotFoundException('You do not have a counsellor account.', 422);
        }

        return Request::query()
            ->whereIn('type', [
                RequestTypeEnum::organizationCounsellorInvite->value,
                RequestTypeEnum::organizationCounsellorApplication->value,
                RequestTypeEnum::organizationCounsellorCompensationChange->value,
            ])
            ->wherePending()
            ->where(function ($query) use ($counsellor) {
                $query->whereFrom($counsellor)->orWhereTo($counsellor);
            })
            ->latest()
            ->paginate(PaginationEnum::preferencesPagination->value);
    }
}
