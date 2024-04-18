<?php

namespace App\DTOs;

use App\Models\TherapyTopic;
use App\Models\User;
use Illuminate\Http\Request;
use MrRobertAmoah\DTO\BaseDTO;

class GetTherapyTopicMessagesDTO extends BaseDTO
{
    public ?User $user = null;
    public ?TherapyTopic $topic = null;
    public ?String $like = null;
    public ?bool $groupBy = null;
    public String|int|null $sessionId = null;
    public String|int|null $replyId = null;
}