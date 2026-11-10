<?php

namespace App\Enums;

use App\Traits\EnumTrait;

// SCRUM-114: the one button a therapy or group card shows its viewer (ResolveTherapyCardStateAction).
enum TherapyCardActionEnum: string
{
    use EnumTrait;

    case view = 'view';
    case open = 'open';
    case join = 'join';
    case ask = 'ask';
    case requestSent = 'requestSent';
    case full = 'full';
    case ended = 'ended';
    case offer = 'offer';
    case offerSent = 'offerSent';
    case invited = 'invited';
    case blocked = 'blocked';
}
