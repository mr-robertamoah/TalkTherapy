<?php

namespace App\Enums;

use App\Traits\EnumTrait;

enum TransactionStatusSourceEnum: string
{
    use EnumTrait;

    case initiate = 'INITIATE';
    case webhook = 'WEBHOOK';
    case verify = 'VERIFY';

    // TT-7.3b-b/SCRUM-233: chargeAuthorization() is a synchronous server-to-server call -- unlike
    // the checkout-redirect flow (INITIATE then a separate WEBHOOK/VERIFY), a definitive status
    // comes back in the SAME response that starts the charge, so ChargeOrganizationForModelAction
    // records it directly rather than needing an initiate-then-verify split.
    case orgCharge = 'ORG_CHARGE';

    // TT-7.3b-e/SCRUM-236: the periodic retainer-invoice settlement charge -- ProcessOrganizationInvoiceSettlementJob's
    // own chargeAuthorization() call, same synchronous shape as ORG_CHARGE above, distinguished
    // only so the status-history audit trail can tell a per-session pay-per-use charge apart from
    // an aggregated invoice settlement.
    case orgSettlement = 'ORG_SETTLEMENT';
}
