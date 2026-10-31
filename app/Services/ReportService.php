<?php

namespace App\Services;

use App\Actions\EnsureAddedbyIsValidAction;
use App\Actions\Report\CreateReportAction;
use App\Actions\Report\DefaultReportedPartyAction;
use App\Actions\Report\DeleteReportAction;
use App\Actions\Report\EnsureCanCreateReportAction;
use App\Actions\Report\EnsureCanReportReportableAction;
use App\Actions\Report\EnsureCanUpdateReportAction;
use App\Actions\Report\EnsureReportDataIsValidAction;
use App\Actions\Report\EnsureReportExistsAction;
use App\Actions\Report\UpdateReportAction;
use App\DTOs\CreateReportDTO;
use App\Enums\PaginationEnum;
use App\Exceptions\ReportException;
use App\Models\Report;

class ReportService extends Service
{
    public function createReport(CreateReportDTO $createReportDTO)
    {
        EnsureAddedbyIsValidAction::new()->execute(
            $createReportDTO,
            'You are not allowed to use the account to add the report.'
        );

        EnsureCanCreateReportAction::new()->execute($createReportDTO);

        EnsureCanReportReportableAction::new()->execute($createReportDTO);

        DefaultReportedPartyAction::new()->execute($createReportDTO);

        EnsureReportDataIsValidAction::new()->execute($createReportDTO);

        $report = CreateReportAction::new()->execute($createReportDTO);

        AppService::new()->alertAdminWithReport($report);

        return $report;
    }

    public function updateReport(CreateReportDTO $createReportDTO)
    {
        EnsureReportExistsAction::new()->execute($createReportDTO);

        EnsureCanUpdateReportAction::new()->execute($createReportDTO);

        // SCRUM-347: a reporter can't re-point their report at a therapy they aren't in.
        EnsureCanReportReportableAction::new()->execute($createReportDTO, required: false);

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
        // SCRUM-337: admins and the reporter only, and a missing report gets the same answer.
        if (! $createReportDTO->report?->isViewableBy($createReportDTO->user)) {
            throw new ReportException('Report not found.', 404);
        }

        return $createReportDTO->report;
    }

    public function getReports(CreateReportDTO $createReportDTO)
    {
        $query = Report::query();

        // SCRUM-337: this listed every report to anyone signed in. Admins see all; everyone else
        // only their own, whatever addedby/reportable filter they pass.
        if (! $createReportDTO->user->isAdmin()) {
            $query->whereReportedBy($createReportDTO->user);
        }

        $query->when($createReportDTO->addedby, function ($query) use ($createReportDTO) {
            $query->whereAddedby($createReportDTO->addedby);
        });

        $query->when($createReportDTO->reportable, function ($query) use ($createReportDTO) {
            $query->whereReportable($createReportDTO->reportable);
        });

        // ReportResource lists each report's evidence.
        $query->with(['files', 'reportable'])->orderByDesc('created_at');

        return $query->paginate(PaginationEnum::preferencesPagination->value);
    }
}
