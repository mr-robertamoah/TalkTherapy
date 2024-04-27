<?php

namespace App\Actions\Report;

use App\Actions\Action;
use App\DTOs\CreateReportDTO;
use App\Exceptions\ReportException;
use App\Models\Counsellor;

class EnsureCanCreateReportAction extends Action
{
    public function execute(CreateReportDTO $createReportDTO)
    {
        if (
            $createReportDTO->user->is($createReportDTO->addedby) ||
            (
                $createReportDTO->addedby::class == Counsellor::class && 
                $createReportDTO->addedby?->user->is($createReportDTO->user)
            )
        ) return;

        throw new ReportException("You are not allowed to create a report with the account provided.", 422);
    }
}