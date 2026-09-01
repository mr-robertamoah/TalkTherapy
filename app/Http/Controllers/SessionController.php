<?php

namespace App\Http\Controllers;

use App\DTOs\CreateSessionDTO;
use App\DTOs\GetCounsellorCalendarSessionsDTO;
use App\DTOs\GetSessionsDTO;
use App\Http\Requests\CreateSessionRequest;
use App\Http\Requests\GetCounsellorCalendarSessionsRequest;
use App\Http\Requests\UpdateSessionRequest;
use App\Http\Resources\SessionResource;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\TherapyTopic;
use App\Services\SessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Throwable;

class SessionController extends Controller
{
    // SCRUM-213/TT-2.6b: the page itself carries no session data -- the calendar fetches its own
    // range-scoped data client-side from getCalendarSessions() above, matching this ticket's own
    // "range-scoped fetching" requirement (never the counsellor's entire session history in one
    // payload). Counsellor-only, unlike MyOrganizationsDashboard's "any user, optional sections"
    // pattern -- a calendar has no meaning at all for a non-counsellor.
    public function calendar(Request $request)
    {
        if (! $request->user()->counsellor) {
            return Redirect::route('home')->withErrors(['alert' => 'You have to be a counsellor to view a session calendar.']);
        }

        return Inertia::render('Counsellor/Calendar');
    }

    public function getCalendarSessions(GetCounsellorCalendarSessionsRequest $request)
    {
        try {
            $sessions = SessionService::new()->getCounsellorCalendarSessions(
                GetCounsellorCalendarSessionsDTO::new()->fromArray([
                    'user' => $request->user(),
                    'startDate' => $request->startDate,
                    'endDate' => $request->endDate,
                ])
            );

            return response()->json(['sessions' => $sessions]);
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return response()->json(['message' => $message], $status);
        }
    }

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

    // Every lookup below reads $request->route(...) rather than the magic ->sessionId/etc.
    // properties: Request::__get() prefers a same-named parsed-body/query key over the route
    // parameter, so a client could otherwise send e.g. {"sessionId": 42} in the body of a
    // request to a completely different session's URL and have it silently resolve to the
    // spoofed id instead (SCRUM-116, same class of bug SCRUM-110 fixed in TransactionController).
    public function updateSession(UpdateSessionRequest $request)
    {
        $session = Session::find($request->route('sessionId'));

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
        $session = Session::find($request->route('sessionId'));

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
                'name' => $request->name,
            ])
        );
    }

    public function setCurrentTopic(Request $request)
    {
        $session = Session::find($request->route('sessionId'));

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
        $session = Session::find($request->route('sessionId'));

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
        $session = Session::find($request->route('sessionId'));

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
        $session = Session::find($request->route('sessionId'));

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
        $session = Session::find($request->route('sessionId'));

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
        $session = Session::find($request->route('sessionId'));

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
        return $request->route('groupTherapyId')
            ? GroupTherapy::find($request->route('groupTherapyId'))
            : Therapy::find($request->route('therapyId'));
    }

    private function returnSuccess(Request $request, Session $session)
    {
        $session = new SessionResource($session);

        if ($request->acceptsJson()) {
            return response()->json(['session' => $session]);
        }

        return Redirect::back()->with(['session' => $session]);
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
