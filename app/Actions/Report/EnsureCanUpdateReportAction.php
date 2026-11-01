<?php

namespace App\Actions\Report;

use App\Actions\Action;
use App\DTOs\CreateReportDTO;
use App\Enums\ReportStatusEnum;
use App\Exceptions\ReportException;

class EnsureCanUpdateReportAction extends Action
{
    public function execute(CreateReportDTO $createReportDTO)
    {
        if (! $createReportDTO->report->isReportedBy($createReportDTO->user)) {
            // SCRUM-337: the same answer as a missing report (EnsureReportExistsAction).
            throw new ReportException('Report not found.', 404);
        }

        // SCRUM-340: once an admin has started reviewing a report, the reporter can't change or
        // delete it -- it would change what is being reviewed (and whose messages can be unlocked).
        if ($createReportDTO->report->status != ReportStatusEnum::open) {
            throw new ReportException('This report is being reviewed and can no longer be changed.', 422);
        }
    }
}
