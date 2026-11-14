<?php

use App\Enums\AdministratorTypeEnum;
use App\Events\SessionCheckRequestedEvent;
use App\Jobs\StoreVisitationJob;
use App\Models\Administrator;
use App\Models\User;
use App\Support\TwoFactorCodes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;

// SCRUM-423: when one of an account's sessions may have ended, its open pages are told to check (a content-free
// broadcast), and the check answers 401 to an ended session -- the page then goes to sign-in and closes its live
// connection (resources/js/Support/sessionEnded.js).

// The message is sent after the response (defer); here, at once.
beforeEach(fn () => $this->withoutDefer());

function sotSignIn($test, User $user): void
{
    $test->post('/login', ['username' => $user->username, 'password' => 'password']);
}

function sotNudged(User $user): bool
{
    return Event::dispatched(SessionCheckRequestedEvent::class, fn ($event) => $event->broadcastOn()[0]->name === "private-users.{$user->id}")->isNotEmpty();
}

test('a password change tells the account\'s open pages to check, with nothing in the message', function () {
    Event::fake([SessionCheckRequestedEvent::class]);
    $user = User::factory()->create();

    $user->forceFill(['password' => Hash::make('quiet harbour 2026')])->save();

    expect(sotNudged($user))->toBeTrue();
    Event::assertDispatched(SessionCheckRequestedEvent::class, fn ($event) => $event->broadcastWith() === [] && $event->broadcastAs() === 'session.check');
});

test('two-factor turned on or off tells them too; other profile changes don\'t', function () {
    Event::fake([SessionCheckRequestedEvent::class]);
    $user = User::factory()->create();

    $user->forceFill(['username' => 'someone_else'])->save();
    expect(sotNudged($user))->toBeFalse();

    $user->forceFill(['two_factor_secret' => TwoFactorCodes::newSecret(), 'two_factor_confirmed_at' => now()])->save();
    expect(sotNudged($user))->toBeTrue();

    Event::fake([SessionCheckRequestedEvent::class]);
    $user->fresh()->turnOffTwoFactor();
    expect(sotNudged($user))->toBeTrue();
});

test('the message goes out only once the change is committed', function () {
    Event::fake([SessionCheckRequestedEvent::class]);
    $user = User::factory()->create();

    DB::transaction(function () use ($user) {
        $user->forceFill(['password' => Hash::make('quiet harbour 2026')])->save();
        expect(sotNudged($user))->toBeFalse();
    });

    expect(sotNudged($user))->toBeTrue();
});

// Signing out reaches this browser's tabs in the browser (sessionEnded.js); a server message would reach the account's
// other devices too and keep their idle sessions alive (security review).
test('signing out sends no message to the account\'s other devices', function () {
    $user = User::factory()->create();
    sotSignIn($this, $user);
    Event::fake([SessionCheckRequestedEvent::class]);

    $this->post(route('logout'));

    expect(sotNudged($user))->toBeFalse();
});

test('the check answers a signed-in page with nothing, and an ended or missing session with 401', function () {
    $user = User::factory()->create();

    $this->getJson(route('session.check', ['user' => $user->id]))->assertUnauthorized();

    sotSignIn($this, $user);
    $this->getJson(route('session.check', ['user' => $user->id]))->assertNoContent();

    // Ended from another device: the password changed.
    $user->fresh()->forceFill(['password' => Hash::make('quiet harbour 2026')])->save();
    app('auth')->forgetGuards();

    $this->getJson(route('session.check', ['user' => $user->id]))->assertUnauthorized();
});

test('a page shown to someone else gets 401 once another person has signed in on the browser', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();
    sotSignIn($this, $second);

    $this->getJson(route('session.check', ['user' => $first->id]))->assertUnauthorized();
    $this->getJson(route('session.check'))->assertUnauthorized();
});

test('the check stays open to an administrator who still has to set up two-factor (SCRUM-34d)', function () {
    config(['auth.two_factor.admins_required_from' => now()->subDay()->toDateString()]);
    $admin = User::factory()->adult()->has(Administrator::factory()->state(['type' => AdministratorTypeEnum::normal->value]))->create();

    $this->actingAs($admin)->getJson(route('session.check', ['user' => $admin->id]))->assertNoContent();
});

test('a failure while sending the message (here a listener that throws) never fails the change', function () {
    $user = User::factory()->create();
    Event::listen(SessionCheckRequestedEvent::class, fn () => throw new RuntimeException('Reverb is down'));

    $user->forceFill(['password' => Hash::make('quiet harbour 2026')])->save();

    expect(Hash::check('quiet harbour 2026', $user->fresh()->password))->toBeTrue();
});

// Code review: the session that makes the change must stay signed in -- its own tabs get the message too.
test('the session that changes its own password still answers the check', function () {
    $user = User::factory()->create();
    sotSignIn($this, $user);

    $this->put(route('password.update'), ['current_password' => 'password', 'password' => 'quiet harbour 2026', 'password_confirmation' => 'quiet harbour 2026'])
        ->assertSessionHasNoErrors();

    $this->getJson(route('session.check', ['user' => $user->id]))->assertNoContent();
});

test('a session ended by two-factor turned on elsewhere answers 401', function () {
    $user = User::factory()->create();
    sotSignIn($this, $user);

    $user->fresh()->forceFill(['two_factor_secret' => TwoFactorCodes::newSecret(), 'two_factor_confirmed_at' => now()])->save();
    app('auth')->forgetGuards();

    $this->getJson(route('session.check', ['user' => $user->id]))->assertUnauthorized();
});

test('the check isn\'t recorded as a visit', function () {
    Queue::fake();
    $user = User::factory()->create();

    $this->actingAs($user)->getJson(route('session.check', ['user' => $user->id]))->assertNoContent();

    Queue::assertNotPushed(StoreVisitationJob::class);
});

// QA: Sanctum's own session check on the API (password_hash_web) mustn't read the session's own new password as someone
// else's change -- the page would go to sign-in with "Your session ended".
test('the session that changes its own password stays signed in for its next API call', function () {
    $user = User::factory()->create();
    sotSignIn($this, $user);

    $this->put(route('password.update'), ['current_password' => 'password', 'password' => 'quiet harbour 2026', 'password_confirmation' => 'quiet harbour 2026'])
        ->assertSessionHasNoErrors();
    app('auth')->forgetGuards();

    $this->withHeaders(['Referer' => config('app.url'), 'Origin' => config('app.url')])
        ->getJson('/api/users/guardianship')->assertOk();
});
