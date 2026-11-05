<?php

namespace App\Http\Controllers;

use App\Actions\SafeguardingException\SendSafeguardingExceptionRequestAction;
use App\DTOs\SafeguardingExceptionRequestDTO;
use App\Models\GroupTherapy;
use App\Models\Therapy;
use App\Support\FailureResponse;
use Illuminate\Http\Request;
use Throwable;

// SCRUM-381: a counsellor asks the super administrator for a safeguarding exception for their paused
// 1:1 client, or for a paused member of their group (`memberKey`, from the group's pausedMembers).
// Always JSON (the therapy page's modal posts with axios).
class SafeguardingExceptionRequestController extends Controller
{
    public function storeForTherapy(Request $request, int $therapyId)
    {
        return $this->send($request, Therapy::query()->find($therapyId));
    }

    public function storeForGroup(Request $request, int $groupTherapyId)
    {
        return $this->send($request, GroupTherapy::query()->find($groupTherapyId));
    }

    private function send(Request $request, Therapy|GroupTherapy|null $therapy)
    {
        try {
            $sent = SendSafeguardingExceptionRequestAction::new()->execute(SafeguardingExceptionRequestDTO::new()->fromArray([
                'user' => $request->user(),
                'therapy' => $therapy,
                'memberKey' => is_scalar($request->input('memberKey')) ? (string) $request->input('memberKey') : null,
                'reason' => is_string($request->input('reason')) ? $request->input('reason') : null,
                'proposedEndsAt' => is_string($request->input('proposedEndsAt')) ? $request->input('proposedEndsAt') : null,
            ]));

            return response()->json(['status' => true, 'requestId' => $sent->id], 201);
        } catch (Throwable $th) {
            return FailureResponse::json($th);
        }
    }
}
