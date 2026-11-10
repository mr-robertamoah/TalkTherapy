<?php

namespace App\DTOs;

use App\Models\Comment;
use App\Models\GroupTherapy;
use App\Models\Post;
use App\Models\Therapy;
use App\Models\User;
use MrRobertAmoah\DTO\BaseDTO;

class CreateCommentDTO extends BaseDTO
{
    public ?User $user = null;

    // SCRUM-354: public therapies and groups only -- EnsureCommentableExistsAction checks.
    public Post|Therapy|GroupTherapy|null $commentable = null;

    public ?Comment $comment = null;

    public ?string $content = null;
}
