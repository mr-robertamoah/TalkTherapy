<?php

namespace App\Exceptions;

use Exception;

// TT-7.3b-f2/SCRUM-238: deliberately NOT PaymentRequiredException -- TherapyController's own
// catch for that exception flags a "paymentRequired" flash prop that routes the client toward a
// personal Pay Now flow (SCRUM-219/221), which would be actively misleading here: an org-billing
// suspension has no personal-pay fallback (the org, not the member, must resolve it), and a
// retainer-covered engagement never has a personal payment path to resume in the first place.
// This is a hard access denial (mirrors TherapyAccessDeniedException's role), just for a
// different underlying reason.
class OrganizationBillingSuspendedException extends Exception
{
    //
}
