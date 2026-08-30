<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\DTOs\OrganizationDTO;
use App\Enums\PaginationEnum;
use App\Models\Request;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class GetOrganizationRequestQueueAction extends Action
{
    // Matches to/from being this specific organization -- covers counsellor/member invites and
    // applications (for = the Organization) and compensation-change negotiations (for = the
    // OrganizationCounsellor affiliation, but from/to still alternate between the Organization
    // and the Counsellor across rounds), without needing to touch `for` at all.
    public function execute(OrganizationDTO $dto): LengthAwarePaginator
    {
        return Request::query()
            ->wherePending()
            ->where(function ($query) use ($dto) {
                $query->whereFrom($dto->organization)->orWhereTo($dto->organization);
            })
            ->latest()
            ->paginate(PaginationEnum::preferencesPagination->value);
    }
}
