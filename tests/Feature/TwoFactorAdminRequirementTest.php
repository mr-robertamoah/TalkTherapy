<?php

use App\Actions\TwoFactor\ApplyDueTwoFactorResetsAction;
use App\Enums\AdministratorTypeEnum;
use App\Models\Administrator;
use App\Models\TwoFactorReset;
use App\Models\User;
use App\Notifications\TwoFactorChangedNotification;
use App\Notifications\TwoFactorResetRequestedNotification;
use App\Support\Terminal;
use App\Support\TwoFactorCodes;
use App\Support\TwoFactorRequirement;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;

// SCRUM-34d: two-factor sign-in required for platform administrators from a configured date (a reminder before, sent
// to set it up after -- never locked out), and `user:two-factor-reset` on the server.

function tarAdmin(bool $super = false): User
{
    return User::factory()->adult()->has(Administrator::factory()->state(['type' => $super ? AdministratorTypeEnum::super->value : AdministratorTypeEnum::normal->value]))->create();
}

function tarTurnOn(User $user): User
{
    [, $hashes] = TwoFactorCodes::newRecoveryCodes();
    $user->forceFill(['two_factor_secret' => TwoFactorCodes::newSecret(), 'two_factor_recovery_codes' => $hashes, 'two_factor_confirmed_at' => now(), 'remember_token' => 'remembered'])->save();

    return $user->fresh();
}

// Whether a person is at a terminal (the test runner never is).
function tarAtATerminal(bool $attached): void
{
    app()->instance(Terminal::class, new class($attached) extends Terminal
    {
        public function __construct(private bool $attachedHere) {}

        public function attached(): bool
        {
            return $this->attachedHere;
        }
    });
}

function tarRequiredFrom(?string $date): void
{
    config(['auth.two_factor.admins_required_from' => $date]);
}

test('nothing applies while no date is set', function () {
    tarRequiredFrom(null);
    $admin = tarAdmin();

    expect(TwoFactorRequirement::for($admin))->toBeNull();
    $this->actingAs($admin)->get(route('administrator'))->assertOk();
});

test('before the date, an administrator without it gets a reminder and nothing is blocked', function () {
    tarRequiredFrom(now()->addDays(10)->toDateString());
    $admin = tarAdmin();

    $this->actingAs($admin)->get(route('administrator'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('twoFactorRequirement.from', now()->addDays(10)->toDateString())->where('twoFactorRequirement.required', false));
    $this->getJson(route('admin.users'))->assertOk();
});

test('from the date, their pages send them to the profile, which still opens and says why', function () {
    tarRequiredFrom(now()->toDateString());
    $admin = tarAdmin();

    $this->actingAs($admin)->get(route('home'))->assertRedirect(route('profile.show'));
    $this->get(route('administrator'))->assertRedirect(route('profile.show'));
    $this->get(route('profile.show'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('twoFactorRequirement.required', true));
});

test('from the date, admin actions and their JSON are refused until it is set up', function () {
    tarRequiredFrom(now()->subDay()->toDateString());
    $admin = tarAdmin(super: true);

    $this->actingAs($admin)->getJson(route('admin.users'))->assertForbidden()->assertJsonPath('message', fn ($message) => str_contains($message, 'required for administrators'));
    $this->getJson(route('admin.badges'))->assertForbidden();
    $this->post(route('admin.settings.platform-fee.update'))->assertRedirect(route('profile.show'));
    // An Inertia PATCH from a page that was already open: the 303 Inertia needs.
    $this->patch('/therapies/1', [], ['X-Inertia' => 'true'])->assertStatus(303)->assertRedirect(route('profile.show'));
});

// Security review: admin powers on shared routes, where the controller checks isAdmin() itself -- refused by default.
test('from the date, admin powers on shared routes are refused too: content, verifying, answering requests, channels', function () {
    tarRequiredFrom(now()->subDay()->toDateString());
    $admin = tarAdmin(super: true);

    $this->actingAs($admin)->getJson('/api/sessions/1/messages')->assertForbidden();
    $this->getJson('/api/messages/1')->assertForbidden();
    $this->postJson('/counsellor/1/verify')->assertForbidden();
    $this->postJson('/api/requests/1', ['response' => 'accepted'])->assertForbidden();
    $this->postJson('/broadcasting/auth', ['channel_name' => 'private-admin.help-requests', 'socket_id' => '1.1'])->assertForbidden();
});

test('from the date, their own account still works: profile edits, notices, the profile page\'s calls', function () {
    tarRequiredFrom(now()->subDay()->toDateString());
    $admin = tarAdmin();

    $this->actingAs($admin)->patch(route('profile.update'), ['firstName' => 'Still', 'lastName' => 'Here'])->assertSessionHasNoErrors();
    expect($admin->fresh()->name)->toContain('Still');
    $this->getJson(route('notifications.count'))->assertOk();
    $this->getJson(route('api.users.guardianship'))->assertOk();
    $this->get(route('password.confirm'))->assertOk();
});

test('turning it off after the date is allowed, and sends them straight back to set it up', function () {
    tarRequiredFrom(now()->subDay()->toDateString());
    $admin = tarTurnOn(tarAdmin());

    $this->actingAs($admin)->deleteJson(route('two_factor.destroy'), ['current_password' => 'password', 'code' => (new Google2FA)->getCurrentOtp($admin->two_factor_secret)])->assertSuccessful();

    $this->get(route('home'))->assertRedirect(route('profile.show'));
});

test('guests are not affected', function () {
    tarRequiredFrom(now()->subDay()->toDateString());

    $this->get(route('login'))->assertOk();
});

test('never a lockout: setting it up, the rest of the profile and signing out still work', function () {
    tarRequiredFrom(now()->subDay()->toDateString());
    $admin = tarAdmin();

    $this->actingAs($admin)->postJson(route('two_factor.setup'), ['current_password' => 'password'])->assertOk()->assertJsonStructure(['secret', 'qrSvg']);
    $this->assertAuthenticatedAs($admin);
    $this->post(route('logout'))->assertRedirect();
    $this->assertGuest();
});

test('once it is on, everything opens again', function () {
    tarRequiredFrom(now()->subDay()->toDateString());
    $admin = tarTurnOn(tarAdmin());

    expect(TwoFactorRequirement::for($admin))->toBeNull();
    $this->actingAs($admin)->get(route('administrator'))->assertOk()->assertInertia(fn ($page) => $page->where('twoFactorRequirement', null));
    $this->getJson(route('admin.users'))->assertOk();
});

test('it is only for platform administrators', function () {
    tarRequiredFrom(now()->subDay()->toDateString());
    $user = User::factory()->create();

    expect(TwoFactorRequirement::for($user))->toBeNull();
    $this->actingAs($user)->get(route('profile.show'))->assertOk()->assertInertia(fn ($page) => $page->where('twoFactorRequirement', null));
});

test('with setup switched off, nobody is sent to a setup that is not there', function () {
    tarRequiredFrom(now()->subDay()->toDateString());
    config(['auth.two_factor.enabled' => false]);
    $admin = tarAdmin();

    expect(TwoFactorRequirement::for($admin))->toBeNull();
    $this->actingAs($admin)->get(route('administrator'))->assertOk();
});

test('only a YYYY-MM-DD date counts: anything else requires nothing, and says so in the log', function (mixed $value) {
    Log::spy();
    tarRequiredFrom($value);

    expect(TwoFactorRequirement::startsAt())->toBeNull()
        ->and(TwoFactorRequirement::for(tarAdmin()))->toBeNull();
    Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message) => str_contains($message, 'TWO_FACTOR_ADMINS_REQUIRED_FROM'));
})->with(['not a date', 'tomorrow', 'next monday', '15/01/2027', '2027-02-30', '2027-01-15 23:00', true]);

test('false means off, quietly', function () {
    Log::spy();
    tarRequiredFrom(false);

    expect(TwoFactorRequirement::startsAt())->toBeNull();
    Log::shouldNotHaveReceived('warning');
});

// SCRUM-424: the command schedules the reset (it applies after the wait), like the admin list; --now applies at once.
test('the reset command schedules a reset for an administrator, after asking, and logs it', function () {
    Notification::fake();
    Log::spy();
    $admin = tarTurnOn(tarAdmin(super: true));
    $hours = (int) config('auth.two_factor.reset_wait_hours');

    $this->artisan('user:two-factor-reset', ['user' => $admin->username, '--reason' => 'TICKET-1'])
        ->expectsConfirmation("Schedule a reset of two-factor sign-in for #{$admin->id} {$admin->username} (administrator)? It applies in {$hours} hours unless they cancel it; they'll be told now.", 'yes')
        ->assertSuccessful();

    $admin->refresh();
    expect($admin->hasTwoFactorEnabled())->toBeTrue()
        ->and($admin->pendingTwoFactorReset)->source->toBe('server')->reason->toBe('TICKET-1')->requested_by_user_id->toBeNull();
    Notification::assertSentTo($admin, TwoFactorResetRequestedNotification::class);
    Log::shouldHaveReceived('notice')->withArgs(fn ($message, $context) => $message === 'Two-factor sign-in reset requested on the server' && $context['user_id'] === $admin->id && $context['admin'] === true && filled($context['by']) && $context['forced'] === false);

    $this->travel($hours + 1)->hours();
    TwoFactorReset::query()->whereNull('notified_at')->update(['notified_at' => now()]); // the warning email went out (faked here)
    ApplyDueTwoFactorResetsAction::new()->execute();
    expect($admin->fresh())->hasTwoFactorEnabled()->toBeFalse()->remember_token->not->toBe('remembered');
    Notification::assertSentTo($admin, TwoFactorChangedNotification::class, fn ($notification) => $notification->change === TwoFactorChangedNotification::RESET_BY_ADMIN);
});

test('the reset command changes nothing when the answer is no', function () {
    $user = tarTurnOn(User::factory()->create());

    $this->artisan('user:two-factor-reset', ['user' => $user->email])
        ->expectsConfirmation("Schedule a reset of two-factor sign-in for #{$user->id} {$user->username}? It applies in ".config('auth.two_factor.reset_wait_hours')." hours unless they cancel it; they'll be told now.", 'no')
        ->assertFailed();

    expect($user->fresh()->pendingTwoFactorReset)->toBeNull();
});

test('the reset command finds by id with --force, cancels with --cancel, and fails for an unknown account or one without it', function () {
    Notification::fake();
    $user = tarTurnOn(User::factory()->create());

    $this->artisan('user:two-factor-reset', ['user' => (string) $user->id, '--id' => true, '--force' => true])
        ->expectsOutputToContain("Found #{$user->id} {$user->username}")->assertSuccessful();
    expect($user->fresh()->pendingTwoFactorReset)->not->toBeNull();

    $this->artisan('user:two-factor-reset', ['user' => (string) $user->id, '--id' => true, '--force' => true])
        ->expectsOutputToContain('already scheduled')->assertFailed();
    $this->artisan('user:two-factor-reset', ['user' => (string) $user->id, '--id' => true, '--cancel' => true])->assertSuccessful();
    expect($user->fresh()->pendingTwoFactorReset)->toBeNull();

    $plain = User::factory()->create();
    $this->artisan('user:two-factor-reset', ['user' => $plain->username, '--force' => true])
        ->expectsOutputToContain('doesn\'t have two-factor sign-in on')->assertFailed();
    $this->artisan('user:two-factor-reset', ['user' => 'nobody-by-this-name', '--force' => true])
        ->expectsOutputToContain('No account')->assertFailed();
});

test('the reset command never reads a number as an id without --id (a username may be all digits)', function () {
    Notification::fake();
    $other = tarTurnOn(User::factory()->create());
    $numeric = tarTurnOn(User::factory()->create(['username' => (string) $other->id]));

    $this->artisan('user:two-factor-reset', ['user' => (string) $other->id, '--force' => true])->assertSuccessful();

    expect($numeric->fresh()->pendingTwoFactorReset)->not->toBeNull()
        ->and($other->fresh()->pendingTwoFactorReset)->toBeNull();
});

test('--now applies at once -- typed by hand only, with a reason', function () {
    Notification::fake();
    Log::spy();
    $admin = tarTurnOn(tarAdmin(super: true));
    tarAtATerminal(true);

    $this->artisan('user:two-factor-reset', ['user' => $admin->username, '--now' => true, '--force' => true, '--reason' => 'x'])->assertFailed();
    $this->artisan('user:two-factor-reset', ['user' => $admin->username, '--now' => true])->expectsOutputToContain('needs a --reason')->assertFailed();
    $this->artisan('user:two-factor-reset', ['user' => $admin->username, '--now' => true, '--reason' => 'x', '--no-interaction' => true])->assertFailed();
    // QA: a script piping "yes" in has prompts but no terminal.
    tarAtATerminal(false);
    $this->artisan('user:two-factor-reset', ['user' => $admin->username, '--now' => true, '--reason' => 'x'])
        ->expectsOutputToContain('for a person at a terminal')->assertFailed();
    expect($admin->fresh()->hasTwoFactorEnabled())->toBeTrue();

    tarAtATerminal(true);
    $this->artisan('user:two-factor-reset', ['user' => $admin->username, '--now' => true, '--reason' => 'TICKET-9'])
        ->expectsConfirmation("Turn off two-factor sign-in for #{$admin->id} {$admin->username} (administrator) NOW, without the wait? They'll be told, and signed out everywhere.", 'yes')
        ->assertSuccessful();

    expect($admin->fresh()->hasTwoFactorEnabled())->toBeFalse();
    Log::shouldHaveReceived('notice')->withArgs(fn ($message, $context) => $message === 'Two-factor sign-in reset on the server' && $context['immediate'] === true && $context['has_reason'] === true && ! array_key_exists('reason', $context));
});
