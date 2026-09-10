<?php

namespace App\Notifications\Concerns;

// TT-7.7c/SCRUM-251: shared by RefundRequestedNotification/RefundRequestRejectedNotification --
// both interpolate a client-supplied free-text `reason` into MailMessage::line(), which Laravel
// compiles through a CommonMark parser (SCRUM-254: unescaped free text there is vulnerable to
// Markdown injection, e.g. a reason like "[click here](evil.com)" rendering as a real link).
// Extracted here rather than left duplicated once a second notification needed the identical
// stopgap -- SCRUM-254's own broader cleanup will likely apply this same trait to the other,
// pre-existing instances of this pattern elsewhere in the codebase.
trait EscapesMarkdown
{
    private function escapeMarkdown(string $text): string
    {
        return preg_replace('/([\\\\`*_{}\[\]()#+\-.!])/', '\\\\$1', $text);
    }
}
