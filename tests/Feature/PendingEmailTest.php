<?php

use App\Actions\TwoFactor\ApplyDueTwoFactorResetsAction;
use App\Actions\User\AnonymiseClosedAccountAction;
use App\Actions\User\ApplyDueEmailChangesAction;
use App\Actions\User\ConfirmPendingEmailAction;
use App\Actions\User\RequestEmailChangeAction;
use App\Enums\AdministratorTypeEnum;
use App\Exceptions\UserException;
use App\Http\Middleware\EndStaleSessions;
use App\Http\Resources\AdminUserResource;
use App\Models\Administrator;
use App\Models\TwoFactorReset;
use App\Models\User;
use App\Notifications\ConfirmNewEmailNotification;
use App\Notifications\EmailChangedNotification;
use App\Notifications\TwoFactorResetRequestedNotification;
use App\Support\TwoFactorCodes;
use Database\Seeders\RestoreGuardSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

// SCRUM-427: a new account email takes effect only once its owner confirms it -- and, when the current address is
// verified, 48 hours after that, with a "This wasn't me" link to the current address meanwhile. Until then the current
// address is the account's, password reset and sign-in included.

function pndUser(array $attributes = []): User
{
    return User::factory()->create(['email' => 'current@example.com', 'email_verified_at' => now()->subWeek(), ...$attributes]);
}

function pndSuper(): User
{
    return User::factory()->adult()->has(Administrator::factory()->state(['type' => AdministratorTypeEnum::super->value]))->create();
}

// The waiting change's own link ($email names which one the test means; it must be the one waiting).
function pndLink(string $route, User $user, string $email, int $hours = 24): string
{
    $user = $user->fresh();
    expect(mb_strtolower((string) $user->pending_email))->toBe(mb_strtolower($email));

    return URL::temporarySignedRoute($route, now()->addHours($hours), ['id' => $user->id, 'hash' => $user->pendingEmailHash()]);
}

function pndHash(User $user): string
{
    return (string) $user->fresh()->pendingEmailHash();
}

function pndPastTheWait($test): int
{
    $test->travel((int) config('auth.email_change_wait_hours') + 1)->hours();

    return ApplyDueEmailChangesAction::new()->execute();
}

test('while a change waits, password reset and sign-in use the current address -- never the new one', function () {
    Notification::fake();
    $user = pndUser();
    RequestEmailChangeAction::new()->execute($user, 'wanted@example.org');

    $this->post(route('password.email'), ['email' => 'current@example.com']);
    $this->post(route('password.email'), ['email' => 'wanted@example.org']);
    Notification::assertSentToTimes($user, ResetPassword::class, 1);
    Notification::assertSentTo($user, ResetPassword::class, fn ($notification, $channels, $notifiable) => array_key_first($notifiable->routeNotificationFor('mail')) === 'current@example.com');

    $this->post('/login', ['email' => 'wanted@example.org', 'password' => 'password']);
    $this->assertGuest();
});

test('opening the confirm link changes nothing; its button confirms -- and with a verified address it then waits', function () {
    Notification::fake();
    $user = pndUser();
    RequestEmailChangeAction::new()->execute($user, 'wanted@example.org');

    $show = pndLink('email.change.confirm.show', $user, 'wanted@example.org');
    $response = $this->get($show)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertInertia(fn ($page) => $page->component('Auth/EmailChangeLink')->where('mode', 'confirm'));
    expect($user->fresh()->pending_email_confirmed_at)->toBeNull();

    $this->post($response->viewData('page')['props']['action'])->assertRedirect(route('login'));

    expect($user->fresh())->email->toBe('current@example.com')->pending_email_confirmed_at->not->toBeNull();
    Notification::assertSentOnDemand(EmailChangedNotification::class, fn ($notification) => $notification->stage === EmailChangedNotification::CONFIRMED);

    expect(ApplyDueEmailChangesAction::new()->execute())->toBe(0)
        ->and(pndPastTheWait($this))->toBe(1)
        ->and($user->fresh())->email->toBe('wanted@example.org')->email_verified_at->not->toBeNull()->pending_email->toBeNull();
    Notification::assertSentOnDemand(EmailChangedNotification::class, fn ($notification, $channels, $notifiable) => $notification->stage === EmailChangedNotification::APPLIED && $notifiable->routes['mail'] === 'current@example.com');
});

test('with no verified address to protect, confirming applies at once', function () {
    Notification::fake();
    $user = pndUser(['email_verified_at' => null]);
    RequestEmailChangeAction::new()->execute($user, 'wanted@example.org');

    $this->post(pndLink('email.change.confirm', $user, 'wanted@example.org'))->assertSessionHas('status', 'Your new email address is confirmed.');

    expect($user->fresh())->email->toBe('wanted@example.org')->email_verified_at->not->toBeNull();
});

test('"This wasn\'t me" stops the change and signs the account out everywhere -- before it takes effect only', function () {
    Notification::fake();
    $user = pndUser(['remember_token' => 'remembered']);
    RequestEmailChangeAction::new()->execute($user, 'attacker@example.org');

    $link = null;
    Notification::assertSentOnDemand(EmailChangedNotification::class, function ($notification, $channels, $notifiable) use (&$link) {
        $link = $notification->toMail($notifiable)->actionUrl;

        return $notifiable->routes['mail'] === 'current@example.com';
    });
    $this->post(pndLink('email.change.confirm', $user, 'attacker@example.org'));

    $this->get($link)->assertOk()->assertInertia(fn ($page) => $page->where('mode', 'stop'));
    $this->post(str_replace('/email/stop/', '/email/stop/', $link))->assertRedirect(route('login'));

    expect($user->fresh())->pending_email->toBeNull()->session_version->toBe(1)->remember_token->not->toBe('remembered')->email->toBe('current@example.com')
        ->and(pndPastTheWait($this))->toBe(0);
    $this->post($link)->assertSessionHas('status', fn ($status) => str_contains($status, 'no longer valid'));
});

test('a link for an earlier request does nothing once another address was asked for', function () {
    Notification::fake();
    $user = pndUser();
    RequestEmailChangeAction::new()->execute($user, 'first@example.org');
    $old = pndLink('email.change.confirm', $user, 'first@example.org');
    RequestEmailChangeAction::new()->execute($user->fresh(), 'second@example.org');

    $this->post($old)->assertSessionHas('status', fn ($status) => str_contains($status, 'no longer valid'));

    expect($user->fresh())->pending_email->toBe('second@example.org')->pending_email_confirmed_at->toBeNull();
});

test('an address another account took meanwhile is never applied', function () {
    Notification::fake();
    $user = pndUser(['email_verified_at' => null]);
    RequestEmailChangeAction::new()->execute($user, 'wanted@example.org');
    User::factory()->create(['email' => 'wanted@example.org']);

    $this->post(pndLink('email.change.confirm', $user, 'wanted@example.org'))->assertSessionHas('status', fn ($status) => str_contains($status, 'another account'));

    expect($user->fresh())->email->toBe('current@example.com')->pending_email->toBeNull();
});

test('the links need their signature and expire', function () {
    Notification::fake();
    $user = pndUser();
    RequestEmailChangeAction::new()->execute($user, 'wanted@example.org');

    $this->post(route('email.change.confirm', ['id' => $user->id, 'hash' => pndHash($user)]))->assertForbidden();
    $link = pndLink('email.change.confirm', $user, 'wanted@example.org');
    $this->travel(25)->hours();
    $this->post($link)->assertForbidden();

    expect($user->fresh()->pending_email_confirmed_at)->toBeNull();
});

test('a change a super administrator asked for ends the person\'s other sessions when it takes effect -- remember-me too', function () {
    Notification::fake();
    $user = pndUser(['email_verified_at' => null, 'remember_token' => 'remembered']);
    $this->post('/login', ['username' => $user->username, 'password' => 'password']);
    $this->get(route('profile.show'))->assertOk();

    RequestEmailChangeAction::new()->execute($user->fresh(), 'by-team@example.org', pndSuper());
    ConfirmPendingEmailAction::new()->execute($user->id, pndHash($user));
    app('auth')->forgetGuards();

    expect($user->fresh())->session_version->toBe(1)->remember_token->not->toBe('remembered');
    $this->get(route('profile.show'))->assertRedirect(route('login'));
});

test('their own change doesn\'t raise the session version (their other sessions ended when they asked)', function () {
    Notification::fake();
    $user = pndUser(['email_verified_at' => null]);
    RequestEmailChangeAction::new()->execute($user, 'mine@example.org');
    ConfirmPendingEmailAction::new()->execute($user->id, pndHash($user));

    expect($user->fresh())->email->toBe('mine@example.org')->session_version->toBe(0);
});

test('confirming in their own signed-in browser keeps that browser signed in, with a message', function () {
    Notification::fake();
    $user = pndUser(['email_verified_at' => null]);
    $this->post('/login', ['username' => $user->username, 'password' => 'password']);
    RequestEmailChangeAction::new()->execute($user->fresh(), 'by-team@example.org', pndSuper());

    $this->post(pndLink('email.change.confirm', $user, 'by-team@example.org'))->assertRedirect(route('profile.show'))->assertSessionHas('status', 'email-change-confirmed');
    app('auth')->forgetGuards();

    $this->get(route('profile.show'))->assertOk();
});

test('existing sessions keep their fingerprint until a version is raised', function () {
    $user = pndUser();

    expect(EndStaleSessions::fingerprint($user))->toBe(hash_hmac('sha256', $user->getAuthPassword().'|', (string) config('app.key')));
});

test('the profile shows the waiting address; the link again, or the request dropped (logged)', function () {
    Notification::fake();
    Log::spy();
    $user = pndUser();
    RequestEmailChangeAction::new()->execute($user, 'wanted@example.org');

    $this->actingAs($user->fresh(), 'web')->get(route('profile.show'))->assertInertia(fn ($page) => $page->where('auth.user.pendingEmail', 'wanted@example.org')->where('auth.user.pendingEmailEffectiveAt', null));
    $this->postJson(route('profile.email.resend'))->assertOk();
    Notification::assertSentOnDemandTimes(ConfirmNewEmailNotification::class, 2);

    $this->deleteJson(route('profile.email.cancel'))->assertOk();
    $this->deleteJson(route('profile.email.cancel'))->assertUnprocessable();
    $this->postJson(route('profile.email.resend'))->assertUnprocessable();
    expect($user->fresh()->pending_email)->toBeNull();
    Log::shouldHaveReceived('notice')->withArgs(fn ($message, $context) => $message === 'Account email change cancelled' && $context['requested_by_team'] === false);
});

test('asking again for the address already waiting only resends its link -- no second warning', function () {
    Notification::fake();
    $user = pndUser();
    RequestEmailChangeAction::new()->execute($user, 'wanted@example.org');
    RequestEmailChangeAction::new()->execute($user->fresh(), 'Wanted@Example.org');

    Notification::assertSentOnDemandTimes(EmailChangedNotification::class, 1);
    Notification::assertSentOnDemandTimes(ConfirmNewEmailNotification::class, 2);
});

test('no more than 5 new addresses a day', function () {
    Notification::fake();
    $user = pndUser();

    foreach (range(1, 5) as $n) {
        RequestEmailChangeAction::new()->execute($user->fresh(), "try{$n}@example.org");
    }

    expect(fn () => RequestEmailChangeAction::new()->execute($user->fresh(), 'six@example.org'))->toThrow(UserException::class);
});

test('the confirm email names nobody (an unconfirmed address learns nothing about the account)', function () {
    $user = pndUser(['firstName' => 'Distinctive', 'lastName' => 'Personname']);
    $mail = (string) (new ConfirmNewEmailNotification($user->id, 'wanted@example.org', 'a-hash'))->toMail((new AnonymousNotifiable)->route('mail', 'wanted@example.org'))->render();

    expect($mail)->not->toContain('Distinctive')->not->toContain('Personname');
});

test('removing the email drops a waiting request too', function () {
    Notification::fake();
    $user = pndUser();
    RequestEmailChangeAction::new()->execute($user, 'wanted@example.org');

    $this->actingAs($user->fresh())->patch(route('profile.update'), ['email' => '', 'current_password' => 'password'])->assertSessionHasNoErrors();

    expect($user->fresh())->email->toBeNull()->pending_email->toBeNull();
});

test('a closed account forgets a waiting address', function () {
    expect(User::ANONYMISED_ATTRIBUTES)->toContain('pending_email', 'pending_email_requested_at', 'pending_email_requested_by', 'pending_email_confirmed_at', 'last_verified_email');
});

// Security review (re-review): removing the email, or unticking it, mustn't make a change skip the wait.
test('removing the email and then adding another still waits -- and the removed address can stop it', function () {
    Notification::fake();
    $user = pndUser();
    $this->actingAs($user)->patch(route('profile.update'), ['email' => '', 'current_password' => 'password'])->assertSessionHasNoErrors();

    RequestEmailChangeAction::new()->execute($user->fresh(), 'attacker@example.org');
    Notification::assertSentOnDemand(EmailChangedNotification::class, fn ($notification, $channels, $notifiable) => $notification->stage === EmailChangedNotification::REQUESTED && $notifiable->routes['mail'] === 'current@example.com');

    expect(ConfirmPendingEmailAction::new()->execute($user->id, pndHash($user)))->toBe(ConfirmPendingEmailAction::WAITING)
        ->and($user->fresh()->email)->toBeNull();
});

test('an address unticked by an admin is still protected: the change waits', function () {
    Notification::fake();
    $super = pndSuper();
    $user = pndUser();
    $this->actingAs($super)->postJson(route('admin.users.update', ['userId' => $user->id]), ['emailVerified' => false])->assertOk();
    $this->postJson(route('admin.users.update', ['userId' => $user->id]), ['email' => 'attacker@example.org'])->assertOk();

    expect(ConfirmPendingEmailAction::new()->execute($user->id, pndHash($user)))->toBe(ConfirmPendingEmailAction::WAITING)
        ->and($user->fresh()->email)->toBe('current@example.com');
    Notification::assertSentOnDemand(EmailChangedNotification::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'current@example.com');
});

// SCRUM-431: it was 30 days -- a super administrator could untick "verified", wait them out, and change it at once.
test('an address unticked long ago still holds a change back, and is still warned', function () {
    Notification::fake();
    $super = pndSuper();
    $user = pndUser();
    $this->actingAs($super)->postJson(route('admin.users.update', ['userId' => $user->id]), ['emailVerified' => false])->assertOk();
    $this->travel(400)->days();
    $this->actingAs($super)->postJson(route('admin.users.update', ['userId' => $user->id]), ['email' => 'late@example.org'])->assertOk();

    Notification::assertSentOnDemand(EmailChangedNotification::class, fn ($notification, $channels, $notifiable) => $notification->stage === EmailChangedNotification::REQUESTED && $notifiable->routes['mail'] === 'current@example.com');
    expect(ConfirmPendingEmailAction::new()->execute($user->id, pndHash($user)))->toBe(ConfirmPendingEmailAction::WAITING)
        ->and($user->fresh()->email)->toBe('current@example.com');
});

test('an account that never had a verified address takes a confirmed change at once', function () {
    Notification::fake();
    $user = pndUser(['email_verified_at' => null]);
    RequestEmailChangeAction::new()->execute($user, 'first@example.org');

    expect(ConfirmPendingEmailAction::new()->execute($user->id, pndHash($user)))->toBe(ConfirmPendingEmailAction::CONFIRMED);
    Notification::assertSentOnDemandTimes(EmailChangedNotification::class, 0);
});

test('once a new address is verified, it is the one protected', function () {
    $user = pndUser();
    $user->forceFill(['email' => 'second@example.com', 'email_verified_at' => now()])->save();

    expect($user->fresh())->protectedEmail()->toBe('second@example.com')->last_verified_email->toBe('current@example.com');
});

test('a remembered address another account now has gets no warning, and the change still waits', function () {
    Notification::fake();
    Log::spy();
    $user = pndUser();
    $user->forceFill(['email' => null, 'email_verified_at' => null])->save();
    User::factory()->create(['email' => 'current@example.com', 'email_verified_at' => now()]);

    RequestEmailChangeAction::new()->execute($user->fresh(), 'mine@example.org');

    expect(ConfirmPendingEmailAction::new()->execute($user->id, pndHash($user)))->toBe(ConfirmPendingEmailAction::WAITING);
    expect(pndPastTheWait($this))->toBe(1)
        ->and($user->fresh()->email)->toBe('mine@example.org');
    Notification::assertSentOnDemandTimes(EmailChangedNotification::class, 0);
    Log::shouldHaveReceived('notice')->withArgs(fn ($message) => str_contains($message, 'another account'))->atLeast()->once();
});

test('"This wasn\'t me" also stops the password working -- whoever made the change knows it', function () {
    Notification::fake();
    $user = pndUser();
    RequestEmailChangeAction::new()->execute($user, 'attacker@example.org');

    $this->post(pndLink('email.change.stop', $user, 'attacker@example.org', 96));

    expect(Hash::check('password', $user->fresh()->password))->toBeFalse();
});

test('the link can be sent again only a few times a day', function () {
    Notification::fake();
    $user = pndUser();
    RequestEmailChangeAction::new()->execute($user, 'wanted@example.org');

    foreach (range(1, 5) as $n) {
        RequestEmailChangeAction::new()->resend($user->fresh());
    }

    expect(fn () => RequestEmailChangeAction::new()->resend($user->fresh()))->toThrow(UserException::class);
});

test('the wait can\'t be set below a day', function (string $value) {
    putenv("EMAIL_CHANGE_WAIT_HOURS={$value}");
    $config = require base_path('config/auth.php');
    putenv('EMAIL_CHANGE_WAIT_HOURS');

    expect($config['email_change_wait_hours'])->toBe(48);
})->with(['1', '0', 'soon']);

test('the person\'s own change also ends "remember me" elsewhere', function () {
    Notification::fake();
    $user = pndUser(['remember_token' => 'remembered']);

    $this->actingAs($user)->patch(route('profile.update'), ['email' => 'wanted@example.org', 'current_password' => 'password'])->assertSessionHasNoErrors();

    expect($user->fresh()->remember_token)->not->toBe('remembered');
});

// Security confirmation review.
test('with no email at all, the last verified address is still protected long after', function () {
    Notification::fake();
    $user = pndUser();
    $user->forceFill(['email' => null, 'email_verified_at' => null])->save();
    $this->travel(60)->days();

    RequestEmailChangeAction::new()->execute($user->fresh(), 'patient@example.org');

    expect(ConfirmPendingEmailAction::new()->execute($user->id, pndHash($user)))->toBe(ConfirmPendingEmailAction::WAITING);
});

test('"This wasn\'t me" after the email was removed puts the owner\'s address back, so they can reset their password', function () {
    Notification::fake();
    $user = pndUser();
    $user->forceFill(['email' => null, 'email_verified_at' => null])->save();
    RequestEmailChangeAction::new()->execute($user->fresh(), 'attacker@example.org');

    $this->post(pndLink('email.change.stop', $user, 'attacker@example.org', 96));

    expect($user->fresh())->email->toBe('current@example.com')->email_verified_at->not->toBeNull()->pending_email->toBeNull();
});

test('the wait is between a day and three days', function (string $value, int $expected) {
    putenv("EMAIL_CHANGE_WAIT_HOURS={$value}");
    $config = require base_path('config/auth.php');
    putenv('EMAIL_CHANGE_WAIT_HOURS');

    expect($config['email_change_wait_hours'])->toBe($expected);
})->with([['24', 24], ['72', 72], ['200', 48]]);

test('anonymising a closed account doesn\'t write its address back into the remembered one', function () {
    $user = pndUser();

    AnonymiseClosedAccountAction::new()->execute($user->fresh());

    expect($user->fresh())->last_verified_email->toBeNull()->email->toBeNull();
});

// QA: links belong to one request, not just to an address.
test('a link from a cancelled request doesn\'t work when the same address is asked for again', function () {
    Notification::fake();
    $user = pndUser();
    RequestEmailChangeAction::new()->execute($user, 'wanted@example.org');
    $old = pndLink('email.change.confirm', $user, 'wanted@example.org');
    RequestEmailChangeAction::new()->cancel($user->fresh());
    $this->travel(1)->seconds();
    RequestEmailChangeAction::new()->execute($user->fresh(), 'wanted@example.org');

    $this->post($old)->assertSessionHas('status', fn ($status) => str_contains($status, 'no longer valid'));

    expect($user->fresh()->pending_email_confirmed_at)->toBeNull();
});

test('after the email was removed, the profile and the warning say what really happens', function () {
    Notification::fake();
    $user = pndUser();
    $user->forceFill(['email' => null, 'email_verified_at' => null])->save();
    RequestEmailChangeAction::new()->execute($user->fresh(), 'next@example.org');

    $this->actingAs($user->fresh(), 'web')->get(route('profile.show'))->assertInertia(fn ($page) => $page->where('auth.user.pendingEmailWaits', true));
    Notification::assertSentOnDemand(EmailChangedNotification::class, fn ($notification, $channels, $notifiable) => str_contains((string) $notification->toMail($notifiable)->render(), 'was removed from'));
});

// SCRUM-428: two-factor turned on while the change waited may be the intruder's phone -- or the owner's, if the old
// mailbox is the intruder. "This wasn't me" schedules the 48-hour reset; both addresses are told, with a cancel link.
test('"This wasn\'t me" schedules a two-factor reset for two-factor turned on during the wait, telling both addresses', function () {
    Notification::fake();
    $user = pndUser();
    RequestEmailChangeAction::new()->execute($user, 'newplace@example.org');
    $this->travel(1)->minutes();
    $user->fresh()->forceFill(['two_factor_secret' => TwoFactorCodes::newSecret(), 'two_factor_confirmed_at' => now()])->save();

    $this->post(pndLink('email.change.stop', $user, 'newplace@example.org', 96));

    $reset = $user->fresh()->pendingTwoFactorReset;
    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue()
        ->and($reset)->not->toBeNull()->source->toBe(TwoFactorReset::SOURCE_EMAIL_STOP);
    Notification::assertSentTo($user, TwoFactorResetRequestedNotification::class, fn ($notification) => $notification->afterEmailStop);
    Notification::assertSentOnDemand(TwoFactorResetRequestedNotification::class, function ($notification, $channels, $notifiable) {
        $mail = (string) $notification->toMail($notifiable)->render();

        return $notifiable->routes['mail'] === 'newplace@example.org' && str_contains($mail, 'Cancel this reset') && str_contains($mail, 'Hello!');
    });
});

// Security review: an intruder removes the email, registers an account with it (unverified) -- the warnings still go.
test('an unverified account holding the remembered address doesn\'t silence the warnings', function () {
    Notification::fake();
    $user = pndUser();
    $user->forceFill(['email' => null, 'email_verified_at' => null])->save();
    User::factory()->create(['email' => 'current@example.com', 'email_verified_at' => null]);

    RequestEmailChangeAction::new()->execute($user->fresh(), 'attacker@example.org');

    Notification::assertSentOnDemand(EmailChangedNotification::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'current@example.com' && $notification->stage === EmailChangedNotification::REQUESTED);
});

// SCRUM-431: an intruder can turn two-factor on first, then ask for the change.
test('two-factor turned on in the days before the change was asked for is reset too', function () {
    Notification::fake();
    $user = pndUser();
    $user->forceFill(['two_factor_secret' => TwoFactorCodes::newSecret(), 'two_factor_confirmed_at' => now()->subDays(3)])->save();
    RequestEmailChangeAction::new()->execute($user->fresh(), 'attacker@example.org');

    $this->post(pndLink('email.change.stop', $user, 'attacker@example.org', 96));

    expect($user->fresh()->pendingTwoFactorReset)->source->toBe(TwoFactorReset::SOURCE_EMAIL_STOP);
    Notification::assertSentOnDemand(TwoFactorResetRequestedNotification::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'attacker@example.org');
});

test('the look-back is read from the configuration', function () {
    Notification::fake();
    config(['auth.email_stop_two_factor_lookback_days' => 30]);
    $user = pndUser();
    $user->forceFill(['two_factor_secret' => TwoFactorCodes::newSecret(), 'two_factor_confirmed_at' => now()->subDays(20)])->save();
    RequestEmailChangeAction::new()->execute($user->fresh(), 'attacker@example.org');

    $this->post(pndLink('email.change.stop', $user, 'attacker@example.org', 96));

    expect($user->fresh()->pendingTwoFactorReset)->not->toBeNull();
});

test('the look-back stays between 7 and 90 days', function (string $value, int $expected) {
    putenv("EMAIL_STOP_TWO_FACTOR_LOOKBACK_DAYS={$value}");
    $config = require base_path('config/auth.php');
    putenv('EMAIL_STOP_TWO_FACTOR_LOOKBACK_DAYS');

    expect($config['email_stop_two_factor_lookback_days'])->toBe($expected);
})->with([['30', 30], ['3', 14], ['365', 14], ['soon', 14]]);

test('two-factor the owner had long before, or a change a super administrator asked for, schedules nothing', function () {
    Notification::fake();
    $owner = pndUser(['email' => 'owner@example.com']);
    $owner->forceFill(['two_factor_secret' => TwoFactorCodes::newSecret(), 'two_factor_confirmed_at' => now()->subDays(15)])->save();
    RequestEmailChangeAction::new()->execute($owner->fresh(), 'elsewhere@example.org');
    $this->post(pndLink('email.change.stop', $owner, 'elsewhere@example.org', 96));

    $byTeam = pndUser(['email' => 'team@example.com']);
    RequestEmailChangeAction::new()->execute($byTeam, 'other@example.org', pndSuper());
    $this->travel(1)->minutes();
    $byTeam->fresh()->forceFill(['two_factor_secret' => TwoFactorCodes::newSecret(), 'two_factor_confirmed_at' => now()])->save();
    $this->post(pndLink('email.change.stop', $byTeam, 'other@example.org', 96));

    expect($owner->fresh()->pendingTwoFactorReset)->toBeNull()
        ->and($byTeam->fresh()->pendingTwoFactorReset)->toBeNull();
    Notification::assertNotSentTo([$owner, $byTeam], TwoFactorResetRequestedNotification::class);
});

// Security review: if the old mailbox is the intruder, only the copy to the dropped address reaches the owner -- the
// reset waits for that one.
test('a reset after "This wasn\'t me" waits for the copy to the dropped address, not the account\'s own', function () {
    Notification::fake();
    $user = pndUser();
    RequestEmailChangeAction::new()->execute($user, 'newplace@example.org');
    $this->travel(1)->minutes();
    $user->fresh()->forceFill(['two_factor_secret' => TwoFactorCodes::newSecret(), 'two_factor_confirmed_at' => now()])->save();
    $this->post(pndLink('email.change.stop', $user, 'newplace@example.org', 96));
    $reset = $user->fresh()->pendingTwoFactorReset;

    // The account's own copy going out doesn't count.
    TwoFactorResetRequestedNotification::markSent(new NotificationSent($user->fresh(), TwoFactorResetRequestedNotification::for($reset), 'mail'));
    expect($reset->fresh())->mail_required->toBeTrue()->notified_at->toBeNull();
    $this->travel(49)->hours();
    expect(ApplyDueTwoFactorResetsAction::new()->execute())->toBe(0);

    // The dropped address's copy does.
    TwoFactorResetRequestedNotification::markSent(new NotificationSent((new AnonymousNotifiable)->route('mail', 'newplace@example.org'), TwoFactorResetRequestedNotification::for($reset), 'mail'));
    expect($reset->fresh()->notified_at)->not->toBeNull()
        ->and(ApplyDueTwoFactorResetsAction::new()->execute())->toBe(1);
});

// SCRUM-433: a long-remembered mailbox may belong to someone else now -- "This wasn't me" must not hand them the account.
test('removing the email remembers when -- the address and the date', function () {
    $user = pndUser();
    $user->forceFill(['email' => null, 'email_verified_at' => null])->save();

    expect($user->fresh())->last_verified_email->toBe('current@example.com')
        ->last_verified_email_at->not->toBeNull()
        ->and(User::ANONYMISED_ATTRIBUTES)->toContain('last_verified_email_at')
        ->and($user->fresh()->toArray())->not->toHaveKey('last_verified_email_at');
});

test('"This wasn\'t me" doesn\'t put back an address remembered too long ago -- whoever holds it can\'t reset the password', function () {
    Notification::fake();
    Log::spy();
    $user = pndUser();
    $user->forceFill(['email' => null, 'email_verified_at' => null])->save();
    $this->travel((int) config('auth.email_stop_restore_days') + 1)->days();
    RequestEmailChangeAction::new()->execute($user->fresh(), 'attacker@example.org');

    $this->post(pndLink('email.change.stop', $user, 'attacker@example.org', 96))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', fn (string $status) => str_contains($status, 'contact support'));

    expect($user->fresh())->email->toBeNull()->pending_email->toBeNull()
        ->and(Hash::check('password', $user->fresh()->password))->toBeFalse();
    Log::shouldHaveReceived('warning')->with('Account email change stopped by its owner', Mockery::on(fn ($context) => $context['email_restored'] === false && $context['has_email'] === false));

    $this->post(route('password.email'), ['email' => 'current@example.com']);
    Notification::assertNotSentTo($user->fresh(), ResetPassword::class);
});

test('an address remembered before the date was recorded is never put back', function () {
    Notification::fake();
    $user = pndUser();
    $user->forceFill(['email' => null, 'email_verified_at' => null])->save();
    $user->forceFill(['last_verified_email_at' => null])->save();
    RequestEmailChangeAction::new()->execute($user->fresh(), 'attacker@example.org');

    $this->post(pndLink('email.change.stop', $user, 'attacker@example.org', 96));

    expect($user->fresh())->email->toBeNull()->last_verified_email->toBe('current@example.com');
});

test('inside the restore window the address is put back, with the usual message', function () {
    Notification::fake();
    config(['auth.email_stop_restore_days' => 60]);
    $user = pndUser();
    $user->forceFill(['email' => null, 'email_verified_at' => null])->save();
    $this->travel(45)->days();
    RequestEmailChangeAction::new()->execute($user->fresh(), 'attacker@example.org');

    $this->post(pndLink('email.change.stop', $user, 'attacker@example.org', 96))
        ->assertSessionHas('status', fn (string $status) => str_contains($status, 'Reset your password now'));

    expect($user->fresh())->email->toBe('current@example.com')->email_verified_at->not->toBeNull();
});

test('the restore window stays between 7 and 90 days', function (string $value, int $expected) {
    putenv("EMAIL_STOP_RESTORE_DAYS={$value}");
    $config = require base_path('config/auth.php');
    putenv('EMAIL_STOP_RESTORE_DAYS');

    expect($config['email_stop_restore_days'])->toBe($expected);
})->with([['14', 14], ['3', 30], ['365', 30], ['soon', 30]]);

test('only super administrators see the remembered address and when it was remembered', function () {
    $user = pndUser();
    $user->forceFill(['email' => null, 'email_verified_at' => null])->save();
    $admin = User::factory()->adult()->has(Administrator::factory()->state(['type' => AdministratorTypeEnum::normal->value]))->create();

    $super = (new AdminUserResource($user->fresh()))->toArray(request()->setUserResolver(fn () => pndSuper()));
    $plain = (new AdminUserResource($user->fresh()))->toArray(request()->setUserResolver(fn () => $admin));

    expect($super)->lastVerifiedEmail->toBe('current@example.com')->lastVerifiedEmailAt->not->toBeNull()
        ->and($plain)->lastVerifiedEmail->toBeNull()->lastVerifiedEmailAt->toBeNull();
});

test('the restore-guard demo accounts seed once: one remembered recently, one with no date', function () {
    $this->seed(RestoreGuardSeeder::class);
    $this->seed(RestoreGuardSeeder::class);

    expect(User::query()->where('username', 'restore_guard_recent')->count())->toBe(1)
        ->and(User::query()->where('username', 'restore_guard_recent')->first())->email->toBeNull()->last_verified_email_at->not->toBeNull()
        ->and(User::query()->where('username', 'restore_guard_old')->first())->last_verified_email->toBe('restore.guard.old@example.com')->last_verified_email_at->toBeNull();
});

// SCRUM-434: a long-remembered mailbox may be someone else's -- its warning carries nothing to act on.
test('a warning to an address remembered too long ago has no stop link, no new address and no name', function () {
    Notification::fake();
    $user = pndUser(['firstName' => 'Pndstale']);
    $user->forceFill(['email' => null, 'email_verified_at' => null])->save();
    $this->travel((int) config('auth.email_stop_restore_days') + 1)->days();

    RequestEmailChangeAction::new()->execute($user->fresh(), 'patient@example.org');

    Notification::assertSentOnDemand(EmailChangedNotification::class, function ($notification, $channels, $notifiable) {
        $mail = (string) $notification->toMail($notifiable)->render();

        return $notifiable->routes['mail'] === 'current@example.com'
            && ! str_contains($mail, 'email-change/stop') && ! str_contains($mail, 'This wasn')
            && ! str_contains($mail, 'example.org') && ! str_contains($mail, 'Pndstale')
            && str_contains($mail, 'contact TalkTherapy support');
    });
    // The change still waits for its owner to confirm it.
    expect(ConfirmPendingEmailAction::new()->execute($user->id, pndHash($user)))->toBe(ConfirmPendingEmailAction::WAITING);
});

test('a remembered address with no date gets the same limited warning when asked and when applied -- not when confirmed', function () {
    Notification::fake();
    $user = pndUser();
    $user->forceFill(['email' => null, 'email_verified_at' => null])->save();
    $user->forceFill(['last_verified_email_at' => null])->save();

    RequestEmailChangeAction::new()->execute($user->fresh(), 'patient@example.org');
    ConfirmPendingEmailAction::new()->execute($user->id, pndHash($user));
    pndPastTheWait($this);

    Notification::assertSentOnDemandTimes(EmailChangedNotification::class, 2);
    foreach ([EmailChangedNotification::REQUESTED, EmailChangedNotification::APPLIED] as $stage) {
        Notification::assertSentOnDemand(EmailChangedNotification::class, function ($notification, $channels, $notifiable) use ($stage) {
            $mail = (string) $notification->toMail($notifiable)->render();

            return $notification->stage === $stage && $notification->stale && $notification->name === ''
                && $notification->pendingHash === null && $notification->maskedNewEmail === null
                && ! str_contains($mail, 'This wasn') && ! str_contains($mail, 'example.org') && str_contains($mail, 'contact TalkTherapy support');
        });
    }
});

test('inside the restore window the remembered address still gets the full warning with its stop link', function () {
    Notification::fake();
    $user = pndUser();
    $user->forceFill(['email' => null, 'email_verified_at' => null])->save();

    RequestEmailChangeAction::new()->execute($user->fresh(), 'patient@example.org');

    Notification::assertSentOnDemand(EmailChangedNotification::class, fn ($notification, $channels, $notifiable) => ! $notification->stale
        && str_contains((string) $notification->toMail($notifiable)->render(), 'This wasn'));
});

test('a verified current address is never treated as stale', function () {
    $user = pndUser();
    $user->forceFill(['last_verified_email' => 'old@example.com', 'last_verified_email_at' => null])->save();

    expect($user->fresh()->protectedEmailIsStale())->toBeFalse();
});

test('an address unticked long ago is still the account\'s own: its warnings stay full, with the stop link', function () {
    Notification::fake();
    $user = pndUser();
    $user->forceFill(['email_verified_at' => null])->save();
    $this->travel((int) config('auth.email_stop_restore_days') + 1)->days();

    expect($user->fresh()->protectedEmailIsStale())->toBeFalse();
    RequestEmailChangeAction::new()->execute($user->fresh(), 'patient@example.org');

    Notification::assertSentOnDemand(EmailChangedNotification::class, fn ($notification, $channels, $notifiable) => ! $notification->stale
        && $notifiable->routes['mail'] === 'current@example.com' && str_contains((string) $notification->toMail($notifiable)->render(), 'This wasn'));
});

test('an unverified different address with a long-remembered one: the remembered one is stale', function () {
    $user = pndUser();
    $user->forceFill(['email' => 'second@example.com', 'email_verified_at' => null])->save();
    $this->travel((int) config('auth.email_stop_restore_days') + 1)->days();

    expect($user->fresh())->protectedEmail()->toBe('current@example.com')
        ->and($user->fresh()->protectedEmailIsStale())->toBeTrue();
});
