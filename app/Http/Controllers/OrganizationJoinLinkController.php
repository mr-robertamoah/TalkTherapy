<?php

namespace App\Http\Controllers;

use App\DTOs\OrganizationDTO;
use App\Http\Resources\OrganizationJoinLinkResource;
use App\Models\Organization;
use App\Services\OrganizationService;
use Illuminate\Http\Request;
use Throwable;

// SCRUM-415: an organisation's admins see the join links made for it and revoke one still unused.
// Creating one stays LinkController::createLink (SCRUM-214).
class OrganizationJoinLinkController extends Controller
{
    public function index(Request $request)
    {
        try {
            $links = OrganizationService::new()->getOrganizationJoinLinks($this->organizationDTO($request));

            return OrganizationJoinLinkResource::collection($links);
        } catch (Throwable $th) {
            return $this->failureResponse($request, $th);
        }
    }

    public function revoke(Request $request)
    {
        try {
            $link = OrganizationService::new()->revokeOrganizationJoinLink(
                $this->organizationDTO($request),
                $request->route('linkId'),
            );

            return response()->json(['link' => new OrganizationJoinLinkResource($link->load('addedby'))]);
        } catch (Throwable $th) {
            return $this->failureResponse($request, $th);
        }
    }

    private function organizationDTO(Request $request): OrganizationDTO
    {
        return OrganizationDTO::new()->fromArray([
            'user' => $request->user(),
            'organization' => Organization::find($request->route('organizationId')),
        ]);
    }
}
