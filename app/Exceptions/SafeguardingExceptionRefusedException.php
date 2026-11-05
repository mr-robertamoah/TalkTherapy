<?php

namespace App\Exceptions;

// SCRUM-381: a refused step on a safeguarding exception, or on a counsellor's request for one. The
// code is the HTTP status (see ResolvesExceptionResponse).
class SafeguardingExceptionRefusedException extends AppException
{
    //
}
