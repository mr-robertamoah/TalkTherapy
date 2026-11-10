<?php

namespace App\Actions\Report;

use App\Actions\Action;
use App\Exceptions\ReportException;
use App\Models\Report;
use App\Models\User;

// SCRUM-340: every admin review step (changing status, naming the reported party, unlocking
// confidential messages) needs an admin who isn't part of the report. A non-admin gets the same
// 404 as a missing report, so these routes can't be used to probe which reports exist.
class EnsureAdminCanReviewReportAction extends Action
{
    public const NOT_FOUND = 'Report not found.';

    public const INVOLVED = 'You are part of this report, so another administrator has to review it.';

    public function execute(?User $admin, ?Report $report): void
    {
        if (! $report || ! $admin?->isAdmin()) {
            throw new ReportException(self::NOT_FOUND, 404);
        }

        if ($report->involves($admin)) {
            throw new ReportException(self::INVOLVED, 403);
        }
    }
}
