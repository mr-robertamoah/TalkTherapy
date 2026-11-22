<?php

namespace App\Services;

use App\Actions\EnsureAddedbyIsValidAction;
use App\Actions\Post\AwardPostPublishedStarAction;
use App\Actions\Post\CreatePostAction;
use App\Actions\Post\DeletePostAction;
use App\Actions\Post\EnsureCanCreatePostAction;
use App\Actions\Post\EnsureCanUpdatePostAction;
use App\Actions\Post\EnsurePostDataIsValidAction;
use App\Actions\Post\EnsurePostExistsAction;
use App\Actions\Post\UpdatePostAction;
use App\Actions\Suspension\EnsureCanActAsCounsellorAction;
use App\DTOs\CreatePostDTO;
use App\Enums\PaginationEnum;
use App\Models\Post;
use Illuminate\Support\Facades\Log;
use Throwable;

class PostService extends Service
{
    public function createPost(CreatePostDTO $createPostDTO)
    {
        EnsureAddedbyIsValidAction::new()->execute(
            $createPostDTO,
            'You are not allowed to use the account to add the post.'
        );

        EnsureCanCreatePostAction::new()->execute($createPostDTO);

        // SCRUM-359: a suspended counsellor can't publish as one.
        EnsureCanActAsCounsellorAction::new()->execute($createPostDTO->addedby);

        EnsurePostDataIsValidAction::new()->execute($createPostDTO);

        $post = CreatePostAction::new()->execute($createPostDTO);

        // TT-7.11b/SCRUM-471: a counsellor's post earns a star, a few a day. Never blocks publishing it.
        try {
            AwardPostPublishedStarAction::new()->execute($post);
        } catch (Throwable $exception) {
            Log::warning('Unable to award the star for a published post.', [
                'post_id' => $post->id,
                'exception' => $exception->getMessage(),
            ]);
        }

        return $post;
    }

    public function updatePost(CreatePostDTO $createPostDTO)
    {
        EnsurePostExistsAction::new()->execute($createPostDTO);

        EnsureCanUpdatePostAction::new()->execute($createPostDTO);

        EnsureCanActAsCounsellorAction::new()->execute($createPostDTO->post?->addedby);

        EnsurePostDataIsValidAction::new()->execute($createPostDTO, 'update');

        return UpdatePostAction::new()->execute($createPostDTO);
    }

    public function deletePost(CreatePostDTO $createPostDTO)
    {
        EnsurePostExistsAction::new()->execute($createPostDTO);

        EnsureCanUpdatePostAction::new()->execute($createPostDTO);

        return DeletePostAction::new()->execute($createPostDTO);
    }

    public function getPost(CreatePostDTO $createPostDTO)
    {
        EnsurePostExistsAction::new()->execute($createPostDTO);

        return $createPostDTO->post;
    }

    public function getPosts(CreatePostDTO $createPostDTO)
    {
        $query = Post::query();

        $query->when($createPostDTO->like, function ($query) use ($createPostDTO) {
            $query->whereLike($createPostDTO->like);
        });

        $query->when($createPostDTO->addedby, function ($query) use ($createPostDTO) {
            $query->whereAddedby($createPostDTO->addedby);
        });

        $query->orderByDesc('created_at');

        return $query->paginate(PaginationEnum::homePagination->value);
    }
}
