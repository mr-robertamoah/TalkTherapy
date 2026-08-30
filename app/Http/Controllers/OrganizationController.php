<?php

namespace App\Http\Controllers;

use App\DTOs\GetOrganizationDirectoryDTO;
use App\DTOs\OrganizationDTO;
use App\Http\Requests\CreateOrganizationRequest;
use App\Http\Requests\UpdateOrganizationRequest;
use App\Http\Resources\MyAdministeredOrganizationResource;
use App\Http\Resources\MyOrganizationCounsellorAffiliationResource;
use App\Http\Resources\MyOrganizationMembershipResource;
use App\Http\Resources\OrganizationDirectoryResource;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use App\Services\OrganizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Throwable;

class OrganizationController extends Controller
{
    // Any authenticated user, not just an org's own admins -- this is how a counsellor/member
    // discovers an org to apply to (TT-6.6c). Verified-only, curated field set: see
    // OrganizationDirectoryResource and GetOrganizationDirectoryAction's own comments.
    public function index(Request $request)
    {
        try {
            $organizations = OrganizationService::new()->getOrganizationDirectory(
                GetOrganizationDirectoryDTO::new()->fromArray([
                    'isProvider' => $request->has('isProvider') ? $request->boolean('isProvider') : null,
                    'isConsumer' => $request->has('isConsumer') ? $request->boolean('isConsumer') : null,
                ])
            );

            return OrganizationDirectoryResource::collection($organizations);
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return response()->json(['message' => $message], $status);
        }
    }

    public function store(CreateOrganizationRequest $request)
    {
        try {
            $organization = OrganizationService::new()->createOrganization(
                OrganizationDTO::new()->fromArray([
                    'user' => $request->user(),
                    'name' => $request->name,
                    'legalName' => $request->legalName,
                    'registrationNumber' => $request->registrationNumber,
                    'description' => $request->description,
                    'email' => $request->email,
                    'phone' => $request->phone,
                    'isProvider' => $request->boolean('isProvider'),
                    'isConsumer' => $request->boolean('isConsumer'),
                ])
            );

            return $this->returnSuccess($request, $organization);
        } catch (Throwable $th) {
            return $this->returnFailure($request, $th);
        }
    }

    // Reads $request->route('organizationId') rather than the magic ->organizationId property
    // -- SCRUM-116 flagged the same magic-property route-param bypass pattern in other
    // controllers; deliberately not repeating it here.
    public function update(UpdateOrganizationRequest $request)
    {
        try {
            $organization = OrganizationService::new()->updateOrganization(
                OrganizationDTO::new()->fromArray([
                    'user' => $request->user(),
                    'organization' => Organization::find($request->route('organizationId')),
                    'name' => $request->name,
                    'legalName' => $request->legalName,
                    'registrationNumber' => $request->registrationNumber,
                    'description' => $request->description,
                    'email' => $request->email,
                    'phone' => $request->phone,
                    'isProvider' => $request->has('isProvider') ? $request->boolean('isProvider') : null,
                    'isConsumer' => $request->has('isConsumer') ? $request->boolean('isConsumer') : null,
                    'selfApplyEnabled' => $request->has('selfApplyEnabled') ? $request->boolean('selfApplyEnabled') : null,
                ])
            );

            return $this->returnSuccess($request, $organization);
        } catch (Throwable $th) {
            return $this->returnFailure($request, $th);
        }
    }

    public function show(Request $request)
    {
        try {
            $organization = OrganizationService::new()->getOrganization(
                OrganizationDTO::new()->fromArray([
                    'user' => $request->user(),
                    'organization' => Organization::find($request->route('organizationId')),
                ])
            );

            return response()->json(['organization' => new OrganizationResource($organization)]);
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return response()->json(['message' => $message], $status);
        }
    }

    // "My organizations" (TT-6.6b) -- self-scoped to the authenticated user, feeding TT-6.5b/c's
    // own org-selection UI.
    public function myCounsellorAffiliations(Request $request)
    {
        try {
            $affiliations = OrganizationService::new()->getMyOrganizationCounsellorAffiliations($request->user());

            return MyOrganizationCounsellorAffiliationResource::collection($affiliations);
        } catch (Throwable $th) {
            return $this->returnFailure($request, $th);
        }
    }

    public function myMemberships(Request $request)
    {
        try {
            $memberships = OrganizationService::new()->getMyOrganizationMemberships($request->user());

            return MyOrganizationMembershipResource::collection($memberships);
        } catch (Throwable $th) {
            return $this->returnFailure($request, $th);
        }
    }

    public function myAdministeredOrganizations(Request $request)
    {
        try {
            $organizations = OrganizationService::new()->getMyAdministeredOrganizations($request->user());

            return MyAdministeredOrganizationResource::collection($organizations);
        } catch (Throwable $th) {
            return $this->returnFailure($request, $th);
        }
    }

    private function returnSuccess(Request $request, Organization $organization)
    {
        $resource = new OrganizationResource($organization);

        if ($request->acceptsJson()) {
            return response()->json(['organization' => $resource]);
        }

        return Redirect::back()->with(['organization' => $resource]);
    }

    private function returnFailure(Request $request, Throwable $th)
    {
        $status = $this->statusFor($th);
        $message = $this->messageFor($th, $status);

        if ($request->acceptsJson()) {
            return response()->json(['message' => $message], $status);
        }

        return Redirect::back()->withErrors(['alert' => $message]);
    }
}
