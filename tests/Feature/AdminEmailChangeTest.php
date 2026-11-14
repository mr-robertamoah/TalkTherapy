<?php

use App\Enums\AdministratorTypeEnum;
use App\Models\Administrator;
use App\Models\User;
use App\Notifications\AccountEmailChangedByTeamNotification;
use App\Notifications\EmailChangedNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

// SCRUM-425: changing someone's email from the admin users list -- only a super administrator, never an
// administrator's, a real and unused address, never marked verified by the admin, and the old address is told.

function aecAdmin(bool $super): User
{
    return User::factory()->adult()->has(Administrator::factory()->state(['type' => $super ? AdministratorTypeEnum::super->value : AdministratorTypeEnum::normal->value]))->create();
}

function aecPerson(array $attributes = []): User
{
    return User::factory()->create(['email' => 'person@example.com', 'email_verified_at' => now()->subWeek(), ...$attributes]);
}

function aecUpdate($test, User $admin, User $user, array $data)
{
    return $test->actingAs($admin)->postJson(route('admin.users.update', ['userId' => $user->id]), $data);
}

test('an ordinary administrator can\'t change an email; their other edits still work and keep it verified', function () {
    $user = aecPerson();
    $verifiedAt = $user->email_verified_at->toDateTimeString();

    aecUpdate($this, aecAdmin(false), $user, ['email' => 'attacker@example.org', 'emailVerified' => true])->assertForbidden();
    expect($user->fresh()->email)->toBe('person@example.com');

    aecUpdate($this, aecAdmin(false), $user, ['firstName' => 'Renamed'])->assertOk();
    expect($user->fresh())->firstName->toBe('Renamed')->email->toBe('person@example.com')
        ->and($user->fresh()->email_verified_at->toDateTimeString())->toBe($verifiedAt);
});

test('a super administrator can ask for a change: it waits for its owner, the current address is told (masked), logged', function () {
    Notification::fake();
    Log::spy();
    $admin = aecAdmin(true);
    $user = aecPerson();
    $before = $user->name ?: $user->username;

    aecUpdate($this, $admin, $user, ['email' => 'new.place@example.org', 'emailVerified' => true])->assertOk();

    // SCRUM-427: the current address (and its verification) stays until the new one is confirmed.
    expect($user->fresh())->email->toBe('person@example.com')->email_verified_at->not->toBeNull()->pending_email->toBe('new.place@example.org');
    Notification::assertSentOnDemand(EmailChangedNotification::class, function (EmailChangedNotification $notification, array $channels, AnonymousNotifiable $notifiable) use ($before) {
        return $notifiable->routes['mail'] === 'person@example.com'
            && $notification->maskedNewEmail === 'n•••@e•••.org'
            && $notification->name === $before
            && $notification->byTeam
            && str_contains((string) $notification->toMail($notifiable)->render(), 'If you asked us to');
    });
    Log::shouldHaveReceived('notice')->withArgs(fn ($message, $context) => $message === 'Account email change requested by an administrator' && $context['admin_id'] === $admin->id && $context['user_id'] === $user->id);
});

test('an old address that was never verified isn\'t emailed -- but the account is told in the app', function () {
    Notification::fake();
    $user = aecPerson(['email_verified_at' => null]);

    aecUpdate($this, aecAdmin(true), $user, ['email' => 'new.place@example.org'])->assertOk();

    Notification::assertSentOnDemandTimes(EmailChangedNotification::class, 0);
    Notification::assertSentTo($user, AccountEmailChangedByTeamNotification::class, fn ($notification) => $notification->maskedNewEmail === 'n•••@e•••.org');
});

test('never an administrator\'s email -- even by a super administrator', function () {
    $other = aecAdmin(false);
    $original = $other->email;

    aecUpdate($this, aecAdmin(true), $other, ['email' => 'new.place@example.org'])->assertUnprocessable();

    expect($other->fresh()->email)->toBe($original);
});

test('a real address, and not someone else\'s', function () {
    $user = aecPerson();
    User::factory()->create(['email' => 'taken@example.com']);
    $admin = aecAdmin(true);

    aecUpdate($this, $admin, $user, ['email' => 'not-an-address'])->assertUnprocessable()->assertJsonValidationErrors('email');
    aecUpdate($this, $admin, $user, ['email' => 'taken@example.com'])->assertUnprocessable()->assertJsonValidationErrors('email');
    aecUpdate($this, $admin, $user, ['email' => 'person@example.com', 'firstName' => 'Same'])->assertOk();
});

test('ticking or unticking an address verified is a super administrator\'s (logged)', function () {
    Log::spy();
    $user = aecPerson(['email_verified_at' => null]);

    aecUpdate($this, aecAdmin(false), $user, ['email' => 'person@example.com', 'emailVerified' => true])->assertForbidden();
    expect($user->fresh()->email_verified_at)->toBeNull();

    $super = aecAdmin(true);
    aecUpdate($this, $super, $user, ['emailVerified' => true])->assertOk();
    expect($user->fresh()->email_verified_at)->not->toBeNull();
    Log::shouldHaveReceived('notice')->withArgs(fn ($message, $context) => $message === 'Account email marked verified by an administrator' && $context['admin_id'] === $super->id);

    // SCRUM-427: unticking too is a super administrator's, and logged.
    aecUpdate($this, aecAdmin(false), $user, ['emailVerified' => false])->assertForbidden();
    aecUpdate($this, $super, $user, ['emailVerified' => false])->assertOk();
    expect($user->fresh()->email_verified_at)->toBeNull();
    Log::shouldHaveReceived('notice')->withArgs(fn ($message, $context) => $message === 'Account email marked unverified by an administrator' && $context['admin_id'] === $super->id);
});

// Security review: a super admin's change can't be finished by an ordinary admin ticking the new address verified.
// SCRUM-427: a requested address becomes the account's only through its owner's link -- no admin tick reaches it.
test('after a super administrator\'s request, no admin can make the new address the account\'s', function () {
    Notification::fake();
    $user = aecPerson();
    aecUpdate($this, aecAdmin(true), $user, ['email' => 'new.place@example.org'])->assertOk();

    aecUpdate($this, aecAdmin(true), $user, ['emailVerified' => true])->assertOk();
    aecUpdate($this, aecAdmin(false), $user, ['emailVerified' => true])->assertOk();

    expect($user->fresh())->email->toBe('person@example.com')->pending_email->toBe('new.place@example.org');
});

test('an email can\'t be removed, and a refusal changes nothing else in the same request', function () {
    $user = aecPerson(['firstName' => 'Original']);

    aecUpdate($this, aecAdmin(true), $user, ['email' => '', 'firstName' => 'Changed'])->assertUnprocessable();
    aecUpdate($this, aecAdmin(false), $user, ['email' => 'new.place@example.org', 'firstName' => 'Changed', 'emailVerified' => false])->assertForbidden();

    expect($user->fresh())->email->toBe('person@example.com')->firstName->toBe('Original')->email_verified_at->not->toBeNull();
});

test('an administrator can\'t change their own email here (their profile does it), but can edit the rest', function () {
    $admin = aecAdmin(true);
    $original = $admin->email;

    aecUpdate($this, $admin, $admin, ['email' => 'elsewhere@example.org'])->assertUnprocessable();
    aecUpdate($this, $admin, $admin, ['firstName' => 'Selfedit'])->assertOk();

    expect($admin->fresh())->email->toBe($original)->firstName->toBe('Selfedit');
});

test('an ordinary admin can\'t edit another administrator\'s account at all', function () {
    $other = aecAdmin(true);

    aecUpdate($this, aecAdmin(false), $other, ['firstName' => 'Hijacked', 'emailVerified' => false])->assertForbidden();

    expect($other->fresh()->firstName)->not->toBe('Hijacked');
});

test('changing only the capitals isn\'t an email change', function () {
    Notification::fake();
    $user = aecPerson();

    aecUpdate($this, aecAdmin(false), $user, ['email' => 'Person@Example.com'])->assertOk();

    expect($user->fresh()->email_verified_at)->not->toBeNull();
    Notification::assertNothingSent();
});

test('the admin user edit is limited to 30 saves a minute', function () {
    $user = aecPerson();
    $admin = aecAdmin(false);

    foreach (range(1, 30) as $try) {
        aecUpdate($this, $admin, $user, ['firstName' => 'Try'.$try]);
    }

    aecUpdate($this, $admin, $user, ['firstName' => 'One more'])->assertStatus(429);
});
