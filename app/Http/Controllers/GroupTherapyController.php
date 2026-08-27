<?php

namespace App\Http\Controllers;

use App\DTOs\GetTherapyDTO;
use App\DTOs\GroupTherapyDTO;
use App\DTOs\JoinGroupTherapyDTO;
use App\Http\Requests\CreateGroupTherapyRequest;
use App\Http\Requests\UpdateGroupTherapyRequest;
use App\Http\Resources\GroupTherapyMiniResource;
use App\Http\Resources\GroupTherapyResource;
use App\Http\Resources\RequestResource;
use App\Http\Resources\SessionResource;
use App\Http\Resources\TherapyTopicResource;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Services\GroupTherapyService;
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
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return response()->json(['message' => $message], $status);
        }
    }

    public function getUserGroupTherapies(Request $request)
    {
        try {
            $therapies = GroupTherapyService::new()->getUserGroupTherapies($request->user());

            return GroupTherapyMiniResource::collection($therapies);
        } catch (Throwable $th) {
            return $this->returnFailure($request, $th);
        }
    }

    public function getWardGroupTherapies(Request $request)
    {
        try {
            $therapies = GroupTherapyService::new()->getWardGroupTherapies($request->user());

            return GroupTherapyMiniResource::collection($therapies);
        } catch (Throwable $th) {
            return $this->returnFailure($request, $th);
        }
    }

    public function getCounsellorGroupTherapies(Request $request)
    {
        try {
            $therapies = GroupTherapyService::new()->getCounsellorGroupTherapies($request->user());

            return GroupTherapyMiniResource::collection($therapies);
        } catch (Throwable $th) {
            return $this->returnFailure($request, $th);
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
                'groupTherapy' => new GroupTherapyMiniResource($therapy),
            ]);
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return response()->json(['message' => $message], $status);
        }
    }

    // Every lookup below reads $request->route(...) rather than the magic ->groupTherapyId
    // property -- see the identical fix/rationale in SessionController (SCRUM-116).
    public function updateGroupTherapy(UpdateGroupTherapyRequest $request)
    {
        try {
            GroupTherapyService::new()->updateGroupTherapy(
                GroupTherapyDTO::new()->fromArray([
                    'user' => $request->user(),
                    'groupTherapy' => GroupTherapy::find($request->route('groupTherapyId')),
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
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return Redirect::back()->withErrors(['alert' => $message]);
        }
    }

    public function deleteGroupTherapy(Request $request)
    {
        try {
            GroupTherapyService::new()->deleteGroupTherapy(
                GroupTherapyDTO::new()->fromArray([
                    'user' => $request->user(),
                    'groupTherapy' => GroupTherapy::find($request->route('groupTherapyId')),
                ])
            );

            return Redirect::route('home');
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return Redirect::back()->withErrors(['alert' => $message]);
        }
    }

    public function endGroupTherapy(Request $request)
    {
        try {
            GroupTherapyService::new()->endGroupTherapy(
                GroupTherapyDTO::new()->fromArray([
                    'user' => $request->user(),
                    'therapy' => GroupTherapy::find($request->route('groupTherapyId')),
                ])
            );

            return Redirect::back();
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return Redirect::back()->withErrors(['alert' => $message]);
        }
    }

    public function getGroupTherapy(Request $request)
    {
        try {
            $therapy = GroupTherapyService::new()->getGroupTherapy(
                GetTherapyDTO::new()->fromArray([
                    'user' => $request->user(),
                    'groupTherapy' => GroupTherapy::find($request->route('groupTherapyId')),
                ])
            );

            $pendingRequest = $therapy->pendingRequestFor($request->user()?->counsellor);
            $pendingMembershipRequest = $request->user()
                ? $therapy->pendingMembershipRequestFor($request->user())
                : null;

            return Inertia::render('GroupTherapy/Index', [
                'therapy' => new GroupTherapyResource($therapy),
                'session' => session('session'),
                'pendingRequest' => $pendingRequest ? new RequestResource($pendingRequest) : null,
                'pendingMembershipRequest' => $pendingMembershipRequest ? new RequestResource($pendingMembershipRequest) : null,
                'recentSessions' => SessionResource::collection($therapy->sessions()->latest()->take(5)->get()),
                'recentTopics' => TherapyTopicResource::collection($therapy->topics()->latest()->take(5)->get()),
            ]);
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return Redirect::route('home')->with('message', $message);
        }
    }

    public function joinGroupTherapy(Request $request)
    {
        try {
            $result = GroupTherapyService::new()->joinGroupTherapy(
                JoinGroupTherapyDTO::new()->fromArray([
                    'user' => $request->user(),
                    'groupTherapy' => GroupTherapy::find($request->route('groupTherapyId')),
                    'anonymous' => $request->boolean('anonymous'),
                ])
            );

            return response()->json([
                'status' => true,
                'result' => $result instanceof GroupTherapy
                    ? new GroupTherapyResource($result)
                    : new RequestResource($result),
            ]);
        } catch (Throwable $th) {
            return $this->returnFailure($request, $th);
        }
    }

    public function chat(Request $request)
    {
        try {
            $therapy = GroupTherapyService::new()->getGroupTherapy(
                GetTherapyDTO::new()->fromArray([
                    'user' => $request->user(),
                    'groupTherapy' => GroupTherapy::find($request->route('groupTherapyId')),
                ])
            );

            return Inertia::render('GroupTherapy/Chat', [
                'therapy' => new GroupTherapyResource($therapy),
            ]);
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return Redirect::route('home')->with('message', $message);
        }
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
