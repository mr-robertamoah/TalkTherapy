<?php

namespace App\Http\Controllers;

use App\Actions\GetModelWithModelNameAndIdAction;
use App\DTOs\CreateDiscussionDTO;
use App\DTOs\CreateRequestDTO;
use App\DTOs\GetDiscussionsDTO;
use App\Http\Requests\CreateDiscussionRequest;
use App\Http\Resources\CounsellorResource;
use App\Http\Resources\DiscussionResource;
use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\Session;
use App\Services\DiscussionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Throwable;

class DiscussionController extends Controller
{
    // Every lookup below reads $request->route('discussionId') rather than the magic
    // ->discussionId property -- Illuminate\Http\Request::__get() prefers a same-named
    // parsed-body/query key over the route parameter, so a client could otherwise override which
    // discussion a request targets by sending a same-named body field (SCRUM-116/SCRUM-130).
    public function showChat(Request $request)
    {
        $discussion = Discussion::find($request->route('discussionId'));

        abort_unless($discussion, 404);

        $user = $request->user();

        abort_if(
            $user->isNotAdmin() && $discussion->isNotParticipant($user->counsellor),
            403,
            'You are not allowed to view this discussion.'
        );

        return Inertia::render('Discussion/Chat', [
            'discussion' => new DiscussionResource($discussion),
        ]);
    }

    public function createDiscussion(CreateDiscussionRequest $request)
    {
        try {
            $discussion = DiscussionService::new()->createDiscussion(
                CreateDiscussionDTO::new()->fromArray([
                    'user' => $request->user(),
                    'description' => $request->description,
                    'name' => $request->name,
                    'startTime' => $request->startTime,
                    'endTime' => $request->endTime,
                    'session' => Session::find($request->sessionId),
                    'addedby' => GetModelWithModelNameAndIdAction::new()->execute($request->addedbyType, $request->addedbyId),
                    'for' => GetModelWithModelNameAndIdAction::new()->execute($request->forType, $request->forId),
                ])
            );

            return $this->returnSuccess($request, $discussion);
        } catch (Throwable $th) {

            return $this->returnFailure($request, $th);
        }
    }

    public function updateDiscussion(Request $request)
    {
        $discussion = Discussion::find($request->route('discussionId'));

        try {
            $discussion = DiscussionService::new()->updateDiscussion(
                CreateDiscussionDTO::new()->fromArray([
                    'user' => $request->user(),
                    'description' => $request->description,
                    'name' => $request->name,
                    'discussion' => $discussion,
                    'startTime' => $request->startTime,
                    'endTime' => $request->endTime,
                    'addedby' => $discussion->addedby,
                    'session' => Session::find($request->sessionId),
                    'deletedSession' => Session::find($request->deletedSessionId),
                    'for' => $discussion?->for,
                ])
            );

            return $this->returnSuccess($request, $discussion);
        } catch (Throwable $th) {

            return $this->returnFailure($request, $th);
        }
    }

    public function deleteDiscussion(Request $request)
    {
        try {
            DiscussionService::new()->deleteDiscussion(
                CreateDiscussionDTO::new()->fromArray([
                    'user' => $request->user(),
                    'discussion' => $discussion = Discussion::find($request->route('discussionId')),
                ])
            );

            return $this->returnSuccess($request, $discussion);
        } catch (Throwable $th) {

            return $this->returnFailure($request, $th);
        }
    }

    public function sendCounsellorRequest(Request $request)
    {
        try {
            return DiscussionService::new()->sendCounsellorRequest(
                CreateRequestDTO::new()->fromArray([
                    'from' => $request->user()?->counsellor,
                    'for' => Discussion::find($request->route('discussionId')),
                    'to' => Counsellor::find($request->counsellorId),
                ])
            );
        } catch (Throwable $th) {
            return $this->returnFailure($request, $th);
        }
    }

    public function removeCounsellor(Request $request)
    {
        try {
            DiscussionService::new()->removeCounsellor(
                GetDiscussionsDTO::new()->fromArray([
                    'user' => $request->user(),
                    'discussion' => Discussion::find($request->route('discussionId')),
                    'counsellor' => $counsellor = Counsellor::find($request->counsellorId),
                ])
            );

            return response()->json([
                'counsellor' => new CounsellorResource($counsellor),
            ]);
        } catch (Throwable $th) {
            return $this->returnFailure($request, $th);
        }
    }

    public function getDiscussions(Request $request)
    {
        try {
            return DiscussionService::new()->getDiscussions(
                GetDiscussionsDTO::new()->fromArray([
                    'user' => $request->user(),
                    'name' => $request->name,
                    'counsellor' => Counsellor::find($request->counsellorId),
                    'for' => GetModelWithModelNameAndIdAction::new()->execute($request->forType, $request->forId),
                ])
            );
        } catch (Throwable $th) {

            return $this->returnFailure($request, $th);
        }
    }

    public function getDiscussionCounsellors(Request $request)
    {
        try {
            return DiscussionService::new()->getDiscussionCounsellors(
                GetDiscussionsDTO::new()->fromArray([
                    'user' => $request->user(),
                    'name' => $request->name,
                    'discussion' => Discussion::find($request->route('discussionId')),
                ])
            );
        } catch (Throwable $th) {

            return $this->returnFailure($request, $th);
        }
    }

    public function endDiscussion(Request $request)
    {
        $discussion = Discussion::find($request->route('discussionId'));

        try {
            $discussion = DiscussionService::new()->endDiscussion(
                CreateDiscussionDTO::new()->fromArray([
                    'user' => $request->user(),
                    'discussion' => $discussion,
                ])
            );

            return $this->returnSuccess($request, $discussion);
        } catch (Throwable $th) {

            return $this->returnFailure($request, $th);
        }
    }

    public function getInDiscussion(Request $request)
    {
        $discussion = Discussion::find($request->route('discussionId'));

        try {
            $discussion = DiscussionService::new()->getInDiscussion(
                CreateDiscussionDTO::new()->fromArray([
                    'user' => $request->user(),
                    'discussion' => $discussion,
                ])
            );

            return $this->returnSuccess($request, $discussion);
        } catch (Throwable $th) {

            return $this->returnFailure($request, $th);
        }
    }

    public function abandonDiscussion(Request $request)
    {
        $discussion = Discussion::find($request->route('discussionId'));

        try {
            $discussion = DiscussionService::new()->abandonDiscussion(
                CreateDiscussionDTO::new()->fromArray([
                    'user' => $request->user(),
                    'discussion' => $discussion,
                ])
            );

            return $this->returnSuccess($request, $discussion);
        } catch (Throwable $th) {

            return $this->returnFailure($request, $th);
        }
    }

    private function returnSuccess(Request $request, Discussion $discussion)
    {
        $discussion = new DiscussionResource($discussion);

        if ($request->acceptsJson()) {
            return response()->json(['discussion' => $discussion]);
        }

        return Redirect::back()->with(['discussion' => $discussion]);
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
