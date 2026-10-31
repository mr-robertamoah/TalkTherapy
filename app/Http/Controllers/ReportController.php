<?php

namespace App\Http\Controllers;

use App\Actions\GetModelWithModelNameAndIdAction;
use App\Actions\Report\EnsureCanReportReportableAction;
use App\DTOs\CreateReportDTO;
use App\Exceptions\ReportException;
use App\Http\Resources\ReportResource;
use App\Models\GroupTherapy;
use App\Models\Report;
use App\Services\ReportService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Throwable;

class ReportController extends Controller
{
    // Every lookup below reads $request->route('reportId') rather than the magic ->reportId
    // property -- see the identical fix/rationale in SessionController (SCRUM-116/SCRUM-130).
    public function createReport(Request $request)
    {
        try {
            $report = ReportService::new()->createReport(
                CreateReportDTO::new()->fromArray([
                    'user' => $request->user(),
                    'description' => $request->description,
                    'data' => $request->data,
                    'files' => $request->file('files'),
                    'reportable' => $this->reportable($request),
                    'addedby' => GetModelWithModelNameAndIdAction::new()->execute($request->addedbyType, $request->addedbyId),
                ])
            );

            return $this->returnSuccess($request, $report);
        } catch (Throwable $th) {

            return $this->returnFailure($request, $th);
        }
    }

    public function updateReport(Request $request)
    {
        try {
            // Security review (SCRUM-347): a re-point to a therapy id that doesn't exist answers like
            // one the reporter isn't in, instead of being silently ignored.
            if (($request->filled('reportableType') || $request->filled('reportableId')) && ! $this->reportable($request)) {
                throw new ReportException(EnsureCanReportReportableAction::NOT_ALLOWED, 422);
            }

            $report = ReportService::new()->updateReport(
                CreateReportDTO::new()->fromArray([
                    'user' => $request->user(),
                    'description' => $request->description,
                    'data' => $request->data,
                    'report' => Report::find($request->route('reportId')),
                    'files' => $request->file('files'),
                    'reportable' => $this->reportable($request),
                ])
            );

            return $this->returnSuccess($request, $report);
        } catch (Throwable $th) {

            return $this->returnFailure($request, $th);
        }
    }

    public function deleteReport(Request $request)
    {
        $report = Report::find($request->route('reportId'));
        try {
            ReportService::new()->deleteReport(
                CreateReportDTO::new()->fromArray([
                    'user' => $request->user(),
                    'report' => $report,
                ])
            );

            return $this->returnSuccess($request, $report);
        } catch (Throwable $th) {

            return $this->returnFailure($request, $th);
        }
    }

    public function getReport(Request $request)
    {
        $report = Report::find($request->route('reportId'));
        try {
            ReportService::new()->getReport(
                CreateReportDTO::new()->fromArray([
                    'user' => $request->user(),
                    'report' => $report,
                ])
            );

            return $this->returnSuccess($request, $report);
        } catch (Throwable $th) {

            return $this->returnFailure($request, $th);
        }
    }

    public function getReports(Request $request)
    {
        try {
            $reports = ReportService::new()->getReports(
                CreateReportDTO::new()->fromArray([
                    'user' => $request->user(),
                    // SCRUM-337: no 'like' here -- CreateReportDTO has no such property, so passing
                    // it made this endpoint fail with a 500 on every call.
                    'addedby' => GetModelWithModelNameAndIdAction::new()->execute($request->addedbyType, $request->addedbyId),
                    'reportable' => $this->reportable($request),
                ])
            );

            return ReportResource::collection($reports);
        } catch (Throwable $th) {

            return $this->returnFailure($request, $th);
        }
    }

    private function returnSuccess(Request $request, Report $report)
    {
        $report = new ReportResource($report);

        if ($request->acceptsJson()) {
            return response()->json(['report' => $report]);
        }

        return Redirect::back()->with(['report' => $report]);
    }

    private function returnFailure(Request $request, Throwable $th)
    {
        $status = $this->statusFor($th);
        $message = $this->messageFor($th, $status);

        if ($request->acceptsJson()) {
            return response()->json(['message' => $message], $status);
        }

        return Redirect::back()->withErrors(['alert' => $message]);
    }

    // SCRUM-347: GetModelWithModelNameAndIdAction lowercases the name ('GroupTherapy' becomes
    // 'Grouptherapy', which the autoloader can't find), so a group report never resolved its group.
    private function reportable(Request $request): ?Model
    {
        if (strcasecmp((string) $request->reportableType, 'GroupTherapy') === 0) {
            return GroupTherapy::find($request->reportableId);
        }

        return GetModelWithModelNameAndIdAction::new()->execute($request->reportableType, $request->reportableId);
    }
}
