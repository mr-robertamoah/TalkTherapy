<?php

namespace App\Http\Controllers;

use App\Actions\Request\GetRequestResourceAction;
use App\DTOs\SessionScheduleProposalDTO;
use App\Http\Requests\CounterOfferSessionScheduleProposalRequest;
use App\Http\Requests\CreateSessionScheduleProposalRequest;
use App\Models\Request as ModelsRequest;
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
                    'about' => $request->about,
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

    // Reads $request->route('requestId') rather than the magic ->requestId property, same
    // rationale as RequestController::respond() (SCRUM-116/130/133).
    public function counterOffer(CounterOfferSessionScheduleProposalRequest $request)
    {
        try {
            $counterOffer = SessionScheduleProposalService::new()->counterOffer(
                SessionScheduleProposalDTO::new()->fromArray([
                    'user' => $request->user(),
                    'request' => ModelsRequest::find($request->route('requestId')),
                    'startTime' => $request->startTime,
                    'endTime' => $request->endTime,
                    'name' => $request->name,
                    'about' => $request->about,
                    'type' => $request->type,
                    'paymentType' => $request->paymentType,
                    'expiryDays' => $request->expiryDays,
                ])
            );

            return response()->json(['proposal' => GetRequestResourceAction::new()->execute($counterOffer)]);
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return response()->json(['message' => $message], $status);
        }
    }
}
