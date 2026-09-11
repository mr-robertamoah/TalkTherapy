<?php

use App\Models\Counsellor;
use App\Models\Guardianship;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use App\Models\VideoConsent;
use App\Models\VideoConsentReminder;
use App\Notifications\GuardianVideoConsentReminderNotification;
use App\Services\AppService;
use Illuminate\Support\Facades\Notification;

// TT-3.1e-e/SCRUM-284: the day-before and hour-before sweeps -- both must fire exactly once per
// session per window, go to EVERY guardian of the ward, and must NOT fire once consent already
// covers the session ("a wrongly-suppressed reminder ... is a functional gap", per the ticket's
// own stated priority -- test the suppression logic as carefully as the firing logic).

function minorTherapyWithGuardiansAndSessionForReminders(array $sessionOverrides = []): array
{
    $minor = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $minor->id,
        'counsellor_id' => $counsellor->id,
    ]);
    $guardianOne = User::factory()->create();
    $guardianTwo = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardianOne->id, 'ward_id' => $minor->id]);
    Guardianship::query()->create(['guardian_id' => $guardianTwo->id, 'ward_id' => $minor->id]);
    $session = Session::factory()->create(array_merge([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'type' => 'ONLINE',
        'status' => 'PENDING',
        'start_time' => now()->addHours(2),
        'end_time' => now()->addHours(3),
    ], $sessionOverrides));

    return compact('minor', 'therapy', 'session', 'guardianOne', 'guardianTwo');
}

test('the hour-before sweep reminds every guardian when a session starts within the next hour and consent is outstanding', function () {
    Notification::fake();
    $data = minorTherapyWithGuardiansAndSessionForReminders(['start_time' => now()->addMinutes(30), 'end_time' => now()->addMinutes(90)]);

    AppService::new()->sendHourBeforeGuardianVideoConsentReminders();

    Notification::assertSentTo($data['guardianOne'], GuardianVideoConsentReminderNotification::class);
    Notification::assertSentTo($data['guardianTwo'], GuardianVideoConsentReminderNotification::class);
    expect(VideoConsentReminder::query()->where('session_id', $data['session']->id)->first()->hour_before_sent_at)->not->toBeNull();
});

test('the day-before sweep reminds every guardian when a session starts within the next day and consent is outstanding', function () {
    Notification::fake();
    $data = minorTherapyWithGuardiansAndSessionForReminders(['start_time' => now()->addHours(20), 'end_time' => now()->addHours(21)]);

    AppService::new()->sendDayBeforeGuardianVideoConsentReminders();

    Notification::assertSentTo($data['guardianOne'], GuardianVideoConsentReminderNotification::class);
    Notification::assertSentTo($data['guardianTwo'], GuardianVideoConsentReminderNotification::class);
    expect(VideoConsentReminder::query()->where('session_id', $data['session']->id)->first()->day_before_sent_at)->not->toBeNull();
});

test('a session more than a day away does not get the day-before reminder yet', function () {
    Notification::fake();
    minorTherapyWithGuardiansAndSessionForReminders(['start_time' => now()->addDays(3), 'end_time' => now()->addDays(3)->addHour()]);

    AppService::new()->sendDayBeforeGuardianVideoConsentReminders();

    Notification::assertNothingSent();
});

test('a session more than an hour away does not get the hour-before reminder yet', function () {
    Notification::fake();
    minorTherapyWithGuardiansAndSessionForReminders(['start_time' => now()->addHours(3), 'end_time' => now()->addHours(4)]);

    AppService::new()->sendHourBeforeGuardianVideoConsentReminders();

    Notification::assertNothingSent();
});

test('no reminder fires once a valid consent grant already covers the session', function () {
    Notification::fake();
    $data = minorTherapyWithGuardiansAndSessionForReminders(['start_time' => now()->addMinutes(30), 'end_time' => now()->addMinutes(90)]);
    VideoConsent::factory()->create([
        'ward_id' => $data['minor']->id,
        'consentable_type' => Therapy::class,
        'consentable_id' => $data['therapy']->id,
    ]);

    AppService::new()->sendHourBeforeGuardianVideoConsentReminders();

    Notification::assertNothingSent();
});

test('the hour-before reminder does not fire twice for the same session', function () {
    Notification::fake();
    $data = minorTherapyWithGuardiansAndSessionForReminders(['start_time' => now()->addMinutes(30), 'end_time' => now()->addMinutes(90)]);

    AppService::new()->sendHourBeforeGuardianVideoConsentReminders();
    Notification::fake();
    AppService::new()->sendHourBeforeGuardianVideoConsentReminders();

    Notification::assertNothingSent();
});

test('the day-before and hour-before reminders are independent -- both can fire for the same session', function () {
    Notification::fake();
    $data = minorTherapyWithGuardiansAndSessionForReminders(['start_time' => now()->addMinutes(30), 'end_time' => now()->addMinutes(90)]);

    AppService::new()->sendDayBeforeGuardianVideoConsentReminders();
    AppService::new()->sendHourBeforeGuardianVideoConsentReminders();

    $reminder = VideoConsentReminder::query()->where('session_id', $data['session']->id)->first();
    expect($reminder->day_before_sent_at)->not->toBeNull()
        ->and($reminder->hour_before_sent_at)->not->toBeNull();
});

test('an adult client\'s upcoming session never gets a reminder', function () {
    Notification::fake();
    $adultClient = User::factory()->adult()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $adultClient->id, 'counsellor_id' => $counsellor->id,
    ]);
    Session::factory()->create([
        'for_type' => Therapy::class, 'for_id' => $therapy->id,
        'type' => 'ONLINE', 'status' => 'PENDING',
        'start_time' => now()->addMinutes(30), 'end_time' => now()->addMinutes(90),
    ]);

    AppService::new()->sendHourBeforeGuardianVideoConsentReminders();

    Notification::assertNothingSent();
});
