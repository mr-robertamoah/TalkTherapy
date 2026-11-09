<?php

namespace App\Exceptions;

// SCRUM-395: urgent help is switched off (HELP_ENABLED). 503 with its own code, so the dialog shows this
// message -- and only this one: any other 503 (maintenance, a proxy) keeps the emergency-number fallback.
class HelpUnavailableException extends HelpException implements HasErrorCode
{
    public const CODE = 'help_unavailable';

    public const MESSAGE = 'The urgent help chat is not available right now. If you are in danger, call your emergency number now.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE, 503);
    }

    public function errorCode(): ?string
    {
        return self::CODE;
    }
}
