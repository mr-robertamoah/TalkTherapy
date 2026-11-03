<?php

namespace App\Actions\Visitor;

use App\Actions\Action;
use App\Models\User;
use App\Notifications\VisitorsStatusNotification;
use Illuminate\Support\Facades\Log;

// Scheduled daily: sends the super admin the visitors status. SCRUM-376: moved out of AppService,
// and no longer crashes the run when there is no super admin.
class SendVisitorsStatusToSuperAdminAction extends Action
{
    public function execute(): void
    {
        $superAdmin = User::query()->whereSuperAdmin()->first();

        if (! $superAdmin) {
            Log::warning('No super admin to send the visitors status to.');

            return;
        }

        $superAdmin->notify(new VisitorsStatusNotification);
    }
}
