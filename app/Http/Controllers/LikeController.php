<?php

namespace App\Http\Controllers;

use App\Actions\GetModelWithModelNameAndIdAction;
use App\DTOs\CreateLikeDTO;
use App\Http\Resources\LikeResource;
use App\Models\Counsellor;
use App\Models\Like;
use App\Models\Post;
use App\Models\Therapy;
use App\Services\LikeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Throwable;

class LikeController extends Controller
{
    // SCRUM-349: never GroupTherapy until likees are authorized (SCRUM-351) -- it
    // resolves now, and getLikes has no visibility check.
    private const LIKEABLE_MODELS = [Counsellor::class, Post::class, Therapy::class];

    public function like(Request $request)
    {
        try {
            $like = LikeService::new()->like(
                CreateLikeDTO::new()->fromArray([
                    'user' => $request->user(),
                    'likeable' => GetModelWithModelNameAndIdAction::new()->execute($request->likeableType, $request->likeableId, self::LIKEABLE_MODELS),
                ])
            );

            return $this->returnSuccess($request, $like);
        } catch (Throwable $th) {

            return $this->returnFailure($request, $th);
        }
    }

    public function dislike(Request $request)
    {
        try {
            $like = LikeService::new()->dislike(
                CreateLikeDTO::new()->fromArray([
                    'user' => $request->user(),
                    'likeable' => GetModelWithModelNameAndIdAction::new()->execute($request->likeableType, $request->likeableId, self::LIKEABLE_MODELS),
                ])
            );

            return $this->returnSuccess($request, $like);
        } catch (Throwable $th) {

            return $this->returnFailure($request, $th);
        }
    }

    public function getLikes(Request $request)
    {
        try {
            return LikeService::new()->getLikes(
                CreateLikeDTO::new()->fromArray([
                    'likeable' => GetModelWithModelNameAndIdAction::new()->execute($request->likeableType, $request->likeableId, self::LIKEABLE_MODELS),
                ])
            );
        } catch (Throwable $th) {

            return $this->returnFailure($request, $th);
        }
    }

    private function returnSuccess(Request $request, Like $like)
    {
        $like = new LikeResource($like);

        if ($request->acceptsJson()) {
            return response()->json(['like' => $like]);
        }

        return Redirect::back()->with(['like' => $like]);
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
