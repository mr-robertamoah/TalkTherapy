<?php

use App\Actions\Session\ClearOldSessionPinsAction;
use App\Enums\SessionTypeEnum;
use App\Models\Session;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// SCRUM-447 (TT-2.9f): an in-person session's exact map pin is cleared a set period after its end time; the
// landmark stays. Helpers are file-local and pin-prefixed (--parallel).

function pinSession(int $endedDaysAgo, string $type = 'IN_PERSON', array $attributes = []): Session
{
    $place = $type === SessionTypeEnum::in_person->value
        ? ['latitude' => 5.6037, 'longitude' => -0.187, 'landmark' => 'Opposite the Accra Mall main gate']
        : [];

    return Session::factory()->create([
        'type' => $type,
        'start_time' => now()->subDays($endedDaysAgo)->subHour(),
        'end_time' => now()->subDays($endedDaysAgo),
        ...$place,
        ...$attributes,
    ]);
}

function pinRow(Session $session): object
{
    return DB::table('sessions')->where('id', $session->id)->first();
}

test('a pin is kept 29 days after the session and cleared after 31, keeping the landmark', function () {
    config(['in_person.pin_retention_days' => 30]);
    $recent = pinSession(29);
    $old = pinSession(31);

    expect(ClearOldSessionPinsAction::new()->execute())->toBe(1);

    expect(pinRow($recent))->latitude->not->toBeNull()->longitude->not->toBeNull()
        ->and(pinRow($old))->latitude->toBeNull()->longitude->toBeNull()
        ->landmark->toBe('Opposite the Accra Mall main gate');
});

test('online sessions are untouched', function () {
    config(['in_person.pin_retention_days' => 30]);
    // An online session never has a place since SCRUM-443; one written by hand is still not this job's to clear.
    $online = pinSession(60, 'ONLINE', ['latitude' => 1.5, 'longitude' => 2.5, 'landmark' => 'Somewhere']);

    expect(ClearOldSessionPinsAction::new()->execute())->toBe(0)
        ->and((float) pinRow($online)->latitude)->toBe(1.5)
        ->and(pinRow($online)->landmark)->toBe('Somewhere');
});

test('soft-deleted sessions are cleared too, without changing updated_at, and a second run touches nothing', function () {
    config(['in_person.pin_retention_days' => 30]);
    $deleted = pinSession(40);
    $deleted->delete();
    DB::table('sessions')->where('id', $deleted->id)->update(['updated_at' => now()->subDays(40)]);
    $updatedAt = pinRow($deleted)->updated_at;
    $alreadyCleared = pinSession(50, attributes: ['latitude' => null, 'longitude' => null]);

    expect(ClearOldSessionPinsAction::new()->execute(1))->toBe(1)
        ->and(pinRow($deleted))->latitude->toBeNull()->updated_at->toBe($updatedAt)
        ->and(pinRow($alreadyCleared)->landmark)->toBe('Opposite the Accra Mall main gate')
        ->and(ClearOldSessionPinsAction::new()->execute())->toBe(0);
});

test('works through more sessions than one batch', function () {
    config(['in_person.pin_retention_days' => 30]);
    $sessions = collect(range(1, 5))->map(fn () => pinSession(35));

    expect(ClearOldSessionPinsAction::new()->execute(2))->toBe(5)
        ->and($sessions->every(fn (Session $session) => pinRow($session)->latitude === null))->toBeTrue();
});

test('a setting under the minimum, or not a number, skips the run; empty or 0 means 30 days', function (mixed $configured) {
    config(['in_person.pin_retention_days' => $configured]);
    Log::spy();
    $old = pinSession(400);

    expect(ClearOldSessionPinsAction::days())->toBeNull()
        ->and(ClearOldSessionPinsAction::new()->execute())->toBe(0)
        ->and(pinRow($old)->latitude)->not->toBeNull();
    Log::shouldHaveReceived('error')->once();
})->with([-1, 'thirty']);

test('the environment setting defaults to 30 and reads a number', function () {
    putenv('SESSION_PIN_RETENTION_DAYS');
    $default = (require base_path('config/in_person.php'))['pin_retention_days'];
    putenv('SESSION_PIN_RETENTION_DAYS=0');
    $zero = (require base_path('config/in_person.php'))['pin_retention_days'];
    putenv('SESSION_PIN_RETENTION_DAYS=1');
    config(['in_person.pin_retention_days' => (require base_path('config/in_person.php'))['pin_retention_days']]);
    putenv('SESSION_PIN_RETENTION_DAYS');

    expect($default)->toBe(30)->and($zero)->toBe(30)
        ->and(ClearOldSessionPinsAction::days())->toBe(1);
});

test('the privacy policy and "Your data" state the period', function () {
    config(['in_person.pin_retention_days' => 45]);

    $this->get(route('privacy'))->assertInertia(fn ($page) => $page->where('retentionDays.sessionPin', 45));
    $this->actingAs(User::factory()->adult()->create())->get(route('profile.your-data'))
        ->assertInertia(fn ($page) => $page->where('retentionDays.sessionPin', 45));
});

test('the scheduler clears them daily', function () {
    $this->artisan('schedule:list')->expectsOutputToContain('clear-old-session-pins');
});
