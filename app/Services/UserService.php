<?php

namespace App\Services;

use App\DTOs\UpdateUserDTO;
use App\Models\User;

class UserService extends Service
{
    public function updateSettings(UpdateUserDTO $updateUserDTO): User
    {
        $settings = $updateUserDTO->user->settings ? $updateUserDTO->user->settings : [];
        $updateUserDTO->user->settings = [...$settings, ...$updateUserDTO->settings];
        
        $updateUserDTO->user->save();
        
        return $updateUserDTO->user->refresh();
    }
}