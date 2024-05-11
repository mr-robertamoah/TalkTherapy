<?php

namespace App\Http\Controllers;

use App\Actions\GetModelWithModelNameAndIdAction;
use App\DTOs\CreateDiscussionDTO;
use App\Http\Requests\CreateDiscussionRequest;
use App\Http\Resources\DiscussionResource;
use App\Models\Discussion;
use App\Models\Session;
use App\Services\DiscussionService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Throwable;

class DiscussionController extends Controller
{
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
                    'from' => GetModelWithModelNameAndIdAction::new()->execute($request->addedbyType, $request->addedbyId),
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
        $discussion = Discussion::find($request->discussionId);
        
        try {
            $discussion = DiscussionService::new()->updateDiscussion(
                CreateDiscussionDTO::new()->fromArray([
                    'user' => $request->user(),
                    'description' => $request->description,
                    'name' => $request->name,
                    'discussion' => $discussion,
                    'startTime' => $request->startTime,
                    'endTime' => $request->endTime,
                    'session' => Session::find($request->sessionId),
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
                    'discussion' => $discussion = Discussion::find($request->discussionId),
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
        
        if ($request->acceptsJson()) return response()->json(['discussion' => $discussion]);
        
        return Redirect::back()->with(['discussion' => $discussion]);
    }

    private function returnFailure(Request $request, Throwable $th)
    {
        $message = $th->getCode() == 500 ? "Something unfortunate happened. Please try again shortly." : $th->getMessage();
        
        ds($th);

        if ($request->acceptsJson()) throw new Exception($message);
        return Redirect::back()->withErrors(['alert'=> $message]);
    }
}
