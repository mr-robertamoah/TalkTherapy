<?php

namespace App\Traits;

use App\Support\FailureResponse;
use Throwable;

trait ResolvesExceptionResponse
{
    // SCRUM-374: the rules live in App\Support\FailureResponse, shared with the AppException
    // renderer in bootstrap/app.php; these remain for the controllers' inline catch blocks.
    protected function statusFor(Throwable $th): int
    {
        return FailureResponse::status($th);
    }

    protected function messageFor(Throwable $th, int $status): string
    {
        return FailureResponse::message($th, $status);
    }
}
