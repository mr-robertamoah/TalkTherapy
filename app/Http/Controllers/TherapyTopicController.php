<?php

namespace App\Http\Controllers;

use App\DTOs\CreateTherapyTopicDTO;
use App\Http\Requests\CreateTherapyTopicRequest;
use App\Http\Requests\UpdateTherapyTopicRequest;
use App\Http\Resources\TherapyTopicResource;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\TherapyTopic;
use App\Services\TherapyTopicService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Throwable;

class TherapyTopicController extends Controller
{
    public function createTherapyTopic(CreateTherapyTopicRequest $request)
    {
        try {
            $topic = TherapyTopicService::new()->createTherapyTopic(
                CreateTherapyTopicDTO::new()->fromArray([
                    'user' => $request->user(),
                    'name' => $request->name,
                    'description' => $request->description,
                    'sessions' => $request->sessions,
                    'therapy' => Therapy::find($request->therapyId),
                ])
            );

            return $this->returnSuccess($request, $topic);
        } catch (Throwable $th) {
            
            return $this->returnFailure($request, $th);
        }
    }
    
    public function getTherapyTopics(Request $request)
    {
        return TherapyTopicService::new()->getTherapyTopics(
            Therapy::find($request->therapyId),
            $request->user(),
            $request->name
        );
    }

    public function updateTherapyTopic(UpdateTherapyTopicRequest $request)
    {
        $topic = TherapyTopic::find($request->topicId);
        ds($request->sessions);
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
        $topic = TherapyTopic::find($request->topicId);
        
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
        
        if ($request->acceptsJson()) return response()->json(['topic' => $topic]);
        
        return Redirect::back()->with(['topic' => $topic]);
    }

    private function returnFailure(Request $request, Throwable $th)
    {
        $message = $th->getCode() == 500 ? "Something unfortunate happened. Please try again shortly." : $th->getMessage();
        
        ds($th);

        if ($request->acceptsJson()) throw new Exception($message);
        return Redirect::back()->withErrors(['alert'=> $message]);
    }
}
