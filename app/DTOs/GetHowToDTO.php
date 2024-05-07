<?php

namespace App\DTOs;

use App\Models\User;
use Illuminate\Http\Request;
use MrRobertAmoah\DTO\BaseDTO;

class GetHowToDTO extends BaseDTO
{
    public ?User $user = null;
    public ?string $name = null;
    public ?string $pageLike = null;
}