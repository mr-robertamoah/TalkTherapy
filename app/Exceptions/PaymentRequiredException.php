<?php

namespace App\Exceptions;

use Exception;

// SCRUM-219/TT-7.5a: distinguishable from TherapyAccessDeniedException so a controller can route
// a blocked-for-payment client back toward the Pay flow, rather than treating it as a plain
// access-denied dead end.
class PaymentRequiredException extends Exception
{
    //
}
