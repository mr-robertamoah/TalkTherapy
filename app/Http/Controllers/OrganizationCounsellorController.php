<?php

namespace App\Http\Controllers;

use App\DTOs\OrganizationCounsellorRequestDTO;
use App\Http\Requests\InviteOrganizationCounsellorRequest;
use App\Http\Resources\OrganizationRequestResource;
use App\Models\Counsellor;
use App\Models\Organization;
use App\Services\OrganizationCounsellorRequestService;
use Illuminate\Http\Request;
use Throwable;

class OrganizationCounsellorController extends Controller
{
    public function invite(InviteOrganizationCounsellorRequest $request)
    {
        try {
            $organizationRequest = OrganizationCounsellorRequestService::new()->inviteCounsellor(
                OrganizationCounsellorRequestDTO::new()->fromArray([
                    'user' => $request->user(),
                    'organization' => Organization::find($request->route('organizationId')),
                    'counsellor' => Counsellor::find($request->counsellorId),
                ])
            );

            return response()->json(['request' => new OrganizationRequestResource($organizationRequest)]);
        } catch (Throwable $th) {
            return $this->returnFailure($request, $th);
        }
    }

    public function apply(Request $request)
    {
        try {
            $organizationRequest = OrganizationCounsellorRequestService::new()->applyAsCounsellor(
                OrganizationCounsellorRequestDTO::new()->fromArray([
                    'user' => $request->user(),
                    'organization' => Organization::find($request->route('organizationId')),
                    'counsellor' => $request->user()?->counsellor,
                ])
            );

            return response()->json(['request' => new OrganizationRequestResource($organizationRequest)]);
        } catch (Throwable $th) {
            return $this->returnFailure($request, $th);
        }
    }

    private function returnFailure(Request $request, Throwable $th)
    {
        $status = $this->statusFor($th);
        $message = $this->messageFor($th, $status);

        return response()->json(['message' => $message], $status);
    }
}
