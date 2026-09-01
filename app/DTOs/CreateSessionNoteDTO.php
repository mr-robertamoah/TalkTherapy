<?php

namespace App\DTOs;

use App\Models\Counsellor;
use App\Models\Session;
use App\Models\SessionNote;
use App\Models\User;
use MrRobertAmoah\DTO\BaseDTO;

class CreateSessionNoteDTO extends BaseDTO
{
    public ?string $content = null;

    public ?User $user = null;

    public ?Counsellor $counsellor = null;

    public ?Session $session = null;

    public ?SessionNote $sessionNote = null;
}
