<?php

namespace App\Http\Controllers;

use App\Actions\GetModelWithModelNameAndIdAction;
use App\DTOs\CreateLikeDTO;
use App\Http\Resources\LikeResource;
use App\Models\Like;
use App\Services\LikeService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Throwable;

class LikeController extends Controller
{
    public function like(Request $request)
    {
        try {
            $like = LikeService::new()->like(
                CreateLikeDTO::new()->fromArray([
                    'user' => $request->user(),
                    'likeable' => GetModelWithModelNameAndIdAction::new()->execute($request->likeableType, $request->likeableId),
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
                    'likeable' => GetModelWithModelNameAndIdAction::new()->execute($request->likeableType, $request->likeableId),
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
                    'likeable' => GetModelWithModelNameAndIdAction::new()->execute($request->likeableType, $request->likeableId),
                ])
            );
        } catch (Throwable $th) {
            
            return $this->returnFailure($request, $th);
        }
    }

    private function returnSuccess(Request $request, Like $like)
    {
        $like = new LikeResource($like);
        
        if ($request->acceptsJson()) return response()->json(['like' => $like]);
        
        return Redirect::back()->with(['like' => $like]);
    }

    private function returnFailure(Request $request, Throwable $th)
    {
        $message = $th->getCode() == 500 ? "Something unfortunate happened. Please try again shortly." : $th->getMessage();
        
        ds($th);

        if ($request->acceptsJson()) throw new Exception($message);
        return Redirect::back()->withErrors(['alert'=> $message]);
    }
}
