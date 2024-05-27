<?php

namespace App\DTOs;

use App\Models\User;
use Illuminate\Http\Request;
use MrRobertAmoah\DTO\BaseDTO;
use Illuminate\Notifications\Notification;

class GuardianAlertDTO extends BaseDTO
{
    public ?User $user = null;
    public ?Notification $notification = null;
}