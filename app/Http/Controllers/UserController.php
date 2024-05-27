<?php

namespace App\Http\Controllers;

use App\Actions\GetModelWithModelNameAndIdAction;
use App\DTOs\CreateRequestDTO;
use App\DTOs\GetGuardianshipDTO;
use App\DTOs\GetUsersDTO;
use App\Http\Resources\GuardianshipResource;
use App\Http\Resources\UserMiniResource;
use App\Models\Guardianship;
use App\Models\User;
use App\Services\UserService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Throwable;

class UserController extends Controller
{
    public function getUsers(Request $request)
    {
        try {
            return UserService::new()->getUsers(
                GetUsersDTO::new()->fromArray([
                    'user' => $request->user(),
                    'like' => $request->like,
                ])
            );
        } catch (Throwable $th) {
            
            return $this->returnFailure($request, $th);
        }
    }

    public function sendGuardianshipRequest(Request $request)
    {
        try {
            return UserService::new()->sendGuardianshipRequest(
                CreateRequestDTO::new()->fromArray([
                    'from' => $request->user(),
                    'for' => $request->user(),
                    'to' => User::find($request->guardianId),
                ])
            );
        } catch (Throwable $th) {
            return $this->returnFailure($request, $th);
        }
    }

    public function deleteGuardianship(Request $request)
    {
        try {
            UserService::new()->deleteGuardianship(
                GetGuardianshipDTO::new()->fromArray([
                    'user' => $request->user(),
                    'guardianship' => $guardianship = Guardianship::find($request->guardianshipId),
                ])
            );

            return response()->json([
                'guardianship' => new GuardianshipResource($guardianship)
            ]);
        } catch (Throwable $th) {
            return $this->returnFailure($request, $th);
        }
    }

    public function getGuardianship(Request $request)
    {
        try {
            return UserService::new()->getGuardianship($request->user());
        } catch (Throwable $th) {
            return $this->returnFailure($request, $th);
        }
    }

    private function returnSuccess(Request $request, User $user)
    {
        $user = new UserMiniResource($user);
        
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
