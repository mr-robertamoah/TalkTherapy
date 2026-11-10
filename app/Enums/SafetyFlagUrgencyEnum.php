<?php

namespace App\Enums;

use App\Traits\EnumTrait;

// SCRUM-32: how soon the counsellor thinks a flagged client needs care.
enum SafetyFlagUrgencyEnum: string
{
    use EnumTrait;

    case imminent = 'IMMINENT';
    case urgent = 'URGENT';
    case nonUrgent = 'NON_URGENT';

    public function label(): string
    {
        return match ($this) {
            self::imminent => 'Imminent',
            self::urgent => 'Urgent',
            self::nonUrgent => 'Not urgent',
        };
    }
}
