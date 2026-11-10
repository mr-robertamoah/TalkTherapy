<?php

use App\Actions\Notification\PruneNotificationsAction;
use App\Actions\User\AnonymiseClosedAccountAction;
use App\Models\Counsellor;
use App\Models\User;
use App\Notifications\GuardianAccessPausedNotification;
use App\Notifications\GuardianSafetyNoticeNotification;
use App\Notifications\SafetyNoticeNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// SCRUM-39 (TT-5.4d): notifications are deleted after the owner's period -- their own choice or the
// platform default -- never an unread safety notice. Helpers are file-local and npru-prefixed (--parallel).

function npruInsert(User|Counsellor $notifiable, int $daysAgo, string $type = GuardianAccessPausedNotification::class, bool $read = false): string
{
    $id = (string) Str::uuid();
    DB::table('notifications')->insert([
        'id' => $id, 'type' => $type, 'notifiable_type' => $notifiable::class, 'notifiable_id' => $notifiable->id,
        'data' => '{}', 'read_at' => $read ? now() : null,
        'created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo),
    ]);

    return $id;
}

function npruExists(string $id): bool
{
    return DB::table('notifications')->where('id', $id)->exists();
}

test('notifications older than the default go, newer ones stay', function () {
    config(['notifications.retention_days' => 90]);
    $person = User::factory()->adult()->create();
    $old = npruInsert($person, 91);
    $readOld = npruInsert($person, 120, read: true);
    $recent = npruInsert($person, 89);

    expect(PruneNotificationsAction::new()->execute())->toBe(2)
        ->and(npruExists($old))->toBeFalse()
        ->and(npruExists($readOld))->toBeFalse()
        ->and(npruExists($recent))->toBeTrue();
});

test('a person\'s own period wins over the default, longer or shorter, and covers their counsellor profile', function () {
    config(['notifications.retention_days' => 90]);
    $keepsLonger = User::factory()->adult()->create(['notification_retention_days' => 365]);
    $keepsShorter = Counsellor::factory()->for(User::factory()->adult()->state(['notification_retention_days' => 30]))->create();
    $usesDefault = User::factory()->adult()->create();

    $longerKept = npruInsert($keepsLonger, 200);
    $longerGone = npruInsert($keepsLonger, 400);
    $shorterGone = npruInsert($keepsShorter->user, 40);
    $profileGone = npruInsert($keepsShorter, 40);
    $profileKept = npruInsert($keepsShorter, 20);
    $defaultKept = npruInsert($usesDefault, 40);

    PruneNotificationsAction::new()->execute();

    expect(npruExists($longerKept))->toBeTrue()
        ->and(npruExists($longerGone))->toBeFalse()
        ->and(npruExists($shorterGone))->toBeFalse()
        ->and(npruExists($profileGone))->toBeFalse()
        ->and(npruExists($profileKept))->toBeTrue()
        ->and(npruExists($defaultKept))->toBeTrue();
});

test('an unread safety notice is never deleted; a dismissed one is, like any other', function () {
    config(['notifications.retention_days' => 30]);
    $person = User::factory()->adult()->create();
    $unread = npruInsert($person, 400, SafetyNoticeNotification::class);
    $unreadGuardian = npruInsert($person, 400, GuardianSafetyNoticeNotification::class);
    $dismissed = npruInsert($person, 400, SafetyNoticeNotification::class, read: true);

    PruneNotificationsAction::new()->execute();

    expect(npruExists($unread))->toBeTrue()
        ->and(npruExists($unreadGuardian))->toBeTrue()
        ->and(npruExists($dismissed))->toBeFalse();
});

test('a missing or too-short default skips the run, with an error -- never deleting more', function (mixed $configured) {
    config(['notifications.retention_days' => $configured]);
    Log::spy();
    $person = User::factory()->adult()->create();
    $old = npruInsert($person, 400);

    expect(PruneNotificationsAction::new()->execute())->toBe(0)
        ->and(npruExists($old))->toBeTrue();
    Log::shouldHaveReceived('error')->once();
})->with([null, 'ninety', 3]);

test('a stored period under the minimum is treated as the minimum', function () {
    config(['notifications.retention_days' => 90]);
    $person = User::factory()->adult()->create(['notification_retention_days' => 1]);
    $kept = npruInsert($person, 3);
    $gone = npruInsert($person, 8);

    PruneNotificationsAction::new()->execute();

    expect(npruExists($kept))->toBeTrue()->and(npruExists($gone))->toBeFalse();
});

test('a person sets their period from the profile -- an option or the default, nothing else', function () {
    config(['notifications.retention_days' => 90, 'notifications.retention_options' => [30, 90, 180, 365]]);
    $person = User::factory()->adult()->create();

    $this->actingAs($person)->post(route('notifications.retention'), ['days' => 365])->assertRedirect()->assertSessionHasNoErrors();
    expect($person->fresh()->notification_retention_days)->toBe(365);

    $this->actingAs($person)->post(route('notifications.retention'), ['days' => 45])->assertSessionHasErrors('days');
    $this->actingAs($person)->post(route('notifications.retention'), ['days' => 'forever'])->assertSessionHasErrors('days');
    expect($person->fresh()->notification_retention_days)->toBe(365);

    $this->actingAs($person)->post(route('notifications.retention'), ['days' => null])->assertSessionHasNoErrors();
    expect($person->fresh()->notification_retention_days)->toBeNull();

    // A guest can't.
    auth()->guard('web')->logout();
    $this->post(route('notifications.retention'), ['days' => 30])->assertRedirect(route('login'));
});

test('the profile page and the privacy policy show the periods', function () {
    config(['notifications.retention_days' => 90, 'notifications.retention_options' => [30, 90, 180, 365]]);
    $person = User::factory()->adult()->create(['notification_retention_days' => 180]);

    $this->actingAs($person)->get(route('profile.show'))->assertInertia(fn ($page) => $page
        ->where('notificationRetention', ['days' => 180, 'defaultDays' => 90, 'options' => [30, 90, 180, 365]]));
    $this->get(route('privacy'))->assertInertia(fn ($page) => $page->where('retentionDays.notifications', 90));
});

test('choosing the default\'s own number, a default-period counsellor, and soft-deleted owners are all pruned once', function () {
    config(['notifications.retention_days' => 90]);
    Log::spy();
    $choseNinety = User::factory()->adult()->create(['notification_retention_days' => 90]);
    $counsellor = Counsellor::factory()->for(User::factory()->adult())->create();
    $closed = User::factory()->adult()->create();
    $closedProfile = Counsellor::factory()->for(User::factory()->adult())->create();

    $ids = [
        npruInsert($choseNinety, 91), npruInsert($counsellor, 91), npruInsert($closed, 91), npruInsert($closedProfile, 91),
    ];
    $kept = [npruInsert($choseNinety, 80), npruInsert($counsellor, 80)];
    $closed->delete();
    $closedProfile->delete();

    expect(PruneNotificationsAction::new()->execute())->toBe(4);
    foreach ($ids as $id) {
        expect(npruExists($id))->toBeFalse();
    }
    foreach ($kept as $id) {
        expect(npruExists($id))->toBeTrue();
    }
    // A record of the run: what was deleted, by period.
    Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $message === 'Notifications pruned.' && $context['deletedByDays'] === [90 => 4] && $context['defaultDays'] === 90)->once();
});

test('notifications whose owner is gone for good follow the default', function () {
    config(['notifications.retention_days' => 90]);
    $purgedCounsellor = Counsellor::factory()->for(User::factory()->adult())->create();
    $old = npruInsert($purgedCounsellor, 91);
    $recent = npruInsert($purgedCounsellor, 10);
    $unreadNotice = (string) Str::uuid();
    DB::table('notifications')->insert([
        'id' => $unreadNotice, 'type' => SafetyNoticeNotification::class, 'notifiable_type' => User::class, 'notifiable_id' => 999999,
        'data' => '{}', 'created_at' => now()->subDays(400), 'updated_at' => now()->subDays(400),
    ]);
    $purgedCounsellor->forceDelete();

    PruneNotificationsAction::new()->execute();

    expect(npruExists($old))->toBeFalse()->and(npruExists($recent))->toBeTrue()->and(npruExists($unreadNotice))->toBeTrue();
});

test('deletion runs in batches until nothing is due', function () {
    config(['notifications.retention_days' => 30]);
    $person = User::factory()->adult()->create();
    foreach (range(1, 5) as $i) {
        npruInsert($person, 40 + $i);
    }
    $kept = npruInsert($person, 5);

    expect(PruneNotificationsAction::new()->execute(batch: 2))->toBe(5)
        ->and(DB::table('notifications')->where('notifiable_id', $person->id)->pluck('id')->all())->toBe([$kept]);
});

test('an unusable default is shown nowhere as if it applied', function () {
    config(['notifications.retention_days' => 'ninety']);
    $person = User::factory()->adult()->create();

    $this->actingAs($person)->get(route('profile.show'))->assertInertia(fn ($page) => $page->where('notificationRetention.defaultDays', null));
    $this->get(route('privacy'))->assertInertia(fn ($page) => $page->where('retentionDays.notifications', null));
});

test('closing an account deletes its counsellor profile\'s notifications too', function () {
    $counsellor = Counsellor::factory()->for(User::factory()->adult())->create();
    $profiles = npruInsert($counsellor, 1);
    $own = npruInsert($counsellor->user, 1);
    $counsellor->user->delete();
    $counsellor->user->forceFill(['deleted_at' => now()->subDays(config('user.closed_account_retention_days') + 1)])->saveQuietly();

    expect(AnonymiseClosedAccountAction::new()->execute($counsellor->user->fresh()))->toBeTrue()
        ->and(npruExists($own))->toBeFalse()
        ->and(npruExists($profiles))->toBeFalse();
});
