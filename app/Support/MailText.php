<?php

namespace App\Support;

use Illuminate\Contracts\Support\Htmlable;

// SCRUM-366: mail lines and greetings are rendered as Markdown. Secured encoding
// (AppServiceProvider) already stops links, images and HTML; this stops the rest of the
// block-level formatting a user could smuggle in through their name or a reason -- a heading,
// a list, a code block -- by keeping every line on one line and putting an invisible zero-width
// space before a Markdown marker at its start (so it is no longer at the start). Invisible in both
// the HTML and the plain-text part, unlike a backslash. A quote can't start anyway: secured
// encoding turns ">" into "&gt;". Inline emphasis (*x*) is left alone: it can only restyle text,
// never add a link. Lines the app builds as HTML (Htmlable) are passed through untouched.
class MailText
{
    private const ZERO_WIDTH_SPACE = "\u{200B}";

    public static function inline(mixed $line): mixed
    {
        if ($line instanceof Htmlable || ! is_string($line)) {
            return $line;
        }

        $flat = trim(preg_replace('/\s*\R\s*/u', ' ', $line));

        return preg_match('/^(#{1,6}|[-+*]|\d+[.)]|`{3,}|~{3,}|=+|\||(?:[-*_]\s*){3,}$)(?=\s|$)/u', $flat)
            ? self::ZERO_WIDTH_SPACE.$flat
            : $flat;
    }
}
