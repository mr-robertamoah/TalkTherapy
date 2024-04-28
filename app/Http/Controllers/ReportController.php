<?php

namespace App\Http\Controllers;

use App\Actions\GetModelWithModelNameAndIdAction;
use App\DTOs\CreateReportDTO;
use App\Http\Resources\ReportResource;
use App\Models\Report;
use App\Services\ReportService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Throwable;

class ReportController extends Controller
{
    public function createReport(Request $request)
    {
        try {
            $report = ReportService::new()->createReport(
                CreateReportDTO::new()->fromArray([
                    'user' => $request->user(),
                    'description' => $request->description,
                    'data' => $request->data,
                    'files' => $request->file('files'),
                    'reportable' => GetModelWithModelNameAndIdAction::new()->execute($request->reportableType, $request->reportableId),
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
            $report = ReportService::new()->updateReport(
                CreateReportDTO::new()->fromArray([
                    'user' => $request->user(),
                    'description' => $request->description,
                    'data' => $request->data,
                    'report' => Report::find($request->reportId),
                    'files' => $request->file('files'),
                    'reportable' => GetModelWithModelNameAndIdAction::new()->execute($request->reportableType, $request->reportableId),
                ])
            );

            return $this->returnSuccess($request, $report);
        } catch (Throwable $th) {
            
            return $this->returnFailure($request, $th);
        }
    }
    
    public function deleteReport(Request $request)
    {
        $report = Report::find($request->reportId);
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
        $report = Report::find($request->reportId);
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
                    'like' => $request->like,
                    'addedby' => GetModelWithModelNameAndIdAction::new()->execute($request->addedbyType, $request->addedbyId),
                    'reportable' => GetModelWithModelNameAndIdAction::new()->execute($request->reportableType, $request->reportableId),
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
        
        if ($request->acceptsJson()) return response()->json(['report' => $report]);
        
        return Redirect::back()->with(['report' => $report]);
    }

    private function returnFailure(Request $request, Throwable $th)
    {
        $message = $th->getCode() == 500 ? "Something unfortunate happened. Please try again shortly." : $th->getMessage();
        
        ds($th);

        if ($request->acceptsJson()) throw new Exception($message);

        return Redirect::back()->withErrors(['alert'=> $message]);
    }
}
