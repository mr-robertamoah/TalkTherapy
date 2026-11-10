<?php

namespace App\Exceptions;

// SCRUM-288: starting or joining a therapy as a minor with no active guardian. Still a
// CannotCreateTherapyException (and still a 422) for existing callers, with the same
// guardian_required code as a paused therapy so the frontend shows the same guidance.
class CannotCreateTherapyWithoutGuardianException extends CannotCreateTherapyException implements HasErrorCode
{
    public function errorCode(): string
    {
        return GuardianRequiredException::CODE;
    }
}
