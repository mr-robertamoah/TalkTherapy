<?php

namespace App\DTOs;

use App\Models\Discussion;
use App\Models\User;
use Illuminate\Http\Request;
use MrRobertAmoah\DTO\BaseDTO;

class GetDiscussionMessagesDTO extends BaseDTO
{
    public ?User $user = null;
    public ?Discussion $discussion = null;
    public ?String $like = null;
    public String|int|null $replyId = null;
}