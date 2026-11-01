<?php

namespace App\Enums;

use App\Traits\EnumTrait;

// SCRUM-359: what a suspension covers. account -- the whole account: no sign-in, nothing at all.
// counsellor -- only the counsellor role: the person can still use TalkTherapy as a client.
// An account suspension also counts as a counsellor suspension.
enum SuspensionScopeEnum: string
{
    use EnumTrait;

    case account = 'account';
    case counsellor = 'counsellor';

    public function label(): string
    {
        return $this == self::account ? 'whole account' : 'counsellor role';
    }
}
