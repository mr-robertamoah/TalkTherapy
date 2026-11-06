<?php

namespace App\Enums;

use App\Traits\EnumTrait;

// SCRUM-31: what a row of a counsellor's verification history (counsellor_verification_events) records.
enum CounsellorVerificationEventEnum: string
{
    use EnumTrait;

    // Verified before verifications could end: the backfill gave them their first end date.
    case baseline = 'baseline';
    case verified = 'verified';
    case reverified = 'reverified';
    case rejected = 'rejected';
    case reminderSent = 'reminder_sent';
    case lapsed = 'lapsed';
    case revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::baseline => 'Verified before verifications could end; given a first end date',
            self::verified => 'Verified',
            self::reverified => 'Re-verified',
            self::rejected => 'Verification request rejected',
            self::reminderSent => 'Reminded that verification is ending',
            self::lapsed => 'Verification ended',
            self::revoked => 'Verification revoked',
        };
    }
}
