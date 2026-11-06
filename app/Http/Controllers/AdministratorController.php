<?php

namespace App\Http\Controllers;

use App\Actions\Admin\GetAdminToDoCountsAction;
use App\Actions\Admin\GetPlatformStatsAction;
use App\DTOs\DeleteCounsellorDTO;
use App\DTOs\GetCounsellorStatsForAdminDTO;
use App\DTOs\GetModelsForAdminDTO;
use App\DTOs\GetVerificationRequestsDTO;
use App\DTOs\UpdateUserDTO;
use App\Enums\RequestStatusEnum;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Requests\AdminUpdateUserRequest;
use App\Http\Resources\AdminCounsellorResource;
use App\Http\Resources\AdministratorResource;
use App\Http\Resources\AdminUserResource;
use App\Models\Counsellor;
use App\Models\User;
use App\Services\CounsellorService;
use App\Services\RequestService;
use App\Services\UserService;
use App\Support\CounsellorVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Throwable;

class AdministratorController extends Controller
{
    // SCRUM-387 (+ SCRUM-49): the admin area's overview -- what is waiting on the administrators
    // and the platform figures. The `admin` route middleware refuses a non-admin first; each page
    // checks again here.
    public function show(Request $request)
    {
        if ($redirect = $this->refuseNonAdmin($request)) {
            return $redirect;
        }

        $user = $request->user();

        return Inertia::render('Admin/Overview', [
            'administrator' => new AdministratorResource($user->administrator),
            'toDo' => GetAdminToDoCountsAction::new()->execute($user),
            'stats' => GetPlatformStatsAction::new()->execute($user),
        ]);
    }

    // SCRUM-387: the old /administrator tabs, each a page now. Each hosts the component the tab did,
    // which keeps fetching its own list (routes/api.php); what the page itself filters by is in the
    // query string.
    public function users(Request $request)
    {
        return $this->refuseNonAdmin($request) ?? Inertia::render('Admin/People/Users');
    }

    public function counsellors(Request $request)
    {
        return $this->refuseNonAdmin($request) ?? Inertia::render('Admin/People/Counsellors', [
            'search' => Str::limit(trim((string) $request->query('search')), 100, ''),
        ]);
    }

    public function counsellorVerification(Request $request)
    {
        if ($redirect = $this->refuseNonAdmin($request)) {
            return $redirect;
        }

        $status = (string) $request->query('status', RequestStatusEnum::pending->value);

        return Inertia::render('Admin/People/CounsellorVerification', [
            // 'all', or one of the statuses; anything else lists the pending ones.
            'status' => $status === 'all' || RequestStatusEnum::tryFrom($status) ? $status : RequestStatusEnum::pending->value,
            'statuses' => RequestStatusEnum::values(),
            // SCRUM-31: the renewals list (due, ended, revoked) instead of the requests.
            'view' => $request->query('view') === 'renewals' ? 'renewals' : 'requests',
            'renewalWindowDays' => CounsellorVerification::renewalWindowDays(),
            'renewalFilter' => $request->query('filter') === 'ended' ? 'ended' : 'due',
        ]);
    }

    public function testimonials(Request $request)
    {
        return $this->refuseNonAdmin($request) ?? Inertia::render('Admin/Content/Testimonials');
    }

    public function howTos(Request $request)
    {
        return $this->refuseNonAdmin($request) ?? Inertia::render('Admin/Content/HowTos');
    }

    // SCRUM-387: the sidebar's badges, as JSON (admin.badges) -- only the counts, never a page's own
    // queries. The `admin` middleware refuses a non-admin; badges() is empty for one anyway.
    public function badges(Request $request): JsonResponse
    {
        return response()->json(['badges' => GetAdminToDoCountsAction::new()->badges($request->user())]);
    }

    private function refuseNonAdmin(Request $request): ?RedirectResponse
    {
        $user = $request->user();

        if (is_null($user) || $user->isNotAdmin()) {
            return Redirect::route('home')->with('message', EnsureUserIsAdmin::MESSAGE);
        }

        return null;
    }

    public function getCounsellors(Request $request)
    {
        return CounsellorService::new()->geCounsellorsForAdmin(
            GetModelsForAdminDTO::new()->fromArray([
                'user' => $request->user(),
                'filterType' => $request->filterType,
                'filterValue' => $request->filterValue,
            ])
        );
    }

    public function getUsers(Request $request)
    {
        return UserService::new()->getUsersForAdmin(
            GetModelsForAdminDTO::new()->fromArray([
                'user' => $request->user(),
                'filterType' => $request->filterType,
                'filterValue' => $request->filterValue,
            ])
        );
    }

    // Every lookup below reads $request->route('userId'/'counsellorId') rather than the magic
    // ->userId/->counsellorId properties -- see the identical fix/rationale in SessionController
    // (SCRUM-116/SCRUM-130).
    public function updateUser(AdminUpdateUserRequest $request)
    {
        try {
            $user = UserService::new()->updateUserByAdmin(
                UpdateUserDTO::new()->fromArray([
                    'user' => $request->user(),
                    'updatedUser' => User::find($request->route('userId')),
                    'firstName' => $request->firstName,
                    'lastName' => $request->lastName,
                    'otherNames' => $request->otherNames,
                    'email' => $request->email,
                    'country' => $request->country,
                    'emailVerified' => $request->boolean('emailVerified'),
                    'dob' => $request->dob,
                ])
            );

            return $this->successResponse($request, ['user' => new AdminUserResource($user)]);
        } catch (Throwable $th) {

            return $this->failureResponse($request, $th);
        }
    }

    public function deleteUser(AdminUpdateUserRequest $request)
    {
        $user = User::find($request->route('userId'));
        try {
            UserService::new()->deleteUserByAdmin(
                UpdateUserDTO::new()->fromArray([
                    'user' => $request->user(),
                    'updatedUser' => $user,
                ])
            );

            return $this->successResponse($request, ['user' => new AdminUserResource($user)]);
        } catch (Throwable $th) {

            return $this->failureResponse($request, $th);
        }
    }

    // SCRUM-134: reuses the same CounsellorService::deleteCounsellor() chain as self-service
    // deletion -- EnsureCanDeleteCounsellorAction already requires isSuperAdmin() for this branch
    // and applies the same eligibility checks (no pending sessions/in-session therapy/active
    // affiliations) as it does to a counsellor deleting their own account; there is no
    // admin-only bypass of those safety checks.
    public function deleteCounsellor(Request $request)
    {
        $counsellor = Counsellor::find($request->route('counsellorId'));

        try {
            CounsellorService::new()->deleteCounsellor(
                DeleteCounsellorDTO::new()->fromArray([
                    'user' => $request->user(),
                    'counsellor' => $counsellor,
                ])
            );

            return $this->successResponse($request, ['counsellor' => new AdminCounsellorResource($counsellor)]);
        } catch (Throwable $th) {

            return $this->failureResponse($request, $th);
        }
    }

    public function getCounsellorStats(Request $request)
    {
        return CounsellorService::new()->getCounsellorStats(
            GetCounsellorStatsForAdminDTO::new()->fromArray([
                'user' => $request->user(),
                'counsellor' => Counsellor::find($request->route('counsellorId')),
            ])
        );
    }

    public function getVerificationRequests(Request $request)
    {
        return RequestService::new()->getVerificationRequestsForCounsellors(
            GetVerificationRequestsDTO::new()->fromArray([
                'user' => $request->user(),
                'filterType' => $request->filterType,
                'filterValue' => $request->filterValue,
            ])
        );
    }
}
