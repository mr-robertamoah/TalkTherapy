<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\Enums\PaginationEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Models\Request;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

// SCRUM-415: the person's own applications to join organisations as a member that are still waiting --
// from a join link or the directory alike (both are the same request).
class GetMyPendingMemberApplicationsAction extends Action
{
    public function execute(User $user): LengthAwarePaginator
    {
        return Request::query()
            ->where('type', RequestTypeEnum::organizationMemberApplication->value)
            ->where('status', RequestStatusEnum::pending->value)
            ->whereFrom($user)
            ->with(['for', 'from', 'to'])
            ->latest()
            ->latest('id')
            ->paginate(PaginationEnum::preferencesPagination->value);
    }
}
