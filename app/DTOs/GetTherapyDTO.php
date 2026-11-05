<?php

namespace App\DTOs;

use App\Models\GroupTherapy;
use App\Models\Therapy;
use App\Models\User;
use MrRobertAmoah\DTO\BaseDTO;

class GetTherapyDTO extends BaseDTO
{
    public ?User $user = null;

    public ?Therapy $therapy = null;

    public ?GroupTherapy $groupTherapy = null;

    // SCRUM-383: the `invitation` query value a group's page was opened with (a member invitation
    // link's uuid) -- one more way in to a preview of a private group, when it is usable
    // (FindUsableGroupInvitationAction).
    public ?string $invitationUuid = null;

    // SCRUM-73/383: the group's page (only) may show a reduced preview of a private group to
    // someone holding a usable invitation, or to a former member. Every other caller (the chat, the
    // sessions) needs full access.
    public bool $allowPreview = false;
}
