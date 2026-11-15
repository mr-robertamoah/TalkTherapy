<?php

namespace App\Exceptions;

// SCRUM-37: a refusal in the outcome check-in flow.
class OutcomeCheckInException extends AppException
{
    public static function notFound(): self
    {
        return new self('That check-in could not be found.', 404);
    }
}
