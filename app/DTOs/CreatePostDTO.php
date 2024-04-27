<?php

namespace App\DTOs;

use App\Models\Counsellor;
use App\Models\Post;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Http\Request;
use MrRobertAmoah\DTO\BaseDTO;

class CreatePostDTO extends BaseDTO
{
    public ?User $user = null;
    public ?Post $post = null;
    public Counsellor|User|null $addedby = null;
    public Therapy|null $postable = null;
    public ?string $content = null;
    public ?string $like = null;
    public ?array $files = [];
    public ?array $deletedFiles = [];
}