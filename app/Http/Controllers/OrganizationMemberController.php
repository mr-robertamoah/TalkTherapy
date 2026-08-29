<?php

namespace App\Http\Controllers;

use App\DTOs\OrganizationDTO;
use App\DTOs\OrganizationMemberRequestDTO;
use App\Http\Requests\InviteOrganizationMemberRequest;
use App\Http\Resources\OrganizationMemberResource;
use App\Http\Resources\OrganizationRequestResource;
use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationMemberRequestService;
use App\Services\OrganizationService;
use Illuminate\Http\Request;
use Throwable;

class OrganizationMemberController extends Controller
{
    // Admin-only (TT-6.6a) -- guarded by EnsureUserIsOrganizationAdminAction inside the service,
    // same as show()/update() on OrganizationController.
    public function index(Request $request)
    {
        try {
            $members = OrganizationService::new()->getOrganizationMembers(
                OrganizationDTO::new()->fromArray([
                    'user' => $request->user(),
                    'organization' => Organization::find($request->route('organizationId')),
                ])
            );

            return OrganizationMemberResource::collection($members);
        } catch (Throwable $th) {
            return $this->returnFailure($request, $th);
        }
    }

    // Deliberately does NOT return the invited user's full OrganizationRequestResource/
    // UserMiniResource (name/username/gender/country/dob) -- unlike a Counsellor profile, an
    // ordinary User isn't meant to be publicly/cross-org discoverable, and the inviting admin
    // already knows the id they supplied. Echoing it back would let any org admin enumerate
    // arbitrary users' PII (and create a persisted pending invite against them) simply by
    // guessing ids (security review finding, SCRUM-124).
    public function invite(InviteOrganizationMemberRequest $request)
    {
        try {
            $organizationRequest = OrganizationMemberRequestService::new()->inviteMember(
                OrganizationMemberRequestDTO::new()->fromArray([
                    'user' => $request->user(),
                    'organization' => Organization::find($request->route('organizationId')),
                    'member' => User::find($request->userId),
                ])
            );

            return response()->json(['request' => [
                'id' => $organizationRequest->id,
                'type' => $organizationRequest->type,
                'status' => $organizationRequest->status,
            ]]);
        } catch (Throwable $th) {
            return $this->returnFailure($request, $th);
        }
    }

    public function apply(Request $request)
    {
        try {
            $organizationRequest = OrganizationMemberRequestService::new()->applyAsMember(
                OrganizationMemberRequestDTO::new()->fromArray([
                    'user' => $request->user(),
                    'organization' => Organization::find($request->route('organizationId')),
                    'member' => $request->user(),
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
