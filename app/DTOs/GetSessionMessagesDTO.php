<?php

namespace App\DTOs;

use App\Models\Session;
use App\Models\User;
use Illuminate\Http\Request;
use MrRobertAmoah\DTO\BaseDTO;

class GetSessionMessagesDTO extends BaseDTO
{
    public ?User $user = null;
    public ?Session $session = null;
    public ?String $like = null;
    public ?bool $groupBy = null;
    public String|int|null $topicId = null;
    public String|int|null $replyId = null;
}