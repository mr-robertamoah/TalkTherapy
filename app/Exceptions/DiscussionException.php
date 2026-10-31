<?php

namespace App\Exceptions;

use Exception;

class DiscussionException extends Exception
{
    // SCRUM-343: the one refusal every discussion action -- and EnsureDiscussionExistsAction --
    // gives a caller it turns away. It names nothing (discussion names are free text about cases)
    // and is the same whether or not the discussion exists, so ids can't be probed either.
    public static function notAllowed(): self
    {
        return new self('You are not allowed to do that with this discussion.', 422);
    }
}
