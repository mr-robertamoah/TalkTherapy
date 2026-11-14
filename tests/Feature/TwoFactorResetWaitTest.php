<?php

use App\Actions\TwoFactor\ApplyDueTwoFactorResetsAction;
use App\Actions\User\AnonymiseClosedAccountAction;
use App\Enums\AdministratorTypeEnum;
use App\Models\Administrator;
use App\Models\TwoFactorReset;
use App\Models\User;
use App\Notifications\TwoFactorChangedNotification;
use App\Notifications\TwoFactorResetRequestedNotification;
use App\Support\Terminal;
use App\Support\TwoFactorCodes;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use PragmaRX\Google2FA\Google2FA;

// SCRUM-424: a reset of two-factor sign-in by the team waits before it applies, and can be cancelled meanwhile.

function trwSuper(): User
{
    return User::factory()->adult()->has(Administrator::factory()->state(['type' => AdministratorTypeEnum::super->value]))->create();
}

function trwUser(array $attributes = []): array
{
    [$codes, $hashes] = TwoFactorCodes::newRecoveryCodes();
    $secret = TwoFactorCodes::newSecret();
    $user = User::factory()->create(['email_verified_at' => now(), ...$attributes]);
    $user->forceFill(['two_factor_secret' => $secret, 'two_factor_recovery_codes' => $hashes, 'two_factor_confirmed_at' => now()->subDay()])->save();

    return [$user->fresh(), $secret, $codes];
}

function trwSchedule($test, User $user): TwoFactorReset
{
    $test->actingAs(trwSuper())->postJson(route('admin.users.two_factor.reset', ['userId' => $user->id]), ['current_password' => 'password', 'reason' => 'TICKET-7'])->assertOk();

    return $user->fresh()->pendingTwoFactorReset;
}

// A visitor with no session (after the admin's request in the same test).
function trwGuest(): void
{
    auth()->guard('web')->logout();
    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');
}

// With notifications faked no email goes out, so nothing would apply (it waits for the warning): mark it sent.
function trwEmailed(): void
{
    TwoFactorReset::query()->whereNull('notified_at')->update(['notified_at' => now()]);
}

function trwPastTheWait($test): int
{
    $test->travel((int) config('auth.two_factor.reset_wait_hours') + 1)->hours();

    return ApplyDueTwoFactorResetsAction::new()->execute();
}

test('a reset from the admin list waits 48 hours, tells the person with a cancel link, and keeps the reason to itself', function () {
    Notification::fake();
    [$user] = trwUser();

    $reset = trwSchedule($this, $user);

    expect($reset)->reason->toBe('TICKET-7')->source->toBe('admin')
        ->and($reset->due_at->diffInHours(now(), true))->toBeGreaterThan(47.9)
        ->and($user->fresh()->hasTwoFactorEnabled())->toBeTrue();
    Notification::assertSentTo($user, TwoFactorResetRequestedNotification::class, function ($notification, $channels) use ($user) {
        $mail = (string) $notification->toMail($user)->render();

        return $channels === ['database', 'mail']
            && str_contains($mail, 'two-factor/resets/') && str_contains($mail, 'signature=')
            && ! str_contains($mail, 'TICKET-7')
            && ! str_contains(json_encode($notification->toArray($user)), 'signature');
    });
});

test('nothing applies early; past the wait it applies once', function () {
    [$user] = trwUser();
    trwSchedule($this, $user);

    expect(ApplyDueTwoFactorResetsAction::new()->execute())->toBe(0)
        ->and($user->fresh()->hasTwoFactorEnabled())->toBeTrue()
        ->and(trwPastTheWait($this))->toBe(1)
        ->and($user->fresh()->hasTwoFactorEnabled())->toBeFalse()
        ->and(ApplyDueTwoFactorResetsAction::new()->execute())->toBe(0)
        ->and(TwoFactorReset::query()->sole()->applied_at)->not->toBeNull();
});

test('opening the emailed link changes nothing; its button cancels; then the link says so', function () {
    Notification::fake();
    [$user] = trwUser();
    $reset = trwSchedule($this, $user);
    $url = URL::temporarySignedRoute('two_factor_reset.cancel.show', $reset->due_at, ['reset' => $reset->id]);
    trwGuest();

    $response = $this->get($url)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertInertia(fn ($page) => $page->component('Auth/CancelTwoFactorReset')->where('state', 'pending'));
    $action = $response->viewData('page')['props']['action'];
    expect($reset->fresh()->isPending())->toBeTrue();

    // The page's own button: a POST to the address it was opened at.
    $this->post($action)->assertStatus(303)->assertRedirect($action);

    expect($reset->fresh())->cancel_reason->toBe('link')->cancelled_at->not->toBeNull();
    Notification::assertSentTo($user, TwoFactorChangedNotification::class, fn ($notification) => $notification->change === TwoFactorChangedNotification::RESET_CANCELLED);
    $this->get($url)->assertInertia(fn ($page) => $page->where('state', 'cancelled'));
    $this->post($action)->assertStatus(303);
    Notification::assertSentToTimes($user, TwoFactorChangedNotification::class, 1);
    expect(trwPastTheWait($this))->toBe(0)->and($user->fresh()->hasTwoFactorEnabled())->toBeTrue();
});

test('the link is refused without its signature, for another reset, or once the reset is due', function () {
    [$user] = trwUser();
    $reset = trwSchedule($this, $user);
    trwGuest();

    $this->get(route('two_factor_reset.cancel.show', ['reset' => $reset->id]))->assertForbidden();
    $this->post(route('two_factor_reset.cancel', ['reset' => $reset->id]))->assertForbidden();
    $signed = URL::temporarySignedRoute('two_factor_reset.cancel', $reset->due_at, ['reset' => $reset->id]);
    $this->post(str_replace($reset->id, (string) str()->ulid(), $signed))->assertForbidden();

    // The page stays readable a week after it's due (it says it applied); after that the link is dead.
    $page = URL::temporarySignedRoute('two_factor_reset.cancel.show', $reset->due_at->copy()->addDays(7), ['reset' => $reset->id]);
    $this->travel(49)->hours();
    trwEmailed();
    ApplyDueTwoFactorResetsAction::new()->execute();
    $this->get($page)->assertOk()->assertInertia(fn ($inertia) => $inertia->where('state', 'applied'));
    $this->post($page)->assertStatus(303);
    expect($reset->fresh())->cancelled_at->toBeNull()->applied_at->not->toBeNull();
    $this->travel(8)->days();
    $this->get($page)->assertForbidden();
});

test('signing in with a code during the wait cancels it, and they are told', function () {
    Notification::fake();
    [$user, $secret] = trwUser();
    $reset = trwSchedule($this, $user);
    trwGuest();

    $this->post('/login', ['username' => $user->username, 'password' => 'password']);
    $this->post(route('two_factor.challenge.store'), ['code' => (new Google2FA)->getCurrentOtp($secret)]);

    $this->assertAuthenticatedAs($user);
    expect($reset->fresh()->cancel_reason)->toBe('signed_in');
    Notification::assertSentTo($user, TwoFactorChangedNotification::class, fn ($notification) => $notification->change === TwoFactorChangedNotification::RESET_CANCELLED);
});

test('making new recovery codes or turning it off themselves cancels it quietly', function () {
    Notification::fake();
    [$user, $secret, $codes] = trwUser();
    $reset = trwSchedule($this, $user);

    $this->actingAs($user, 'web')->postJson(route('two_factor.recovery_codes'), ['current_password' => 'password', 'code' => $codes[0]])->assertOk();
    expect($reset->fresh()->cancel_reason)->toBe('self_change');

    [$other, , $otherCodes] = trwUser();
    $second = trwSchedule($this, $other);
    $this->flushSession()->actingAs($other, 'web')->deleteJson(route('two_factor.destroy'), ['current_password' => 'password', 'code' => $otherCodes[0]])->assertSuccessful();
    expect($second->fresh()->cancel_reason)->toBe('self_change');
    Notification::assertNotSentTo([$user, $other], TwoFactorChangedNotification::class, fn ($notification) => $notification->change === TwoFactorChangedNotification::RESET_CANCELLED);
});

test('the person can cancel from their profile, which shows when it would apply', function () {
    [$user] = trwUser();
    $reset = trwSchedule($this, $user);

    $this->actingAs($user, 'web')->get(route('profile.show'))->assertInertia(fn ($page) => $page->where('auth.user.twoFactorResetDueAt', fn ($due) => $due !== null));
    $this->deleteJson(route('two_factor.reset.cancel'))->assertOk();
    $this->deleteJson(route('two_factor.reset.cancel'))->assertUnprocessable();

    expect($reset->fresh()->cancel_reason)->toBe('profile');
});

test('a super administrator can cancel it from the admin list; an ordinary one can\'t', function () {
    [$user] = trwUser();
    $reset = trwSchedule($this, $user);
    $url = route('admin.users.two_factor.reset.cancel', ['userId' => $user->id]);

    $this->actingAs(User::factory()->adult()->has(Administrator::factory()->state(['type' => AdministratorTypeEnum::normal->value]))->create())->deleteJson($url)->assertForbidden();
    $this->actingAs(trwSuper())->deleteJson($url)->assertOk()->assertJsonPath('user.twoFactorResetDueAt', null);

    expect($reset->fresh()->cancel_reason)->toBe('admin');
});

test('only one at a time, and never for an account without a verified email', function () {
    [$user] = trwUser();
    trwSchedule($this, $user);

    $this->actingAs(trwSuper())->postJson(route('admin.users.two_factor.reset', ['userId' => $user->id]), ['current_password' => 'password'])
        ->assertUnprocessable()->assertJsonPath('message', fn ($message) => str_contains($message, 'already scheduled'));

    [$unverified] = trwUser(['email_verified_at' => null]);
    $this->postJson(route('admin.users.two_factor.reset', ['userId' => $unverified->id]), ['current_password' => 'password'])
        ->assertUnprocessable()->assertJsonPath('message', fn ($message) => str_contains($message, 'no verified email'));
    expect($unverified->fresh()->pendingTwoFactorReset)->toBeNull();
});

test('set up again meanwhile (a new phone), the old reset no longer applies -- quietly', function () {
    Notification::fake();
    [$user] = trwUser();
    $reset = trwSchedule($this, $user);
    $user->fresh()->forceFill(['two_factor_secret' => TwoFactorCodes::newSecret(), 'two_factor_confirmed_at' => now()])->save();
    trwEmailed();

    expect(trwPastTheWait($this))->toBe(0)
        ->and($user->fresh()->hasTwoFactorEnabled())->toBeTrue()
        ->and($reset->fresh()->cancel_reason)->toBe('superseded');
    Notification::assertNotSentTo($user, TwoFactorChangedNotification::class);
});

test('the wait is never shorter than an hour: 0, negative or text means 48', function (string $value) {
    putenv("TWO_FACTOR_RESET_WAIT_HOURS={$value}");
    $config = require base_path('config/auth.php');
    putenv('TWO_FACTOR_RESET_WAIT_HOURS');

    expect($config['two_factor']['reset_wait_hours'])->toBe(48);
})->with(['0', '-5', 'soon']);

test('a valid wait is used as set', function () {
    putenv('TWO_FACTOR_RESET_WAIT_HOURS=12');
    $config = require base_path('config/auth.php');
    putenv('TWO_FACTOR_RESET_WAIT_HOURS');

    expect($config['two_factor']['reset_wait_hours'])->toBe(12);
});

test('the scheduler applies them every minute', function () {
    $this->artisan('schedule:list')->expectsOutputToContain('apply-due-two-factor-resets');
});

test('the seeded demo account has a scheduled reset waiting', function () {
    User::factory()->create(['username' => 'mr_robertamoah']);
    (new DatabaseSeeder)->createTwoFactorResetDemoData();

    $user = User::query()->where('username', 'two_factor_reset_demo')->sole();
    expect($user->hasTwoFactorEnabled())->toBeTrue()
        ->and($user->pendingTwoFactorReset)->not->toBeNull()->reason->toBe('DEMO-424');
});

// Ops/security reviews: never before the warning email went out.
test('a due reset waits while its email hasn\'t gone out, and applies once it has', function () {
    Notification::fake();
    [$user] = trwUser();
    trwSchedule($this, $user);

    expect(trwPastTheWait($this))->toBe(0)
        ->and($user->fresh()->hasTwoFactorEnabled())->toBeTrue();

    trwEmailed();
    expect(ApplyDueTwoFactorResetsAction::new()->execute())->toBe(1);
});

test('the email going out is what marks it told', function () {
    [$user] = trwUser();
    $reset = trwSchedule($this, $user);

    expect($reset->fresh())->mail_required->toBeTrue()->notified_at->not->toBeNull();
});

test('already off when due: closed quietly, nothing applied', function () {
    Notification::fake();
    [$user] = trwUser();
    $reset = trwSchedule($this, $user);
    trwEmailed();
    $user->fresh()->turnOffTwoFactor();

    expect(trwPastTheWait($this))->toBe(0)->and($reset->fresh()->cancel_reason)->toBe('already_off');
    Notification::assertNotSentTo($user, TwoFactorChangedNotification::class);
});

test('a server-scheduled reset (or one on an administrator) is cancelled on the server, not from the admin list', function () {
    Notification::fake();
    [$user] = trwUser();
    $this->artisan('user:two-factor-reset', ['user' => $user->username, '--force' => true])->assertSuccessful();

    $this->actingAs(trwSuper())->deleteJson(route('admin.users.two_factor.reset.cancel', ['userId' => $user->id]))
        ->assertUnprocessable()->assertJsonPath('message', fn ($message) => str_contains($message, 'on the server'));
    expect($user->fresh()->pendingTwoFactorReset)->not->toBeNull();
});

test('the command warns about an account without a verified email, and won\'t do it with --force', function () {
    [$user] = trwUser(['email_verified_at' => null]);

    $this->artisan('user:two-factor-reset', ['user' => $user->username, '--force' => true])
        ->expectsOutputToContain('no verified email')->assertFailed();
    expect($user->fresh()->pendingTwoFactorReset)->toBeNull();

    $this->artisan('user:two-factor-reset', ['user' => $user->username])
        ->expectsOutputToContain('no verified email')
        ->expectsConfirmation("Schedule a reset of two-factor sign-in for #{$user->id} {$user->username}? It applies in 48 hours unless they cancel it; they'll be told now.", 'yes')
        ->assertSuccessful();
    expect($user->fresh()->pendingTwoFactorReset)->mail_required->toBeFalse();
});

test('--now while one is waiting records it as applied, without a "cancelled" notice', function () {
    Notification::fake();
    [$user] = trwUser();
    $reset = trwSchedule($this, $user);

    // A person at a terminal (the test runner never is).
    app()->instance(Terminal::class, new class extends Terminal
    {
        public function attached(): bool
        {
            return true;
        }
    });
    $this->artisan('user:two-factor-reset', ['user' => $user->username, '--now' => true, '--reason' => 'TICKET-3'])
        ->expectsConfirmation("Turn off two-factor sign-in for #{$user->id} {$user->username} NOW, without the wait? They'll be told, and signed out everywhere.", 'yes')
        ->assertSuccessful();

    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse()
        ->and($reset->fresh())->applied_at->not->toBeNull()->cancelled_at->toBeNull();
    Notification::assertNotSentTo($user, TwoFactorChangedNotification::class, fn ($notification) => $notification->change === TwoFactorChangedNotification::RESET_CANCELLED);
});

test('a closed account\'s resets go with it', function () {
    [$user] = trwUser();
    trwSchedule($this, $user);

    AnonymiseClosedAccountAction::new()->execute($user->fresh());

    expect(TwoFactorReset::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

test('when it would apply is shown only to the person themselves', function () {
    [$user] = trwUser();
    trwSchedule($this, $user);

    $this->flushSession()->actingAs(User::factory()->create(), 'web')->get(route('profile.show'))
        ->assertInertia(fn ($page) => $page->where('auth.user.twoFactorResetDueAt', null));
});
