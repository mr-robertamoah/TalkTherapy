<?php

namespace App\Traits;

use Throwable;

trait ResolvesExceptionResponse
{
    // A thrown exception's own getCode() is only trustworthy as an HTTP status when it falls
    // within the real 400-599 range -- anything else (most commonly the default code 0) means
    // "unexpected", and must map to a generic 500 rather than being echoed back verbatim.
    protected function statusFor(Throwable $th): int
    {
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
