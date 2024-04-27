<?php

namespace App\Actions\Report;

use App\Actions\Action;
use App\DTOs\CreateReportDTO;
use App\Exceptions\ReportException;
use App\Models\Counsellor;

class EnsureCanUpdateReportAction extends Action
{
    public function execute(CreateReportDTO $createReportDTO)
    {
        if (
            $createReportDTO->user->is($createReportDTO->report->addedby) ||
            (
                $createReportDTO->report->addedby::class == Counsellor::class && 
                $createReportDTO->report->addedby->user->is($createReportDTO->user)
            )
        ) return;

        throw new ReportException("You are not allowed to update/delete this report.", 422);
    }
}