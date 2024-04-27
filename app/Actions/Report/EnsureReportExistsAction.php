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
        ) return;

        throw new ReportException("You cannot perform this action because report was not found.", 422);
    }
}