<?php

namespace App\Actions\Notification;

use App\Actions\Action;
use App\Models\User;

// SCRUM-39 (TT-5.4d): a person chooses how long their notifications are kept -- one of the options, or
// null for the platform default. Applies from the next nightly prune.
class SetNotificationRetentionAction extends Action
{
    public function execute(User $user, ?int $days): void
    {
        $user->forceFill(['notification_retention_days' => $days])->save();
    }
}
