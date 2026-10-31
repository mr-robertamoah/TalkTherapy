<?php

namespace App\DTOs;

use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\Message;
use App\Models\Session;
use App\Models\TherapyTopic;
use App\Models\User;
use MrRobertAmoah\DTO\BaseDTO;

class CreateMessageDTO extends BaseDTO
{
    public ?string $content = null;

    public ?string $type = null;

    public ?string $status = null;

    public ?array $files = null;

    public ?array $deletedFiles = null;

    public ?Message $reply = null;

    public ?Message $message = null;

    public ?TherapyTopic $therapyTopic = null;

    // Null on an update means "leave it as it is" (SCRUM-195) -- see MessageController::updateMessage().
    public ?bool $confidential = false;

    public ?User $user = null;

    public User|Counsellor|null $from = null;

    public User|Counsellor|null $to = null;

    public Session|Discussion|null $for = null;
}
