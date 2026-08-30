<?php

namespace App\Http\Controllers;

use App\DTOs\OrganizationAdminDTO;
use App\Http\Requests\AddOrganizationAdminRequest;
use App\Http\Requests\UpdateOrganizationAdminRoleRequest;
use App\Http\Resources\OrganizationAdminResource;
use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationAdminService;
use Illuminate\Http\Request;
use Throwable;

class OrganizationAdminController extends Controller
{
    public function store(AddOrganizationAdminRequest $request)
    {
        try {
            $organization = OrganizationAdminService::new()->addAdmin(
                OrganizationAdminDTO::new()->fromArray([
                    'user' => $request->user(),
                    'organization' => Organization::find($request->route('organizationId')),
                    'admin' => User::find($request->userId),
                    'role' => $request->role,
                ])
            );

            return $this->returnAdmins($organization);
        } catch (Throwable $th) {
            return $this->returnFailure($request, $th);
        }
    }

    // Reads $request->route('userId') rather than the magic ->userId property, matching the
    // SCRUM-116 route-param-spoofing precedent already applied to OrganizationController::update().
    public function update(UpdateOrganizationAdminRoleRequest $request)
    {
        try {
            $organization = OrganizationAdminService::new()->updateAdminRole(
                OrganizationAdminDTO::new()->fromArray([
                    'user' => $request->user(),
                    'organization' => Organization::find($request->route('organizationId')),
                    'admin' => User::find($request->route('userId')),
                    'role' => $request->role,
                ])
            );

            return $this->returnAdmins($organization);
        } catch (Throwable $th) {
            return $this->returnFailure($request, $th);
        }
    }

    public function destroy(Request $request)
    {
        try {
            $organization = OrganizationAdminService::new()->removeAdmin(
                OrganizationAdminDTO::new()->fromArray([
                    'user' => $request->user(),
                    'organization' => Organization::find($request->route('organizationId')),
                    'admin' => User::find($request->route('userId')),
                ])
            );

            return $this->returnAdmins($organization);
        } catch (Throwable $th) {
            return $this->returnFailure($request, $th);
        }
    }

    private function returnAdmins(Organization $organization)
    {
        return response()->json(['admins' => OrganizationAdminResource::collection($organization->admins)]);
    }

    private function returnFailure(Request $request, Throwable $th)
    {
        $status = $this->statusFor($th);
        $message = $this->messageFor($th, $status);

        return response()->json(['message' => $message], $status);
    }
}
