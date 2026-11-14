<?php

namespace App\Support;

// SCRUM-422: an address shown only partly -- enough for its owner to recognise, not enough to read off: the first
// character of the name and of the domain, and the ending (r•••@g•••.com).
class MaskedEmail
{
    private const MASK = '•••';

    public static function of(?string $email): string
    {
        $email = trim((string) $email);

        if (! str_contains($email, '@')) {
            return $email === '' ? '' : mb_substr($email, 0, 1).self::MASK;
        }

        [$name, $domain] = explode('@', $email, 2);
        $dot = mb_strrpos($domain, '.');
        $host = $dot === false ? $domain : mb_substr($domain, 0, $dot);
        $ending = $dot === false ? '' : mb_substr($domain, $dot);

        return mb_substr($name, 0, 1).self::MASK.'@'.mb_substr($host, 0, 1).self::MASK.$ending;
    }
}
