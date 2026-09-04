<?php

namespace App\Enums;

use App\Traits\EnumTrait;

enum OrganizationInvoiceStatusEnum: string
{
    use EnumTrait;

    // Accruing lines for the current period -- the only status a periodic sweep ever picks up
    // for settlement.
    case open = 'OPEN';
    // A settlement charge is in flight (Transaction created, Paystack call not yet resolved).
    case pending = 'PENDING';
    case settled = 'SETTLED';
    // Terminal -- no automatic retry. TT-7.3b-f2's dunning/suspension mechanism (not yet built)
    // is the intended follow-up for a failed invoice, not this ticket's job. A later period opens
    // normally regardless of a prior one sitting failed (suspension enforcement lives at the
    // access-gate call site, not the billing pipeline -- SCRUM-230 architect review).
    case failed = 'FAILED';
}
