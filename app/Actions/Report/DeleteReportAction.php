<?php

namespace App\Actions\Report;

use App\Actions\Action;
use App\Actions\File\DeleteAttachedFilesAction;
use App\DTOs\CreateReportDTO;
use Illuminate\Support\Facades\Log;

class DeleteReportAction extends Action
{
    public function execute(CreateReportDTO $createReportDTO)
    {
        if ($createReportDTO->reportable) {
            $createReportDTO->report->reportable()->dissociate();
            $createReportDTO->report->save();
        }

        // The report goes on the next line, so nothing will retry a file that couldn't be deleted --
        // say so loudly, with ids, for someone to clean up.
        $report = $createReportDTO->report;
        $attached = $report->files()->pluck('files.id');
        DeleteAttachedFilesAction::new()->execute($report, 'report deleted');
        $left = $report->files()->pluck('files.id');
        if ($left->isNotEmpty()) {
            Log::error('A deleted report left stored files that could not be deleted.', ['report' => $report->id, 'files' => $left->all(), 'of' => $attached->count()]);
        }

        return $createReportDTO->report->delete();
    }
}
