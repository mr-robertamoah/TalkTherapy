<?php

use App\Actions\Discussion\EnsureDiscussionDataIsValidAction;
use App\DTOs\CreateDiscussionDTO;
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
