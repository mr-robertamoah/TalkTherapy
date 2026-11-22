<?php

namespace App\Support;

use Carbon\Carbon;

// SCRUM-471 (security re-check): a time from a request, in the app's timezone, for a form request's rules() to compare
// -- or null when it isn't one (garbage, an array), so the `date` rule answers with a 422 instead of Carbon throwing a
// 500 while the rules are still being built. A missing value parses as now, as Carbon::parse(null) always did here.
final class RequestTime
{
    public static function parse(mixed $value): ?Carbon
    {
        return rescue(fn () => Carbon::parse($value)->setTimezone(config('app.timezone')), null, false);
    }
}
