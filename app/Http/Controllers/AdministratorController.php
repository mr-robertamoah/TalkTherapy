<?php

namespace App\Http\Controllers;

use App\DTOs\GetModelsForAdminDTO;
use App\DTOs\GetCounsellorStatsForAdminDTO;
use App\DTOs\GetVerificationRequestsDTO;
use App\DTOs\UpdateUserDTO;
use App\Http\Requests\AdminUpdateUserRequest;
use App\Http\Resources\AdministratorResource;
use App\Http\Resources\AdminUserResource;
use App\Models\Counsellor;
use App\Models\User;
use App\Services\CounsellorService;
use App\Services\RequestService;
use App\Services\UserService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Throwable;

class AdministratorController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        if (is_null($user) || $user->isNotAdmin()) {
            return Redirect::route('home')->with('message', 'You are not authorized to visit /administrator page.');
        }

        return Inertia::render('Admin', [
            'administrator' => new AdministratorResource($user->administrator)
        ]);
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

    public function updateUser(AdminUpdateUserRequest $request)
    {
        try {
            $user = UserService::new()->updateUserByAdmin(
                UpdateUserDTO::new()->fromArray([
                    'user' => $request->user(),
                    'updatedUser' => User::find($request->userId),
                    'firstName' => $request->firstName,
                    'lastName' => $request->lastName,
                    'otherNames' => $request->otherNames,
                    'email' => $request->email,
                    'country' => $request->country,
                    'emailVerified' => !!$request->emailVerified,
                    'dob' => $request->dob,
                ])
            );

            return $this->returnSuccess($request, $user);
        } catch (Throwable $th) {
            
            return $this->returnFailure($request, $th);
        }
    }

    public function deleteUser(AdminUpdateUserRequest $request)
    {
        $user = User::find($request->userId);
        try {
            UserService::new()->deleteUserByAdmin(
                UpdateUserDTO::new()->fromArray([
                    'user' => $request->user(),
                    'updatedUser' => $user,
                ])
            );

            return $this->returnSuccess($request, $user);
        } catch (Throwable $th) {
            
            return $this->returnFailure($request, $th);
        }
    }

    public function getCounsellorStats(Request $request)
    {
        return CounsellorService::new()->getCounsellorStats(
            GetCounsellorStatsForAdminDTO::new()->fromArray([
                'user' => $request->user(),
                'counsellor' => Counsellor::find($request->counsellorId),
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

    private function returnSuccess(Request $request, User $user)
    {
        $user = new AdminUserResource($user);
        
        if ($request->acceptsJson()) return response()->json(['user' => $user]);
        
        return Redirect::back()->with(['user' => $user]);
    }

    private function returnFailure(Request $request, Throwable $th)
    {
        $message = $th->getCode() == 500 ? "Something unfortunate happened. Please try again shortly." : $th->getMessage();
        
        ds($th);

        if ($request->acceptsJson()) throw new Exception($message);
        return Redirect::back()->withErrors(['alert'=> $message]);
    }
}
