<?php

namespace App\Enums;

use App\Traits\EnumTrait;

// SCRUM-32: where the flag was raised -- a 1:1 therapy, a group, or an urgent help chat.
enum SafetyFlagKindEnum: string
{
    use EnumTrait;

    case therapy = 'THERAPY';
    case group = 'GROUP';
    case helpChat = 'HELP_CHAT';

    public function label(): string
    {
        return match ($this) {
            self::therapy => '1:1 therapy',
            self::group => 'Group therapy',
            self::helpChat => 'Urgent help chat',
        };
    }
}
