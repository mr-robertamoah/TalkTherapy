<?php

use App\Actions\VideoConsent\SendGuardianVideoConsentRemindersAction;
use App\Enums\SessionStatusEnum;
use App\Enums\TherapyStatusEnum;
use App\Enums\VideoConsentLevelEnum;
use App\Enums\VideoConsentReminderWindowEnum;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Guardianship;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use App\Models\VideoConsent;
use App\Models\VideoConsentReminder;
use App\Notifications\GuardianVideoConsentReminderNotification;
use Illuminate\Support\Facades\Notification;

// SCRUM-313 PR 4: guardians are reminded about a group session's video consent too -- for each of their wards in it who
// has none, once per window per ward.

function gcrMinor(): array
{
    $minor = User::factory()->create(['dob' => now()->subYears(15)->toDateString()]);
    $guardian = User::factory()->adult()->create(['email_verified_at' => now()]);
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $minor->id, 'ward_was_minor_at_creation' => true]);

    return [$minor, $guardian];
}

// A running group with these members and an online session starting within the hour.
function gcrGroup(User ...$members): array
{
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $group = GroupTherapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => User::factory()->adult()->create()->id, 'status' => TherapyStatusEnum::in_session->value,
    ]);
    $group->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    foreach ($members as $member) {
        $group->allMemberships()->attach($member->id, ['anonymous' => false, 'was_minor_at_join' => ! $member->isAdult()]);
    }
    $session = Session::factory()->create([
        'for_type' => GroupTherapy::class, 'for_id' => $group->id, 'status' => SessionStatusEnum::pending->value,
        'type' => 'ONLINE', 'start_time' => now()->addMinutes(30), 'end_time' => now()->addMinutes(90),
    ]);

    return [$group, $session];
}

function gcrRemind(): void
{
    SendGuardianVideoConsentRemindersAction::new()->execute(VideoConsentReminderWindowEnum::hour_before);
}

test('each minor member\'s guardians are reminded, once, and nobody about an adult', function () {
    Notification::fake();
    [$minor, $guardian] = gcrMinor();
    [$other, $otherGuardian] = gcrMinor();
    $adult = User::factory()->adult()->create();
    [, $session] = gcrGroup($minor, $other, $adult);

    gcrRemind();
    gcrRemind();

    Notification::assertSentToTimes($guardian, GuardianVideoConsentReminderNotification::class, 1);
    Notification::assertSentToTimes($otherGuardian, GuardianVideoConsentReminderNotification::class, 1);
    Notification::assertSentTo($guardian, GuardianVideoConsentReminderNotification::class, fn ($notification) => $notification->ward->is($minor));
    expect(VideoConsentReminder::query()->where('session_id', $session->id)->pluck('ward_id')->sort()->values()->all())
        ->toBe(collect([$minor->id, $other->id])->sort()->values()->all());
});

test('a minor whose guardians already consented isn\'t reminded about; one who joins later still is', function () {
    Notification::fake();
    [$minor, $guardian] = gcrMinor();
    [$group] = gcrGroup($minor);
    VideoConsent::factory()->create([
        'ward_id' => $minor->id, 'guardian_id' => $guardian->id, 'consentable_type' => GroupTherapy::class,
        'consentable_id' => $group->id, 'level' => VideoConsentLevelEnum::watch->value,
    ]);

    gcrRemind();
    Notification::assertNothingSent();

    [$late, $lateGuardian] = gcrMinor();
    $group->allMemberships()->attach($late->id, ['anonymous' => false, 'was_minor_at_join' => true]);
    gcrRemind();

    Notification::assertSentToTimes($lateGuardian, GuardianVideoConsentReminderNotification::class, 1);
    Notification::assertNotSentTo($guardian, GuardianVideoConsentReminderNotification::class);
});

test('a group reminder points the guardian at their own ward list, never the group page', function () {
    [$minor, $guardian] = gcrMinor();
    [$group, $session] = gcrGroup($minor);
    $notification = new GuardianVideoConsentReminderNotification($session, VideoConsentReminderWindowEnum::hour_before->value, $minor);

    $data = $notification->toArray($guardian);
    $mail = $notification->toMail($guardian);

    expect($data)->not->toHaveKey('groupTherapyId')
        ->and($data['group'])->toBeTrue()
        ->and($mail->actionUrl)->toBe(route('therapies'))
        ->and(GuardianVideoConsentReminderNotification::present($data)->url)->toEqual(route('therapies'));
});

test('a guardian of two minors in the group is reminded about each, and each window fires once', function () {
    Notification::fake();
    [$minor, $guardian] = gcrMinor();
    $sibling = User::factory()->create(['dob' => now()->subYears(13)->toDateString()]);
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $sibling->id, 'ward_was_minor_at_creation' => true]);
    gcrGroup($minor, $sibling);

    gcrRemind();
    SendGuardianVideoConsentRemindersAction::new()->execute(VideoConsentReminderWindowEnum::day_before);
    gcrRemind();

    Notification::assertSentToTimes($guardian, GuardianVideoConsentReminderNotification::class, 4);
    foreach ([$minor, $sibling] as $ward) {
        foreach ([VideoConsentReminderWindowEnum::hour_before, VideoConsentReminderWindowEnum::day_before] as $window) {
            Notification::assertSentTo($guardian, GuardianVideoConsentReminderNotification::class, fn ($n) => $n->ward->is($ward) && $n->window === $window->value);
        }
    }
});

test('a session-only consent stops it; one from a guardian who no longer counts doesn\'t', function () {
    Notification::fake();
    [$minor, $guardian] = gcrMinor();
    [$other, $otherGuardian] = gcrMinor();
    $formerGuardian = User::factory()->create(['dob' => null]);
    Guardianship::query()->create(['guardian_id' => $formerGuardian->id, 'ward_id' => $other->id, 'ward_was_minor_at_creation' => true]);
    [, $session] = gcrGroup($minor, $other);
    VideoConsent::factory()->create(['ward_id' => $minor->id, 'guardian_id' => $guardian->id, 'consentable_type' => Session::class, 'consentable_id' => $session->id, 'level' => VideoConsentLevelEnum::watch->value]);
    VideoConsent::factory()->create(['ward_id' => $other->id, 'guardian_id' => $formerGuardian->id, 'consentable_type' => Session::class, 'consentable_id' => $session->id, 'level' => VideoConsentLevelEnum::watch->value]);

    gcrRemind();

    Notification::assertNotSentTo($guardian, GuardianVideoConsentReminderNotification::class);
    Notification::assertSentTo($otherGuardian, GuardianVideoConsentReminderNotification::class);
    // A guardian who doesn't count isn't told about the ward's sessions either.
    Notification::assertNotSentTo($formerGuardian, GuardianVideoConsentReminderNotification::class);
});

test('a minor creator is reminded about; a member who left, or a minor who is one of its counsellors, isn\'t', function () {
    Notification::fake();
    [$creator, $creatorGuardian] = gcrMinor();
    [$leaver, $leaverGuardian] = gcrMinor();
    [$minorCounsellorUser, $counsellorGuardian] = gcrMinor();
    [$group] = gcrGroup($leaver);
    $group->forceFill(['addedby_id' => $creator->id])->save();
    $group->allMemberships()->updateExistingPivot($leaver->id, ['left_at' => now()]);
    $counsellor = Counsellor::factory()->create(['user_id' => $minorCounsellorUser->id]);
    $group->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);

    gcrRemind();

    Notification::assertSentTo($creatorGuardian, GuardianVideoConsentReminderNotification::class);
    Notification::assertNotSentTo($leaverGuardian, GuardianVideoConsentReminderNotification::class);
    Notification::assertNotSentTo($counsellorGuardian, GuardianVideoConsentReminderNotification::class);
});

test('offline, started or far-off group sessions aren\'t reminded about', function () {
    Notification::fake();
    [$minor, $guardian] = gcrMinor();
    [, $session] = gcrGroup($minor);
    $session->update(['type' => 'IN_PERSON']);
    $other = $session->replicate()->fill(['type' => 'ONLINE', 'status' => SessionStatusEnum::in_session->value]);
    $other->save();
    $far = $session->replicate()->fill(['type' => 'ONLINE', 'start_time' => now()->addDays(3), 'end_time' => now()->addDays(3)->addHour()]);
    $far->save();

    gcrRemind();

    Notification::assertNotSentTo($guardian, GuardianVideoConsentReminderNotification::class);
});

test('a group reminder names the ward and the group; a one-to-one one still links its therapy', function () {
    [$minor, $guardian] = gcrMinor();
    [$group, $session] = gcrGroup($minor);
    $group->update(['name' => 'Exam Stress Circle']);
    $groupMail = (new GuardianVideoConsentReminderNotification($session->fresh(), 'hour_before', $minor))->toMail($guardian);
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $minor->id]);
    $oneToOne = Session::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id, 'start_time' => now()->addMinutes(30)]);
    $data = (new GuardianVideoConsentReminderNotification($oneToOne, 'hour_before'))->toArray($guardian);

    expect(implode(' ', $groupMail->introLines))->toContain('Exam Stress Circle')->toContain($minor->name)
        ->and($data)->toHaveKey('therapyId')->not->toHaveKey('group');
});
