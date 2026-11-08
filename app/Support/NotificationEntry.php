<?php

namespace App\Support;

use Illuminate\Support\Carbon;

// SCRUM-39: what the notification center shows for one notification -- a title, a short message and,
// where it makes sense, a link (built on the server; the page it leads to still checks access).
final class NotificationEntry
{
    // The sentence the emails to a client end with when their support changes (security review: kept in the
    // center too, on a mental-health platform).
    public const CRISIS_LINE = ' If you need support urgently, please contact your local emergency services or a crisis line.';

    public function __construct(
        public readonly string $title,
        public readonly string $message = '',
        public readonly ?string $url = null,
    ) {}

    // A link only when every id it needs was saved (old rows may lack one) -- never an error.
    public static function link(string $route, array $parameters): ?string
    {
        foreach ($parameters as $value) {
            if ($value === null || $value === '') {
                return null;
            }
        }

        // A wrong route name is a bug, so it's reported (reviews) -- and still never breaks the list.
        return rescue(fn () => route($route, $parameters), null, report: true);
    }

    // A saved date as "Nov 8, 2026", or null when it is missing or unreadable.
    public static function date(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? rescue(fn () => Carbon::parse($value)->toFormattedDateString(), null, report: false) : null;
    }

    // A saved free-text value (a name), or the fallback.
    public static function text(array $data, string $key, string $fallback): string
    {
        return is_string($data[$key] ?? null) && trim($data[$key]) !== '' ? trim($data[$key]) : $fallback;
    }
}
