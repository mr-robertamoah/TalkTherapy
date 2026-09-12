<?php

use App\Actions\User\AlertGuardianAction;
use App\DTOs\GuardianAlertDTO;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Guardianship;
use App\Models\Therapy;
use App\Models\User;
use App\Notifications\TherapyCreatedNotification;
use Illuminate\Support\Facades\Notification;

// TT-4.10b/SCRUM-291: prefers the linked record's stable client_was_minor_at_creation snapshot
// (TT-4.10a) over a live isAdult() re-check of $guardianAlertDTO->user's (self-editable) dob.

test('alerts guardians when no record is passed and the user is live-minor (unchanged fallback behaviour)', function () {
    Notification::fake();
    $minor = User::factory()->create();
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $minor->id]);
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $minor->id]);

    AlertGuardianAction::new()->execute(GuardianAlertDTO::new()->fromArray([
        'user' => $minor,
        'notification' => new TherapyCreatedNotification($therapy),
    ]));

    Notification::assertSentTo($guardian, TherapyCreatedNotification::class);
});

test('does not alert when no record is passed and the user is live-adult (unchanged fallback behaviour)', function () {
    Notification::fake();
    $adult = User::factory()->adult()->create();
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $adult->id]);
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $adult->id]);

    AlertGuardianAction::new()->execute(GuardianAlertDTO::new()->fromArray([
        'user' => $adult,
        'notification' => new TherapyCreatedNotification($therapy),
    ]));

    Notification::assertNotSentTo($guardian, TherapyCreatedNotification::class);
});

test('alerts guardians per the record\'s snapshot even when the user\'s live dob now reads as adult', function () {
    Notification::fake();
    $client = User::factory()->adult()->create();
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $client->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $client->id, 'client_was_minor_at_creation' => true,
    ]);

    AlertGuardianAction::new()->execute(GuardianAlertDTO::new()->fromArray([
        'user' => $client,
        'notification' => new TherapyCreatedNotification($therapy),
        'for' => $therapy,
    ]));

    Notification::assertSentTo($guardian, TherapyCreatedNotification::class);
});

test('does not alert once the record\'s snapshot says adult, even if the user\'s live dob still reads as a minor', function () {
    Notification::fake();
    $client = User::factory()->create();
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $client->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $client->id, 'client_was_minor_at_creation' => false,
    ]);

    AlertGuardianAction::new()->execute(GuardianAlertDTO::new()->fromArray([
        'user' => $client,
        'notification' => new TherapyCreatedNotification($therapy),
        'for' => $therapy,
    ]));

    Notification::assertNotSentTo($guardian, TherapyCreatedNotification::class);
});

test('a Counsellor-created group therapy\'s null (not-applicable) snapshot falls back to the user\'s own live status', function () {
    Notification::fake();
    $minorCounsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $minorCounsellorUser->id]);
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $minorCounsellorUser->id]);
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id, 'client_was_minor_at_creation' => null,
    ]);

    // $guardianAlertDTO->user is the minor counsellor's own account, NOT the group's addedby --
    // clientIsMinor() would be false for this record (not applicable), but that must not silently
    // suppress the alert for this user's own live-minor status. AlertGuardianAction's fallback
    // only kicks in because 'for' is correctly NOT passed here (see GroupTherapyService's own
    // reasoning for this exact branch).
    AlertGuardianAction::new()->execute(GuardianAlertDTO::new()->fromArray([
        'user' => $minorCounsellorUser,
        'notification' => new TherapyCreatedNotification($groupTherapy),
    ]));

    Notification::assertSentTo($guardian, TherapyCreatedNotification::class);
});
