<?php

namespace App\Enums;

use App\Traits\EnumTrait;

// SCRUM-33: a help request's life -- waiting for a counsellor, connected to one, then ended (either
// side, or the chat ran its time), expired (nobody accepted in time) or cancelled (the person).
enum HelpRequestStatusEnum: string
{
    use EnumTrait;

    case waiting = 'WAITING';
    case connected = 'CONNECTED';
    case ended = 'ENDED';
    case expired = 'EXPIRED';
    case cancelled = 'CANCELLED';

    public function isLive(): bool
    {
        return $this === self::waiting || $this === self::connected;
    }
}
