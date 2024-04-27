<?php

namespace App\Services;

use App\Actions\EnsureAddedbyIsValidAction;
use App\Actions\Report\EnsureCanUpdateReportAction;
use App\Actions\Report\EnsureReportExistsAction;
use App\Actions\Report\EnsureReportDataIsValidAction;
use App\Actions\Report\CreateReportAction;
use App\Actions\Report\DeleteReportAction;
use App\Actions\Report\EnsureCanCreateReportAction;
use App\Actions\Report\UpdateReportAction;
use App\DTOs\CreateReportDTO;
use App\Enums\PaginationEnum;
use App\Models\Report;

class ReportService extends Service
{
    public function createReport(CreateReportDTO $createReportDTO)
    {
        EnsureAddedbyIsValidAction::new()->execute(
            $createReportDTO,
            "You are not allowed to use the account to add the report."
        );

        EnsureCanCreateReportAction::new()->execute($createReportDTO);

        EnsureReportDataIsValidAction::new()->execute($createReportDTO);

        $report = CreateReportAction::new()->execute($createReportDTO);

        AppService::new()->alertAdminWithReport($report);

        return $report;
    }

    public function updateReport(CreateReportDTO $createReportDTO)
    {
        EnsureReportExistsAction::new()->execute($createReportDTO);

        EnsureCanUpdateReportAction::new()->execute($createReportDTO);

        EnsureReportDataIsValidAction::new()->execute($createReportDTO, 'update');

        return UpdateReportAction::new()->execute($createReportDTO);
    }

    public function deleteReport(CreateReportDTO $createReportDTO)
    {
        EnsureReportExistsAction::new()->execute($createReportDTO);

        EnsureCanUpdateReportAction::new()->execute($createReportDTO);

        return DeleteReportAction::new()->execute($createReportDTO);
    }

    public function getReport(CreateReportDTO $createReportDTO)
    {
        EnsureReportExistsAction::new()->execute($createReportDTO);

        return $createReportDTO->report;
    }

    public function getReports(CreateReportDTO $createReportDTO)
    {
        $query = Report::query();

        $query->when($createReportDTO->addedby, function ($query) use ($createReportDTO) {
            $query->whereAddedby($createReportDTO->addedby);
        });

        $query->when($createReportDTO->reportable, function ($query) use ($createReportDTO) {
            $query->whereReportable($createReportDTO->reportable);
        });

        $query->orderByDesc('created_at');

        return $query->paginate(PaginationEnum::preferencesPagination->value);
    }
}