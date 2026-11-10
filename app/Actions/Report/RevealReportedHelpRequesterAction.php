<?php

namespace App\Actions\Report;

use App\Actions\Action;
use App\Actions\Admin\RecordAdminContentReadAction;
use App\Enums\ReportOutcomeEnum;
use App\Enums\ReportStatusEnum;
use App\Exceptions\ReportException;
use App\Models\AdminContentAccess;
use App\Models\Report;
use App\Models\User;

// SCRUM-398: an administrator reviewing a report on an urgent help request may see who asked -- the same
// logged read as the help queue's (RevealHelpRequesterAction: help_request_identity, every time), but gated
// by the report: an uninvolved reviewer, while the report is open or under review; after that only a super admin,
// resolved with a suspension recommended and none applied yet (they must see who it is before suspending them --
// EnsureCanApplySuspensionAction). Name and username only.
class RevealReportedHelpRequesterAction extends Action
{
    public const NOT_NOW = 'Who asked can only be seen while the report is being reviewed, or before suspending them.';

    public function execute(?User $admin, ?Report $report): array
    {
        EnsureAdminCanReviewReportAction::new()->execute($admin, $report);

        $help = $report->helpRequest();
        if (! $help) {
            throw new ReportException(EnsureAdminCanReviewReportAction::NOT_FOUND, 404);
        }

        // After review, only for suspending: a super admin, and only until the suspension is applied.
        $suspensionPending = $report->status == ReportStatusEnum::resolved && $report->outcome == ReportOutcomeEnum::suspension_recommended
            && $admin->isSuperAdmin() && ! $report->suspensions()->exists();
        if (! $report->isActive() && ! $suspensionPending) {
            throw new ReportException(self::NOT_NOW, 403);
        }

        RecordAdminContentReadAction::new()->execute($admin, AdminContentAccess::HELP_REQUEST_IDENTITY, $help);
        $person = $help->user()->withTrashed()->first();

        return [
            'name' => $person?->name,
            'username' => $person?->username,
        ];
    }
}
