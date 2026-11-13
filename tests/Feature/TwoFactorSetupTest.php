<?php

use App\Actions\TwoFactor\StartTwoFactorSetupAction;
use App\Actions\TwoFactor\VerifyTwoFactorCodeAction;
use App\Actions\User\AnonymiseClosedAccountAction;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Notifications\TwoFactorChangedNotification;
use App\Support\TwoFactorCodes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;

// SCRUM-34a: setting up and managing two-factor sign-in from the profile.

beforeEach(function () {
    config(['auth.two_factor.enabled' => true]);
});

// The code an authenticator app would show $steps time-steps from now.
function tfsCode(string $secret, int $steps = 0): string
{
    $google2fa = new Google2FA;

    return $google2fa->oathTotp($secret, $google2fa->getTimestamp() + $steps);
}

// A user who has turned two-factor sign-in on; returns [user, recovery codes].
function tfsEnabledUser($test): array
{
    $user = User::factory()->create();
    $secret = $test->actingAs($user)->postJson(route('two_factor.setup'), ['current_password' => 'password'])->json('secret');
    $codes = $test->postJson(route('two_factor.confirm'), ['code' => tfsCode($secret)])->json('recoveryCodes');

    return [$user->fresh(), $codes];
}

test('setup needs the password and gives a secret and a QR, without turning it on', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(route('two_factor.setup'), ['current_password' => 'wrong'])
        ->assertUnprocessable()->assertJsonValidationErrors('current_password');

    $response = $this->postJson(route('two_factor.setup'), ['current_password' => 'password'])->assertOk();

    expect($response->json('secret'))->toBeString()->not->toBeEmpty()
        ->and($response->json('qrSvg'))->toContain('<svg');

    // Nothing is saved to the account until a code confirms it -- only this session holds it, encrypted.
    $user->refresh();
    expect($user->two_factor_secret)->toBeNull()
        ->and($user->hasTwoFactorEnabled())->toBeFalse()
        ->and(json_encode(session(StartTwoFactorSetupAction::SESSION_KEY)))->not->toContain($response->json('secret'));
});

test('a right code turns it on, gives 8 recovery codes kept only as hashes, and sends a notice', function () {
    Notification::fake();
    $user = User::factory()->create(['remember_token' => 'old-token']);
    $secret = $this->actingAs($user)->postJson(route('two_factor.setup'), ['current_password' => 'password'])->json('secret');

    $this->postJson(route('two_factor.confirm'), ['code' => '000000'])->assertUnprocessable();
    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse();

    $codes = $this->postJson(route('two_factor.confirm'), ['code' => tfsCode($secret)])->assertOk()->json('recoveryCodes');

    $user->refresh();
    expect($user->hasTwoFactorEnabled())->toBeTrue()
        ->and($codes)->toHaveCount(8)
        ->and($user->two_factor_recovery_codes)->toBe(array_map(fn ($code) => TwoFactorCodes::hash($code), $codes))
        ->and($user->two_factor_recovery_codes)->not->toContain($codes[0])
        ->and($user->remember_token)->not->toBe('old-token')
        ->and($user->two_factor_secret)->toBe($secret)
        ->and(DB::table('users')->where('id', $user->id)->value('two_factor_secret'))->not->toBe($secret)
        ->and(session(StartTwoFactorSetupAction::SESSION_KEY))->toBeNull();

    Notification::assertSentTo($user, TwoFactorChangedNotification::class, fn ($notification) => $notification->change === TwoFactorChangedNotification::ENABLED);
});

test('setup can\'t start again while it is on', function () {
    [$user] = tfsEnabledUser($this);

    $this->actingAs($user)->postJson(route('two_factor.setup'), ['current_password' => 'password'])->assertUnprocessable();
});

test('an app code works only once', function () {
    [$user] = tfsEnabledUser($this);
    $code = tfsCode($user->two_factor_secret, 1);

    expect(VerifyTwoFactorCodeAction::new()->execute($user->fresh(), $code))->toBe(VerifyTwoFactorCodeAction::TOTP)
        ->and(VerifyTwoFactorCodeAction::new()->execute($user->fresh(), $code))->toBeNull();
});

test('a recovery code works once, in any case or spacing', function () {
    [$user, $codes] = tfsEnabledUser($this);

    expect(VerifyTwoFactorCodeAction::new()->execute($user, strtoupper($codes[0])))->toBe(VerifyTwoFactorCodeAction::RECOVERY)
        ->and(VerifyTwoFactorCodeAction::new()->execute($user->fresh(), $codes[0]))->toBeNull()
        ->and(VerifyTwoFactorCodeAction::new()->execute($user->fresh(), ' '.str_replace('-', ' ', $codes[1]).' '))->toBe(VerifyTwoFactorCodeAction::RECOVERY)
        ->and(VerifyTwoFactorCodeAction::new()->execute($user->fresh(), str_replace('-', '', $codes[2])))->toBe(VerifyTwoFactorCodeAction::RECOVERY)
        ->and($user->fresh()->two_factor_recovery_codes)->toHaveCount(5)
        // Kept as keyed hashes, not a plain SHA-256 anyone could check a leaked table against.
        ->and($user->fresh()->two_factor_recovery_codes)->not->toContain(hash('sha256', TwoFactorCodes::normalise($codes[3])));
});

test('turning it off needs the password and a code; it clears everything and sends a notice', function () {
    Notification::fake();
    [$user, $codes] = tfsEnabledUser($this);

    $this->actingAs($user)->deleteJson(route('two_factor.destroy'), ['current_password' => 'password'])
        ->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->deleteJson(route('two_factor.destroy'), ['current_password' => 'wrong', 'code' => $codes[0]])
        ->assertUnprocessable()->assertJsonValidationErrors('current_password');
    $this->deleteJson(route('two_factor.destroy'), ['current_password' => 'password', 'code' => 'nope-nope'])
        ->assertUnprocessable();
    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();

    $this->deleteJson(route('two_factor.destroy'), ['current_password' => 'password', 'code' => $codes[0]])->assertNoContent();

    $user->refresh();
    expect($user->hasTwoFactorEnabled())->toBeFalse()
        ->and($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_recovery_codes)->toBeNull();
    Notification::assertSentTo($user, TwoFactorChangedNotification::class, fn ($notification) => $notification->change === TwoFactorChangedNotification::DISABLED);
});

test('new recovery codes need the password and replace the old ones', function () {
    Notification::fake();
    [$user, $old] = tfsEnabledUser($this);

    $this->actingAs($user)->postJson(route('two_factor.recovery_codes'), ['current_password' => 'wrong', 'code' => $old[0]])
        ->assertUnprocessable()->assertJsonValidationErrors('current_password');
    // The password alone isn't enough -- or it could make codes and then turn two-factor off with one.
    $this->postJson(route('two_factor.recovery_codes'), ['current_password' => 'password'])
        ->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->postJson(route('two_factor.recovery_codes'), ['current_password' => 'password', 'code' => 'nope-nope'])->assertUnprocessable();

    $new = $this->postJson(route('two_factor.recovery_codes'), ['current_password' => 'password', 'code' => $old[0]])->assertOk()->json('recoveryCodes');

    expect($new)->toHaveCount(8)
        ->and(VerifyTwoFactorCodeAction::new()->execute($user->fresh(), $old[1]))->toBeNull()
        ->and(VerifyTwoFactorCodeAction::new()->execute($user->fresh(), $new[0]))->toBe(VerifyTwoFactorCodeAction::RECOVERY);
    Notification::assertSentTo($user, TwoFactorChangedNotification::class, fn ($notification) => $notification->change === TwoFactorChangedNotification::RECOVERY_CODES_REGENERATED);
});

test('switched off, setting it up is hidden -- but those who have it can still manage it', function () {
    [$user, $codes] = tfsEnabledUser($this);
    config(['auth.two_factor.enabled' => false]);

    $this->actingAs($user)->postJson(route('two_factor.setup'), ['current_password' => 'password'])->assertNotFound();
    $this->postJson(route('two_factor.confirm'), ['code' => '123456'])->assertNotFound();
    $codes = $this->postJson(route('two_factor.recovery_codes'), ['current_password' => 'password', 'code' => $codes[0]])->assertOk()->json('recoveryCodes');
    $this->deleteJson(route('two_factor.destroy'), ['current_password' => 'password', 'code' => $codes[0]])->assertNoContent();
});

test('signed-out people can\'t reach it', function () {
    $this->postJson(route('two_factor.setup'), ['current_password' => 'password'])->assertUnauthorized();
});

test('whether two-factor is on is told only to the person themselves', function () {
    [$user] = tfsEnabledUser($this);
    $other = User::factory()->create();

    $this->actingAs($user);
    expect((new UserResource($user))->toArray(request())['twoFactorEnabled'])->toBeTrue();

    $this->actingAs($other);
    expect((new UserResource($user))->toArray(request())['twoFactorEnabled'])->toBeNull();
});

test('the notice says what changed, links to the profile and carries no secret', function () {
    $entry = TwoFactorChangedNotification::present(['change' => TwoFactorChangedNotification::DISABLED]);

    expect($entry->title)->toBe('Two-factor sign-in turned off')
        ->and($entry->url)->toBe(route('profile.show'));

    expect(TwoFactorChangedNotification::present([])->title)->toBe('Two-factor sign-in changed');
});

test('confirming needs a setup started in this session, not expired', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(route('two_factor.confirm'), ['code' => '123456'])->assertUnprocessable();

    $secret = $this->postJson(route('two_factor.setup'), ['current_password' => 'password'])->json('secret');
    $this->travel(StartTwoFactorSetupAction::MINUTES + 1)->minutes();

    $this->postJson(route('two_factor.confirm'), ['code' => tfsCode($secret)])->assertUnprocessable();
    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse();
});

// The pending secret lives only in the session that started it, never on the account.
test('only the secret this session is setting up can be confirmed', function () {
    $user = User::factory()->create();
    $mine = $this->actingAs($user)->postJson(route('two_factor.setup'), ['current_password' => 'password'])->json('secret');

    // A fresh session starts its own setup ...
    $theirs = $this->withSession([])->flushSession()->postJson(route('two_factor.setup'), ['current_password' => 'password'])->json('secret');
    expect($theirs)->not->toBe($mine);

    // ... so a code for the first secret confirms nothing, and the account still has no secret.
    $this->postJson(route('two_factor.confirm'), ['code' => tfsCode($mine)])->assertUnprocessable();
    expect($user->fresh()->two_factor_secret)->toBeNull();
});

test('a recovery code can\'t confirm a setup', function () {
    [$user, $codes] = tfsEnabledUser($this);
    $this->actingAs($user)->deleteJson(route('two_factor.destroy'), ['current_password' => 'password', 'code' => $codes[0]])->assertNoContent();

    $this->postJson(route('two_factor.setup'), ['current_password' => 'password'])->assertOk();
    $this->postJson(route('two_factor.confirm'), ['code' => $codes[1]])->assertUnprocessable();
});

test('the code that confirmed setup can\'t then turn it off', function () {
    $user = User::factory()->create();
    $secret = $this->actingAs($user)->postJson(route('two_factor.setup'), ['current_password' => 'password'])->json('secret');
    $code = tfsCode($secret);
    $this->postJson(route('two_factor.confirm'), ['code' => $code])->assertOk();

    $this->deleteJson(route('two_factor.destroy'), ['current_password' => 'password', 'code' => $code])->assertUnprocessable();
    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();
});

test('new recovery codes and turning off need it to be on', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(route('two_factor.recovery_codes'), ['current_password' => 'password', 'code' => '123456'])->assertUnprocessable();
    $this->deleteJson(route('two_factor.destroy'), ['current_password' => 'password', 'code' => '123456'])->assertUnprocessable();
});

test('recovery codes made before the app key changed still work, with the old key kept', function () {
    [$user, $codes] = tfsEnabledUser($this);
    $oldKey = config('app.key');

    config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.previous_keys' => [$oldKey]]);

    expect(VerifyTwoFactorCodeAction::new()->execute($user->fresh(), $codes[0]))->toBe(VerifyTwoFactorCodeAction::RECOVERY);
});

test('the on/off check never decrypts the secret', function () {
    [$user] = tfsEnabledUser($this);
    DB::table('users')->where('id', $user->id)->update(['two_factor_secret' => 'not-decryptable']);

    expect(User::find($user->id)->hasTwoFactorEnabled())->toBeTrue();
});

test('closing an account wipes its two-factor data', function () {
    [$user] = tfsEnabledUser($this);

    AnonymiseClosedAccountAction::new()->execute($user->fresh());

    $user->refresh();
    expect($user->getRawOriginal('two_factor_secret'))->toBeNull()
        ->and($user->two_factor_recovery_codes)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull();
});
