<?php

namespace App\Http\Controllers;

use App\DTOs\CreateTherapyDTO;
use App\DTOs\GetTherapyDTO;
use App\Http\Requests\CreateTherapyRequest;
use App\Http\Resources\TherapyMiniResource;
use App\Http\Resources\TherapyResource;
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
                    'amount' => $request->amount,
                    'public' => $request->public,
                    'allowInPerson' => $request->allowInPerson,
                    'anonymous' => $request->anonymous,
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

            return Inertia::render('Therapy/Index', [
                'therapy' => new TherapyResource($therapy)
            ]);
        } catch (Throwable $th) {
            return Redirect::route('home')->with('message', $th->getMessage());
        }
    }

    public function show(Request $request)
    {
        return Inertia::render('Therapy/Show', [
            'therapies' => TherapyResource::collection(
                TherapyService::new()->getTherapies($request->user())
            )
        ]);
    }
}
