<?php

namespace App\Http\Controllers;

use App\Actions\Request\GetRequestResourceAction;
use App\DTOs\OrganizationCounsellorCompensationDTO;
use App\Http\Requests\CreateOrganizationCounsellorCompensationRequest;
use App\Http\Resources\OrganizationCounsellorCompensationNegotiationStateResource;
use App\Http\Resources\OrganizationCounsellorCompensationResource;
use App\Models\OrganizationCounsellor;
use App\Models\Request as ModelsRequest;
use App\Services\OrganizationCounsellorCompensationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Throwable;

class OrganizationCounsellorCompensationController extends Controller
{
    public function index(Request $request)
    {
        try {
            $compensations = OrganizationCounsellorCompensationService::new()->getCompensations(
                OrganizationCounsellorCompensationDTO::new()->fromArray([
                    'user' => $request->user(),
                    'organizationCounsellor' => OrganizationCounsellor::find($request->route('organizationCounsellorId')),
                ])
            );

            return OrganizationCounsellorCompensationResource::collection($compensations);
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return response()->json(['message' => $message], $status);
        }
    }

    // SCRUM-150 (TT-6.4c): a wholly separate, additive read from index() above -- never touches
    // organization_counsellor_compensations, only the latest negotiation Request (if any).
    // index() itself is completely unmodified (SCRUM-123's accepted-terms history stays as-is).
    public function negotiationState(Request $request)
    {
        try {
            $negotiationState = OrganizationCounsellorCompensationService::new()->getNegotiationState(
                OrganizationCounsellorCompensationDTO::new()->fromArray([
                    'user' => $request->user(),
                    'organizationCounsellor' => OrganizationCounsellor::find($request->route('organizationCounsellorId')),
                ])
            );

            return new OrganizationCounsellorCompensationNegotiationStateResource($negotiationState);
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return response()->json(['message' => $message], $status);
        }
    }

    // SCRUM-146 (TT-6.4c): returns the created pending Request, not a compensation resource --
    // this admin write is no longer immediately effective. No other frontend or backend consumer
    // of this endpoint's response exists yet (confirmed via grep; TT-6.5a's admin dashboard isn't
    // built), so this is a safe response-contract change with no deprecation window needed.
    public function store(CreateOrganizationCounsellorCompensationRequest $request)
    {
        try {
            $proposal = OrganizationCounsellorCompensationService::new()->proposeCompensationChange(
                OrganizationCounsellorCompensationDTO::new()->fromArray([
                    'user' => $request->user(),
                    'organizationCounsellor' => OrganizationCounsellor::find($request->route('organizationCounsellorId')),
                    'type' => $request->type,
                    'amount' => $request->amount,
                    'currency' => $request->currency,
                    'percentage' => $request->percentage,
                    'basis' => $request->basis,
                    'expiryDays' => $request->expiryDays,
                ])
            );

            $resource = GetRequestResourceAction::new()->execute($proposal);

            if ($request->acceptsJson()) {
                return response()->json(['proposal' => $resource]);
            }

            return Redirect::back()->with(['proposal' => $resource]);
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            if ($request->acceptsJson()) {
                return response()->json(['message' => $message], $status);
            }

            return Redirect::back()->withErrors(['alert' => $message]);
        }
    }

    // SCRUM-148 (TT-6.4c): counters a pending proposal with new terms -- keyed on requestId
    // (the negotiation being responded to), not organizationCounsellorId, since only the current
    // to-party of that specific request may act on it.
    public function counterOffer(CreateOrganizationCounsellorCompensationRequest $request)
    {
        try {
            $counterOffer = OrganizationCounsellorCompensationService::new()->counterOffer(
                OrganizationCounsellorCompensationDTO::new()->fromArray([
                    'user' => $request->user(),
                    'request' => ModelsRequest::find($request->route('requestId')),
                    'type' => $request->type,
                    'amount' => $request->amount,
                    'currency' => $request->currency,
                    'percentage' => $request->percentage,
                    'basis' => $request->basis,
                    'expiryDays' => $request->expiryDays,
                ])
            );

            $resource = GetRequestResourceAction::new()->execute($counterOffer);

            if ($request->acceptsJson()) {
                return response()->json(['proposal' => $resource]);
            }

            return Redirect::back()->with(['proposal' => $resource]);
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            if ($request->acceptsJson()) {
                return response()->json(['message' => $message], $status);
            }

            return Redirect::back()->withErrors(['alert' => $message]);
        }
    }
}
