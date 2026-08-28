<?php

namespace App\Http\Controllers;

use App\DTOs\CreateTherapyTopicDTO;
use App\DTOs\GetTherapyTopicsDTO;
use App\Http\Requests\CreateTherapyTopicRequest;
use App\Http\Requests\UpdateTherapyTopicRequest;
use App\Http\Resources\TherapyTopicResource;
use App\Models\Therapy;
use App\Models\TherapyTopic;
use App\Services\TherapyTopicService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Throwable;

class TherapyTopicController extends Controller
{
    // Every lookup below reads $request->route('therapyId'/'topicId') rather than the magic
    // ->therapyId/->topicId properties -- see the identical fix/rationale in SessionController
    // (SCRUM-116/SCRUM-130). createTherapyTopic/getTherapyTopics were already only reading
    // therapyId, not a distinct topicId body field, despite SCRUM-130's ticket text assuming
    // otherwise -- confirmed via CreateTherapyTopicRequest, which has no topicId rule at all.
    public function createTherapyTopic(CreateTherapyTopicRequest $request)
    {
        try {
            $topic = TherapyTopicService::new()->createTherapyTopic(
                CreateTherapyTopicDTO::new()->fromArray([
                    'user' => $request->user(),
                    'name' => $request->name,
                    'description' => $request->description,
                    'sessions' => $request->sessions,
                    'therapy' => Therapy::find($request->route('therapyId')),
                ])
            );

            return $this->returnSuccess($request, $topic);
        } catch (Throwable $th) {

            return $this->returnFailure($request, $th);
        }
    }

    public function getTherapyTopics(Request $request)
    {
        try {
            return TherapyTopicService::new()->getTherapyTopics(
                GetTherapyTopicsDTO::new()->fromArray([
                    'therapy' => Therapy::find($request->route('therapyId')),
                    'user' => $request->user(),
                    'name' => $request->name,
                ])
            );
        } catch (Throwable $th) {

            return $this->returnFailure($request, $th);
        }
    }

    public function updateTherapyTopic(UpdateTherapyTopicRequest $request)
    {
        $topic = TherapyTopic::find($request->route('topicId'));
        try {
            $topic = TherapyTopicService::new()->updateTherapyTopic(
                CreateTherapyTopicDTO::new()->fromArray([
                    'user' => $request->user(),
                    'name' => $request->name,
                    'description' => $request->description,
                    'sessions' => $request->sessions,
                    'therapy' => $topic?->therapy,
                    'therapyTopic' => $topic,
                ])
            );

            return $this->returnSuccess($request, $topic);
        } catch (Throwable $th) {

            return $this->returnFailure($request, $th);
        }
    }

    public function deleteTherapyTopic(Request $request)
    {
        $topic = TherapyTopic::find($request->route('topicId'));

        try {
            TherapyTopicService::new()->deleteTherapyTopic(
                CreateTherapyTopicDTO::new()->fromArray([
                    'user' => $request->user(),
                    'therapy' => $topic?->therapy,
                    'therapyTopic' => $topic,
                ])
            );

            return $this->returnSuccess($request, $topic);
        } catch (Throwable $th) {

            return $this->returnFailure($request, $th);
        }
    }

    private function returnSuccess(Request $request, TherapyTopic $topic)
    {
        $topic = new TherapyTopicResource($topic);

        if ($request->acceptsJson()) {
            return response()->json(['topic' => $topic]);
        }

        return Redirect::back()->with(['topic' => $topic]);
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
