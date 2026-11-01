<?php

namespace App\DTOs;

use App\Models\Session;
use App\Models\User;
use MrRobertAmoah\DTO\BaseDTO;

class GetSessionMessagesDTO extends BaseDTO
{
    public ?User $user = null;

    public ?Session $session = null;

    public ?string $like = null;

    public string|int|null $topicId = null;

    public string|int|null $replyId = null;
}
