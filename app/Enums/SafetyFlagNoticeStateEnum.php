<?php

namespace App\Enums;

use App\Traits\EnumTrait;

// SCRUM-32: where the client's notice, or a minor's guardians' notice, stands (TT-4.3c sends them).
// Pending: held for the handling administrator (sent automatically at the re-alert time if nobody acts).
// Held: kept back for a recorded safety reason (the handler's, or for guardians the counsellor's) until
// the handler decides. Sent, or withheld for good with a reason. Cancelled: the counsellor withdrew the
// flag before anyone acknowledged it (TT-4.3c, the user's decision). Not applicable: guardians of an
// adult, or a non-urgent flag.
enum SafetyFlagNoticeStateEnum: string
{
    use EnumTrait;

    case pending = 'PENDING';
    case held = 'HELD';
    case sent = 'SENT';
    case withheld = 'WITHHELD';
    case notApplicable = 'NOT_APPLICABLE';
    case cancelled = 'CANCELLED';
    // Nobody to send it to when it went: a closed account, a person who has since turned 18, or no active
    // guardian (reviews: never "sent" when nobody was told).
    case noRecipient = 'NO_RECIPIENT';

    // Still waiting for a decision (a flag can't be resolved while one of its notices is).
    public function isUnsettled(): bool
    {
        return $this === self::pending || $this === self::held;
    }

    /** @return list<string> */
    public static function unsettledValues(): array
    {
        return [self::pending->value, self::held->value];
    }

    public function label(): string
    {
        return match ($this) {
            self::pending => 'Waiting -- goes out automatically unless you send or hold it',
            self::held => 'Held',
            self::sent => 'Sent',
            self::withheld => 'Withheld',
            self::notApplicable => 'Not applicable',
            self::cancelled => 'Cancelled (withdrawn before anyone acknowledged it, or the account was closed)',
            self::noRecipient => 'Not sent -- nobody to send it to',
        };
    }
}
