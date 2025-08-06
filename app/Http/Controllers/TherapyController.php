<?php

namespace App\Http\Controllers;

use App\DTOs\AssistTherapyDTO;
use App\DTOs\CreateTherapyDTO;
use App\DTOs\GetTherapyDTO;
use App\Http\Requests\TherapyAssistanceRequest;
use App\Http\Requests\CreateTherapyRequest;
use App\Http\Requests\UpdateTherapyRequest;
use App\Http\Resources\RequestResource;
use App\Http\Resources\SessionResource;
use App\Http\Resources\TherapyMiniResource;
use App\Http\Resources\TherapyResource;
use App\Http\Resources\TherapyTopicResource;
use App\Models\Counsellor;
use App\Models\Therapy;
use App\Services\TherapyService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Throwable;

class TherapyController extends Controller
{
    public function getRandomTherapies(Request $request)
    {
        try {
            $therapies = TherapyService::new()->getRandomTherapies($request->user());        

            return TherapyMiniResource::collection($therapies);
        } catch (Throwable $th) {
            $message = $th->getCode() == 500 ? "Something unfortunate happened. Please try again shortly." : $th->getMessage();
            ds($th);
            throw new Exception($message);
        }
    }

    public function getPublicTherapies(Request $request)
    {
        try {
            $therapies = TherapyService::new()->getPublicTherapies($request->user());

            return TherapyMiniResource::collection($therapies);
        } catch (Throwable $th) {
            $message = $th->getCode() == 500 ? "Something unfortunate happened. Please try again shortly." : $th->getMessage();
            ds($th);
            throw new Exception($message);
        }
    }

    public function getUserTherapies(Request $request)
    {
        try {
            $therapies = TherapyService::new()->getUserTherapies($request->user());        

            return TherapyMiniResource::collection($therapies);
        } catch (Throwable $th) {
           $this->returnFailure($request, $th);
        }
    }

    public function getWardTherapies(Request $request)
    {
        try {
            $therapies = TherapyService::new()->getWardTherapies($request->user());        

            return TherapyMiniResource::collection($therapies);
        } catch (Throwable $th) {
           $this->returnFailure($request, $th);
        }
    }

    public function getCounsellorTherapies(Request $request)
    {
        try {
            $therapies = TherapyService::new()->getCounsellorTherapies($request->user());        

            return TherapyMiniResource::collection($therapies);
        } catch (Throwable $th) {
           $this->returnFailure($request, $th);
        }
    }

    public function createTherapy(CreateTherapyRequest $request)
    {
        try {
            $therapy = TherapyService::new()->createTherapy(
                CreateTherapyDTO::new()->fromArray([
                    'user' => $request->user(),
                    'counsellor' => Counsellor::find($request->counsellorId),
                    'name' => $request->name,
                    'backgroundStory' => $request->backgroundStory,
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
                ])
            );
            return response()->json([
                'status' => true,
                'therapy' => new TherapyMiniResource($therapy)
            ]);
        } catch (Throwable $th) {
            $message = $th->getCode() == 500 ? "Something unfortunate happened. Please try again shortly." : $th->getMessage();
            ds($th);
            throw new Exception($message);
        }
    }
    
    public function updateTherapy(UpdateTherapyRequest $request)
    {
        try {
            TherapyService::new()->updateTherapy(
                CreateTherapyDTO::new()->fromArray([
                    'user' => $request->user(),
                    'therapy' => Therapy::find($request->therapyId),
                    'name' => $request->name,
                    'backgroundStory' => $request->backgroundStory,
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
                ])
            );

            return Redirect::back();
        } catch (Throwable $th) {
            $message = $th->getCode() == 500 ? "Something unfortunate happened. Please try again shortly." : $th->getMessage();

            ds($th);
            throw new Exception($message);
        }
    }
    
    public function deleteTherapy(Request $request)
    {
        try {
            TherapyService::new()->deleteTherapy(
                CreateTherapyDTO::new()->fromArray([
                    'user' => $request->user(),
                    'therapy' => Therapy::find($request->therapyId),
                ])
            );

            return Redirect::route('home');
        } catch (Throwable $th) {
            $message = $th->getCode() == 500 ? "Something unfortunate happened. Please try again shortly." : $th->getMessage();

            throw new Exception($message);
        }
    }
    
    public function endTherapy(Request $request)
    {
        try {
            TherapyService::new()->endTherapy(
                CreateTherapyDTO::new()->fromArray([
                    'user' => $request->user(),
                    'therapy' => Therapy::find($request->therapyId),
                ])
            );

            return Redirect::back();
        } catch (Throwable $th) {
            $message = $th->getCode() == 500 ? "Something unfortunate happened. Please try again shortly." : $th->getMessage();

            throw new Exception($message);
        }
    }

    public function getTherapy(Request $request)
    {
        try {
            $therapy = TherapyService::new()->getTherapy(
                GetTherapyDTO::new()->fromArray([
                    'user' => $request->user(),
                    'therapy' => Therapy::find($request->therapyId),
                ])
            );

            $pendingRequest = $therapy->pendingRequestFor($request->user()?->counsellor);

            return Inertia::render('Therapy/Index', [
                'therapy' => new TherapyResource($therapy),
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

    public function sendAssistanceRequest(TherapyAssistanceRequest $request)
    {
        try {
            TherapyService::new()->sendAssistanceRequest(
                AssistTherapyDTO::new()->fromArray([
                    'user' => $request->user(),
                    'counsellors' => Counsellor::findMany($request->counsellorIds),
                    'therapy' => Therapy::find($request->therapyId)
                ])
            );

            return response()->json([
                'status' => true
            ]);
        } catch (Throwable $th) {
            $message = $th->getCode() == 500 ? "Something unfortunate happened. Please try again shortly." : $th->getMessage();

            ds($th);
            throw new Exception($message);
        }
    }

    public function show()
    {
        return Inertia::render('Therapy/Show');
    }

    private function returnFailure(Request $request, Throwable $th)
    {
        $message = $th->getCode() == 500 ? "Something unfortunate happened. Please try again shortly." : $th->getMessage();
        
        ds($th);

        if ($request->acceptsJson()) throw new Exception($message);
        return Redirect::back()->withErrors(['alert'=> $message]);
    }
}
