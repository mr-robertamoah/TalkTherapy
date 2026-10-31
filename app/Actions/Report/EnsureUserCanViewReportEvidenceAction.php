<?php

namespace App\Actions\Report;

use App\Actions\Action;
use App\Exceptions\ReportEvidenceAccessDeniedException;
use App\Models\File;
use App\Models\Report;
use App\Models\User;

// SCRUM-337: gates the only retrieval path for report evidence -- admins and the reporter, for a
// file actually attached to that report, on the private disk. Every refusal is the same 404, like
// the identity/license/attachment routes.
class EnsureUserCanViewReportEvidenceAction extends Action
{
    private const EVIDENCE_DISKS = ['report_evidence'];

    public function execute(User $user, Report $report, File $file): void
    {
        $isAttached = $report->files()->where('files.id', $file->id)->exists();

        if ($isAttached && $report->isViewableBy($user) && in_array($file->storage, self::EVIDENCE_DISKS, true)) {
            return;
        }

        throw new ReportEvidenceAccessDeniedException('Evidence not found.', 404);
    }
}
