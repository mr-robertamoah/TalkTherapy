<?php

namespace App\Exceptions;

use Exception;

// TT-4.10c/SCRUM-292: thrown by EnsureDobChangeIsAllowedAction when a dob edit would flip a
// relevant user's minor/adult status and a pending approval Request has been created in its
// place -- distinct from a plain validation failure (nothing was rejected outright; the edit was
// deferred, not refused).
class DobChangeRequiresApprovalException extends Exception
{
    //
}
