<?php

namespace App\Services;

use App\Actions\EnsureAddedbyIsValidAction;
use App\Actions\Post\CreatePostAction;
use App\Actions\Post\DeletePostAction;
use App\Actions\Post\EnsureCanCreatePostAction;
use App\Actions\Post\EnsureCanUpdatePostAction;
use App\Actions\Post\EnsurePostDataIsValidAction;
use App\Actions\Post\EnsurePostExistsAction;
use App\Actions\Post\UpdatePostAction;
use App\Actions\Star\CreateStarAction;
use App\DTOs\CreatePostDTO;
use App\DTOs\CreateStarDTO;
use App\Enums\PaginationEnum;
use App\Enums\StarTypeEnum;
use App\Http\Resources\PostResource;
use App\Models\Post;

class PostService extends Service
{
    public function createPost(CreatePostDTO $createPostDTO)
    {
        EnsureAddedbyIsValidAction::new()->execute(
            $createPostDTO,
            "You are not allowed to use the account to add the post."
        );

        EnsureCanCreatePostAction::new()->execute($createPostDTO);

        EnsurePostDataIsValidAction::new()->execute($createPostDTO);

        $post = CreatePostAction::new()->execute($createPostDTO);

        CreateStarAction::new()->execute(
            CreateStarDTO::fromArray([
                'starredby' => null,
                'starred' => $createPostDTO->addedby ?: $createPostDTO->user,
                'starreable' => $post,
                'type' => StarTypeEnum::contribution->value,
            ])
        );

        return $post;
    }

    public function updatePost(CreatePostDTO $createPostDTO)
    {
        EnsurePostExistsAction::new()->execute($createPostDTO);

        EnsureCanUpdatePostAction::new()->execute($createPostDTO);

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

        return $query->paginate(PaginationEnum::preferencesPagination->value);
    }
}