<?php

namespace App\Exceptions;

// TT-3.1e-f/SCRUM-285: a VideoException subtype specifically for "a minor client's join was
// blocked for lack of guardian video consent" -- distinguished from every other VideoException
// case (wrong session type, not in progress, not a participant, etc.) so the frontend can show a
// specific "guardian consent needed" banner instead of a generic error+retry, without resorting to
// fragile string-matching on the exception message. See VideoSessionController::failure()'s own
// use of instanceof to set the videoConsentRequired response flag.
class VideoConsentRequiredException extends VideoException
{
    //
}
