<?php

use App\Enums\ConstantsEnum;
use App\Enums\RequestTypeEnum;
use App\Http\Resources\GroupTherapyMiniResource;
use App\Http\Resources\GroupTherapyResource;
use App\Http\Resources\RequestResource;
use App\Http\Resources\SessionResource;
use App\Http\Resources\TherapyResource;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Request as AppRequest;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Http\Request;

// SCRUM-345: a masked (anonymous) person's real user id is never sent to a viewer who doesn't need
// it -- a stable id would let them link the same anonymous person across therapies and requests.

function presentFor345($resource, ?User $viewer): array
{
    return json_decode(json_encode(
        $resource->toArray(Request::create('/')->setUserResolver(fn () => $viewer))
    ), true);
}

function aCounsellorFor345(): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
}

test('an anonymous therapy\'s client id goes only to its own counsellor', function () {
    $client = User::factory()->create();
    $counsellor = aCounsellorFor345();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id, 'anonymous' => true, 'public' => true,
    ])->fresh();

    $toStranger = presentFor345(new TherapyResource($therapy), aCounsellorFor345()->user);
    expect($toStranger['user']['id'])->toBeNull()
        ->and($toStranger['user']['fullName'])->toBe(ConstantsEnum::anonymousUserLabel->value);

    // Its counsellor addresses chat messages to this id.
    expect(presentFor345(new TherapyResource($therapy), $counsellor->user)['user']['id'])->toBe($client->id);
    expect(presentFor345(new TherapyResource($therapy), $client)['user']['id'])->toBe($client->id);
});

test('an anonymous group\'s creator id is never sent to anyone else', function () {
    $creator = User::factory()->create();
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $creator->id, 'anonymous' => true, 'public' => true])->fresh();
    $viewer = User::factory()->create();

    expect(presentFor345(new GroupTherapyResource($group), $viewer)['addedby']['id'])->toBeNull();
    expect(presentFor345(new GroupTherapyMiniResource($group), $viewer)['addedby']['id'])->toBeNull();
    expect(presentFor345(new GroupTherapyResource($group), $creator)['addedby']['id'])->toBe($creator->id);
});

test('masked request parties carry no id', function () {
    $requester = User::factory()->create();
    $creator = User::factory()->create();
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $creator->id, 'anonymous' => true]);
    $membership = AppRequest::query()->forceCreate([
        'from_type' => User::class, 'from_id' => $requester->id,
        'to_type' => User::class, 'to_id' => $creator->id,
        'for_type' => GroupTherapy::class, 'for_id' => $group->id,
        'type' => RequestTypeEnum::groupTherapyMembership->value, 'status' => 'PENDING', 'data' => [],
    ])->fresh();

    $toCreator = presentFor345(new RequestResource($membership), $creator);
    expect($toCreator['from']['id'])->toBeNull()
        ->and($toCreator['from']['fullName'])->toBe(ConstantsEnum::anonymousUserLabel->value);
    expect(presentFor345(new RequestResource($membership), $requester)['to']['id'])->toBeNull();
    // Each still sees their own id.
    expect(presentFor345(new RequestResource($membership), $requester)['from']['id'])->toBe($requester->id);

    $client = User::factory()->create();
    $counsellor = aCounsellorFor345();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'anonymous' => true]);
    $offer = AppRequest::query()->forceCreate([
        'from_type' => Counsellor::class, 'from_id' => $counsellor->id,
        'to_type' => User::class, 'to_id' => $client->id,
        'for_type' => Therapy::class, 'for_id' => $therapy->id,
        'type' => RequestTypeEnum::therapy->value, 'status' => 'PENDING', 'data' => [],
    ])->fresh();

    expect(presentFor345(new RequestResource($offer), $counsellor->user)['to']['id'])->toBeNull();
});

test('a session never carries an anonymous member\'s id to anyone else', function () {
    $member = User::factory()->create();
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()->create()->id, 'anonymous' => true]);
    $group->users()->attach($member->id, ['anonymous' => true]);
    $session = Session::factory()->create([
        'for_id' => $group->id, 'for_type' => GroupTherapy::class,
        'updatedby_type' => User::class, 'updatedby_id' => $member->id,
    ])->fresh();

    expect(presentFor345(new SessionResource($session), User::factory()->create())['updatedById'])->toBeNull();
    expect(presentFor345(new SessionResource($session), $member)['updatedById'])->toBe($member->id);
});
