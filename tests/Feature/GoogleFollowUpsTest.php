<?php

use App\Actions\Auth\ConnectGoogleAccountAction;
use App\Actions\Auth\ResolveGoogleAccountAction;
use App\Actions\TwoFactor\ApplyDueTwoFactorResetsAction;
use App\Actions\TwoFactor\ResetTwoFactorByAdminAction;
use App\DTOs\GoogleAccount;
use App\DTOs\UpdateUserDTO;
use App\Enums\AdministratorTypeEnum;
use App\Enums\GoogleAccountOutcomeEnum;
use App\Exceptions\TwoFactorException;
use App\Http\Controllers\Auth\GoogleSignUpController;
use App\Models\Administrator;
use App\Models\BlockedGoogleIdentity;
use App\Models\TwoFactorReset;
use App\Models\User;
use App\Services\UserService;
use App\Support\ClientAddress;
use App\Support\Terminal;
use App\Support\TwoFactorCodes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;

// SCRUM-456 (TT-12.13): Google sign-in follow-ups -- ASCII usernames, a Google account blocked when an administrator
// closes its account, the free-email indexes, and the two-factor reset of an account Google is the only way into.
// Helpers are file-local and gfu-prefixed (--parallel).

beforeEach(function () {
    Notification::fake();
    config(['auth.google.enabled' => true, 'services.google.client_id' => 'test-client-id', 'services.google.client_secret' => 'test-secret', 'services.google.redirect' => 'https://talktherapy.test/auth/google/callback']);
    RateLimiter::clear('registrations:'.ClientAddress::key('127.0.0.1'));
});

function gfuSuper(): User
{
    return User::factory()->adult()->has(Administrator::factory()->state(['type' => AdministratorTypeEnum::super->value]))->create();
}

function gfuLinked(string $sub, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->forceFill(['google_id' => $sub, 'google_linked_at' => now()])->save();

    return $user->fresh();
}

function gfuCloseByAdmin(User $admin, User $user): void
{
    UserService::new()->deleteUserByAdmin(UpdateUserDTO::new()->fromArray(['user' => $admin, 'updatedUser' => $user]));
}

function gfuGoogle(string $sub): GoogleAccount
{
    return new GoogleAccount($sub, 'someone@gmail.com', true);
}

// No password, no email: Google is the only way in. Two-factor on.
function gfuGoogleOnlyWithTwoFactor(array $attributes = []): User
{
    [, $hashes] = TwoFactorCodes::newRecoveryCodes();
    $user = gfuLinked('sub-only', ['password' => null, 'email' => null, 'email_verified_at' => null, ...$attributes]);
    $user->forceFill(['two_factor_secret' => TwoFactorCodes::newSecret(), 'two_factor_recovery_codes' => $hashes, 'two_factor_confirmed_at' => now()])->save();

    return $user->fresh();
}

// --- Usernames ---------------------------------------------------------------------------------

test('registration refuses a username with non-ASCII letters, look-alikes included', function (string $username) {
    $this->post(route('register'), ['username' => $username, 'password' => 'a-Long-password-1', 'password_confirmation' => 'a-Long-password-1'])
        ->assertSessionHasErrors('username');

    $this->assertGuest();
})->with([
    'Cyrillic a' => ["\u{0430}dmin"],
    'accented' => ["jos\u{00E9}"],
]);

test('registration still takes an ASCII username with dashes and underscores', function () {
    $this->post(route('register'), ['username' => 'gfu-new_1', 'password' => 'a-Long-password-1', 'password_confirmation' => 'a-Long-password-1'])
        ->assertRedirect('/preferences');
});

test('Google\'s finish step refuses a non-ASCII username too', function () {
    $this->withSession(['google.sign_up' => ['sub' => 'sub-gfu-new', 'email' => null, 'expires_at' => now()->addMinutes(5)->getTimestamp()]])
        ->post(route('auth.google.finish.store'), ['username' => "p\u{0430}ypal"])
        ->assertSessionHasErrors('username');

    expect(User::query()->where('google_id', 'sub-gfu-new')->exists())->toBeFalse();
});

test('an existing non-ASCII username is left as it is and still signs in', function () {
    $user = User::factory()->create(['username' => "jos\u{00E9}"]);

    $this->post(route('login'), ['username' => "jos\u{00E9}", 'password' => 'password']);

    $this->assertAuthenticatedAs($user);
});

// --- A Google account blocked when an administrator closes its account --------------------------

test('an administrator closing an account blocks its Google account by a keyed hash, never the raw id', function () {
    $user = gfuLinked('sub-closed-by-admin');

    gfuCloseByAdmin(gfuSuper(), $user);

    $row = DB::table('blocked_google_identities')->sole();
    expect($row->sub_hash)->toBe(hash_hmac('sha256', 'blocked-google:sub-closed-by-admin', config('app.key')))
        ->and($row->sub_hash)->not->toContain('sub-closed-by-admin')
        ->and($row->reason)->toBe(BlockedGoogleIdentity::REASON_CLOSED_BY_ADMIN)
        ->and($row->blocked_at)->not->toBeNull()
        ->and(User::query()->withTrashed()->find($user->id)->google_id)->toBeNull();
});

test('a blocked Google account can\'t start a new account, with the usual neutral words', function () {
    gfuCloseByAdmin(gfuSuper(), gfuLinked('sub-blocked'));

    $resolution = ResolveGoogleAccountAction::new()->execute(gfuGoogle('sub-blocked'));

    expect($resolution->outcome)->toBe(GoogleAccountOutcomeEnum::Refused)
        ->and($resolution->refusal())->toBe(ResolveGoogleAccountAction::NOT_CONNECTED)
        ->and(ResolveGoogleAccountAction::new()->execute(gfuGoogle('sub-other'))->outcome)->toBe(GoogleAccountOutcomeEnum::StartSignUp);
});

test('a blocked Google account can\'t be connected to another account, with the usual "taken" words', function () {
    gfuCloseByAdmin(gfuSuper(), gfuLinked('sub-blocked'));
    $someone = User::factory()->create();

    expect(ConnectGoogleAccountAction::new()->execute($someone, gfuGoogle('sub-blocked')))->toBe(ConnectGoogleAccountAction::TAKEN)
        ->and($someone->fresh()->google_id)->toBeNull();
});

test('a sign-up already waiting is refused at the finish step once its Google account is blocked', function () {
    BlockedGoogleIdentity::block('sub-gfu-waiting', BlockedGoogleIdentity::REASON_CLOSED_BY_ADMIN);

    $this->withSession(['google.sign_up' => ['sub' => 'sub-gfu-waiting', 'email' => null, 'expires_at' => now()->addMinutes(5)->getTimestamp()]])
        ->post(route('auth.google.finish.store'), ['username' => 'gfu_waiting'])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['google' => GoogleSignUpController::EXPIRED]);

    expect(User::query()->where('username', 'gfu_waiting')->exists())->toBeFalse();
});

test('closing your own account doesn\'t block its Google account', function () {
    $user = gfuLinked('sub-self-closed');

    $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password'])->assertSessionHasNoErrors();

    expect(User::query()->withTrashed()->find($user->id)->trashed())->toBeTrue()
        ->and(DB::table('blocked_google_identities')->count())->toBe(0)
        ->and(ResolveGoogleAccountAction::new()->execute(gfuGoogle('sub-self-closed'))->outcome)->toBe(GoogleAccountOutcomeEnum::StartSignUp);
});

test('a super administrator closing their own account from the admin list isn\'t blocked either', function () {
    $admin = gfuSuper();
    $admin->forceFill(['google_id' => 'sub-own', 'google_linked_at' => now()])->save();

    gfuCloseByAdmin($admin->fresh(), $admin->fresh());

    expect(DB::table('blocked_google_identities')->count())->toBe(0);
});

test('closing an account without Google blocks nothing, and blocking the same Google account twice keeps one row', function () {
    gfuCloseByAdmin(gfuSuper(), User::factory()->create());
    expect(DB::table('blocked_google_identities')->count())->toBe(0);

    BlockedGoogleIdentity::block('sub-twice');
    BlockedGoogleIdentity::block('sub-twice');
    expect(DB::table('blocked_google_identities')->count())->toBe(1)
        ->and(BlockedGoogleIdentity::isBlocked('sub-twice'))->toBeTrue()
        ->and(BlockedGoogleIdentity::isBlocked('sub-never'))->toBeFalse();
});

test('a block survives an APP_KEY rotation, and is written again under the new key', function () {
    BlockedGoogleIdentity::block('sub-rotated');
    $oldKey = config('app.key');

    config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.previous_keys' => [$oldKey]]);

    expect(BlockedGoogleIdentity::isBlocked('sub-rotated'))->toBeTrue()
        ->and(BlockedGoogleIdentity::query()->where('sub_hash', BlockedGoogleIdentity::hashFor('sub-rotated'))->exists())->toBeTrue()
        ->and(ResolveGoogleAccountAction::new()->execute(gfuGoogle('sub-rotated'))->outcome)->toBe(GoogleAccountOutcomeEnum::Refused);

    // The old key retired as well: still blocked, by the hash written under the current one.
    config(['app.previous_keys' => []]);
    expect(BlockedGoogleIdentity::isBlocked('sub-rotated'))->toBeTrue();

    // Lifted by hand: every hash goes.
    config(['app.previous_keys' => [$oldKey]]);
    expect(BlockedGoogleIdentity::lift('sub-rotated'))->toBe(2)
        ->and(BlockedGoogleIdentity::isBlocked('sub-rotated'))->toBeFalse();
});

// --- Indexes -----------------------------------------------------------------------------------

test('pending_email and last_verified_email are indexed', function () {
    $indexed = collect(Schema::getIndexes('users'))->pluck('columns')->map(fn ($columns) => implode(',', $columns));

    expect($indexed)->toContain('pending_email')->toContain('last_verified_email');
});

// --- Two-factor reset of an account Google is the only way into -------------------------------

test('the admin list says what a reset means for an account Google is the only way into, and won\'t schedule one', function () {
    $admin = gfuSuper();
    $user = gfuGoogleOnlyWithTwoFactor();

    $this->actingAs($admin)->getJson(route('admin.users', ['search' => $user->username]))
        ->assertOk()->assertJsonFragment(['googleOnlyNotice' => ResetTwoFactorByAdminAction::GOOGLE_ONLY.' '.ResetTwoFactorByAdminAction::GOOGLE_ONLY_ON_SERVER]);

    $this->actingAs($admin)->postJson(route('admin.users.two_factor.reset', ['userId' => $user->id]), ['current_password' => 'password'])
        ->assertUnprocessable();
    expect($user->fresh()->pendingTwoFactorReset)->toBeNull();
});

test('the admin action refuses it even when the account looks verified (an email-less "verified" row)', function () {
    $user = gfuGoogleOnlyWithTwoFactor(['email_verified_at' => now()]);

    expect($user->hasVerifiedEmail())->toBeTrue()->and($user->signsInOnlyWithGoogle())->toBeTrue();
    expect(fn () => ResetTwoFactorByAdminAction::new()->execute(gfuSuper(), $user, null))
        ->toThrow(TwoFactorException::class, ResetTwoFactorByAdminAction::GOOGLE_ONLY);
});

test('an account with a password, or without Google, isn\'t "Google only"', function () {
    $user = gfuLinked('sub-with-password', ['email_verified_at' => now()]);

    expect($user->signsInOnlyWithGoogle())->toBeFalse()
        ->and(User::factory()->create()->signsInOnlyWithGoogle())->toBeFalse();
});

test('the server command says so and asks on its own before scheduling it', function () {
    $user = gfuGoogleOnlyWithTwoFactor();

    $this->artisan('user:two-factor-reset', ['user' => $user->username])
        ->expectsOutputToContain(ResetTwoFactorByAdminAction::GOOGLE_ONLY)
        ->expectsConfirmation('Reset it anyway, leaving the Google account as the way in?', 'no')
        ->expectsOutputToContain('Nothing changed.')
        ->assertFailed();
    expect($user->fresh()->pendingTwoFactorReset)->toBeNull();

    $this->artisan('user:two-factor-reset', ['user' => $user->username])
        ->expectsConfirmation('Reset it anyway, leaving the Google account as the way in?', 'yes')
        ->expectsConfirmation("Schedule a reset of two-factor sign-in for #{$user->id} {$user->username}? It applies in 48 hours unless they cancel it; they'll be told now.", 'yes')
        ->assertSuccessful();
    expect($user->fresh()->pendingTwoFactorReset)->not->toBeNull();
});

test('--google-only-ok answers that question; --force alone doesn\'t', function () {
    // Email-less but "verified", so --force isn't already refused for a missing verified email.
    $user = gfuGoogleOnlyWithTwoFactor(['email_verified_at' => now()]);

    $this->artisan('user:two-factor-reset', ['user' => $user->username, '--force' => true])
        ->expectsOutputToContain(ResetTwoFactorByAdminAction::GOOGLE_ONLY)
        ->expectsOutputToContain('--google-only-ok')
        ->assertFailed();
    expect($user->fresh()->pendingTwoFactorReset)->toBeNull();

    $this->artisan('user:two-factor-reset', ['user' => $user->username, '--force' => true, '--google-only-ok' => true])
        ->assertSuccessful();
    expect($user->fresh()->pendingTwoFactorReset)->not->toBeNull();
});

test('applied at once (--now), the confirmation is asked too, and Google stays connected', function () {
    $user = gfuGoogleOnlyWithTwoFactor();
    app()->instance(Terminal::class, new class extends Terminal
    {
        public function attached(): bool
        {
            return true;
        }
    });

    $this->artisan('user:two-factor-reset', ['user' => $user->username, '--now' => true, '--reason' => 'TICKET-9'])
        ->expectsOutputToContain(ResetTwoFactorByAdminAction::GOOGLE_ONLY)
        ->expectsConfirmation('Reset it anyway, leaving the Google account as the way in?', 'yes')
        ->expectsConfirmation("Turn off two-factor sign-in for #{$user->id} {$user->username} NOW, without the wait? They'll be told, and signed out everywhere.", 'yes')
        ->assertSuccessful();

    expect($user->fresh())->hasTwoFactorEnabled()->toBeFalse()->google_id->toBe('sub-only');
});

test('an ordinary account isn\'t asked the Google question', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    [, $hashes] = TwoFactorCodes::newRecoveryCodes();
    $user->forceFill(['two_factor_secret' => TwoFactorCodes::newSecret(), 'two_factor_recovery_codes' => $hashes, 'two_factor_confirmed_at' => now()])->save();

    $this->artisan('user:two-factor-reset', ['user' => $user->username, '--force' => true])
        ->doesntExpectOutputToContain('only with Google')
        ->assertSuccessful();
    expect(TwoFactorReset::query()->where('user_id', $user->id)->exists())->toBeTrue();
});

test('a scheduled reset that would leave Google as the only way in is cancelled when due, unless that was agreed', function () {
    Log::spy();
    // Verified email when asked: no Google question, nothing recorded.
    $user = gfuGoogleOnlyWithTwoFactor(['email' => 'gfu.only@example.com', 'email_verified_at' => now()]);
    expect($user->signsInOnlyWithGoogle())->toBeFalse();
    $this->artisan('user:two-factor-reset', ['user' => $user->username, '--force' => true])->assertSuccessful();
    $reset = $user->fresh()->pendingTwoFactorReset;
    expect($reset->google_only_confirmed_at)->toBeNull();

    // During the wait the email stops being verified: applying it now would leave Google as the only way in.
    User::query()->whereKey($user->id)->update(['email_verified_at' => null]);
    $this->travel((int) config('auth.two_factor.reset_wait_hours') + 1)->hours();
    $reset->forceFill(['notified_at' => now()])->save();

    expect(ApplyDueTwoFactorResetsAction::new()->execute())->toBe(0);
    expect($reset->fresh())->cancel_reason->toBe(TwoFactorReset::WOULD_LEAVE_GOOGLE_ONLY)->cancelled_at->not->toBeNull()
        ->and($user->fresh()->hasTwoFactorEnabled())->toBeTrue();
    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => str_contains($message, 'only way in') && $context['reason'] === TwoFactorReset::WOULD_LEAVE_GOOGLE_ONLY);
});

test('one agreed to on the server for a Google-only account applies, leaving Google connected', function () {
    $user = gfuGoogleOnlyWithTwoFactor();

    $this->artisan('user:two-factor-reset', ['user' => $user->username])
        ->expectsConfirmation('Reset it anyway, leaving the Google account as the way in?', 'yes')
        ->expectsConfirmation("Schedule a reset of two-factor sign-in for #{$user->id} {$user->username}? It applies in 48 hours unless they cancel it; they'll be told now.", 'yes')
        ->assertSuccessful();
    $reset = $user->fresh()->pendingTwoFactorReset;
    expect($reset->google_only_confirmed_at)->not->toBeNull();

    $this->travel((int) config('auth.two_factor.reset_wait_hours') + 1)->hours();

    expect(ApplyDueTwoFactorResetsAction::new()->execute())->toBe(1)
        ->and($user->fresh())->hasTwoFactorEnabled()->toBeFalse()->google_id->toBe('sub-only');
});
