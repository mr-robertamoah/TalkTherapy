<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\Enums\PaginationEnum;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class GetMyAdministeredOrganizationsAction extends Action
{
    public function execute(User $user): LengthAwarePaginator
    {
        // Qualified column, for clarity/future-proofing -- both `organizations` and the
        // `organization_admins` pivot (via withTimestamps()) have a created_at column. Today
        // Eloquent aliases the pivot's copy (pivot_created_at) so an unqualified order-by isn't
        // actually ambiguous, but that's an implementation detail of how withTimestamps() selects
        // pivot columns, not a guarantee -- qualifying it here means this doesn't silently break
        // if that ever changes (verified during review, SCRUM-160).
        return $user->administeredOrganizations()
            ->orderByDesc('organizations.created_at')
            ->paginate(PaginationEnum::preferencesPagination->value);
    }
}
