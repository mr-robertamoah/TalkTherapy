<?php

namespace App\Http\Controllers;

use App\DTOs\CreateSessionDTO;
use App\DTOs\GetSessionsDTO;
use App\Http\Requests\CreateSessionRequest;
use App\Http\Requests\UpdateSessionRequest;
use App\Http\Resources\SessionResource;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\TherapyTopic;
use App\Services\SessionService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Throwable;

class SessionController extends Controller
{
    public function createSession(CreateSessionRequest $request)
    {
        try {
            $session = SessionService::new()->createSession(
                CreateSessionDTO::new()->fromArray([
                    'user' => $request->user(),
                    'name' => $request->name,
                    'about' => $request->about,
                    'landmark' => $request->landmark,
                    'longitude' => (float) $request->lng,
                    'latitude' => (float) $request->lat,
                    'startTime' => $request->startTime,
                    'endTime' => $request->endTime,
                    'for' => $this->getFor($request),
                    'type' => $request->type,
                    'paymentType' => $request->paymentType,
                    'cases' => $request->cases,
                    'topics' => $request->topics,
                ])
            );

            return $this->returnSuccess($request, $session);
        } catch (Throwable $th) {
            
            return $this->returnFailure($request, $th);
        }
    }

    public function updateSession(UpdateSessionRequest $request)
    {
        $session = Session::find($request->sessionId);

        try {
            $session = SessionService::new()->updateSession(
                CreateSessionDTO::new()->fromArray([
                    'user' => $request->user(),
                    'name' => $request->name,
                    'about' => $request->about,
                    'landmark' => $request->landmark,
                    'longitude' => (float) $request->lng,
                    'latitude' => (float) $request->lat,
                    'startTime' => $request->startTime,
                    'endTime' => $request->endTime,
                    'for' => $session?->for,
                    'session' => $session,
                    'type' => $request->type,
                    'paymentType' => $request->paymentType,
                    'cases' => $request->cases,
                    'topics' => $request->topics,
                ])
            );

            return $this->returnSuccess($request, $session);
        } catch (Throwable $th) {
            
            return $this->returnFailure($request, $th);
        }
    }
    
    public function deleteSession(Request $request)
    {
        $session = Session::find($request->sessionId);

        try {
            SessionService::new()->deleteSession(
                CreateSessionDTO::new()->fromArray([
                    'user' => $request->user(),
                    'for' => $session?->for,
                    'session' => $session,
                ])
            );

            return $this->returnSuccess($request, $session);
        } catch (Throwable $th) {
            
            return $this->returnFailure($request, $th);
        }
    }
    
    public function getSessions(Request $request)
    {
        return SessionService::new()->getSessions(
            GetSessionsDTO::new()->fromArray([
                'therapy' => $this->getFor($request),
                'user' => $request->user(),
                'name' => $request->name
            ])
        );
    }

    public function setCurrentTopic(Request $request)
    {
        $session = Session::find($request->sessionId);

        try {
            $session = SessionService::new()->setCurrentTopic(
                CreateSessionDTO::new()->fromArray([
                    'user' => $request->user(),
                    'session' => $session,
                    'therapyTopic' => TherapyTopic::find($request->topicId),
                ])
            );

            return $this->returnSuccess($request, $session);
        } catch (Throwable $th) {
            
            return $this->returnFailure($request, $th);
        }
    }

    public function unsetCurrentTopic(Request $request)
    {
        $session = Session::find($request->sessionId);

        try {
            $session = SessionService::new()->unsetCurrentTopic(
                CreateSessionDTO::new()->fromArray([
                    'user' => $request->user(),
                    'session' => $session,
                    'therapyTopic' => TherapyTopic::find($request->topicId),
                ])
            );

            return $this->returnSuccess($request, $session);
        } catch (Throwable $th) {
            
            return $this->returnFailure($request, $th);
        }
    }
    
    public function failSession(Request $request)
    {
        $session = Session::find($request->sessionId);

        try {
            $session = SessionService::new()->failSession(
                CreateSessionDTO::new()->fromArray([
                    'user' => $request->user(),
                    'for' => $session?->for,
                    'session' => $session,
                ])
            );

            return $this->returnSuccess($request, $session);
        } catch (Throwable $th) {
            
            return $this->returnFailure($request, $th);
        }
    }
    
    public function endSession(Request $request)
    {
        $session = Session::find($request->sessionId);

        try {
            $session = SessionService::new()->endSession(
                CreateSessionDTO::new()->fromArray([
                    'user' => $request->user(),
                    'for' => $session?->for,
                    'session' => $session,
                ])
            );

            return $this->returnSuccess($request, $session);
        } catch (Throwable $th) {
            
            return $this->returnFailure($request, $th);
        }
    }
    
    public function getInSession(Request $request)
    {
        $session = Session::find($request->sessionId);

        try {
            $session = SessionService::new()->getInSession(
                CreateSessionDTO::new()->fromArray([
                    'user' => $request->user(),
                    'for' => $session?->for,
                    'session' => $session,
                ])
            );

            return $this->returnSuccess($request, $session);
        } catch (Throwable $th) {
            
            return $this->returnFailure($request, $th);
        }
    }
    
    public function abandonSession(Request $request)
    {
        $session = Session::find($request->sessionId);

        try {
            $session = SessionService::new()->abandonSession(
                CreateSessionDTO::new()->fromArray([
                    'user' => $request->user(),
                    'for' => $session?->for,
                    'session' => $session,
                ])
            );

            return $this->returnSuccess($request, $session);
        } catch (Throwable $th) {

            return $this->returnFailure($request, $th);
        }
    }

    private function getFor(Request $request)
    {
        return $request->groupTherapyId
            ? GroupTherapy::find($request->groupTherapyId)
            : Therapy::find($request->therapyId);
    }

    private function returnSuccess(Request $request, Session $session)
    {
        $session = new SessionResource($session);
        
        if ($request->acceptsJson()) return response()->json(['session' => $session]);
        
        return Redirect::back()->with(['session' => $session]);
    }

    private function returnFailure(Request $request, Throwable $th)
    {
        $message = $th->getCode() == 500 ? "Something unfortunate happened. Please try again shortly." : $th->getMessage();
        
        ds($th);

        if ($request->acceptsJson()) throw new Exception($message);
        return Redirect::back()->withErrors(['alert'=> $message]);
    }
}
