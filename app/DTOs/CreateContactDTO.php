<?php

namespace App\DTOs;

use App\Models\Contact;
use App\Models\Counsellor;
use App\Models\User;
use Illuminate\Http\Request;
use MrRobertAmoah\DTO\BaseDTO;

class CreateContactDTO extends BaseDTO
{
    public ?User $user = null;
    public ?Contact $contact = null;
    public User|Counsellor|null $addedby = null;
    public ?string $content = null;
    public ?string $name = null;
    public ?string $organisation = null;
    public ?string $type = null;
    public ?string $email = null;
    public bool $use = false;
}