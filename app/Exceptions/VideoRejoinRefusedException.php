<?php

namespace App\Exceptions;

// TT-3.3b/SCRUM-459: an AUTOMATIC rejoin (a browser reconnecting after its connection dropped) was
// refused because the call it was in has ended, or because this person was taken out of it -- both
// things that browser may have missed while it was offline. VideoSessionController::failure() adds
// `rejoinRefused` (the outcome) to the response, so the call lands in its ended/removed state rather
// than retrying or showing a generic error. A person's own "join video" is never refused this way.
class VideoRejoinRefusedException extends VideoException
{
    public const ENDED = 'ended';

    public const REMOVED = 'removed';

    public function __construct(public readonly string $outcome)
    {
        parent::__construct(
            $outcome === self::REMOVED ? 'You were taken out of this video call.' : 'This video call has ended.',
            422,
        );
    }
}
