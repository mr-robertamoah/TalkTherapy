<?php

use App\Actions\Auth\CheckPasswordStrengthAction;
use App\Models\User;
use App\Support\PwnedPasswordVerifier;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;

// SCRUM-35b: at sign-in, whether the password meets today's policy -- only the result is kept, and the profile says so
// until it's changed.

function wpnUser(string $password, array $attributes = []): User
{
    return User::factory()->create(['password' => Hash::make($password), ...$attributes]);
}

function wpnSignIn($test, User $user, string $password)
{
    return $test->post('/login', ['username' => $user->username, 'password' => $password]);
}

// The real breach check against a faked Have I Been Pwned; $breached is the password it says has been seen.
function wpnBreachList(?string $breached = null): void
{
    app()->instance(UncompromisedVerifier::class, new PwnedPasswordVerifier(app(HttpFactory::class), 3));
    Http::preventStrayRequests();
    Http::fake(['api.pwnedpasswords.com/range/*' => fn ($request) => Http::response(
        $breached && str_ends_with($request->url(), substr(strtoupper(sha1($breached)), 0, 5)) ? substr(strtoupper(sha1($breached)), 5).':7' : ''
    )]);
}

test('an old short password is flagged at sign-in, and sign-in goes ahead', function () {
    $user = wpnUser('password');

    wpnSignIn($this, $user, 'password');

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->password_weak_reason)->toBe(CheckPasswordStrengthAction::SHORT_OR_SIMPLE);
});

test('a password containing the username is flagged', function () {
    $user = wpnUser('marigold2026x', ['username' => 'marigold']);

    wpnSignIn($this, $user, 'marigold2026x');

    expect($user->fresh()->password_weak_reason)->toBe(CheckPasswordStrengthAction::CONTAINS_IDENTITY);
});

test('a strong password is checked against the breached list once, after the response', function () {
    wpnBreachList();
    $user = wpnUser('quiet harbour 2026');

    wpnSignIn($this, $user, 'quiet harbour 2026');
    expect($user->fresh())->password_weak_reason->toBeNull()->password_checked_at->not->toBeNull();

    $this->post('/logout');
    wpnSignIn($this, $user, 'quiet harbour 2026');

    Http::assertSentCount(1);
});

test('a breached password is flagged', function () {
    wpnBreachList('quiet harbour 2026');
    $user = wpnUser('quiet harbour 2026');

    wpnSignIn($this, $user, 'quiet harbour 2026');

    expect($user->fresh()->password_weak_reason)->toBe(CheckPasswordStrengthAction::BREACHED);
});

test('the flag is cleared by changing the password or resetting it', function () {
    $user = wpnUser('password');
    wpnSignIn($this, $user, 'password');

    $this->actingAs($user)->put(route('password.update'), [
        'current_password' => 'password', 'password' => 'quiet harbour 2026', 'password_confirmation' => 'quiet harbour 2026',
    ])->assertSessionHasNoErrors();
    expect($user->fresh())->password_weak_reason->toBeNull()->password_checked_at->not->toBeNull();

    $user->forceFill(['password_weak_reason' => CheckPasswordStrengthAction::BREACHED])->save();
    $token = Password::createToken($user);
    $this->post('/logout');
    $this->post(route('password.store'), ['token' => $token, 'email' => $user->email, 'password' => 'calm meadow 2027', 'password_confirmation' => 'calm meadow 2027'])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->password_weak_reason)->toBeNull();
});

test('the reason is shown only to the person themselves, and never stored with the password', function () {
    $user = wpnUser('password');
    wpnSignIn($this, $user, 'password');

    // As the next request would: the user read afresh.
    $this->actingAs($user->fresh())->get('/profile')->assertInertia(fn ($page) => $page->where('auth.user.passwordWeakReason', CheckPasswordStrengthAction::SHORT_OR_SIMPLE));
    // Someone else, in their own session (this one holds the first person's password hash -- SCRUM-34b).
    $this->flushSession()->actingAs(User::factory()->create())->get('/profile')->assertInertia(fn ($page) => $page->where('auth.user.passwordWeakReason', null));
    expect($user->fresh()->toArray())->not->toHaveKey('password_weak_reason')->not->toHaveKey('password_checked_at')
        ->and(collect($user->fresh()->getAttributes())->filter(fn ($value) => $value === 'password'))->toBeEmpty();
});

test('a sign-in never fails because the check can\'t be made', function () {
    $user = wpnUser('quiet harbour 2026');
    app()->instance(UncompromisedVerifier::class, new class implements UncompromisedVerifier
    {
        public function verify($data)
        {
            throw new RuntimeException('down');
        }
    });

    wpnSignIn($this, $user, 'quiet harbour 2026');

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->password_weak_reason)->toBeNull();
});

test('a closed account forgets it', function () {
    expect(User::ANONYMISED_ATTRIBUTES)->toContain('password_weak_reason', 'password_checked_at');
});

test('if the breached list can\'t be reached, the password isn\'t marked checked -- the next sign-in tries again', function () {
    app()->instance(UncompromisedVerifier::class, new PwnedPasswordVerifier(app(HttpFactory::class), 3));
    Http::preventStrayRequests();
    Http::fake(['api.pwnedpasswords.com/*' => Http::sequence()->push('', 503)->push('', 200)]);
    $user = wpnUser('quiet harbour 2026');

    wpnSignIn($this, $user, 'quiet harbour 2026');
    expect($user->fresh()->password_checked_at)->toBeNull();

    $this->post('/logout');
    wpnSignIn($this, $user, 'quiet harbour 2026');
    expect($user->fresh()->password_checked_at)->not->toBeNull();
});

test('a breach found earlier stands, and isn\'t looked up again', function () {
    wpnBreachList();
    $user = wpnUser('quiet harbour 2026', ['password_weak_reason' => CheckPasswordStrengthAction::BREACHED, 'password_checked_at' => now()]);

    wpnSignIn($this, $user, 'quiet harbour 2026');

    expect($user->fresh()->password_weak_reason)->toBe(CheckPasswordStrengthAction::BREACHED);
    Http::assertNothingSent();
});

test('with the breach check turned off, sign-in never looks', function () {
    config(['auth.password_policy.uncompromised' => false]);
    wpnBreachList('quiet harbour 2026');
    $user = wpnUser('quiet harbour 2026');

    wpnSignIn($this, $user, 'quiet harbour 2026');

    Http::assertNothingSent();
    expect($user->fresh()->password_weak_reason)->toBeNull();
});

test('signing in with the email is checked too', function () {
    $user = wpnUser('password');

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    expect($user->fresh()->password_weak_reason)->toBe(CheckPasswordStrengthAction::SHORT_OR_SIMPLE);
});

test('a new account\'s password is already checked', function () {
    $this->post(route('register'), ['username' => 'freshstart', 'password' => 'quiet harbour 2026', 'password_confirmation' => 'quiet harbour 2026']);

    expect(User::where('username', 'freshstart')->sole()->password_checked_at)->not->toBeNull();
});

test('signing in as yourself never checks someone else, whatever the address says', function () {
    $victim = wpnUser('quiet harbour 2026', ['username' => 'victimuser']);
    $attacker = wpnUser('password');

    $this->post('/login?username='.$victim->username, ['username' => $attacker->username, 'password' => 'password']);

    $this->assertAuthenticatedAs($attacker);
    expect($victim->fresh())->password_weak_reason->toBeNull()->password_checked_at->toBeNull()
        ->and($attacker->fresh()->password_weak_reason)->toBe(CheckPasswordStrengthAction::SHORT_OR_SIMPLE);
});
