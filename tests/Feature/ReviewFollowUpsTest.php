<?php

use App\Actions\Counsellor\EnsureCounsellorOfferCapAction;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Http\Resources\GroupTherapyMiniResource;
use App\Http\Resources\GroupTherapyPreviewResource;
use App\Http\Resources\GroupTherapyResource;
use App\Http\Resources\TherapyMiniResource;
use App\Http\Resources\TherapyResource;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Request;
use App\Models\Therapy;
use App\Models\User;

// SCRUM-392: the review follow-ups -- shared model helpers and scopes. Helpers are file-local and
// rfu-prefixed (--parallel).

function rfuCounsellor(): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
}

function rfuRequest(string $type, $from, $to, $for, string $status = 'PENDING'): Request
{
    $request = new Request(['type' => $type, 'data' => [], 'status' => $status]);
    $request->from()->associate($from);
    $request->to()->associate($to);
    $request->for()->associate($for);
    $request->save();

    return $request;
}

test('a group knows who created it, through a counsellor profile too', function () {
    $person = User::factory()->adult()->create();
    $counsellor = rfuCounsellor();
    $byPerson = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $person->id]);
    $byCounsellor = GroupTherapy::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id]);

    expect($byPerson->isCreatedBy($person))->toBeTrue()
        ->and($byPerson->isCreatedBy($counsellor->user))->toBeFalse()
        ->and($byCounsellor->isCreatedBy($counsellor->user))->toBeTrue()
        ->and($byCounsellor->isCreatedBy($person))->toBeFalse();
});

test('a free counsellor place is only the count; taking a counsellor also needs the group not ended', function () {
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()->adult()->create()->id, 'max_counsellors' => 1, 'status' => 'ENDED']);

    expect($group->hasFreeCounsellorPlace())->toBeTrue()
        ->and($group->canTakeCounsellor())->toBeFalse();

    $group->counsellors()->attach(rfuCounsellor()->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    expect($group->fresh()->hasFreeCounsellorPlace())->toBeFalse();
});

test('pending group assistance is found whichever way it was sent, and only for that group and counsellor', function () {
    $counsellor = rfuCounsellor();
    $creator = User::factory()->adult()->create();
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $creator->id]);
    $invite = rfuRequest(RequestTypeEnum::groupTherapy->value, $creator, $counsellor, $group);
    $offer = rfuRequest(RequestTypeEnum::groupTherapy->value, $counsellor, $creator, $group);
    rfuRequest(RequestTypeEnum::groupTherapy->value, $creator, $counsellor, $group, RequestStatusEnum::rejected->value);
    rfuRequest(RequestTypeEnum::groupTherapy->value, $creator, rfuCounsellor(), $group);
    rfuRequest(RequestTypeEnum::groupTherapyMembership->value, $creator, $counsellor->user, $group);
    rfuRequest(RequestTypeEnum::groupTherapy->value, $creator, $counsellor, GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $creator->id]));

    expect(Request::query()->pendingGroupAssistance($group)->whereToOrFrom($counsellor)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$invite->id, $offer->id])->sort()->values()->all());
});

test('the public-list scopes leave out what the viewer is part of, and an orWhere after them can\'t reach private rows', function () {
    $viewer = User::factory()->adult()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $viewer->id]);
    $other = User::factory()->adult()->create();
    $public = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $other->id, 'public' => true, 'counsellor_id' => null]);
    Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $viewer->id, 'public' => true]);
    Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $other->id, 'public' => true, 'counsellor_id' => $counsellor->id]);
    $private = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $other->id, 'public' => false, 'name' => 'Private one']);

    expect(Therapy::query()->publicForOutsider($viewer)->pluck('id')->all())->toBe([$public->id])
        ->and(Therapy::query()->publicForOutsider($viewer)->orWhere('name', 'Never matches')->pluck('id')->all())->toBe([$public->id])
        ->and(Therapy::query()->publicForOutsider($viewer)->pluck('id'))->not->toContain($private->id);

    $openGroup = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $other->id, 'public' => true]);
    $left = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $other->id, 'public' => true]);
    $left->counsellors()->attach($counsellor->id, ['state' => 'INACTIVE', 'role' => 'NORMAL']);
    $counselling = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $other->id, 'public' => true]);
    $counselling->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    GroupTherapy::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id, 'public' => true]);
    GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $other->id, 'public' => false]);

    // A counsellor who left still sees the group (they may offer again).
    expect(GroupTherapy::query()->publicForOutsider($viewer)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$openGroup->id, $left->id])->sort()->values()->all());
});

test('a counsellor may offer to help at most the daily cap in 24 hours, therapies and groups together, whatever became of the offers', function () {
    config(['counsellor.daily_offer_cap' => 2]);
    $counsellor = rfuCounsellor();
    $client = User::factory()->adult()->create();
    $therapy = fn () => Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'public' => true, 'counsellor_id' => null]);
    $group = fn () => GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'public' => true, 'max_counsellors' => 3]);

    // An invite they received, and one they sent as a group's creator, don't count.
    rfuRequest(RequestTypeEnum::therapy->value, $client, $counsellor, $therapy());
    $ownGroup = GroupTherapy::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id]);
    rfuRequest(RequestTypeEnum::groupTherapy->value, $counsellor, rfuCounsellor(), $ownGroup);
    // One from yesterday-and-a-bit doesn't either; a rejected one today does.
    $this->travel(-25)->hours();
    rfuRequest(RequestTypeEnum::therapy->value, $counsellor, $client, $therapy());
    $this->travelBack();
    rfuRequest(RequestTypeEnum::therapy->value, $counsellor, $client, $therapy(), RequestStatusEnum::rejected->value);

    $this->actingAs($counsellor->user)->postJson(route('group.therapies.assist', ['groupTherapyId' => $group()->id]))->assertSuccessful();
    $this->actingAs($counsellor->user)->postJson(route('group.therapies.assist', ['groupTherapyId' => $group()->id]))
        ->assertStatus(422)->assertJsonFragment(['message' => EnsureCounsellorOfferCapAction::REACHED]);
    // A therapy offer counts against the same cap.
    $this->actingAs($counsellor->user)->postJson(route('therapies.assist', ['therapyId' => $therapy()->id]))
        ->assertStatus(422)->assertJsonFragment(['message' => EnsureCounsellorOfferCapAction::REACHED]);

    $this->travel(25)->hours();
    $this->actingAs($counsellor->user)->postJson(route('group.therapies.assist', ['groupTherapyId' => $group()->id]))->assertSuccessful();
});

test('the find-a-counsellor picker lists only currently verified counsellors; the directory still lists them all', function () {
    $verified = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id, 'name' => 'Dr. Pickable Verified']);
    $lapsed = Counsellor::factory()->lapsed()->create(['user_id' => User::factory()->adult()->create()->id, 'name' => 'Dr. Pickable Lapsed']);
    $unverified = Counsellor::factory()->unverified()->create(['user_id' => User::factory()->adult()->create()->id, 'name' => 'Dr. Pickable Unverified']);
    $viewer = User::factory()->adult()->create();

    $ids = fn (string $route) => collect($this->actingAs($viewer)->getJson(route($route, ['name' => 'Pickable']))->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

    expect($ids('counsellors.request.get'))->toBe([$verified->id])
        ->and($ids('api.counsellors'))->toBe(collect([$verified->id, $lapsed->id, $unverified->id])->sort()->values()->all());
});

test('a therapy or group sends its raw status, with a readable label beside it', function () {
    $client = User::factory()->adult()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'status' => TherapyStatusEnum::in_session->value]);
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'status' => TherapyStatusEnum::in_session->value]);

    $this->actingAs($client);
    foreach ([new TherapyResource($therapy), new TherapyMiniResource($therapy),
        new GroupTherapyResource($group), new GroupTherapyMiniResource($group), new GroupTherapyPreviewResource($group)] as $resource) {
        $data = $resource->toArray(request());
        expect($data['status'])->toBe('IN_SESSION')
            ->and($data['statusLabel'])->toBe('in session');
    }
});

test('an offer under the cap goes through, and a client asking counsellors in is never capped', function () {
    config(['counsellor.daily_offer_cap' => 1]);
    $counsellor = rfuCounsellor();
    $client = User::factory()->adult()->create();
    $therapy = fn () => Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'public' => true, 'counsellor_id' => null]);

    $this->actingAs($counsellor->user)->postJson(route('therapies.assist', ['therapyId' => $therapy()->id]))->assertSuccessful();

    // The client asks three counsellors onto three therapies -- invitations, not offers.
    foreach (range(1, 3) as $i) {
        $this->actingAs($client)->postJson(route('therapies.assist', ['therapyId' => $therapy()->id]), ['counsellorIds' => [rfuCounsellor()->id]])->assertSuccessful();
    }
});

test('a group\'s people include its creator once, through a counsellor profile too, and others leave the viewer out', function () {
    $counsellor = rfuCounsellor();
    $member = User::factory()->adult()->create();
    $group = GroupTherapy::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id]);
    $group->users()->attach($member->id, ['anonymous' => false]);

    expect($group->fresh()->getUsers()->pluck('id'))->toContain($counsellor->user_id)->toContain($member->id)
        ->and($group->fresh()->getOtherUsers($counsellor->user)->pluck('id'))->not->toContain($counsellor->user_id)
        ->and($group->fresh()->getOtherUsers($member)->pluck('id'))->toContain($counsellor->user_id)->not->toContain($member->id);

    // A creator whose counsellor profile was removed is still the group's creator.
    $counsellor->delete();
    expect($group->fresh()->isCreatedBy($counsellor->user))->toBeTrue();
});
