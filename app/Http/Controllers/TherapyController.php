<?php

namespace App\Http\Controllers;

use App\DTOs\AssistTherapyDTO;
use App\DTOs\CreateTherapyDTO;
use App\DTOs\GetTherapyDTO;
use App\Exceptions\PaymentRequiredException;
use App\Http\Requests\CreateTherapyRequest;
use App\Http\Requests\TherapyAssistanceRequest;
use App\Http\Requests\UpdateTherapyRequest;
use App\Http\Resources\PublicTherapyResource;
use App\Http\Resources\RequestResource;
use App\Http\Resources\SessionResource;
use App\Http\Resources\TherapyMiniResource;
use App\Http\Resources\TherapyResource;
use App\Http\Resources\TherapyTopicResource;
use App\Models\Counsellor;
use App\Models\Therapy;
use App\Services\TherapyService;
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
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return response()->json(['message' => $message], $status);
        }
    }

    public function getPublicTherapies(Request $request)
    {
        try {
            $therapies = TherapyService::new()->getPublicTherapies($request->user());

            return PublicTherapyResource::collection($therapies);
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return response()->json(['message' => $message], $status);
        }
    }

    public function getUserTherapies(Request $request)
    {
        try {
            $therapies = TherapyService::new()->getUserTherapies($request->user());

            return TherapyMiniResource::collection($therapies);
        } catch (Throwable $th) {
            return $this->returnFailure($request, $th);
        }
    }

    public function getWardTherapies(Request $request)
    {
        try {
            $therapies = TherapyService::new()->getWardTherapies($request->user());

            return TherapyMiniResource::collection($therapies);
        } catch (Throwable $th) {
            return $this->returnFailure($request, $th);
        }
    }

    public function getCounsellorTherapies(Request $request)
    {
        try {
            $therapies = TherapyService::new()->getCounsellorTherapies($request->user());

            return TherapyMiniResource::collection($therapies);
        } catch (Throwable $th) {
            return $this->returnFailure($request, $th);
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
                    'strictPaymentGate' => $request->strictPaymentGate,
                ])
            );

            return response()->json([
                'status' => true,
                'therapy' => new TherapyMiniResource($therapy),
            ]);
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return response()->json(['message' => $message], $status);
        }
    }

    // Every lookup below reads $request->route(...) rather than the magic ->therapyId property
    // -- see the identical fix/rationale in SessionController (SCRUM-116).
    public function updateTherapy(UpdateTherapyRequest $request)
    {
        try {
            TherapyService::new()->updateTherapy(
                CreateTherapyDTO::new()->fromArray([
                    'user' => $request->user(),
                    'therapy' => Therapy::find($request->route('therapyId')),
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
                    'strictPaymentGate' => $request->strictPaymentGate,
                ])
            );

            return Redirect::back();
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return Redirect::back()->withErrors(['alert' => $message]);
        }
    }

    public function deleteTherapy(Request $request)
    {
        try {
            TherapyService::new()->deleteTherapy(
                CreateTherapyDTO::new()->fromArray([
                    'user' => $request->user(),
                    'therapy' => Therapy::find($request->route('therapyId')),
                ])
            );

            return Redirect::route('home');
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return Redirect::back()->withErrors(['alert' => $message]);
        }
    }

    public function endTherapy(Request $request)
    {
        try {
            TherapyService::new()->endTherapy(
                CreateTherapyDTO::new()->fromArray([
                    'user' => $request->user(),
                    'therapy' => Therapy::find($request->route('therapyId')),
                ])
            );

            return Redirect::back();
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return Redirect::back()->withErrors(['alert' => $message]);
        }
    }

    public function getTherapy(Request $request)
    {
        try {
            $therapy = TherapyService::new()->getTherapy(
                GetTherapyDTO::new()->fromArray([
                    'user' => $request->user(),
                    'therapy' => Therapy::find($request->route('therapyId')),
                ])
            );

            $pendingRequest = $therapy->pendingRequestFor($request->user()?->counsellor);
            $pendingSessionScheduleProposal = $therapy->pendingSessionScheduleProposal();

            return Inertia::render('Therapy/Index', [
                'therapy' => new TherapyResource($therapy),
                'session' => session('session'),
                'transactionStatus' => session('transactionStatus'),
                'pendingRequest' => $pendingRequest ? new RequestResource($pendingRequest) : null,
                'pendingSessionScheduleProposal' => $pendingSessionScheduleProposal ? new RequestResource($pendingSessionScheduleProposal) : null,
                'recentSessions' => SessionResource::collection($therapy->sessions()->with('latestTransaction')->latest()->take(5)->get()),
                'recentTopics' => TherapyTopicResource::collection($therapy->topics()->latest()->take(5)->get()),
            ]);
        } catch (PaymentRequiredException $th) {
            return $this->redirectForPaymentRequired($th, $request);
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return Redirect::route('home')->with('message', $message);
        }
    }

    public function chat(Request $request)
    {
        try {
            $therapy = TherapyService::new()->getTherapy(
                GetTherapyDTO::new()->fromArray([
                    'user' => $request->user(),
                    'therapy' => Therapy::find($request->route('therapyId')),
                ])
            );

            return Inertia::render('Therapy/Chat', [
                'therapy' => new TherapyResource($therapy),
            ]);
        } catch (PaymentRequiredException $th) {
            // SCRUM-219/TT-7.5a: chat() is reached through the same EnsureUserHasAccessToTherapyAction
            // as getTherapy() above, so it can throw this exact exception too -- keep both entry
            // points consistent rather than letting this one fall through to the generic message.
            return $this->redirectForPaymentRequired($th, $request);
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return Redirect::route('home')->with('message', $message);
        }
    }

    // SCRUM-219/TT-7.5a: distinct from the generic access-denied catch in both callers above --
    // flags a recoverable "pay to continue" state (not a hard access denial) via a distinguishable
    // flash key, rather than a plain message a blocked client has no way to act on. The actual
    // "resume payment from here" UI is SCRUM-221's job; this only keeps the information needed
    // for it flowing through, matching TT-7.4a's transactionStatus flash-prop precedent.
    private function redirectForPaymentRequired(PaymentRequiredException $th, Request $request)
    {
        return Redirect::route('home')
            ->with('message', $th->getMessage())
            ->with('paymentRequired', true)
            ->with('paymentRequiredTherapyId', $request->route('therapyId'));
    }

    public function sendAssistanceRequest(TherapyAssistanceRequest $request)
    {
        try {
            TherapyService::new()->sendAssistanceRequest(
                AssistTherapyDTO::new()->fromArray([
                    'user' => $request->user(),
                    'counsellors' => Counsellor::findMany($request->counsellorIds),
                    'therapy' => Therapy::find($request->route('therapyId')),
                ])
            );

            return response()->json([
                'status' => true,
            ]);
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return response()->json(['message' => $message], $status);
        }
    }

    public function show()
    {
        return Inertia::render('Therapy/Show');
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
