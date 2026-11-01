<?php

namespace App\Exceptions;

use Exception;

// SCRUM-359: a refused suspension step, or an action refused because someone is suspended. The
// code is the HTTP status (see ResolvesExceptionResponse).
class SuspensionException extends Exception
{
    //
}
