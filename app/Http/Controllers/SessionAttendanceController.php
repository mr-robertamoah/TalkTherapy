<?php

namespace App\Http\Controllers;

use App\DTOs\SessionAttendanceDTO;
use App\Http\Requests\RecordSessionAttendanceRequest;
use App\Http\Resources\SessionResource;
use App\Models\Session;
use App\Services\SessionAttendanceService;
use Illuminate\Http\Request;
use Throwable;

// SCRUM-445: attendance at in-person sessions. The marking counsellor is always the signed-in user's own counsellor
// profile, never input.
class SessionAttendanceController extends Controller
{
    public function index(Request $request)
    {
        try {
            return response()->json(SessionAttendanceService::new()->getAttendance($this->dto($request)));
        } catch (Throwable $th) {
            return $this->failureResponse($request, $th);
        }
    }

    public function store(RecordSessionAttendanceRequest $request)
    {
        try {
            $service = SessionAttendanceService::new();
            $dto = $this->dto($request, $request->validated('attendance'));
            $service->recordAttendance($dto);

            return $this->successResponse($request, $service->getAttendance($dto));
        } catch (Throwable $th) {
            return $this->failureResponse($request, $th);
        }
    }

    public function closeNoShow(Request $request)
    {
        try {
            $session = SessionAttendanceService::new()->closeAsNoShow($this->dto($request));

            return $this->successResponse($request, ['session' => new SessionResource($session)]);
        } catch (Throwable $th) {
            return $this->failureResponse($request, $th);
        }
    }

    private function dto(Request $request, ?array $entries = null): SessionAttendanceDTO
    {
        return SessionAttendanceDTO::new()->fromArray([
            'user' => $request->user(),
            'session' => Session::find($request->route('sessionId')),
            'entries' => $entries,
        ]);
    }
}
