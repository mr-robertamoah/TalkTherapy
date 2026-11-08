<?php

namespace App\Actions\Help;

use App\Actions\Action;
use App\Exceptions\HelpException;
use App\Models\User;

// SCRUM-33: emergency numbers are safety-critical, so only a super administrator changes them.
class EnsureCanManageEmergencyNumbersAction extends Action
{
    public const NOT_ALLOWED = 'Only a super administrator can change emergency numbers.';

    public function execute(?User $actor): void
    {
        if (! $actor?->isSuperAdmin()) {
            throw new HelpException(self::NOT_ALLOWED, 403);
        }
    }
}
