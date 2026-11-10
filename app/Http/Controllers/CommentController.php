<?php

namespace App\Http\Controllers;

use App\Actions\GetModelWithModelNameAndIdAction;
use App\DTOs\CreateCommentDTO;
use App\Http\Resources\CommentResource;
use App\Models\Comment;
use App\Models\GroupTherapy;
use App\Models\Post;
use App\Models\Therapy;
use App\Services\CommentService;
use Illuminate\Http\Request;
use Throwable;

class CommentController extends Controller
{
    // SCRUM-354: groups too -- only public therapies and groups get through EnsureCommentableExistsAction.
    private const COMMENTABLE_MODELS = [Post::class, Therapy::class, GroupTherapy::class];

    public function createComment(Request $request)
    {
        try {
            $comment = CommentService::new()->createComment(
                CreateCommentDTO::new()->fromArray([
                    'user' => $request->user(),
                    'content' => $request->content,
                    'commentable' => GetModelWithModelNameAndIdAction::new()->execute($request->commentableType, $request->commentableId, self::COMMENTABLE_MODELS),
                ])
            );

            return $this->successResponse($request, ['comment' => new CommentResource($comment)]);
        } catch (Throwable $th) {

            return $this->failureResponse($request, $th);
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

            return $this->successResponse($request, ['comment' => new CommentResource($comment)]);
        } catch (Throwable $th) {

            return $this->failureResponse($request, $th);
        }
    }

    public function getComments(Request $request)
    {
        try {
            return CommentService::new()->getComments(
                CreateCommentDTO::new()->fromArray([
                    'commentable' => GetModelWithModelNameAndIdAction::new()->execute($request->commentableType, $request->commentableId, self::COMMENTABLE_MODELS),
                ])
            );
        } catch (Throwable $th) {

            return $this->failureResponse($request, $th);
        }
    }
}
