<?php

namespace App\DTOs;

use App\Models\User;
use Illuminate\Http\Request;
use MrRobertAmoah\DTO\BaseDTO;

class UpdateUserDTO extends BaseDTO
{
    public ?User $user = null;
    public ?User $updatedUser = null;
    public String|int|null $userId = null;
    public String|null $country = null;
    public String|null $firstName = null;
    public String|null $lastName = null;
    public String|null $otherNames = null;
    public String|null $email = null;
    public bool $emailVerified = false;
    public String|null $dob = null;
    public array $settings = [];
}