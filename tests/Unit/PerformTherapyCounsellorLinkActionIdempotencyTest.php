<?php

use App\Actions\Link\CreateLinkAction;
use App\Actions\Link\PerformTherapyCounsellorLinkAction;
use App\DTOs\CreateLinkDTO;
use App\Enums\LinkStateEnum;
use App\Enums\LinkTypeEnum;
use App\Exceptions\CounsellorNotFoundException;
use App\Exceptions\LinkException;
use App\Models\Counsellor;
use App\Models\Guardianship;
use App\Models\Therapy;
use App\Models\User;
use App\Notifications\TherapyAssistanceLinkNotification;
use App\Notifications\TherapyAssistanceRequestAcceptedGuardianNotification;
use App\Services\LinkService;
use Illuminate\Support\Facades\Notification;

// SCRUM-98: extends the same therapy-row-lock fix SCRUM-91 applied to
// RespondToTherapyAssistanceRequestAction to this second, independent code path for assigning a
// counsellor to a therapy (the invite-link flow) -- closing the same TOCTOU race here too.

test('using a therapy-counsellor link assigns the counsellor and notifies the link creator', function () {
    Notification::fake();

    $therapyOwner = User::factory()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $therapyOwner->id]);
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);

    $link = CreateLinkAction::new()->execute(
        CreateLinkDTO::new()->fromArray([
            'addedby' => $therapyOwner,
            'for' => $therapy,
            'type' => LinkTypeEnum::therapyCounsellor->value,
        ])
    );

    PerformTherapyCounsellorLinkAction::new()->execute(
        CreateLinkDTO::new()->fromArray([
            'user' => $counsellorUser,
            'link' => $link,
        ])
    );

    expect($therapy->fresh()->counsellor_id)->toBe($counsellor->id);
    Notification::assertSentTo($therapyOwner, TherapyAssistanceLinkNotification::class);
});

test('using a therapy-counsellor link deactivates it so it cannot be replayed (SCRUM-101)', function () {
    Notification::fake();

    $therapyOwner = User::factory()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $therapyOwner->id]);
    $counsellorUser = User::factory()->create();
    Counsellor::factory()->create(['user_id' => $counsellorUser->id]);

    $link = CreateLinkAction::new()->execute(
        CreateLinkDTO::new()->fromArray([
            'addedby' => $therapyOwner,
            'for' => $therapy,
            'type' => LinkTypeEnum::therapyCounsellor->value,
        ])
    );

    PerformTherapyCounsellorLinkAction::new()->execute(
        CreateLinkDTO::new()->fromArray([
            'user' => $counsellorUser,
            'link' => $link,
        ])
    );

    expect($link->fresh()->state)->toBe(LinkStateEnum::inactive->value);
});

test('a non-counsellor user reaching this action gets a clean exception instead of an uncaught Error (SCRUM-101)', function () {
    $therapyOwner = User::factory()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $therapyOwner->id]);
    $nonCounsellorUser = User::factory()->create();

    $link = CreateLinkAction::new()->execute(
        CreateLinkDTO::new()->fromArray([
            'addedby' => $therapyOwner,
            'for' => $therapy,
            'type' => LinkTypeEnum::therapyCounsellor->value,
        ])
    );

    expect(fn () => PerformTherapyCounsellorLinkAction::new()->execute(
        CreateLinkDTO::new()->fromArray([
            'user' => $nonCounsellorUser,
            'link' => $link,
        ])
    ))->toThrow(CounsellorNotFoundException::class);
});

test('a therapy-counsellor link cannot be replayed through LinkService::performAction once used (SCRUM-101)', function () {
    Notification::fake();

    $therapyOwner = User::factory()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $therapyOwner->id]);
    $firstCounsellorUser = User::factory()->create();
    Counsellor::factory()->create(['user_id' => $firstCounsellorUser->id]);
    $secondCounsellorUser = User::factory()->create();
    Counsellor::factory()->create(['user_id' => $secondCounsellorUser->id]);

    $link = CreateLinkAction::new()->execute(
        CreateLinkDTO::new()->fromArray([
            'addedby' => $therapyOwner,
            'for' => $therapy,
            'type' => LinkTypeEnum::therapyCounsellor->value,
        ])
    );

    LinkService::new()->performAction(
        CreateLinkDTO::new()->fromArray(['user' => $firstCounsellorUser, 'link' => $link])
    );

    expect(fn () => LinkService::new()->performAction(
        CreateLinkDTO::new()->fromArray(['user' => $secondCounsellorUser, 'link' => $link->fresh()])
    ))->toThrow(LinkException::class, 'This link is no longer active.');

    expect($therapy->fresh()->counsellor_id)->toBe(Counsellor::query()->where('user_id', $firstCounsellorUser->id)->value('id'));
});

test('using a therapy-counsellor link once the therapy was concurrently assigned a counsellor throws instead of overwriting the assignment', function () {
    Notification::fake();

    // A therapy-counsellor link and a therapy-assistance-request accept both assign a
    // counsellor to the same therapy, so the therapy row is the shared mutable resource: this
    // link use and a concurrently-committing accept (or a second concurrent link use) could
    // otherwise both see no counsellor assigned yet and both "win", losing one of the two
    // counsellor_id writes (SCRUM-98). Force-loading the link's `for` (Therapy) relation while
    // still counsellor-less, then applying the "other" assignment directly, reproduces exactly
    // that race window -- the fix is only proven if it re-reads the therapy fresh under a lock
    // rather than trusting that stale, cached relation.
    $therapyOwner = User::factory()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $therapyOwner->id]);

    $firstCounsellor = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);

    $secondCounsellorUser = User::factory()->create();
    Counsellor::factory()->create(['user_id' => $secondCounsellorUser->id]);

    $link = CreateLinkAction::new()->execute(
        CreateLinkDTO::new()->fromArray([
            'addedby' => $therapyOwner,
            'for' => $therapy,
            'type' => LinkTypeEnum::therapyCounsellor->value,
        ])
    );

    $secondDTO = CreateLinkDTO::new()->fromArray([
        'user' => $secondCounsellorUser,
        'link' => $link,
    ]);
    $secondDTO->link->for; // force-load the still counsellor-less Therapy relation.

    $therapy->update(['counsellor_id' => $firstCounsellor->id]);

    expect(fn () => PerformTherapyCounsellorLinkAction::new()->execute($secondDTO))
        ->toThrow(LinkException::class);

    expect($therapy->fresh()->counsellor_id)->toBe($firstCounsellor->id);
    Notification::assertNothingSent();
});

// TT-4.10b/SCRUM-291: the guardian alert here must follow the stable client_was_minor_at_creation
// snapshot, not a live re-check of the link creator's (self-editable) dob.

test('using a therapy-counsellor link alerts the client\'s guardian even if their dob was edited to look adult after the therapy was created', function () {
    Notification::fake();

    $therapyOwner = User::factory()->adult()->create();
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $therapyOwner->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $therapyOwner->id,
        'client_was_minor_at_creation' => true,
    ]);
    $counsellorUser = User::factory()->create();
    Counsellor::factory()->create(['user_id' => $counsellorUser->id]);

    $link = CreateLinkAction::new()->execute(
        CreateLinkDTO::new()->fromArray([
            'addedby' => $therapyOwner,
            'for' => $therapy,
            'type' => LinkTypeEnum::therapyCounsellor->value,
        ])
    );

    PerformTherapyCounsellorLinkAction::new()->execute(
        CreateLinkDTO::new()->fromArray(['user' => $counsellorUser, 'link' => $link])
    );

    Notification::assertSentTo($guardian, TherapyAssistanceRequestAcceptedGuardianNotification::class);
});

// Defensive-guard case: this link type's own addedby is client-suppliable (LinkController accepts
// any addedbyType/addedbyId EnsureAddedbyIsValidAction will allow) and isn't otherwise guaranteed
// to be the same person as the therapy's own client -- unlike TherapyService::createTherapy's own
// guaranteed-by-construction case, this action must NOT assume they match, and must fall back to
// a live check on the link's own addedby rather than misreading a mismatched therapy's snapshot.
test('falls back to a live check when the link\'s addedby does not match the therapy\'s own client', function () {
    Notification::fake();

    $therapyOwner = User::factory()->create(['dob' => now()->subYears(15)->toDateString()]);
    $therapyOwnerGuardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $therapyOwnerGuardian->id, 'ward_id' => $therapyOwner->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $therapyOwner->id,
        'client_was_minor_at_creation' => true,
    ]);

    $linkCreator = User::factory()->adult()->create();
    $linkCreatorGuardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $linkCreatorGuardian->id, 'ward_id' => $linkCreator->id]);
    $counsellorUser = User::factory()->create();
    Counsellor::factory()->create(['user_id' => $counsellorUser->id]);

    $link = CreateLinkAction::new()->execute(
        CreateLinkDTO::new()->fromArray([
            'addedby' => $linkCreator,
            'for' => $therapy,
            'type' => LinkTypeEnum::therapyCounsellor->value,
        ])
    );

    PerformTherapyCounsellorLinkAction::new()->execute(
        CreateLinkDTO::new()->fromArray(['user' => $counsellorUser, 'link' => $link])
    );

    // The link creator is a live adult, so no alert at all -- and specifically NOT the therapy
    // owner's guardian, which is what a naive "just always pass $therapy" wiring would have
    // wrongly notified instead.
    Notification::assertNotSentTo($linkCreatorGuardian, TherapyAssistanceRequestAcceptedGuardianNotification::class);
    Notification::assertNotSentTo($therapyOwnerGuardian, TherapyAssistanceRequestAcceptedGuardianNotification::class);
});
