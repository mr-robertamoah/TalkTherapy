<?php

use App\Actions\Request\CreateRequestAction;
use App\Actions\Request\SupersedePendingDobChangeRequestsAction;
use App\DTOs\CreateRequestDTO;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Models\User;
use App\Notifications\DobChangeRequestSupersededNotification;
use Illuminate\Support\Facades\Notification;

// TT-4.11c/SCRUM-304: only ever called from the ageVerification-approval side (one-directional --
// an ordinary dobChange approval must never supersede a pending ageVerification).

test('supersedes a pending dobChange request for the given user', function () {
    $user = User::factory()->create();
    $guardian = User::factory()->create();
    $request = CreateRequestAction::new()->execute(CreateRequestDTO::new()->fromArray([
        'from' => $user, 'to' => $guardian, 'for' => $user,
        'type' => RequestTypeEnum::dobChange->value,
        'data' => ['newDob' => now()->subYears(30)->toDateString(), 'priorDob' => now()->subYears(17)->toDateString()],
    ]));

    SupersedePendingDobChangeRequestsAction::new()->execute($user);

    expect($request->fresh()->status)->toBe(RequestStatusEnum::superseded->value);
});

test('does not touch an already-decided dobChange request', function () {
    $user = User::factory()->create();
    $request = CreateRequestAction::new()->execute(CreateRequestDTO::new()->fromArray([
        'from' => $user, 'to' => null, 'for' => $user,
        'type' => RequestTypeEnum::dobChange->value,
        'data' => ['newDob' => now()->subYears(30)->toDateString(), 'priorDob' => now()->subYears(17)->toDateString()],
    ]));
    $request->update(['status' => RequestStatusEnum::accepted->value]);

    SupersedePendingDobChangeRequestsAction::new()->execute($user);

    expect($request->fresh()->status)->toBe(RequestStatusEnum::accepted->value);
});

test('does not touch a pending dobChange request belonging to a different user', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $request = CreateRequestAction::new()->execute(CreateRequestDTO::new()->fromArray([
        'from' => $otherUser, 'to' => null, 'for' => $otherUser,
        'type' => RequestTypeEnum::dobChange->value,
        'data' => ['newDob' => now()->subYears(30)->toDateString(), 'priorDob' => now()->subYears(17)->toDateString()],
    ]));

    SupersedePendingDobChangeRequestsAction::new()->execute($user);

    expect($request->fresh()->status)->toBe(RequestStatusEnum::pending->value);
});

test('notifies the dobChange request\'s own requester', function () {
    Notification::fake();
    $user = User::factory()->create();
    $guardian = User::factory()->create();
    CreateRequestAction::new()->execute(CreateRequestDTO::new()->fromArray([
        'from' => $user, 'to' => $guardian, 'for' => $user,
        'type' => RequestTypeEnum::dobChange->value,
        'data' => ['newDob' => now()->subYears(30)->toDateString(), 'priorDob' => now()->subYears(17)->toDateString()],
    ]));

    SupersedePendingDobChangeRequestsAction::new()->execute($user);

    Notification::assertSentTo($user, DobChangeRequestSupersededNotification::class);
});
