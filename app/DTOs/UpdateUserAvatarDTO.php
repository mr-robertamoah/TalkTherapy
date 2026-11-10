<?php

namespace App\DTOs;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use MrRobertAmoah\DTO\BaseDTO;

class UpdateUserAvatarDTO extends BaseDTO
{
    public ?User $user = null;

    public ?UploadedFile $avatar = null;

    public ?bool $deleteAvatar = null;
}
