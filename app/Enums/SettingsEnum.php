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

    // SCRUM-471: the daily star caps (whole numbers, 0 or more) -- config('recognition.cap.*') until a super admin sets
    // one. See StarCapPolicy.
    case recognitionCapSession = 'RECOGNITION_CAP_SESSION';
    case recognitionCapHelpChat = 'RECOGNITION_CAP_HELP_CHAT';
    case recognitionCapDiscussion = 'RECOGNITION_CAP_DISCUSSION';
    case recognitionCapPost = 'RECOGNITION_CAP_POST';
    case recognitionCapSessionPerClient = 'RECOGNITION_CAP_SESSION_PER_CLIENT';
    case recognitionCapSessionPerGroup = 'RECOGNITION_CAP_SESSION_PER_GROUP';
}
