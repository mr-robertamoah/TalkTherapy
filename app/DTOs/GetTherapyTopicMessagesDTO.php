<?php

namespace App\DTOs;

use App\Models\TherapyTopic;
use App\Models\User;
use MrRobertAmoah\DTO\BaseDTO;

class GetTherapyTopicMessagesDTO extends BaseDTO
{
    public ?User $user = null;

    public ?TherapyTopic $topic = null;

    public ?string $like = null;

    public string|int|null $sessionId = null;

    public string|int|null $replyId = null;
}
