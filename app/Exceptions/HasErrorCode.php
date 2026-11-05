<?php

namespace App\Exceptions;

// SCRUM-288: a refusal the frontend tells apart by a stable code rather than by its message.
// FailureResponse adds it as `code` to a JSON failure and as `errors.alertCode` to a redirect --
// unless it is null (a refusal of that class that must not be told apart, SCRUM-382 review).
interface HasErrorCode
{
    public function errorCode(): ?string;
}
