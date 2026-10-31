<?php

use App\Http\Resources\TherapyResource;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Message;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Http\Request;

// SCRUM-348: in an individual therapy session the server picks the recipient (the other party), so
// an anonymous client's real id is never sent to anyone -- their own counsellor included.

function anAnonymousTherapySessionFor348(): array
{
    $client = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id, 'anonymous' => true,
    ]);
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class, 'status' => 'IN_SESSION']);

    return [$therapy->fresh(), $session, $client, $counsellor];
}

test('the counsellor never receives the anonymous client\'s id', function () {
    [$therapy, , $client, $counsellor] = anAnonymousTherapySessionFor348();

    $asCounsellor = (new TherapyResource($therapy))->toArray(Request::create('/')->setUserResolver(fn () => $counsellor->user));
    expect(json_decode(json_encode($asCounsellor), true)['user']['id'])->toBeNull();

    $asClient = (new TherapyResource($therapy))->toArray(Request::create('/')->setUserResolver(fn () => $client));
    expect(json_decode(json_encode($asClient), true)['user']['id'])->toBe($client->id);
});

test('a counsellor\'s message reaches the client without the client\'s id being sent', function () {
    [, $session, $client, $counsellor] = anAnonymousTherapySessionFor348();

    $this->actingAs($counsellor->user)->postJson(route('api.messages.create'), [
        'content' => 'How are you today?', 'type' => 'NORMAL',
        'fromType' => 'Counsellor', 'fromId' => $counsellor->id,
        'toType' => 'User', 'toId' => null,
        'forType' => 'Session', 'forId' => $session->id,
    ])->assertSuccessful();

    $message = Message::query()->latest('id')->first();
    expect($message->to_type)->toBe(User::class)->and($message->to_id)->toBe($client->id);
});

test('a client-sent recipient is ignored: the message always goes to the other party', function () {
    [, $session, $client, $counsellor] = anAnonymousTherapySessionFor348();
    $someoneElse = User::factory()->create();

    $this->actingAs($client)->postJson(route('api.messages.create'), [
        'content' => 'Hello', 'type' => 'NORMAL',
        'fromType' => 'User', 'fromId' => $client->id,
        'toType' => 'User', 'toId' => $someoneElse->id,
        'forType' => 'Session', 'forId' => $session->id,
    ])->assertSuccessful();

    $message = Message::query()->latest('id')->first();
    expect($message->to_type)->toBe(Counsellor::class)->and($message->to_id)->toBe($counsellor->id);
});

test('a party using their other account in a therapy session is refused, so ids can\'t be guessed', function () {
    [, $session, $client, $counsellor] = anAnonymousTherapySessionFor348();

    // The counsellor posting as their User account with a guessed client id: right or wrong, refused.
    foreach ([$client->id, User::factory()->create()->id] as $guess) {
        $this->actingAs($counsellor->user)->postJson(route('api.messages.create'), [
            'content' => 'Guess', 'type' => 'NORMAL',
            'fromType' => 'User', 'fromId' => $counsellor->user->id,
            'toType' => 'User', 'toId' => $guess,
            'forType' => 'Session', 'forId' => $session->id,
        ])->assertStatus(422)->assertJsonPath('message', 'You are not allowed to create a message for this session.');
    }

    // The client posting through a counsellor profile of their own.
    $clientCounsellor = Counsellor::factory()->create(['user_id' => $client->id]);
    $this->actingAs($client)->postJson(route('api.messages.create'), [
        'content' => 'Hi', 'type' => 'NORMAL',
        'fromType' => 'Counsellor', 'fromId' => $clientCounsellor->id,
        'toType' => 'Counsellor', 'toId' => $counsellor->id,
        'forType' => 'Session', 'forId' => $session->id,
    ])->assertStatus(422);

    expect(Message::query()->count())->toBe(0);
});

test('a counsellor\'s report on their anonymous client never hands back the client\'s id', function () {
    [$therapy, , $client, $counsellor] = anAnonymousTherapySessionFor348();
    $payload = fn (array $data = []) => [
        'description' => 'Concern', 'reportableType' => 'therapy', 'reportableId' => $therapy->id,
        'addedbyType' => 'user', 'addedbyId' => $counsellor->user->id, 'data' => $data,
    ];

    $created = $this->actingAs($counsellor->user)->postJson(route('api.reports.create'), $payload())->assertOk();
    expect($created->json('report.data.userId'))->toBeNull()
        ->and($created->json('report.data.reportedClient'))->toBeTrue()
        ->and($created->getContent())->not->toContain('"userId":'.$client->id);
    expect($this->actingAs($counsellor->user)->getJson(route('api.reports'))->json('data.0.data.userId'))->toBeNull();

    // Admins see who it is.
    $admin = User::factory()->has(Administrator::factory())->create();
    expect($this->actingAs($admin)->getJson(route('api.reports.get', ['reportId' => $created->json('report.id')]))->json('report.data.userId'))->toBe($client->id);

    // Naming the anonymous client by a guessed id: the same refusal whether the guess is right or wrong.
    $right = $this->actingAs($counsellor->user)->postJson(route('api.reports.create'), $payload(['userId' => $client->id]));
    $wrong = $this->actingAs($counsellor->user)->postJson(route('api.reports.create'), $payload(['userId' => User::factory()->create()->id]));
    expect($right->status())->toBe(422)->and($right->json('message'))->toBe($wrong->json('message'));
});

test('group session messages still keep the recipient the sender chose', function () {
    $creator = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $creator->id, 'anonymous' => false]);
    $group->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $session = Session::factory()->create(['for_id' => $group->id, 'for_type' => GroupTherapy::class, 'status' => 'IN_SESSION']);

    $this->actingAs($creator)->postJson(route('api.messages.create'), [
        'content' => 'To the counsellor', 'type' => 'NORMAL',
        'fromType' => 'User', 'fromId' => $creator->id,
        'toType' => 'Counsellor', 'toId' => $counsellor->id,
        'forType' => 'Session', 'forId' => $session->id,
    ])->assertSuccessful();

    expect(Message::query()->latest('id')->first()->to_id)->toBe($counsellor->id);
});
