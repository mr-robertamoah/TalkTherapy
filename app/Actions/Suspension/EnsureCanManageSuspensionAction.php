<?php

namespace App\Actions\Suspension;

use App\Actions\Action;
use App\Exceptions\SuspensionException;
use App\Models\Suspension;
use App\Models\User;

// SCRUM-359: lifting a suspension, or deciding its appeal, needs a super administrator who isn't
// part of the report it came from. Anyone else gets the same 404 as a missing suspension.
class EnsureCanManageSuspensionAction extends Action
{
    public const NOT_FOUND = 'Suspension not found.';

    public function execute(?User $admin, ?Suspension $suspension): void
    {
        if (! $suspension || ! $admin?->isSuperAdmin()) {
            throw new SuspensionException(self::NOT_FOUND, 404);
        }

        if ($suspension->involves($admin)) {
            throw new SuspensionException('You are part of the report behind this suspension, so another administrator has to handle it.', 403);
        }
    }
}
