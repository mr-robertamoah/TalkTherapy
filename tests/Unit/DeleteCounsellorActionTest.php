<?php

use App\Actions\Counsellor\DeleteCounsellorAction;
use App\DTOs\DeleteCounsellorDTO;
use App\Enums\CounsellorGroupTherapyRoleEnum;
use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Enums\OrganizationCounsellorSourceEnum;
use App\Enums\OrganizationCounsellorStatusEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\GroupTherapy;
use App\Models\Organization;
use App\Models\OrganizationCounsellor;
use App\Models\Request;
use App\Models\Therapy;
use App\Models\User;
use App\Notifications\CounsellorAccountDeletedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

// SCRUM-134: covers the cleanup logic that resolves DeleteCounsellorAction's former
// "TODO clean up before deletion" -- pivot state, org affiliations, sent requests, former-client
// notifications, and the soft-delete itself.

test('deletion soft-deletes the counsellor', function () {
    $owner = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $owner->id]);

    DeleteCounsellorAction::new()->execute(DeleteCounsellorDTO::new()->fromArray([
        'user' => $owner,
        'counsellor' => $counsellor,
    ]));

    expect(Counsellor::find($counsellor->id))->toBeNull();
    expect($counsellor->fresh()->deleted_at)->not->toBeNull();
});

test('deletion flips active group therapy pivot rows to inactive instead of detaching them', function () {
    $owner = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $owner->id]);
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
    ]);
    $groupTherapy->counsellors()->attach($counsellor->id, [
        'state' => CounsellorGroupTherapyStateEnum::active->value,
        'role' => CounsellorGroupTherapyRoleEnum::normal->value,
    ]);

    DeleteCounsellorAction::new()->execute(DeleteCounsellorDTO::new()->fromArray([
        'user' => $owner,
        'counsellor' => $counsellor,
    ]));

    $pivot = DB::table('counsellor_group_therapy')
        ->where('counsellor_id', $counsellor->id)
        ->where('group_therapy_id', $groupTherapy->id)
        ->first();

    expect($pivot)->not->toBeNull();
    expect($pivot->state)->toBe(CounsellorGroupTherapyStateEnum::inactive->value);
});

test('deletion detaches the counsellor from discussions', function () {
    $owner = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $owner->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'counsellor_id' => $counsellor->id,
        'status' => TherapyStatusEnum::ended->value,
    ]);
    $discussion = Discussion::factory()->create([
        'for_id' => $therapy->id,
        'for_type' => Therapy::class,
        'addedby_type' => User::class,
        'addedby_id' => $therapy->addedby_id,
    ]);
    $discussion->counsellors()->attach($counsellor->id);

    DeleteCounsellorAction::new()->execute(DeleteCounsellorDTO::new()->fromArray([
        'user' => $owner,
        'counsellor' => $counsellor,
    ]));

    expect($discussion->counsellors()->whereKey($counsellor->id)->exists())->toBeFalse();
});

test('deletion ends active organization affiliations', function () {
    $owner = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $owner->id]);
    $organization = Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);
    $affiliation = OrganizationCounsellor::factory()->create([
        'organization_id' => $organization->id,
        'counsellor_id' => $counsellor->id,
        'status' => OrganizationCounsellorStatusEnum::active->value,
        'source' => OrganizationCounsellorSourceEnum::invited->value,
    ]);

    DeleteCounsellorAction::new()->execute(DeleteCounsellorDTO::new()->fromArray([
        'user' => $owner,
        'counsellor' => $counsellor,
    ]));

    expect($affiliation->fresh()->status)->toBe(OrganizationCounsellorStatusEnum::ended->value);
});

test('deletion marks the counsellor\'s own pending sent requests as inconsequential', function () {
    $owner = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $owner->id]);
    $request = Request::factory()->create([
        'from_id' => $counsellor->id,
        'from_type' => Counsellor::class,
        'type' => RequestTypeEnum::counsellor->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => [],
    ]);

    DeleteCounsellorAction::new()->execute(DeleteCounsellorDTO::new()->fromArray([
        'user' => $owner,
        'counsellor' => $counsellor,
    ]));

    expect($request->fresh()->status)->toBe(RequestStatusEnum::inconsequencial->value);
});

test('deletion notifies former clients from ended therapies and group therapies', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $owner->id]);

    $therapyClient = User::factory()->create();
    Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $therapyClient->id,
        'counsellor_id' => $counsellor->id,
        'status' => TherapyStatusEnum::ended->value,
    ]);

    $groupTherapyOwner = User::factory()->create();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $groupTherapyOwner->id,
    ]);
    $groupTherapy->counsellors()->attach($counsellor->id, [
        'state' => CounsellorGroupTherapyStateEnum::inactive->value,
        'role' => CounsellorGroupTherapyRoleEnum::normal->value,
    ]);

    DeleteCounsellorAction::new()->execute(DeleteCounsellorDTO::new()->fromArray([
        'user' => $owner,
        'counsellor' => $counsellor,
    ]));

    Notification::assertSentTo($therapyClient, CounsellorAccountDeletedNotification::class);
    Notification::assertSentTo($groupTherapyOwner, CounsellorAccountDeletedNotification::class);
});

// Regression test: GroupTherapy::getUsers() (a general-purpose helper used nowhere else) also
// returns every OTHER counsellor attached to the group, and -- since this counsellor's own pivot
// row hasn't been flipped to inactive yet when notifications are gathered -- the counsellor being
// deleted themselves. Neither is a "former client" and neither should be notified.
test('deletion does not notify co-counsellors or the deleted counsellor themselves', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $owner->id]);

    $coCounsellorUser = User::factory()->create();
    $coCounsellor = Counsellor::factory()->create(['user_id' => $coCounsellorUser->id]);

    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
    ]);
    $groupTherapy->counsellors()->attach([$counsellor->id, $coCounsellor->id], [
        'state' => CounsellorGroupTherapyStateEnum::active->value,
        'role' => CounsellorGroupTherapyRoleEnum::normal->value,
    ]);

    DeleteCounsellorAction::new()->execute(DeleteCounsellorDTO::new()->fromArray([
        'user' => $owner,
        'counsellor' => $counsellor,
    ]));

    Notification::assertNotSentTo($coCounsellorUser, CounsellorAccountDeletedNotification::class);
    Notification::assertNotSentTo($owner, CounsellorAccountDeletedNotification::class);
});

test('deletion does not notify a user unrelated to the counsellor', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $owner->id]);
    $unrelatedUser = User::factory()->create();

    DeleteCounsellorAction::new()->execute(DeleteCounsellorDTO::new()->fromArray([
        'user' => $owner,
        'counsellor' => $counsellor,
    ]));

    Notification::assertNotSentTo($unrelatedUser, CounsellorAccountDeletedNotification::class);
});
