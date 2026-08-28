<?php

use App\Actions\Discussion\EnsureDiscussionDataIsValidAction;
use App\DTOs\CreateDiscussionDTO;
use App\Enums\DiscussionStatusEnum;
use App\Enums\SessionStatusEnum;
use App\Exceptions\DiscussionException;
use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use Carbon\Carbon;

test('rescheduling a discussion to an earlier, genuinely free slot does not throw a false prohibition error', function () {
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);

    $therapyBeingRescheduled = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'counsellor_id' => $counsellor->id,
    ]);

    $discussionBeingRescheduled = Discussion::factory()->create([
        'for_id' => $therapyBeingRescheduled->id,
        'for_type' => Therapy::class,
        'start_time' => Carbon::parse('2025-06-01 20:00:00'),
        'end_time' => Carbon::parse('2025-06-01 21:00:00'),
    ]);

    // An unrelated, pending session for the same counsellor, on a different therapy, that a
    // genuinely free earlier slot should NOT collide with.
    $otherTherapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'counsellor_id' => $counsellor->id,
    ]);
    Session::factory()->create([
        'for_id' => $otherTherapy->id,
        'for_type' => Therapy::class,
        'status' => SessionStatusEnum::pending->value,
        'start_time' => Carbon::parse('2025-06-01 11:00:00'),
        'end_time' => Carbon::parse('2025-06-01 12:00:00'),
    ]);

    $dto = CreateDiscussionDTO::new()->fromArray([
        'user' => $counsellorUser,
        'addedby' => $counsellorUser,
        'for' => $therapyBeingRescheduled,
        'discussion' => $discussionBeingRescheduled,
        'startTime' => '2025-06-01 09:00:00',
        'endTime' => '2025-06-01 10:00:00',
    ]);

    expect(fn () => EnsureDiscussionDataIsValidAction::new()->execute($dto))
        ->not->toThrow(DiscussionException::class);
});

// SCRUM-139: the "does this addedby/counsellor already have a pending discussion in this time
// window" checks wrap where(pending+addedby)->orWhere(pending+counsellor) in an explicit outer
// where() group before applying the trailing date-scope call, so the date scope applies to BOTH
// OR branches, not just the last one. If that wrapping were ever removed, SQL operator precedence
// (AND binds tighter than OR) would let the addedby branch escape the date scope entirely --
// matching a same-user pending discussion at ANY time, not just an overlapping one.

test('an unrelated pending discussion by the same addedby at a genuinely different time does not false-positive', function () {
    $owner = User::factory()->create();

    $unrelatedTherapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $owner->id,
    ]);
    Discussion::factory()->create([
        'for_id' => $unrelatedTherapy->id,
        'for_type' => Therapy::class,
        'addedby_type' => User::class,
        'addedby_id' => $owner->id,
        'status' => DiscussionStatusEnum::pending->value,
        'start_time' => Carbon::parse('2025-01-01 09:00:00'),
        'end_time' => Carbon::parse('2025-01-01 10:00:00'),
    ]);

    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $owner->id,
    ]);

    $dto = CreateDiscussionDTO::new()->fromArray([
        'user' => $owner,
        'addedby' => $owner,
        'for' => $therapy,
        'startTime' => '2025-06-01 09:00:00',
        'endTime' => '2025-06-01 10:00:00',
    ]);

    expect(fn () => EnsureDiscussionDataIsValidAction::new()->execute($dto))
        ->not->toThrow(DiscussionException::class);
});
