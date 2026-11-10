<?php

namespace App\Rules;

use Carbon\Carbon;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

// TT-4.10c/SCRUM-292: extracted from identical inline Rule::prohibitedIf(...) closures
// duplicated across ProfileUpdateRequest and AdminUpdateUserRequest. A bare plausibility floor
// (nobody younger than 10 has a real account) -- unrelated to TT-4.10c's own 18-year-old
// minor/adult boundary gate, which lives in EnsureDobChangeIsAllowedAction instead, not here:
// this stays stateless, FormRequest-layer validation with no DB access, consistent with every
// other rule in this codebase's 42 existing FormRequests.
class MinimumAgeForDobRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value) {
            return;
        }

        if (now()->diffInYears(new Carbon($value), true) < 10) {
            $fail('The :attribute must belong to someone at least 10 years old.');
        }
    }
}
