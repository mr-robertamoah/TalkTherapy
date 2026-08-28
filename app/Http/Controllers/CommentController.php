<?php

namespace App\Http\Controllers;

use App\Actions\GetModelWithModelNameAndIdAction;
use App\DTOs\CreateCommentDTO;
use App\Http\Resources\CommentResource;
use App\Models\Comment;
use App\Services\CommentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Throwable;

class CommentController extends Controller
{
    public function createComment(Request $request)
    {
        try {
            $comment = CommentService::new()->createComment(
                CreateCommentDTO::new()->fromArray([
                    'user' => $request->user(),
                    'content' => $request->content,
                    'commentable' => GetModelWithModelNameAndIdAction::new()->execute($request->commentableType, $request->commentableId),
                ])
            );

            return $this->returnSuccess($request, $comment);
        } catch (Throwable $th) {

            return $this->returnFailure($request, $th);
        }
    }

    // Reads $request->route('commentId') rather than the magic ->commentId property -- see the
    // identical fix/rationale in SessionController (SCRUM-116/SCRUM-130/SCRUM-133).
    public function deleteComment(Request $request)
    {
        try {
            CommentService::new()->deleteComment(
                CreateCommentDTO::new()->fromArray([
                    'user' => $request->user(),
                    'comment' => $comment = Comment::find($request->route('commentId')),
                ])
            );

            return $this->returnSuccess($request, $comment);
        } catch (Throwable $th) {

            return $this->returnFailure($request, $th);
        }
    }

    public function getComments(Request $request)
    {
        try {
            return CommentService::new()->getComments(
                CreateCommentDTO::new()->fromArray([
                    'commentable' => GetModelWithModelNameAndIdAction::new()->execute($request->commentableType, $request->commentableId),
                ])
            );
        } catch (Throwable $th) {

            return $this->returnFailure($request, $th);
        }
    }

    private function returnSuccess(Request $request, Comment $comment)
    {
        $comment = new CommentResource($comment);

        if ($request->acceptsJson()) {
            return response()->json(['comment' => $comment]);
        }

        return Redirect::back()->with(['comment' => $comment]);
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
