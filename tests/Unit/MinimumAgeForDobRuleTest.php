<?php

use App\Rules\MinimumAgeForDobRule;

// TT-4.10c/SCRUM-292: extracted from the identical inline Rule::prohibitedIf(...) closures that
// used to be duplicated across ProfileUpdateRequest and AdminUpdateUserRequest.

function validateWithMinimumAgeForDobRule(?string $value): ?string
{
    $failMessage = null;

    (new MinimumAgeForDobRule)->validate('dob', $value, function (string $message) use (&$failMessage) {
        $failMessage = $message;
    });

    return $failMessage;
}

test('a null value passes (nullable dob)', function () {
    expect(validateWithMinimumAgeForDobRule(null))->toBeNull();
});

test('a dob for someone at least 10 years old passes', function () {
    expect(validateWithMinimumAgeForDobRule(now()->subYears(10)->toDateString()))->toBeNull();
});

test('a dob for someone younger than 10 fails', function () {
    expect(validateWithMinimumAgeForDobRule(now()->subYears(5)->toDateString()))->not->toBeNull();
});
