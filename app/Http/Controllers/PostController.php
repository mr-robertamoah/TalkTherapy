<?php

namespace App\Http\Controllers;

use App\Actions\GetModelWithModelNameAndIdAction;
use App\DTOs\CreatePostDTO;
use App\Http\Resources\PostResource;
use App\Models\Post;
use App\Services\PostService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Throwable;

class PostController extends Controller
{
    public function createPost(Request $request)
    {
        try {
            $post = PostService::new()->createPost(
                CreatePostDTO::new()->fromArray([
                    'user' => $request->user(),
                    'content' => $request->content,
                    'files' => $request->file('files'),
                    'postable' => GetModelWithModelNameAndIdAction::new()->execute($request->postableType, $request->postableId),
                    'addedby' => GetModelWithModelNameAndIdAction::new()->execute($request->addedbyType, $request->addedbyId),
                ])
            );

            return $this->returnSuccess($request, $post);
        } catch (Throwable $th) {
            
            return $this->returnFailure($request, $th);
        }
    }

    public function updatePost(Request $request)
    {
        try {
            $post = PostService::new()->updatePost(
                CreatePostDTO::new()->fromArray([
                    'user' => $request->user(),
                    'content' => $request->content,
                    'post' => Post::find($request->postId),
                    'files' => $request->file('files'),
                    'postable' => GetModelWithModelNameAndIdAction::new()->execute($request->postableType, $request->postableId),
                ])
            );

            return $this->returnSuccess($request, $post);
        } catch (Throwable $th) {
            
            return $this->returnFailure($request, $th);
        }
    }
    
    public function deletePost(Request $request)
    {
        $post = Post::find($request->postId);
        try {
            PostService::new()->getPost(
                CreatePostDTO::new()->fromArray([
                    'user' => $request->user(),
                    'post' => $post,
                ])
            );

            return $this->returnSuccess($request, $post);
        } catch (Throwable $th) {
            
            return $this->returnFailure($request, $th);
        }
    }

    public function getPost(Request $request)
    {
        $post = Post::find($request->postId);
        try {
            PostService::new()->getPost(
                CreatePostDTO::new()->fromArray([
                    'user' => $request->user(),
                    'post' => $post,
                ])
            );

            return $this->returnSuccess($request, $post);
        } catch (Throwable $th) {
            
            return $this->returnFailure($request, $th);
        }
    }

    public function getPosts(Request $request)
    {
        try {
            $posts = PostService::new()->getPosts(
                CreatePostDTO::new()->fromArray([
                    'user' => $request->user(),
                    'like' => $request->like,
                    'addedby' => GetModelWithModelNameAndIdAction::new()->execute($request->addedbyType, $request->addedbyId),
                ])
            );

            return PostResource::collection($posts);
        } catch (Throwable $th) {
            
            return $this->returnFailure($request, $th);
        }
    }

    private function returnSuccess(Request $request, Post $post)
    {
        $post = new PostResource($post);
        
        if ($request->acceptsJson()) return response()->json(['post' => $post]);
        
        return Redirect::back()->with(['post' => $post]);
    }

    private function returnFailure(Request $request, Throwable $th)
    {
        $message = $th->getCode() == 500 ? "Something unfortunate happened. Please try again shortly." : $th->getMessage();
        
        ds($th);

        if ($request->acceptsJson()) throw new Exception($message);

        return Redirect::back()->withErrors(['alert'=> $message]);
    }
}
