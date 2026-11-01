<?php

namespace App\Actions\Suspension;

use App\Actions\Action;
use App\Actions\Report\EnsureAdminCanReviewReportAction;
use App\Enums\ReportOutcomeEnum;
use App\Enums\ReportStatusEnum;
use App\Enums\SuspensionScopeEnum;
use App\Exceptions\SuspensionException;
use App\Models\Report;
use App\Models\User;

// SCRUM-359: who may suspend whom. A super administrator who isn't part of the report, from a
// report resolved with "suspension recommended", against the one person the report is about --
// never themselves and never another super administrator. Called before and again on the locked
// report row inside ApplySuspensionAction.
class EnsureCanApplySuspensionAction extends Action
{
    public const SUPER_ADMIN_ONLY = 'Only a super administrator can suspend someone.';

    public function execute(?User $admin, ?Report $report, SuspensionScopeEnum $scope): User
    {
        EnsureAdminCanReviewReportAction::new()->execute($admin, $report);

        if (! $admin->isSuperAdmin()) {
            throw new SuspensionException(self::SUPER_ADMIN_ONLY, 403);
        }

        if ($report->status != ReportStatusEnum::resolved || $report->outcome != ReportOutcomeEnum::suspension_recommended) {
            throw new SuspensionException('Resolve the report with the outcome "suspension recommended" first.', 422);
        }

        $target = $report->reportedUser();

        if (! $target) {
            throw new SuspensionException('Record who this report is about before suspending anyone.', 422);
        }

        if ($target->is($admin)) {
            throw new SuspensionException('You cannot suspend yourself.', 422);
        }

        if ($target->isSuperAdmin()) {
            throw new SuspensionException('A super administrator cannot be suspended.', 422);
        }

        if ($scope == SuspensionScopeEnum::counsellor && ! $target->counsellor) {
            throw new SuspensionException('This person has no counsellor profile to suspend.', 422);
        }

        if ($target->forgetActiveSuspension()->activeSuspension($scope)) {
            throw new SuspensionException('This person is already suspended.', 422);
        }

        return $target;
    }
}
