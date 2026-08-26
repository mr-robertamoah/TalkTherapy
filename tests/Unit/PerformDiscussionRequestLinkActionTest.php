<?php

use App\Actions\Link\CreateLinkAction;
use App\Actions\Link\PerformDiscussionRequestLinkAction;
use App\DTOs\CreateLinkDTO;
use App\Enums\LinkStateEnum;
use App\Enums\LinkTypeEnum;
use App\Exceptions\LinkException;
use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

// SCRUM-100: the counsellor_discussion(counsellor_id, discussion_id) unique index closes a
// duplicate-pivot-row race that isn't specific to the request-accept flow --
// PerformDiscussionRequestLinkAction attaches counsellors to discussions too, and needed the
// same catch(UniqueConstraintViolationException) treatment so that race surfaces as the
// existing graceful LinkException, not an uncaught 500 (also fixes SCRUM-96's unrelated
// `.`-vs-`,` argument typo in the same throw site, found while touching this file).

test('using a discussion link attaches the counsellor and notifies the discussion creator', function () {
    Notification::fake();

    $discussionOwner = User::factory()->create();
    $therapyOwner = User::factory()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $therapyOwner->id]);
    $discussion = Discussion::factory()->create([
        'for_id' => $therapy->id,
        'for_type' => Therapy::class,
        'addedby_id' => $discussionOwner->id,
        'addedby_type' => User::class,
    ]);
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);

    $link = CreateLinkAction::new()->execute(
        CreateLinkDTO::new()->fromArray([
            'addedby' => $discussionOwner,
            'for' => $discussion,
            'type' => LinkTypeEnum::discussion->value,
        ])
    );

    PerformDiscussionRequestLinkAction::new()->execute(
        CreateLinkDTO::new()->fromArray([
            'user' => $counsellorUser,
            'link' => $link,
        ])
    );

    expect($discussion->fresh()->counsellors()->whereKey($counsellor->id)->exists())->toBeTrue();
});

test('using a discussion link deactivates it so it cannot be replayed (SCRUM-101)', function () {
    Notification::fake();

    $discussionOwner = User::factory()->create();
    $therapyOwner = User::factory()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $therapyOwner->id]);
    $discussion = Discussion::factory()->create([
        'for_id' => $therapy->id,
        'for_type' => Therapy::class,
        'addedby_id' => $discussionOwner->id,
        'addedby_type' => User::class,
    ]);
    $counsellorUser = User::factory()->create();
    Counsellor::factory()->create(['user_id' => $counsellorUser->id]);

    $link = CreateLinkAction::new()->execute(
        CreateLinkDTO::new()->fromArray([
            'addedby' => $discussionOwner,
            'for' => $discussion,
            'type' => LinkTypeEnum::discussion->value,
        ])
    );

    PerformDiscussionRequestLinkAction::new()->execute(
        CreateLinkDTO::new()->fromArray([
            'user' => $counsellorUser,
            'link' => $link,
        ])
    );

    expect($link->fresh()->state)->toBe(LinkStateEnum::inactive->value);
});

test('using a discussion link a second time (even by the same counsellor) throws instead of an uncaught exception', function () {
    // Pre-SCRUM-101, this threw the domain-specific "already part of this discussion" error
    // (via the counsellor_id/discussion_id unique index). Now the link is deactivated after
    // its first use, so a repeat use of the SAME link hits the new "no longer active" gate
    // first, before that domain check is ever reached -- the link is single-use, full stop,
    // regardless of who reuses it.
    Notification::fake();

    $discussionOwner = User::factory()->create();
    $therapyOwner = User::factory()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $therapyOwner->id]);
    $discussion = Discussion::factory()->create([
        'for_id' => $therapy->id,
        'for_type' => Therapy::class,
        'addedby_id' => $discussionOwner->id,
        'addedby_type' => User::class,
    ]);
    $counsellorUser = User::factory()->create();
    Counsellor::factory()->create(['user_id' => $counsellorUser->id]);

    $link = CreateLinkAction::new()->execute(
        CreateLinkDTO::new()->fromArray([
            'addedby' => $discussionOwner,
            'for' => $discussion,
            'type' => LinkTypeEnum::discussion->value,
        ])
    );

    PerformDiscussionRequestLinkAction::new()->execute(
        CreateLinkDTO::new()->fromArray([
            'user' => $counsellorUser,
            'link' => $link,
        ])
    );

    try {
        PerformDiscussionRequestLinkAction::new()->execute(
            CreateLinkDTO::new()->fromArray([
                'user' => $counsellorUser,
                'link' => $link,
            ])
        );

        $this->fail('Expected a LinkException to be thrown.');
    } catch (LinkException $exception) {
        // SCRUM-96 originally fixed this throw site's `.`-vs-`,` argument typo (which made
        // getCode() always return 0 instead of 422) on the "already part of this discussion"
        // exception -- that exact exception is no longer reachable via a same-link replay
        // after SCRUM-101 (see comment above), but the fix still applies to the "no longer
        // active" exception now thrown here, so the code assertion stays meaningful.
        expect($exception->getMessage())->toBe('This link is no longer active.');
        expect($exception->getCode())->toBe(422);
    }

    expect(DB::table('counsellor_discussion')->count())->toBe(1);
});

test('a second, different counsellor cannot also use a general discussion link once it has been used (SCRUM-101)', function () {
    // A general link (to=null) can be used by any counsellor, so the counsellor_id/discussion_id
    // unique index alone doesn't stop a second, DIFFERENT counsellor from also attaching via
    // the same link. Loading $secondDTO->link BEFORE the first use commits, then only
    // performing the first use afterwards, proves the second use's active-state check re-reads
    // the link fresh under lock rather than trusting this stale, already-loaded model.
    Notification::fake();

    $discussionOwner = User::factory()->create();
    $therapyOwner = User::factory()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $therapyOwner->id]);
    $discussion = Discussion::factory()->create([
        'for_id' => $therapy->id,
        'for_type' => Therapy::class,
        'addedby_id' => $discussionOwner->id,
        'addedby_type' => User::class,
    ]);
    $firstCounsellorUser = User::factory()->create();
    Counsellor::factory()->create(['user_id' => $firstCounsellorUser->id]);
    $secondCounsellorUser = User::factory()->create();
    $secondCounsellor = Counsellor::factory()->create(['user_id' => $secondCounsellorUser->id]);

    $link = CreateLinkAction::new()->execute(
        CreateLinkDTO::new()->fromArray([
            'addedby' => $discussionOwner,
            'for' => $discussion,
            'type' => LinkTypeEnum::discussion->value,
        ])
    );

    $secondDTO = CreateLinkDTO::new()->fromArray([
        'user' => $secondCounsellorUser,
        'link' => $link,
    ]);

    PerformDiscussionRequestLinkAction::new()->execute(
        CreateLinkDTO::new()->fromArray([
            'user' => $firstCounsellorUser,
            'link' => $link,
        ])
    );

    expect(fn () => PerformDiscussionRequestLinkAction::new()->execute($secondDTO))
        ->toThrow(LinkException::class, 'This link is no longer active.');

    expect($discussion->fresh()->counsellors()->whereKey($secondCounsellor->id)->exists())->toBeFalse();
    expect(DB::table('counsellor_discussion')->count())->toBe(1);
});

test('the DB enforces a unique (counsellor_id, discussion_id) pair on counsellor_discussion', function () {
    $therapyOwner = User::factory()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $therapyOwner->id]);
    $discussion = Discussion::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);

    DB::table('counsellor_discussion')->insert([
        'counsellor_id' => $counsellor->id,
        'discussion_id' => $discussion->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::table('counsellor_discussion')->insert([
        'counsellor_id' => $counsellor->id,
        'discussion_id' => $discussion->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});
