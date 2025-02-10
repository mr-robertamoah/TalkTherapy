<?php

namespace App\Http\Controllers;

use App\DTOs\GetTherapyDTO;
use App\DTOs\GroupTherapyDTO;
use App\Http\Requests\CreateGroupTherapyRequest;
use App\Http\Requests\CreateTherapyRequest;
use App\Http\Requests\UpdateGroupTherapyRequest;
use App\Http\Resources\GroupTherapyMiniResource;
use App\Http\Resources\GroupTherapyResource;
use App\Http\Resources\RequestResource;
use App\Http\Resources\SessionResource;
use App\Http\Resources\TherapyTopicResource;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Services\GroupTherapyService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Throwable;

class GroupTherapyController extends Controller
{
    public function getRandomGroupTherapies(Request $request)
    {
        try {
            $therapies = GroupTherapyService::new()->getRandomGroupTherapies($request->user());        

            return GroupTherapyMiniResource::collection($therapies);
        } catch (Throwable $th) {
            $message = $th->getCode() == 500 ? "Something unfortunate happened. Please try again shortly." : $th->getMessage();
            ds($th);
            throw new Exception($message);
        }
    }

    public function getUserGroupTherapies(Request $request)
    {
        try {
            $therapies = GroupTherapyService::new()->getUserGroupTherapies($request->user());        

            return GroupTherapyMiniResource::collection($therapies);
        } catch (Throwable $th) {
           $this->returnFailure($request, $th);
        }
    }

    public function getWardGroupTherapies(Request $request)
    {
        try {
            $therapies = GroupTherapyService::new()->getWardGroupTherapies($request->user());        

            return GroupTherapyMiniResource::collection($therapies);
        } catch (Throwable $th) {
           $this->returnFailure($request, $th);
        }
    }

    public function getCounsellorGroupTherapies(Request $request)
    {
        try {
            $therapies = GroupTherapyService::new()->getCounsellorGroupTherapies($request->user());        

            return GroupTherapyMiniResource::collection($therapies);
        } catch (Throwable $th) {
           $this->returnFailure($request, $th);
        }
    }

    public function createGroupTherapy(CreateGroupTherapyRequest $request)
    {
        try {
            $therapy = GroupTherapyService::new()->createGroupTherapy(
                GroupTherapyDTO::new()->fromArray([
                    'user' => $request->user(),
                    'counsellor' => Counsellor::find($request->counsellorId),
                    'name' => $request->name,
                    'about' => $request->about,
                    'per' => $request->per,
                    'currency' => $request->currency,
                    'amount' => $request->amount ? (float) $request->amount : null,
                    'inPersonAmount' => $request->inPersonAmount ? (float) $request->inPersonAmount : null,
                    'allowInPerson' => $request->allowInPerson,
                    'anonymous' => $request->anonymous,
                    'public' => $request->public,
                    'sessionType' => $request->sessionType,
                    'paymentType' => $request->paymentType,
                    'maxSessions' => $request->maxSessions,
                    'cases' => $request->cases,
                    'maxCounsellors' => $request->maxCounsellors,
                    'maxUsers' => $request->maxUsers,
                    'allowAnyone' => $request->allowAnyone,
                    'shareEqually' => $request->shareEqually,
                    'sharePercentage' => $request->sharePercentage ?: null,
                    'counsellorIds' => $request->counsellorIds,
                ])
            );
            return response()->json([
                'status' => true,
                'groupTherapy' => new GroupTherapyMiniResource($therapy)
            ]);
        } catch (Throwable $th) {
            $message = $th->getCode() == 500 ? "Something unfortunate happened. Please try again shortly." : $th->getMessage();
            ds($th);
            throw new Exception($message);
        }
    }
    
    public function updateGroupTherapy(UpdateGroupTherapyRequest $request)
    {
        try {
            GroupTherapyService::new()->updateGroupTherapy(
                GroupTherapyDTO::new()->fromArray([
                    'user' => $request->user(),
                    'therapy' => GroupTherapy::find($request->groupTherapyId),
                    'name' => $request->name,
                    'about' => $request->about,
                    'per' => $request->per,
                    'currency' => $request->currency,
                    'amount' => $request->amount ? (float) $request->amount : null,
                    'inPersonAmount' => $request->inPersonAmount ? (float) $request->inPersonAmount : null,
                    'public' => $request->public,
                    'allowInPerson' => $request->allowInPerson,
                    'anonymous' => $request->anonymous,
                    'sessionType' => $request->sessionType,
                    'paymentType' => $request->paymentType,
                    'maxSessions' => $request->maxSessions,
                    'cases' => $request->cases,
                    'maxCounsellors' => $request->maxCounsellors,
                    'maxUsers' => $request->maxUsers,
                    'allowAnyone' => $request->allowAnyone,
                    'shareEqually' => $request->shareEqually,
                    'sharePercentage' => $request->sharePercentage ?: null,
                    'counsellorIds' => $request->counsellorIds,
                ])
            );

            return Redirect::back();
        } catch (Throwable $th) {
            $message = $th->getCode() == 500 ? "Something unfortunate happened. Please try again shortly." : $th->getMessage();

            ds($th);
            throw new Exception($message);
        }
    }
    
    public function deleteGroupTherapy(Request $request)
    {
        try {
            GroupTherapyService::new()->deleteGroupTherapy(
                GroupTherapyDTO::new()->fromArray([
                    'user' => $request->user(),
                    'therapy' => GroupTherapy::find($request->groupTherapyId),
                ])
            );

            return Redirect::route('home');
        } catch (Throwable $th) {
            $message = $th->getCode() == 500 ? "Something unfortunate happened. Please try again shortly." : $th->getMessage();

            throw new Exception($message);
        }
    }
    
    public function endGroupTherapy(Request $request)
    {
        try {
            GroupTherapyService::new()->endGroupTherapy(
                GroupTherapyDTO::new()->fromArray([
                    'user' => $request->user(),
                    'therapy' => GroupTherapy::find($request->groupTherapyId),
                ])
            );

            return Redirect::back();
        } catch (Throwable $th) {
            $message = $th->getCode() == 500 ? "Something unfortunate happened. Please try again shortly." : $th->getMessage();

            throw new Exception($message);
        }
    }

    public function getGroupTherapy(Request $request)
    {
        try {
            $therapy = GroupTherapyService::new()->getGroupTherapy(
                GetTherapyDTO::new()->fromArray([
                    'user' => $request->user(),
                    'groupTherapy' => GroupTherapy::find($request->groupTherapyId),
                ])
            );

            $pendingRequest = $therapy->pendingRequestFor($request->user()?->counsellor);

            return Inertia::render('GroupTherapy/Index', [
                'therapy' => new GroupTherapyResource($therapy),
                'session' => session('session'),
                'pendingRequest' => $pendingRequest ? new RequestResource($pendingRequest) : null,
                'recentSessions' => SessionResource::collection($therapy->sessions()->latest()->take(5)->get()),
                'recentTopics' => TherapyTopicResource::collection($therapy->topics()->latest()->take(5)->get()),
            ]);
        } catch (Throwable $th) {
            ds($th);
            return Redirect::route('home')->with('message', $th->getMessage());
        }
    }

    private function returnFailure(Request $request, Throwable $th)
    {
        $message = $th->getCode() == 500 ? "Something unfortunate happened. Please try again shortly." : $th->getMessage();
        
        ds($th);

        if ($request->acceptsJson()) throw new Exception($message);
        return Redirect::back()->withErrors(['alert'=> $message]);
    }
}
