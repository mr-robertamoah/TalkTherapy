<?php

namespace App\DTOs;

use App\Models\User;
use Illuminate\Http\Request;
use MrRobertAmoah\DTO\BaseDTO;

class GetUsersDTO extends BaseDTO
{
    public ?User $user = null;
    public ?string $like = null;
}