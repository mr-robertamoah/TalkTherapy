<?php

namespace App\Enums;

use App\Traits\EnumTrait;

enum SettingsEnum: string
{
    use EnumTrait;

    // TT-7.6b/SCRUM-226: percentage (0-100) deducted from a counsellor's gross earnings before
    // payout -- admin-configurable via SettingsService/UpdateSettingAction, not hardcoded.
    case platformFeePercentage = 'PLATFORM_FEE_PERCENTAGE';

    // JSON-encoded {currency => minor-unit amount} map -- a counsellor's available balance in a
    // given currency must reach this before a payout can be triggered (by them or an admin).
    case minimumPayoutAmount = 'MINIMUM_PAYOUT_AMOUNT';

    // JSON-encoded {currency => minor-unit amount} map -- TT-7.3b-a/SCRUM-231: the small, nominal
    // charge run through an org's card to capture a reusable Paystack authorization (there's no
    // "just verify this card" call). Owed back to the org as a credit against its first real
    // invoice (OrganizationPaymentInstrument.pending_credit_amount) once TT-7.3b-e's invoicing
    // exists, never silently kept.
    case organizationPaymentInstrumentVerificationAmount = 'ORGANIZATION_PAYMENT_INSTRUMENT_VERIFICATION_AMOUNT';
}
