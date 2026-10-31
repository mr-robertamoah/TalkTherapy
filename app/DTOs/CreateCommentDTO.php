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

    // SCRUM-349: no GroupTherapy until comments are authorized -- getComments has no visibility
    // check, so resolving groups here would publish private/anonymous group comments (SCRUM-351).
    public Post|Therapy|null $commentable = null;

    public ?Comment $comment = null;

    public ?string $content = null;
}
