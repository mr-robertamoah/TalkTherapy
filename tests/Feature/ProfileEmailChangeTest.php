<?php

use App\Models\Request;
use App\Models\User;
use App\Notifications\EmailChangedNotification;
use App\Support\MaskedEmail;
use App\Support\TwoFactorCodes;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;

// SCRUM-422: changing the account email needs the current password, and the previous verified address is told
// (without the new address in full).

function pecUser(array $attributes = []): User
{
    return User::factory()->create(['email' => 'old.address@example.com', 'email_verified_at' => now(), ...$attributes]);
}

function pecSend($test, User $user, array $data)
{
    return $test->actingAs($user)->patch(route('profile.update'), ['email' => $user->email, ...$data]);
}

test('changing the email without the current password is refused, and nothing else is saved either', function () {
    Notification::fake();
    $user = pecUser(['username' => 'keepmyname']);

    pecSend($this, $user, ['email' => 'new.address@example.org', 'country' => 'Ghana'])->assertSessionHasErrors('current_password');

    expect($user->fresh())->email->toBe('old.address@example.com')->email_verified_at->not->toBeNull()->country->not->toBe('Ghana');
    Notification::assertNothingSent();
});

test('a wrong current password is refused the same way', function () {
    Notification::fake();
    $user = pecUser();

    pecSend($this, $user, ['email' => 'new.address@example.org', 'current_password' => 'not-my-password'])->assertSessionHasErrors('current_password');

    expect($user->fresh()->email)->toBe('old.address@example.com');
    Notification::assertNothingSent();
});

test('with the right password the email changes, needs verifying, and the old address is told -- masked', function () {
    Notification::fake();
    $user = pecUser();

    pecSend($this, $user, ['email' => 'new.address@example.org', 'current_password' => 'password'])->assertSessionHasNoErrors();

    expect($user->fresh())->email->toBe('new.address@example.org')->email_verified_at->toBeNull();
    Notification::assertSentOnDemand(EmailChangedNotification::class, function (EmailChangedNotification $notification, array $channels, AnonymousNotifiable $notifiable) {
        $body = (string) $notification->toMail($notifiable)->render();

        return $notifiable->routes['mail'] === 'old.address@example.com'
            && $channels === ['mail']
            && str_contains($body, 'n•••@e•••.org')
            && ! str_contains($body, 'new.address@example.org')
            && ! str_contains(strtolower($body), 'reset')
            && ! str_contains(serialize($notification), 'new.address@example.org');
    });
    Notification::assertSentOnDemandTimes(EmailChangedNotification::class, 1);
});

test('an old address that was never verified isn\'t told', function () {
    Notification::fake();
    $user = pecUser(['email_verified_at' => null]);

    pecSend($this, $user, ['email' => 'new.address@example.org', 'current_password' => 'password'])->assertSessionHasNoErrors();

    expect($user->fresh()->email)->toBe('new.address@example.org');
    Notification::assertNothingSent();
});

test('clearing the email or adding a first one needs the password too', function () {
    Notification::fake();
    $user = pecUser();
    pecSend($this, $user, ['email' => ''])->assertSessionHasErrors('current_password');
    pecSend($this, $user, ['email' => '', 'current_password' => 'password'])->assertSessionHasNoErrors();
    expect($user->fresh()->email)->toBeNull();
    Notification::assertSentOnDemand(EmailChangedNotification::class, fn ($notification) => $notification->maskedNewEmail === null);

    $noEmail = User::factory()->create(['email' => null, 'email_verified_at' => null]);
    $this->flushSession(); // Another person, in their own session.
    pecSend($this, $noEmail, ['email' => 'first@example.com'])->assertSessionHasErrors('current_password');
    pecSend($this, $noEmail, ['email' => 'first@example.com', 'current_password' => 'password'])->assertSessionHasNoErrors();
    expect($noEmail->fresh()->email)->toBe('first@example.com');
});

test('other edits never ask for the password, and ignore one that\'s sent anyway', function () {
    Notification::fake();
    $user = pecUser();

    pecSend($this, $user, ['country' => 'Ghana'])->assertSessionHasNoErrors();
    pecSend($this, $user->fresh(), ['country' => 'Kenya', 'current_password' => 'wrong'])->assertSessionHasNoErrors();

    expect($user->fresh())->country->toBe('Kenya')->email_verified_at->not->toBeNull();
    Notification::assertNothingSent();
});

test('a refused password stops the save before anything else is looked at (no date-of-birth approval request)', function () {
    $user = pecUser(['dob' => now()->subYears(30)->toDateString()]);
    $requests = Request::count();

    pecSend($this, $user, ['email' => 'new.address@example.org', 'dob' => now()->subYears(15)->toDateString()])->assertSessionHasErrors('current_password');

    expect(Request::count())->toBe($requests)
        ->and($user->fresh()->dob->toDateString())->toBe(now()->subYears(30)->toDateString());
});

test('the profile form is limited to 10 tries a minute', function () {
    $user = pecUser();

    foreach (range(1, 10) as $try) {
        pecSend($this, $user, ['email' => 'new.address@example.org', 'current_password' => 'wrong-'.$try]);
    }

    pecSend($this, $user, ['email' => 'new.address@example.org', 'current_password' => 'password'])
        ->assertSessionHasErrors(['profile' => 'Too many tries. Please wait a minute and try again.']);
    expect($user->fresh()->email)->toBe('old.address@example.com');
});

test('an address is masked to its first letters and its ending', function () {
    expect(MaskedEmail::of('robert@gmail.com'))->toBe('r•••@g•••.com')
        ->and(MaskedEmail::of('a.b@mail.example.co.uk'))->toBe('a•••@m•••.uk')
        ->and(MaskedEmail::of('x@localhost'))->toBe('x•••@l•••')
        ->and(MaskedEmail::of(''))->toBe('');
});

test('a form that leaves the email out doesn\'t count as changing it', function () {
    $user = pecUser();

    $this->actingAs($user)->patch(route('profile.update'), ['country' => 'Ghana'])->assertSessionHasNoErrors();

    expect($user->fresh())->email->toBe('old.address@example.com')->country->toBe('Ghana');
});

test('the warning greets them by the name the account had before, not one set in the same change', function () {
    Notification::fake();
    $user = pecUser(['username' => 'realowner']);
    $before = $user->name ?: $user->username;

    pecSend($this, $user, ['email' => 'new.address@example.org', 'current_password' => 'password', 'firstName' => 'Totally', 'lastName' => 'Legit'])->assertSessionHasNoErrors();

    Notification::assertSentOnDemand(EmailChangedNotification::class, fn (EmailChangedNotification $notification) => $notification->name === $before);
});

test('changing only the letter case counts as a change too (the same test as the page)', function () {
    $user = pecUser();

    pecSend($this, $user, ['email' => 'Old.Address@example.com'])->assertSessionHasErrors('current_password');
});

test('a new email signs the account out on its other devices', function () {
    $user = pecUser();
    $hash = $user->password;

    pecSend($this, $user, ['email' => 'new.address@example.org', 'current_password' => 'password'])->assertSessionHasNoErrors();

    expect($user->fresh()->password)->not->toBe($hash)
        ->and(Hash::check('password', $user->fresh()->password))->toBeTrue();
    $this->assertAuthenticatedAs($user->fresh());
});

test('the password checks next door are limited too: confirming the password and deleting the account', function () {
    $user = pecUser();

    foreach (range(1, 6) as $try) {
        $this->actingAs($user)->post('/confirm-password', ['password' => 'wrong-'.$try]);
    }
    $this->actingAs($user)->post('/confirm-password', ['password' => 'password'])
        ->assertStatus(303)->assertSessionHasErrors(['password' => 'Too many tries. Please wait a minute and try again.']);

    foreach (range(1, 6) as $try) {
        $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'wrong-'.$try]);
    }
    $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'wrong'])
        ->assertStatus(303)->assertSessionHasErrors('password');
    expect($user->fresh())->not->toBeNull();
});

// QA (Bug A): the browser that changed the email stays signed in -- its next call through the API (Sanctum's own
// session check compares the password hash it stored) mustn't see the rehash as someone else's change.
test('the browser that changed the email stays signed in for its next API call', function () {
    $user = pecUser();
    $this->post('/login', ['username' => $user->username, 'password' => 'password']);

    $this->patch(route('profile.update'), ['email' => 'new.address@example.org', 'current_password' => 'password'])->assertSessionHasNoErrors();
    app('auth')->forgetGuards();

    $this->withHeaders(['Referer' => config('app.url'), 'Origin' => config('app.url')])
        ->getJson('/api/users/guardianship')->assertOk();
});

// QA (Bug B): over the limit, an Inertia save gets the 303 it needs (a 302 after PATCH repeats the PATCH -- a loop) and
// an error the form always shows.
test('over the limit, the page gets a 303 and an error it can show', function () {
    $user = pecUser();

    foreach (range(1, 10) as $try) {
        $this->actingAs($user)->patch(route('profile.update'), ['country' => 'Ghana']);
    }

    $this->actingAs($user)->patch(route('profile.update'), ['country' => 'Kenya'], ['X-Inertia' => 'true'])
        ->assertStatus(303)
        ->assertSessionHasErrors(['profile' => 'Too many tries. Please wait a minute and try again.']);
});

// QA: the same cause on the SCRUM-34b path -- new recovery codes rehash the password too.
test('the browser that makes new recovery codes stays signed in for its next API call', function () {
    $user = pecUser();
    [$codes, $hashes] = TwoFactorCodes::newRecoveryCodes();
    $secret = TwoFactorCodes::newSecret();
    $user->forceFill(['two_factor_secret' => $secret, 'two_factor_recovery_codes' => $hashes, 'two_factor_confirmed_at' => now()])->save();
    // Signed in for real (a session login), through the code step.
    $this->post('/login', ['username' => $user->username, 'password' => 'password']);
    $this->post(route('two_factor.challenge.store'), ['code' => (new Google2FA)->getCurrentOtp($secret)]);
    $this->get(route('profile.show'))->assertOk();

    $this->postJson(route('two_factor.recovery_codes'), ['current_password' => 'password', 'code' => $codes[0]])->assertOk();
    app('auth')->forgetGuards();

    $this->withHeaders(['Referer' => config('app.url'), 'Origin' => config('app.url')])
        ->getJson('/api/users/guardianship')->assertOk();
});
