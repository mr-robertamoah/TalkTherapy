<?php

namespace App\Exceptions;

// SCRUM-288: a minor's therapy is paused while they have no active guardian -- the minor, and the
// counsellor of their individual therapy, can read it but not act in it. 403 like the other
// "you may not do this right now" refusals (SuspensionException); the code tells it apart.
// The messages never name a guardian.
class GuardianRequiredException extends AppException implements HasErrorCode
{
    public const CODE = 'guardian_required';

    public const PAUSED = 'This therapy is paused until a guardian is added to the account.';

    public const CLIENT_PAUSED = 'This therapy is paused until a guardian is added to the client\'s account.';

    public const REQUEST_UNAVAILABLE = 'This request can\'t be accepted right now.';

    public static function forViewer(): self
    {
        return new self(self::PAUSED, 403);
    }

    public static function forClient(): self
    {
        return new self(self::CLIENT_PAUSED, 403);
    }

    // To someone who isn't the minor's counsellor (e.g. a group's, answering their request to join).
    public static function requestUnavailable(): self
    {
        return new self(self::REQUEST_UNAVAILABLE, 403);
    }

    public function errorCode(): string
    {
        return self::CODE;
    }
}
