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
}
