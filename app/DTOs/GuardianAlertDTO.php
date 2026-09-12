<?php

namespace App\DTOs;

use App\Models\GroupTherapy;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Notifications\Notification;
use MrRobertAmoah\DTO\BaseDTO;

class GuardianAlertDTO extends BaseDTO
{
    public ?User $user = null;

    public ?Notification $notification = null;

    // TT-4.10b/SCRUM-291: the Therapy/GroupTherapy whose client_was_minor_at_creation snapshot
    // corresponds to $user, when one exists -- AlertGuardianAction prefers this stable snapshot
    // over a live isAdult() re-check. Only pass this when $for's own addedby genuinely IS $user
    // (see each call site's own reasoning) -- passing a record that corresponds to someone else
    // entirely would silently gate on the wrong person's minor status.
    public Therapy|GroupTherapy|null $for = null;
}
