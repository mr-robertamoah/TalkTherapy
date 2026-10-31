<?php

namespace App\Exceptions;

use Exception;

class TherapyException extends Exception
{
    // SCRUM-343/344: the one refusal every therapy action gives a caller it turns away -- the same
    // text as EnsureTherapyExistsAction's "not found", so neither names the therapy nor reveals
    // whether an id exists.
    public const NOT_ALLOWED = 'You are not allowed to do that with this therapy.';

    public static function notAllowed(): self
    {
        return new self(self::NOT_ALLOWED, 422);
    }
}
