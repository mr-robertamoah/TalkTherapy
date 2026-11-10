<?php

use App\Actions\GroupTherapy\CreateGroupTherapyAction;
use App\Actions\Request\CreateRequestAction;
use App\Actions\Request\RespondToGuardianshipRequestAction;
use App\Actions\Therapy\CreateTherapyAction;
use App\DTOs\CreateRequestDTO;
use App\DTOs\CreateTherapyDTO;
use App\DTOs\GroupTherapyDTO;
use App\DTOs\RequestResponseDTO;
use App\Enums\RequestTypeEnum;
use App\Models\Counsellor;
use App\Models\User;

// TT-4.10a/SCRUM-290: each of the three creation paths that can write a minor-status snapshot
// must capture it from the relevant User's own LIVE isAdult() at that exact creation moment, not
// recompute it later -- that stability is the entire point of the snapshot (see the migrations'
// own comments for why).

function aMinorForSnapshotTest(): User
{
    return User::factory()->create(['dob' => now()->subYears(10)->toDateString()]);
}

function anAdultForSnapshotTest(): User
{
    return User::factory()->create(['dob' => now()->subYears(30)->toDateString()]);
}

test('accepting a guardianship request from a minor snapshots ward_was_minor_at_creation as true', function () {
    $ward = aMinorForSnapshotTest();
    $guardian = anAdultForSnapshotTest();

    $request = CreateRequestAction::new()->execute(
        CreateRequestDTO::new()->fromArray([
            'from' => $ward,
            'to' => $guardian,
            'for' => $ward,
            'type' => RequestTypeEnum::guardianship->value,
        ])
    );

    RespondToGuardianshipRequestAction::new()->execute(RequestResponseDTO::new()->fromArray([
        'user' => $guardian,
        'response' => 'accepted',
        'request' => $request,
    ]));

    expect($ward->guardians()->first()->ward_was_minor_at_creation)->toBeTrue();
});

test('accepting a guardianship request from an adult snapshots ward_was_minor_at_creation as false', function () {
    $ward = anAdultForSnapshotTest();
    $guardian = anAdultForSnapshotTest();

    $request = CreateRequestAction::new()->execute(
        CreateRequestDTO::new()->fromArray([
            'from' => $ward,
            'to' => $guardian,
            'for' => $ward,
            'type' => RequestTypeEnum::guardianship->value,
        ])
    );

    RespondToGuardianshipRequestAction::new()->execute(RequestResponseDTO::new()->fromArray([
        'user' => $guardian,
        'response' => 'accepted',
        'request' => $request,
    ]));

    expect($ward->guardians()->first()->ward_was_minor_at_creation)->toBeFalse();
});

test('creating a therapy as a minor snapshots client_was_minor_at_creation as true', function () {
    $therapy = CreateTherapyAction::new()->execute(CreateTherapyDTO::new()->fromArray([
        'user' => aMinorForSnapshotTest(),
        'name' => 'Minor client therapy',
        'backgroundStory' => 'Test background story.',
        'public' => true,
        'maxSessions' => 1,
        'sessionType' => 'ONCE',
        'anonymous' => false,
        'allowInPerson' => false,
        'paymentType' => 'FREE',
    ]));

    expect($therapy->client_was_minor_at_creation)->toBeTrue();
});

test('creating a therapy as an adult snapshots client_was_minor_at_creation as false', function () {
    $therapy = CreateTherapyAction::new()->execute(CreateTherapyDTO::new()->fromArray([
        'user' => anAdultForSnapshotTest(),
        'name' => 'Adult client therapy',
        'backgroundStory' => 'Test background story.',
        'public' => true,
        'maxSessions' => 1,
        'sessionType' => 'ONCE',
        'anonymous' => false,
        'allowInPerson' => false,
        'paymentType' => 'FREE',
    ]));

    expect($therapy->client_was_minor_at_creation)->toBeFalse();
});

function aGroupTherapySnapshotDTO(array $overrides = [])
{
    return GroupTherapyDTO::new()->fromArray(array_merge([
        'name' => 'Snapshot Test Group',
        'about' => 'A group for testing',
        'public' => true,
        'anonymous' => false,
        'allowInPerson' => false,
        'allowAnyone' => false,
        'sessionType' => 'ONCE',
        'paymentType' => 'FREE',
    ], $overrides));
}

test('creating a group therapy as a minor user snapshots client_was_minor_at_creation as true', function () {
    $therapy = CreateGroupTherapyAction::new()->execute(aGroupTherapySnapshotDTO(['user' => aMinorForSnapshotTest()]));

    expect($therapy->client_was_minor_at_creation)->toBeTrue();
});

test('creating a group therapy as an adult user snapshots client_was_minor_at_creation as false', function () {
    $therapy = CreateGroupTherapyAction::new()->execute(aGroupTherapySnapshotDTO(['user' => anAdultForSnapshotTest()]));

    expect($therapy->client_was_minor_at_creation)->toBeFalse();
});

test('creating a group therapy as a counsellor leaves client_was_minor_at_creation null (not applicable)', function () {
    $therapy = CreateGroupTherapyAction::new()->execute(aGroupTherapySnapshotDTO(['counsellor' => Counsellor::factory()->create(['user_id' => User::factory()])]));

    expect($therapy->client_was_minor_at_creation)->toBeNull();
});
