<?php

namespace App\Exceptions;

use Exception;

// TT-3.1e-b/SCRUM-281: distinct from VideoException (the call-availability domain, TT-3.1a) --
// this covers the guardian-consent domain's own errors (grant/mode-set/audit-read authorization),
// matching this codebase's convention of one exception per sub-domain.
class VideoConsentException extends Exception
{
    //
}
