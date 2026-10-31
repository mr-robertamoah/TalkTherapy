<?php

namespace App\Actions\Report;

use App\Actions\Action;
use App\DTOs\CreateReportDTO;
use App\Exceptions\ReportException;

class EnsureReportExistsAction extends Action
{
    public function execute(CreateReportDTO $createReportDTO)
    {
        if (
            $createReportDTO->report
        ) {
            return;
        }

        throw new ReportException('Report not found.', 404);
    }
}
