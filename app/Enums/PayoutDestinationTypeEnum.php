<?php

namespace App\Enums;

use App\Traits\EnumTrait;

// Mirrors Paystack's own Transfer Recipient `type` field values for the two destination kinds
// this platform supports (TT-7.6a/SCRUM-225, per the user's decision to support both, not
// bank-only) -- `nuban` for a bank account, `mobile_money` for Ghanaian mobile money.
enum PayoutDestinationTypeEnum: string
{
    use EnumTrait;

    case nuban = 'NUBAN';
    case mobileMoney = 'MOBILE_MONEY';

    // The literal value Paystack's own API expects in a Transfer Recipient's `type` field --
    // deliberately lowercase/underscored, unlike this enum's own uppercase convention, since it's
    // dictated by Paystack's contract, not this codebase's.
    public function paystackValue(): string
    {
        return match ($this) {
            self::nuban => 'nuban',
            self::mobileMoney => 'mobile_money',
        };
    }
}
