<?php

namespace App\Traits;

use App\Exceptions\AppException;
use Throwable;

trait ResolvesExceptionResponse
{
    // A thrown exception's own getCode() is only trustworthy as an HTTP status when it is one of
    // the app's own refusals (SCRUM-95: an AppException) AND falls within the real 400-599 range.
    // Anything else -- the default code 0, or a third-party exception whose code happens to be an
    // upstream HTTP status (an HTTP client's RequestException) -- maps to a generic 500.
    protected function statusFor(Throwable $th): int
    {
        if (! $th instanceof AppException) {
            return 500;
        }

        return ($th->getCode() >= 400 && $th->getCode() < 600) ? $th->getCode() : 500;
    }

    // Deciding the message from the resolved $status (not the raw getCode()) is what keeps
    // this safe against an uncoded exception (SCRUM-90/SCRUM-92) -- an exception whose code
    // isn't a real HTTP status must never have its raw message shown, even though its status
    // gets mapped to 500 either way.
    protected function messageFor(Throwable $th, int $status): string
    {
        return $status == 500 ? 'Something unfortunate happened. Please try again shortly.' : $th->getMessage();
    }
}
