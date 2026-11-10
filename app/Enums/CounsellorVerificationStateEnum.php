<?php

namespace App\Enums;

use App\Traits\EnumTrait;

// SCRUM-30/31: where a counsellor's platform verification stands (Counsellor::verificationState()).
enum CounsellorVerificationStateEnum: string
{
    use EnumTrait;

    case verified = 'verified';
    case unverified = 'unverified';
    case lapsed = 'lapsed';
    case revoked = 'revoked';

    // What anyone may be told: a revocation shows as an ended verification (its reason and the fact
    // of it are for the counsellor and administrators).
    public function publicValue(): string
    {
        return $this === self::revoked ? self::lapsed->value : $this->value;
    }
}
