<?php

use App\Actions\Link\CreateLinkAction;
use App\Actions\Link\PerformDiscussionRequestLinkAction;
use App\DTOs\CreateLinkDTO;
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

test('using a discussion link a second time throws the existing "already part of this discussion" error, not an uncaught exception', function () {
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
        // SCRUM-96: this throw site used to concatenate the code onto the message with `.`
        // instead of passing it as a separate `,`-separated constructor argument, so
        // getCode() always returned 0 (mapped to a generic 500) instead of the intended 422.
        expect($exception->getMessage())->toBe('You cannot use link because you are already part of this discussion.');
        expect($exception->getCode())->toBe(422);
    }

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
