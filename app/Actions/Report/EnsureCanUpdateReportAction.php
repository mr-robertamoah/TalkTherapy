<?php

namespace App\Actions\Report;

use App\Actions\Action;
use App\DTOs\CreateReportDTO;
use App\Exceptions\ReportException;

class EnsureCanUpdateReportAction extends Action
{
    public function execute(CreateReportDTO $createReportDTO)
    {
        if ($createReportDTO->report->isReportedBy($createReportDTO->user)) {
            return;
        }

        // SCRUM-337: the same answer as a missing report (EnsureReportExistsAction).
        throw new ReportException('Report not found.', 404);
    }
}
