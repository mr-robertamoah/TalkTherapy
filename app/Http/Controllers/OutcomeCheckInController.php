<?php

namespace App\Http\Controllers;

use App\Actions\OutcomeCheckIn\AnswerCheckInAction;
use App\Actions\OutcomeCheckIn\GetCheckInsAction;
use App\Actions\OutcomeCheckIn\ManageCheckInAction;
use App\Actions\OutcomeCheckIn\RequestCheckInAction;
use App\Models\Therapy;
use App\Support\FailureResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

// SCRUM-37 (TT-5.2): outcome check-ins on a 1:1 therapy -- always JSON, never cached by the browser (answers are
// decrypted here). Who may do what: App\Actions\OutcomeCheckIn\CheckInAccess.
class OutcomeCheckInController extends Controller
{
    public function index(Request $request, int $therapyId): JsonResponse
    {
        return $this->respond(fn () => GetCheckInsAction::new()->execute($request->user(), Therapy::query()->find($therapyId)));
    }

    public function store(Request $request, int $therapyId): JsonResponse
    {
        return $this->respond(fn () => ['checkIn' => GetCheckInsAction::present(
            RequestCheckInAction::new()->execute($request->user(), Therapy::query()->find($therapyId), is_string($request->input('measure')) ? $request->input('measure') : ''), true,
        )], 201);
    }

    public function answer(Request $request, int $checkIn): JsonResponse
    {
        return $this->respond(function () use ($request, $checkIn) {
            [$answered, $crisis] = AnswerCheckInAction::new()->execute($request->user(), $checkIn, $request->input('answers'));

            return ['checkIn' => GetCheckInsAction::present($answered, false), 'showCrisisSupport' => $crisis];
        });
    }

    public function cancel(Request $request, int $checkIn): JsonResponse
    {
        return $this->respond(fn () => ['checkIn' => GetCheckInsAction::present(ManageCheckInAction::new()->cancel($request->user(), $checkIn), true)]);
    }

    public function acknowledge(Request $request, int $checkIn): JsonResponse
    {
        return $this->respond(fn () => ['checkIn' => GetCheckInsAction::present(ManageCheckInAction::new()->acknowledge($request->user(), $checkIn), true)]);
    }

    private function respond(callable $work, int $status = 200): JsonResponse
    {
        try {
            return response()->json($work(), $status)->header('Cache-Control', 'no-store, private');
        } catch (Throwable $th) {
            // Something unexpected (not a refusal) is reported, as SafetyFlagController does.
            if (FailureResponse::status($th) === 500) {
                report($th);
            }

            return FailureResponse::json($th)->header('Cache-Control', 'no-store, private');
        }
    }
}
