<?php

namespace App\Http\Controllers;

use App\DTOs\GetOrganizationDirectoryDTO;
use App\DTOs\OrganizationDTO;
use App\Http\Requests\CreateOrganizationRequest;
use App\Http\Requests\UpdateOrganizationRequest;
use App\Http\Resources\OrganizationCounsellorResource;
use App\Http\Resources\OrganizationDirectoryResource;
use App\Http\Resources\OrganizationMemberResource;
use App\Http\Resources\OrganizationResource;
use App\Http\Resources\RequestResource;
use App\Models\Organization;
use App\Services\OrganizationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
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

    // Org admin dashboard (SCRUM-165/TT-6.5a). A non-admin (or a nonexistent organizationId)
    // never reaches a raw error page -- redirected home with the same message show()'s JSON
    // callers already get, since this is a browser navigation, not an API call.
    public function dashboard(Request $request)
    {
        try {
            $dto = OrganizationDTO::new()->fromArray([
                'user' => $request->user(),
                'organization' => Organization::find($request->route('organizationId')),
            ]);

            $organization = OrganizationService::new()->getOrganization($dto);
            $dto->organization = $organization;

            // Each paginator's path defaults to the CURRENT request's URL (this dashboard route),
            // not the dedicated JSON list endpoint -- left alone, links.next would point back at
            // this Inertia page, which the frontend's plain axios "load more" GET can't consume
            // (no X-Inertia header means it gets the full HTML page, not JSON). Explicitly
            // pointing each paginator at its own dedicated route keeps "load more" working
            // identically whether the page came via these initial props or a later fetch.
            $counsellors = null;
            if ($organization->is_provider) {
                $counsellorsPaginator = OrganizationService::new()->getOrganizationCounsellors($dto);
                $counsellorsPaginator->setPath(route('organizations.counsellors.index', ['organizationId' => $organization->id]));
                $counsellors = $this->paginatedResource(OrganizationCounsellorResource::collection($counsellorsPaginator));
            }

            $members = null;
            if ($organization->is_consumer) {
                $membersPaginator = OrganizationService::new()->getOrganizationMembers($dto);
                $membersPaginator->setPath(route('organizations.members.index', ['organizationId' => $organization->id]));
                $members = $this->paginatedResource(OrganizationMemberResource::collection($membersPaginator));
            }

            $requestQueuePaginator = OrganizationService::new()->getOrganizationRequestQueue($dto);
            $requestQueuePaginator->setPath(route('organizations.requests.index', ['organizationId' => $organization->id]));

            return Inertia::render('Organization/Show', [
                'organization' => new OrganizationResource($organization),
                'counsellors' => $counsellors,
                'members' => $members,
                'requestQueue' => $this->paginatedResource(RequestResource::collection($requestQueuePaginator)),
            ]);
        } catch (Throwable $th) {
            $message = $this->messageFor($th, $this->statusFor($th));

            return Redirect::route('home')->withErrors(['alert' => $message]);
        }
    }

    public function requestQueue(Request $request)
    {
        try {
            $requests = OrganizationService::new()->getOrganizationRequestQueue(
                OrganizationDTO::new()->fromArray([
                    'user' => $request->user(),
                    'organization' => Organization::find($request->route('organizationId')),
                ])
            );

            return RequestResource::collection($requests);
        } catch (Throwable $th) {
            return $this->returnFailure($request, $th);
        }
    }

    // A paginated resource collection's data/links/meta envelope is only produced by
    // Laravel's Responsable::toResponse() -- nesting the collection directly inside another
    // array (as an Inertia prop) would otherwise serialize as a flat array with the pagination
    // metadata silently dropped (same class of bug already fixed for controller JSON responses
    // in SCRUM-159).
    private function paginatedResource(AnonymousResourceCollection $resource): array
    {
        return $resource->response()->getData(true);
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
