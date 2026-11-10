<?php

namespace App\Enums;

use App\Traits\EnumTrait;

// SCRUM-33: a help request's life -- waiting for a counsellor, connected to one, then ended (either
// side, or the chat ran its time), expired (nobody accepted in time), cancelled (the person) or
// closed (an administrator, TT-4.4b).
enum HelpRequestStatusEnum: string
{
    use EnumTrait;

    case waiting = 'WAITING';
    case connected = 'CONNECTED';
    case ended = 'ENDED';
    case expired = 'EXPIRED';
    case cancelled = 'CANCELLED';
    case closed = 'CLOSED';

    public function isLive(): bool
    {
        return $this === self::waiting || $this === self::connected;
    }
}
