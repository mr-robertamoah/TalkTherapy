<?php

namespace App\DTOs;

use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\Message;
use App\Models\Session;
use App\Models\TherapyTopic;
use App\Models\User;
use Illuminate\Http\Request;
use MrRobertAmoah\DTO\BaseDTO;

class CreateMessageDTO extends BaseDTO
{
    public ?String $content = null;
    public ?String $type = null;
    public ?String $status = null;
    public ?array $files = null;
    public ?array $deletedFiles = null;
    public ?Message $reply = null;
    public ?Message $message = null;
    public ?TherapyTopic $therapyTopic = null;
    public bool $confidential = false;
    public User|null $user = null;
    public User|Counsellor|null $from = null;
    public User|Counsellor|null $to = null;
    public Session|Discussion|null $for = null;
}