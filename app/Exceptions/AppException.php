<?php

namespace App\Exceptions;

use Exception;

// SCRUM-95: the app's own refusals. ResolvesExceptionResponse shows an exception's code and message
// to the user only when it extends this class, because these messages are written for people.
// Anything else (an HTTP client error, a driver error) is shown as a generic 500, whatever its code,
// since its message can carry an upstream response body or internals.
abstract class AppException extends Exception {}
