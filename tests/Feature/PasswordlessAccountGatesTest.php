<?php

use App\Actions\User\AnonymiseClosedAccountAction;
use App\Actions\User\ConfirmItsYouAction;
use App\Enums\AdministratorTypeEnum;
use App\Http\Middleware\EndStaleSessions;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\User;
use App\Support\TwoFactorCodes;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

// SCRUM-452 (Google sign-in, SCRUM-112): an account made with Google has no password (users.password is nullable). Every
// gate that asks for the current password must refuse it cleanly -- a validation error, never a 500 and never a pass --
// and change nothing; password sign-in fails as for a wrong password.

// An account with no password, as one made with Google will be.
function pagUser(array $attributes = []): User
{
    $user = User::factory()->create(['email_verified_at' => now(), ...$attributes]);
    $user->forceFill(['password' => null])->save();

    return $user->fresh();
}

function pagWithTwoFactor(User $user): User
{
    [, $hashes] = TwoFactorCodes::newRecoveryCodes();
    $user->forceFill(['two_factor_secret' => TwoFactorCodes::newSecret(), 'two_factor_recovery_codes' => $hashes, 'two_factor_confirmed_at' => now()])->save();

    return $user->fresh();
}

// Whatever a gate could have changed on the account.
function pagSnapshot(User $user): array
{
    $user = User::withTrashed()->find($user->id);

    return [
        $user->getRawOriginal('password'), $user->email, $user->pending_email, $user->remember_token, $user->deleted_at,
        $user->two_factor_confirmed_at?->getTimestamp(), $user->getRawOriginal('two_factor_recovery_codes'),
    ];
}

beforeEach(fn () => Event::fake([OtherDeviceLogout::class]));

afterEach(fn () => Event::assertNotDispatched(OtherDeviceLogout::class));

test('the password column takes null, and the account has none', function () {
    expect(pagUser()->getRawOriginal('password'))->toBeNull();
});

test('changing the password is refused', function () {
    $user = pagUser();
    $before = pagSnapshot($user);

    $this->actingAs($user)->from(route('profile.show'))
        ->put(route('password.update'), ['current_password' => '', 'password' => 'a-new-Password-123', 'password_confirmation' => 'a-new-Password-123'])
        ->assertRedirect(route('profile.show'))->assertSessionHasErrors('current_password');
    $this->actingAs($user)->from(route('profile.show'))
        ->put(route('password.update'), ['current_password' => 'anything', 'password' => 'a-new-Password-123', 'password_confirmation' => 'a-new-Password-123'])
        ->assertRedirect(route('profile.show'))->assertSessionHasErrors('current_password');

    expect(pagSnapshot($user))->toBe($before);
});

test('confirming the password is refused', function () {
    $user = pagUser();

    $this->actingAs($user)->from(route('password.confirm'))->post(route('password.confirm.store'), ['password' => 'anything'])
        ->assertSessionHasErrors('password');

    expect(session('auth.password_confirmed_at'))->toBeNull();
});

test('setting up two-factor sign-in is refused', function () {
    $user = pagUser();

    $this->actingAs($user)->postJson(route('two_factor.setup'), ['current_password' => 'anything'])
        ->assertUnprocessable()->assertJsonValidationErrors('current_password');

    expect($user->fresh()->getRawOriginal('two_factor_secret'))->toBeNull();
});

test('new recovery codes and turning two-factor off are refused', function () {
    $user = pagWithTwoFactor(pagUser());
    $before = pagSnapshot($user);

    $this->actingAs($user)->postJson(route('two_factor.recovery_codes'), ['current_password' => 'anything', 'code' => '123456'])
        ->assertUnprocessable()->assertJsonValidationErrors('current_password');
    $this->actingAs($user)->deleteJson(route('two_factor.destroy'), ['current_password' => 'anything', 'code' => '123456'])
        ->assertUnprocessable()->assertJsonValidationErrors('current_password');

    expect(pagSnapshot($user))->toBe($before)
        ->and($user->fresh()->hasTwoFactorEnabled())->toBeTrue();
});

test('changing or removing the email is refused', function () {
    $user = pagUser(['email' => 'pag-owner@example.com']);
    $before = pagSnapshot($user);

    foreach (['pag-new@example.com', ''] as $email) {
        $this->actingAs($user)->from(route('profile.show'))
            ->patch(route('profile.update'), ['email' => $email, 'current_password' => 'anything'])
            ->assertSessionHasErrors('current_password');
    }

    expect(pagSnapshot($user))->toBe($before);
});

test('closing the account is refused', function () {
    $user = pagUser();
    $before = pagSnapshot($user);

    $this->actingAs($user)->from(route('profile.show'))->delete(route('profile.destroy'), ['password' => 'anything'])
        ->assertSessionHasErrors('password');

    expect(pagSnapshot($user))->toBe($before)
        ->and($user->fresh()->trashed())->toBeFalse();
});

test('deleting the counsellor profile is refused', function () {
    $user = pagUser();
    $counsellor = Counsellor::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->delete(route('counsellor.delete', ['counsellorId' => $counsellor->id]), ['password' => 'anything'])
        ->assertSessionHasErrors('password');

    expect($counsellor->fresh()->deleted_at)->toBeNull();
});

test('a super administrator without a password can\'t reset someone\'s two-factor sign-in', function () {
    $admin = pagUser();
    Administrator::factory()->create(['user_id' => $admin->id, 'type' => AdministratorTypeEnum::super->value]);
    $target = pagWithTwoFactor(User::factory()->create());

    $this->actingAs($admin->fresh())->postJson(route('admin.users.two_factor.reset', ['userId' => $target->id]), ['current_password' => 'anything'])
        ->assertUnprocessable()->assertJsonValidationErrors('current_password');

    expect($target->fresh()->hasTwoFactorEnabled())->toBeTrue()
        ->and($target->fresh()->pendingTwoFactorReset()->exists())->toBeFalse();
});

test('"confirm it\'s you" refuses, with or without two-factor, and the download says so', function () {
    $user = pagUser();

    expect(ConfirmItsYouAction::new()->requirements($user)['passwordSet'])->toBeFalse()
        ->and(fn () => ConfirmItsYouAction::new()->execute($user, ['current_password' => 'anything'], 'a copy of your data'))
        ->toThrow(ValidationException::class)
        ->and(fn () => ConfirmItsYouAction::new()->execute(pagWithTwoFactor($user), ['current_password' => 'anything', 'code' => '123456'], 'a copy of your data'))
        ->toThrow(ValidationException::class);

    $this->actingAs($user->fresh())->from(route('profile.show'))
        ->post(route('profile.your-data.download'), ['current_password' => 'anything', 'code' => '123456'])
        ->assertSessionHasErrors('form'); // with two-factor on, password and code are refused together
});

test('password sign-in fails as for a wrong password', function () {
    $user = pagUser(['email' => 'pag-signin@example.com']);

    foreach ([['email' => $user->email], ['username' => $user->username]] as $who) {
        $this->from(route('login'))->post(route('login'), [...$who, 'password' => 'anything'])
            ->assertRedirect(route('login'))->assertSessionHasErrors(array_key_first($who));
        $this->from(route('login'))->post(route('login'), [...$who, 'password' => ''])
            ->assertSessionHasErrors('password');
    }

    $this->assertGuest();
    expect($user->fresh()->getRawOriginal('password'))->toBeNull()
        ->and($user->fresh()->password_checked_at)->toBeNull();
});

test('the session fingerprint handles no password, and changes once one is set', function () {
    $user = pagUser();
    $fingerprint = EndStaleSessions::fingerprint($user);

    expect($fingerprint)->toBeString()->not->toBeEmpty();

    // Signed in (as Google sign-in will do), pages load -- the fingerprint check runs on each.
    $this->withoutVite()->actingAs($user)->get(route('profile.show'))->assertOk();

    $user->forceFill(['password' => 'a-new-Password-123'])->save();

    expect(EndStaleSessions::fingerprint($user->fresh()))->not->toBe($fingerprint);
});

test('the Google id is hidden, not mass assignable, and unique', function () {
    $user = User::factory()->create();
    $user->forceFill(['google_id' => 'pag-google-1', 'google_linked_at' => now()])->save();

    expect($user->fresh()->google_linked_at)->toBeInstanceOf(Carbon::class)
        ->and($user->fresh()->toArray())->not->toHaveKeys(['google_id', 'google_linked_at'])
        ->and((new User(['google_id' => 'pag-google-2']))->google_id)->toBeNull();

    expect(fn () => User::factory()->create()->forceFill(['google_id' => 'pag-google-1'])->save())->toThrow(QueryException::class);
});

test('closing an account lets go of its Google account at once, and anonymising clears it too', function () {
    $user = User::factory()->create();
    $user->forceFill(['google_id' => 'pag-google-close', 'google_linked_at' => now()])->save();

    $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password'])->assertSessionHasNoErrors();

    $closed = User::withTrashed()->find($user->id);
    expect($closed->trashed())->toBeTrue()
        ->and($closed->google_id)->toBeNull()
        ->and($closed->google_linked_at)->toBeNull();

    // The same Google account can be linked to another account straight away.
    User::factory()->create()->forceFill(['google_id' => 'pag-google-close'])->save();

    // Anonymising clears it too, should it somehow still be set.
    User::query()->withTrashed()->whereKey($user->id)->update(['google_id' => 'pag-google-stale', 'google_linked_at' => now()]);
    expect(AnonymiseClosedAccountAction::new()->execute(User::withTrashed()->find($user->id)))->toBeTrue();

    $anonymised = User::withTrashed()->find($user->id);
    expect($anonymised->google_id)->toBeNull()
        ->and($anonymised->google_linked_at)->toBeNull()
        ->and(User::ANONYMISED_ATTRIBUTES)->toContain('google_id', 'google_linked_at');
});

test('the Google sign-in switch is off by default, and its routes are a 404 while it is', function () {
    Route::middleware('google_sign_in')->get('/pag-google-switch', fn () => 'on');

    expect(config('auth.google.enabled'))->toBeFalse();
    $this->get('/pag-google-switch')->assertNotFound();

    config(['auth.google.enabled' => true]);
    $this->get('/pag-google-switch')->assertOk();
});

// Security review: signed in -- as Google sign-in will -- an account with no password stays signed in, with remember-me
// and on the API, without PHP's null-argument deprecations.
test('an account with no password can be signed in with remember-me without deprecations', function () {
    $this->withoutDeprecationHandling();
    $user = pagUser();

    Auth::login($user, remember: true);

    expect(Auth::id())->toBe($user->id)
        ->and($user->getAuthPassword())->toBe('');
});

test('an account with no password stays signed in across API requests', function () {
    $this->withoutDeprecationHandling();
    $user = pagUser();

    $this->actingAs($user)->getJson('/api/user')->assertOk();
    $this->getJson('/api/user')->assertOk()->assertJsonPath('id', $user->id);
});
