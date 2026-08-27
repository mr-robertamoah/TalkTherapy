<?php

namespace App\Http\Controllers;

use App\DTOs\OrganizationMemberBillingConfigDTO;
use App\Http\Requests\CreateOrganizationMemberBillingConfigRequest;
use App\Http\Resources\OrganizationMemberBillingConfigResource;
use App\Models\OrganizationMember;
use App\Services\OrganizationMemberBillingConfigService;
use Illuminate\Support\Facades\Redirect;
use Throwable;

class OrganizationMemberBillingConfigController extends Controller
{
    public function store(CreateOrganizationMemberBillingConfigRequest $request)
    {
        try {
            $config = OrganizationMemberBillingConfigService::new()->setBillingConfig(
                OrganizationMemberBillingConfigDTO::new()->fromArray([
                    'user' => $request->user(),
                    'organizationMember' => OrganizationMember::find($request->route('organizationMemberId')),
                    'mode' => $request->mode,
                    'per' => $request->per,
                    // $request->boolean(), not (bool) $request->includeGroupTherapies -- a bare
                    // (bool) cast on the string "false" evaluates to true, silently flipping a
                    // flag with real future booking-blocking consequences (security review,
                    // SCRUM-125). ->boolean() correctly normalizes "false"/"0" too.
                    'includeGroupTherapies' => $request->boolean('includeGroupTherapies'),
                ])
            );

            $resource = new OrganizationMemberBillingConfigResource($config);

            if ($request->acceptsJson()) {
                return response()->json(['billingConfig' => $resource]);
            }

            return Redirect::back()->with(['billingConfig' => $resource]);
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
