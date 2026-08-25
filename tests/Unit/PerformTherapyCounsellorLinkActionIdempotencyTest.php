<?php

use App\Actions\Link\CreateLinkAction;
use App\Actions\Link\PerformTherapyCounsellorLinkAction;
use App\DTOs\CreateLinkDTO;
use App\Enums\LinkTypeEnum;
use App\Exceptions\LinkException;
use App\Models\Counsellor;
use App\Models\Therapy;
use App\Models\User;
use App\Notifications\TherapyAssistanceLinkNotification;
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
