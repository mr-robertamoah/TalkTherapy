<?php

use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Guardianship;
use App\Models\Therapy;
use App\Models\User;

// TT-4.10b/SCRUM-291: Therapy/GroupTherapy::getUsers()/getOtherUsers() include the client's
// guardians as participants only when the client is a minor -- this must follow the stable
// client_was_minor_at_creation snapshot (TT-4.10a), not a live isAdult() re-check of the client's
// (self-editable) dob.

test('Therapy::getUsers includes the client\'s guardian for a client who edited their dob to look adult, per the stable snapshot', function () {
    $client = User::factory()->adult()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $client->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id,
        'client_was_minor_at_creation' => true,
    ]);

    expect($therapy->getUsers()->pluck('id'))->toContain($guardian->id);
});

test('Therapy::getUsers does not include a guardian once the snapshot says adult, even if the client\'s live dob still reads as a minor', function () {
    $client = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $client->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id,
        'client_was_minor_at_creation' => false,
    ]);

    expect($therapy->getUsers()->pluck('id'))->not->toContain($guardian->id);
});

test('Therapy::getOtherUsers includes the client\'s guardian for a client who edited their dob to look adult, per the stable snapshot', function () {
    $client = User::factory()->adult()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $client->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id,
        'client_was_minor_at_creation' => true,
    ]);

    expect($therapy->getOtherUsers($counsellorUser)->pluck('id'))->toContain($guardian->id);
});

test('GroupTherapy::getUsers includes the client\'s guardian for a client who edited their dob to look adult, per the stable snapshot', function () {
    $client = User::factory()->adult()->create();
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $client->id]);
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'client_was_minor_at_creation' => true,
    ]);

    expect($groupTherapy->getUsers()->pluck('id'))->toContain($guardian->id);
});

test('GroupTherapy::getUsers does not include a guardian once the snapshot says adult, even if the client\'s live dob still reads as a minor', function () {
    $client = User::factory()->create();
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $client->id]);
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'client_was_minor_at_creation' => false,
    ]);

    expect($groupTherapy->getUsers()->pluck('id'))->not->toContain($guardian->id);
});

test('GroupTherapy::getOtherUsers includes the client\'s guardian for a client who edited their dob to look adult, per the stable snapshot', function () {
    $client = User::factory()->adult()->create();
    $otherMember = User::factory()->create();
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $client->id]);
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'client_was_minor_at_creation' => true,
    ]);

    expect($groupTherapy->getOtherUsers($otherMember)->pluck('id'))->toContain($guardian->id);
});

test('a Counsellor-created group therapy never includes a "client" guardian at all (null snapshot, not applicable)', function () {
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => Counsellor::class,
        'addedby_id' => $counsellor->id,
        'client_was_minor_at_creation' => null,
    ]);

    expect($groupTherapy->clientIsMinor())->toBeFalse();
});
