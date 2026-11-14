<?php

use App\Actions\TwoFactor\VerifyTwoFactorCodeAction;
use App\Enums\AdministratorTypeEnum;
use App\Exceptions\TwoFactorException;
use App\Http\Middleware\EndStaleSessions;
use App\Models\Administrator;
use App\Models\User;
use App\Notifications\TwoFactorChangedNotification;
use App\Support\TwoFactorCodes;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;

// SCRUM-34c: a super administrator resets someone's two-factor sign-in (lost phone and recovery codes).

function atrAdmin(bool $super = true): User
{
    return User::factory()->adult()->has(Administrator::factory()->state(['type' => $super ? AdministratorTypeEnum::super->value : AdministratorTypeEnum::normal->value]))->create();
}

function atrWithTwoFactor(): User
{
    [, $hashes] = TwoFactorCodes::newRecoveryCodes();
    $user = User::factory()->create(['remember_token' => 'remembered']);
    $user->forceFill(['two_factor_secret' => TwoFactorCodes::newSecret(), 'two_factor_recovery_codes' => $hashes, 'two_factor_confirmed_at' => now()])->save();

    return $user->fresh();
}

test('a super administrator resets it with their password: off, told, logged, remember-me and other sessions ended', function () {
    Notification::fake();
    Log::spy();
    $admin = atrAdmin();
    $user = atrWithTwoFactor();
    $fingerprint = EndStaleSessions::fingerprint($user);

    $this->actingAs($admin)->postJson(route('admin.users.two_factor.reset', ['userId' => $user->id]), ['current_password' => 'password'])
        ->assertOk()->assertJsonPath('user.twoFactorEnabled', false);

    $user->refresh();
    expect($user->hasTwoFactorEnabled())->toBeFalse()
        ->and($user->getRawOriginal('two_factor_secret'))->toBeNull()
        ->and($user->two_factor_recovery_codes)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull()
        ->and($user->two_factor_last_used_step)->toBeNull()
        ->and($user->remember_token)->not->toBe('remembered')
        ->and(EndStaleSessions::fingerprint($user))->not->toBe($fingerprint);
    Notification::assertSentTo($user, TwoFactorChangedNotification::class, fn ($notification) => $notification->change === TwoFactorChangedNotification::RESET_BY_ADMIN);
    Log::shouldHaveReceived('notice')->withArgs(fn ($message, $context) => $context['admin_id'] === $admin->id && $context['user_id'] === $user->id && array_key_exists('ip', $context));
});

test('it needs the administrator\'s own password', function () {
    $user = atrWithTwoFactor();

    $this->actingAs(atrAdmin())->postJson(route('admin.users.two_factor.reset', ['userId' => $user->id]), ['current_password' => 'wrong'])
        ->assertUnprocessable()->assertJsonValidationErrors('current_password');

    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();
});

test('an ordinary administrator, or anyone else, can\'t', function () {
    $user = atrWithTwoFactor();

    $this->actingAs(atrAdmin(super: false))->postJson(route('admin.users.two_factor.reset', ['userId' => $user->id]), ['current_password' => 'password'])->assertForbidden();
    $this->actingAs(User::factory()->create())->postJson(route('admin.users.two_factor.reset', ['userId' => $user->id]), ['current_password' => 'password'])->assertForbidden();

    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();
});

test('not their own, and not an account without it', function () {
    $admin = atrAdmin();
    $admin->forceFill(['two_factor_secret' => TwoFactorCodes::newSecret(), 'two_factor_confirmed_at' => now()])->save();

    $this->actingAs($admin)->postJson(route('admin.users.two_factor.reset', ['userId' => $admin->id]), ['current_password' => 'password', 'code' => (new Google2FA)->getCurrentOtp($admin->two_factor_secret)])
        ->assertUnprocessable()->assertJsonPath('message', 'You can turn off your own two-factor sign-in from your profile.');
    expect($admin->fresh()->hasTwoFactorEnabled())->toBeTrue();

    $this->actingAs(atrAdmin())->postJson(route('admin.users.two_factor.reset', ['userId' => User::factory()->create()->id]), ['current_password' => 'password'])
        ->assertUnprocessable()->assertJsonPath('message', 'This account doesn\'t have two-factor sign-in on.');
    $this->postJson(route('admin.users.two_factor.reset', ['userId' => 999999]), ['current_password' => 'password'])->assertNotFound();
});

test('the admin list tells only a super administrator whether two-factor is on', function () {
    $user = atrWithTwoFactor();

    $super = collect($this->actingAs(atrAdmin())->getJson(route('admin.users'))->assertOk()->json('data'))->firstWhere('id', $user->id);
    $normal = collect($this->actingAs(atrAdmin(super: false))->getJson(route('admin.users'))->assertOk()->json('data'))->firstWhere('id', $user->id);

    expect($super['twoFactorEnabled'])->toBeTrue()
        ->and($normal['twoFactorEnabled'])->toBeNull();
});

test('the seeded demo account signs in with its documented key, and with a documented recovery code', function () {
    (new DatabaseSeeder)->createTwoFactorDemoData();

    $this->post('/login', ['username' => 'two_factor_demo', 'password' => 'password'])->assertRedirect(route('two_factor.challenge'));
    $this->post(route('two_factor.challenge.store'), ['code' => (new Google2FA)->getCurrentOtp(DatabaseSeeder::TWO_FACTOR_DEMO_SECRET)]);
    $this->assertAuthenticated();

    Auth::logout();
    $this->post('/login', ['username' => 'two_factor_demo', 'password' => 'password']);
    $this->post(route('two_factor.challenge.store'), ['code' => DatabaseSeeder::TWO_FACTOR_DEMO_RECOVERY_CODES[0]]);
    $this->assertAuthenticated();
});

test('a reset clears the wrong-code count', function () {
    $user = atrWithTwoFactor();
    foreach (range(1, 5) as $ignored) {
        VerifyTwoFactorCodeAction::new()->execute($user, '000000');
    }
    expect(fn () => VerifyTwoFactorCodeAction::new()->execute($user, '000000'))->toThrow(TwoFactorException::class);

    $this->actingAs(atrAdmin())->postJson(route('admin.users.two_factor.reset', ['userId' => $user->id]), ['current_password' => 'password'])->assertOk();

    // Set up again: codes are checked, not refused.
    $user->forceFill(['two_factor_secret' => TwoFactorCodes::newSecret(), 'two_factor_confirmed_at' => now()])->save();
    expect(VerifyTwoFactorCodeAction::new()->execute($user->fresh(), '000000'))->toBeNull();
});

test('an ordinary administrator is refused before their password is even checked', function () {
    $user = atrWithTwoFactor();

    $this->actingAs(atrAdmin(super: false))->postJson(route('admin.users.two_factor.reset', ['userId' => $user->id]), ['current_password' => 'wrong'])->assertForbidden();
});

test('never another administrator\'s -- even another super administrator\'s', function () {
    $other = atrAdmin(super: false);
    $other->forceFill(['two_factor_secret' => TwoFactorCodes::newSecret(), 'two_factor_confirmed_at' => now()])->save();

    $this->actingAs(atrAdmin())->postJson(route('admin.users.two_factor.reset', ['userId' => $other->id]), ['current_password' => 'password'])
        ->assertUnprocessable()->assertJsonPath('message', 'An administrator\'s two-factor sign-in can only be reset on the server.');

    expect($other->fresh()->hasTwoFactorEnabled())->toBeTrue();
});

test('a super administrator with two-factor on gives their own code too', function () {
    $secret = TwoFactorCodes::newSecret();
    $admin = atrAdmin();
    $admin->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();
    $user = atrWithTwoFactor();
    $url = route('admin.users.two_factor.reset', ['userId' => $user->id]);

    $this->actingAs($admin->fresh())->postJson($url, ['current_password' => 'password'])->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->postJson($url, ['current_password' => 'password', 'code' => '000000'])->assertUnprocessable();
    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();

    $this->postJson($url, ['current_password' => 'password', 'code' => (new Google2FA)->getCurrentOtp($secret)])->assertOk();
    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse();
});
