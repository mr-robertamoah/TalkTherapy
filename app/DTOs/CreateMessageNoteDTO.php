<?php

namespace App\DTOs;

use App\Models\Counsellor;
use App\Models\Message;
use App\Models\MessageNote;
use App\Models\User;
use MrRobertAmoah\DTO\BaseDTO;

class CreateMessageNoteDTO extends BaseDTO
{
    public ?string $content = null;

    public ?User $user = null;

    public ?Counsellor $counsellor = null;

    public ?Message $message = null;

    public ?MessageNote $messageNote = null;
}
