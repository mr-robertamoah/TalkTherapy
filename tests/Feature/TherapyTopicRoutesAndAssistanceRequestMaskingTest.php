<?php

use App\Enums\ConstantsEnum;
use App\Enums\RequestTypeEnum;
use App\Http\Resources\RequestResource;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Request as TherapyRequest;
use App\Models\Therapy;
use App\Models\TherapyTopic;
use App\Models\User;
use Illuminate\Http\Request;

// SCRUM-344 (security review): topic create/update were broken end to end (a validation rule and a
// relation both still used the dropped therapies.therapy_id), and RequestResource named an
// anonymous client on their own therapy's assistance requests.

function counselledTherapyForTopics344(bool $anonymous = false): array
{
    $client = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id,
        'anonymous' => $anonymous,
    ]);

    return [$therapy->fresh(), $client, $counsellor];
}

test('the therapy\'s counsellor can create and then update a topic over HTTP', function () {
    [$therapy, , $counsellor] = counselledTherapyForTopics344();

    $created = $this->actingAs($counsellor->user)
        ->postJson(route('api.topics.create', ['therapyId' => $therapy->id]), ['name' => 'Sleep', 'description' => 'Trouble sleeping'])
        ->assertOk();

    $topicId = $created->json('topic.id');
    expect(TherapyTopic::find($topicId)?->topicable?->is($therapy))->toBeTrue();

    $this->actingAs($counsellor->user)
        ->patchJson(route('api.topics.update', ['topicId' => $topicId]), ['name' => 'Rest', 'description' => 'Trouble resting'])
        ->assertOk();

    expect(TherapyTopic::find($topicId)->name)->toBe('Rest');
});

test('an outsider creating a topic on someone else\'s therapy is refused without a name', function () {
    [$therapy] = counselledTherapyForTopics344();
    $outsider = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);

    $response = $this->actingAs($outsider->user)
        ->postJson(route('api.topics.create', ['therapyId' => $therapy->id]), ['name' => 'Sleep', 'description' => 'x']);

    expect($response->status())->not->toBe(200)
        ->and($response->getContent())->not->toContain($therapy->name);
    expect(TherapyTopic::query()->where('topicable_id', $therapy->id)->exists())->toBeFalse();
});

test('an assistance request never names an anonymous client to the counsellor', function () {
    [$therapy, $client, $counsellor] = counselledTherapyForTopics344(anonymous: true);
    $request = TherapyRequest::query()->forceCreate([
        'from_type' => Counsellor::class,
        'from_id' => $counsellor->id,
        'to_type' => User::class,
        'to_id' => $client->id,
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'type' => RequestTypeEnum::therapy->value,
        'status' => 'PENDING',
        'data' => [],
    ]);

    $present = fn (User $viewer) => json_decode(json_encode(
        (new RequestResource($request->fresh()))->toArray(Request::create('/')->setUserResolver(fn () => $viewer))
    ), true);

    $toCounsellor = $present($counsellor->user);
    expect($toCounsellor['to']['fullName'])->toBe(ConstantsEnum::anonymousUserLabel->value)
        ->and(json_encode($toCounsellor))->not->toContain($client->email);

    // The client still sees themselves.
    expect($present($client)['to']['fullName'])->not->toBe(ConstantsEnum::anonymousUserLabel->value);
});

test('a group therapy topic can be updated by its counsellor', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
    $group = GroupTherapy::factory()->create();
    $topic = TherapyTopic::query()->forceCreate([
        'counsellor_id' => $counsellor->id,
        'topicable_id' => $group->id,
        'topicable_type' => GroupTherapy::class,
        'name' => 'Grief',
    ]);

    $this->actingAs($counsellor->user)
        ->patchJson(route('api.topics.update', ['topicId' => $topic->id]), ['name' => 'Loss', 'description' => 'Coping with loss'])
        ->assertOk();

    expect($topic->fresh()->name)->toBe('Loss');
});
