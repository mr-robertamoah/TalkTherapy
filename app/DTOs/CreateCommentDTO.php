<?php

namespace App\DTOs;

use App\Models\Comment;
use App\Models\GroupTherapy;
use App\Models\Post;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Http\Request;
use MrRobertAmoah\DTO\BaseDTO;

class CreateCommentDTO extends BaseDTO
{
    public ?User $user = null;
    public Post|Therapy|GroupTherapy|null $commentable = null;
    public Comment|null $comment = null;
    public ?string $content = null;
}