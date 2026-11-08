<?php

namespace App\Support;

// SCRUM-32 (TT-4.3b reviews): what NotifyUninvolvedAdminsAction actually did -- how many administrators
// were told, whether the fallback mailbox was emailed instead, or whether it failed -- so a caller never
// records "fallback mailbox alerted" (in an append-only history) when nothing was sent.
final class AdminNotifyResult
{
    public function __construct(
        public readonly int $told = 0,
        public readonly bool $fellBack = false,
        public readonly bool $failed = false,
    ) {}

    public function reachedSomeone(): bool
    {
        return $this->told > 0 || $this->fellBack;
    }
}
