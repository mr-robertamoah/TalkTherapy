<?php

namespace App\Exceptions;

use Exception;

class TherapyTopicException extends Exception
{
    // SCRUM-344: the one refusal every topic action gives a caller it turns away, also used for
    // "topic not found" -- topic names are free text about a client's issues.
    public static function notAllowed(): self
    {
        return new self('You are not allowed to do that with this topic.', 422);
    }
}
