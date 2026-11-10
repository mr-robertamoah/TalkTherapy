<?php

namespace App\Exceptions;

class LinkException extends AppException
{
    // SCRUM-383: one answer for a link that can't be used -- deactivated, of an unknown or
    // switched-off type, or whose target has gone.
    public const INACTIVE = 'This link is no longer active.';

    public const NO_COUNSELLOR = 'You do not have a counsellor account hence you are not authorized to use this link. Create a counsellor account first.';

    public static function inactive(): self
    {
        return new self(self::INACTIVE, 422);
    }
}
