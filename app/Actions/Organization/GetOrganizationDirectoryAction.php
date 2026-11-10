<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\DTOs\GetOrganizationDirectoryDTO;
use App\Enums\PaginationEnum;
use App\Models\Organization;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class GetOrganizationDirectoryAction extends Action
{
    // Verified-only (2026-08-29 decision): an unverified organization stays invisible to
    // browse/apply until a platform admin verifies it, mirroring how counsellor verification
    // already gates visibility elsewhere in this app.
    public function execute(GetOrganizationDirectoryDTO $dto): LengthAwarePaginator
    {
        return Organization::query()
            // logoFile, not logo -- Organization::logo() was replaced by a tagged fileables
            // MorphToMany + accessor (SCRUM-182/TT-10.4); this still needs the same eager load
            // to avoid the endpoint N+1ing per organization (see Counsellor's TT-10.2 for why).
            ->with('logoFile')
            ->whereNotNull('verified_at')
            ->when(! is_null($dto->isProvider), function ($query) use ($dto) {
                $query->where('is_provider', $dto->isProvider);
            })
            ->when(! is_null($dto->isConsumer), function ($query) use ($dto) {
                $query->where('is_consumer', $dto->isConsumer);
            })
            ->latest()
            ->paginate(PaginationEnum::preferencesPagination->value);
    }
}
