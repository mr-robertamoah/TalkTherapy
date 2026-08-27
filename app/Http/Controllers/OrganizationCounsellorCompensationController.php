<?php

namespace App\Http\Controllers;

use App\DTOs\OrganizationCounsellorCompensationDTO;
use App\Http\Requests\CreateOrganizationCounsellorCompensationRequest;
use App\Http\Resources\OrganizationCounsellorCompensationResource;
use App\Models\OrganizationCounsellor;
use App\Services\OrganizationCounsellorCompensationService;
use Illuminate\Support\Facades\Redirect;
use Throwable;

class OrganizationCounsellorCompensationController extends Controller
{
    public function store(CreateOrganizationCounsellorCompensationRequest $request)
    {
        try {
            $compensation = OrganizationCounsellorCompensationService::new()->setCompensation(
                OrganizationCounsellorCompensationDTO::new()->fromArray([
                    'user' => $request->user(),
                    'organizationCounsellor' => OrganizationCounsellor::find($request->route('organizationCounsellorId')),
                    'type' => $request->type,
                    'amount' => $request->amount,
                    'currency' => $request->currency,
                    'percentage' => $request->percentage,
                    'basis' => $request->basis,
                ])
            );

            $resource = new OrganizationCounsellorCompensationResource($compensation);

            if ($request->acceptsJson()) {
                return response()->json(['compensation' => $resource]);
            }

            return Redirect::back()->with(['compensation' => $resource]);
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
