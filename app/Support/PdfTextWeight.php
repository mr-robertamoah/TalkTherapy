<?php

namespace App\Support;

// SCRUM-38/438: how much laying out some text costs dompdf, in characters -- each line break costs about as much as 100
// characters of prose (devops review, measured), so the export caps count them that way.
class PdfTextWeight
{
    public static function of(?string ...$texts): int
    {
        $text = implode("\n", array_map(fn (?string $t) => (string) $t, $texts));

        return mb_strlen($text) + 100 * substr_count($text, "\n");
    }
}
