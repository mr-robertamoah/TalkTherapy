<?php

use App\Models\User;
use App\Rules\MinimumAgeForDobRule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

// SCRUM-456: age is measured from the date of birth to now, signed -- a future date of birth is age 0, never the size of
// the gap, so stored or imported data can't count as an adult.

test('a past date of birth gives whole years', function () {
    expect(User::ageForDob(now()->subYears(30)->toDateString()))->toBe(30)
        ->and(User::ageForDob(now()->subYears(18)->toDateString()))->toBe(18)
        ->and(User::ageForDob(now()->subYears(18)->addDay()->toDateString()))->toBe(17)
        ->and(User::ageForDob(now()->subYears(30)))->toBe(30)
        ->and(User::ageForDob(null))->toBe(0);
});

test('a future date of birth is age 0, never an adult', function (int $yearsAhead) {
    $dob = now()->addYears($yearsAhead)->toDateString();

    expect(User::ageForDob($dob))->toBe(0)
        ->and(User::isAdultForDob($dob))->toBeFalse();
})->with([1, 18, 30]);

test('a stored future date of birth reads as age 0 and not adult', function () {
    $user = User::factory()->create();
    // Straight to the row, as an import or an old bug would leave it (the forms refuse it: before:today).
    User::query()->whereKey($user->id)->update(['dob' => now()->addYears(30)->toDateString()]);
    $user = $user->fresh();

    expect($user->age)->toBe(0)
        ->and($user->isAdult())->toBeFalse();
});

test('a stored adult date of birth is still adult', function () {
    $user = User::factory()->create(['dob' => now()->subYears(30)->toDateString()]);

    expect($user->fresh()->isAdult())->toBeTrue()
        ->and($user->fresh()->age)->toBe(30);
});

test('the at-least-10 rule refuses a future date (age 0), not only a recent one', function (string $dob, bool $passes) {
    $validator = Validator::make(['dob' => $dob], ['dob' => [new MinimumAgeForDobRule]]);

    expect($validator->passes())->toBe($passes);
})->with([
    'eleven years ago' => [fn () => now()->subYears(11)->toDateString(), true],
    'five years ago' => [fn () => now()->subYears(5)->toDateString(), false],
    'thirty years ahead' => [fn () => now()->addYears(30)->toDateString(), false],
]);

test('the 18th birthday counts from the start of the day, whatever time the value carries', function () {
    $this->travelTo(Carbon::parse('2026-11-16 00:05:00'));

    expect(User::ageForDob('2008-11-16 23:30:00'))->toBe(18)
        ->and(User::ageForDob(Carbon::parse('2008-11-16 23:30:00')))->toBe(18)
        ->and(User::isAdultForDob('2008-11-16 23:30:00'))->toBeTrue()
        ->and(User::ageForDob('2008-11-17'))->toBe(17);
});

test('a 29 February birthday comes round on 1 March in other years', function (string $today, int $age) {
    $this->travelTo(Carbon::parse($today.' 12:00:00'));

    expect(User::ageForDob('2008-02-29'))->toBe($age);
})->with([
    '28 February' => ['2026-02-28', 17],
    '1 March' => ['2026-03-01', 18],
    '29 February of a leap year' => ['2028-02-29', 20],
]);
