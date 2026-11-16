<?php

namespace App\Support;

use App\Rules\MinimumAgeForDobRule;

// SCRUM-454: the one set of rules for a date of birth someone gives -- the profile, the admin edit and Google's
// "Finish setting up" step. Optional everywhere: without one the person counts as under 18.
final class DobRules
{
    public static function for(): array
    {
        // `bail`: the age check parses the value, which throws (a 500) on something that isn't a date. `before:today`:
        // the age check measures the gap either way, so a date in the future would otherwise pass (security review).
        return ['nullable', 'bail', 'date', 'before:today', new MinimumAgeForDobRule];
    }
}
