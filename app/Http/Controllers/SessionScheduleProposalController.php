<?php

namespace App\Http\Controllers;

use App\Actions\Request\GetRequestResourceAction;
use App\DTOs\SessionScheduleProposalDTO;
use App\Http\Requests\CreateSessionScheduleProposalRequest;
use App\Models\Therapy;
use App\Services\SessionScheduleProposalService;
use Throwable;

class SessionScheduleProposalController extends Controller
{
    public function store(CreateSessionScheduleProposalRequest $request)
    {
        try {
            $proposal = SessionScheduleProposalService::new()->propose(
                SessionScheduleProposalDTO::new()->fromArray([
                    'user' => $request->user(),
                    'therapy' => Therapy::find($request->route('therapyId')),
                    'startTime' => $request->startTime,
                    'endTime' => $request->endTime,
                    'name' => $request->name,
                    'type' => $request->type,
                    'paymentType' => $request->paymentType,
                    'expiryDays' => $request->expiryDays,
                ])
            );

            return response()->json(['proposal' => GetRequestResourceAction::new()->execute($proposal)]);
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return response()->json(['message' => $message], $status);
        }
    }
}
