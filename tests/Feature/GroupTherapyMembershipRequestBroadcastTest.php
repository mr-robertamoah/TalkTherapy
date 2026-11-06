<?php

use App\Enums\ConstantsEnum;
use App\Enums\TherapyStatusEnum;
use App\Events\GroupTherapyMembershipRequestReceivedEvent;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Request;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

// SCRUM-386 item 5: a new membership request reaches the group's creator live, on their own user
// channel, with ids only. Helpers are file-local and gmr-prefixed (--parallel).

function gmrGroup(array $attributes = [], ?Counsellor $byCounsellor = null): array
{
    $creator = $byCounsellor?->user ?? User::factory()->adult()->create();
    $group = GroupTherapy::factory()->create(array_merge([
        'addedby_type' => $byCounsellor ? Counsellor::class : User::class,
        'addedby_id' => $byCounsellor?->id ?? $creator->id,
        'anonymous' => false, 'public' => true, 'allow_anyone' => false, 'max_users' => 5,
        'status' => TherapyStatusEnum::in_session->value,
    ], $attributes));

    return [$group, $creator];
}

function gmrAskToJoin($test, User $requester, GroupTherapy $group, array $body = [])
{
    return $test->actingAs($requester)->postJson(route('api.group.therapies.join', ['groupTherapyId' => $group->id]), $body)->assertOk();
}

beforeEach(function () {
    Notification::fake();
});

test('a membership request is broadcast to the group creator\'s own channel only, as ids', function () {
    Event::fake([GroupTherapyMembershipRequestReceivedEvent::class]);
    [$group, $creator] = gmrGroup();
    $requester = User::factory()->adult()->create(['firstName' => 'Visible', 'lastName' => 'Requester']);

    gmrAskToJoin($this, $requester, $group);
    $request = Request::query()->whereFrom($requester)->firstOrFail();

    Event::assertDispatchedTimes(GroupTherapyMembershipRequestReceivedEvent::class, 1);
    Event::assertDispatched(GroupTherapyMembershipRequestReceivedEvent::class, function ($event) use ($creator, $group, $request) {
        $channels = collect($event->broadcastOn())->map(fn ($channel) => $channel->name)->all();

        return $channels === ["private-users.{$creator->id}"]
            && $event->broadcastAs() === 'group-therapy.membership-requested'
            && $event->broadcastWith() === ['groupTherapyId' => $group->id, 'requestId' => $request->id];
    });
});

test('for a group a counsellor created, it goes to the counsellor\'s user', function () {
    Event::fake([GroupTherapyMembershipRequestReceivedEvent::class]);
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id, 'verified_at' => now()]);
    [$group] = gmrGroup(byCounsellor: $counsellor);

    gmrAskToJoin($this, User::factory()->adult()->create(), $group);

    Event::assertDispatched(GroupTherapyMembershipRequestReceivedEvent::class, fn ($event) => collect($event->broadcastOn())->map(fn ($channel) => $channel->name)->all() === ["private-users.{$counsellor->user_id}"]);
});

test('a former member asking to join again is broadcast too', function () {
    Event::fake([GroupTherapyMembershipRequestReceivedEvent::class]);
    [$group] = gmrGroup();
    $former = User::factory()->adult()->create();
    $group->allMemberships()->attach($former->id, ['anonymous' => false, 'was_minor_at_join' => false, 'left_at' => now()->subDay()]);

    gmrAskToJoin($this, $former, $group);

    Event::assertDispatchedTimes(GroupTherapyMembershipRequestReceivedEvent::class, 1);
});

test('joining a group that lets anyone in sends no membership request broadcast', function () {
    Event::fake([GroupTherapyMembershipRequestReceivedEvent::class]);
    [$group] = gmrGroup(['allow_anyone' => true]);

    gmrAskToJoin($this, User::factory()->adult()->create(), $group);

    Event::assertNotDispatched(GroupTherapyMembershipRequestReceivedEvent::class);
});

test('an anonymous requester stays masked: the broadcast carries no identity, and the creator\'s refetch shows them as Anonymous', function () {
    Event::fake([GroupTherapyMembershipRequestReceivedEvent::class]);
    [$group, $creator] = gmrGroup();
    $requester = User::factory()->adult()->create(['firstName' => 'Hidden', 'lastName' => 'Requester', 'username' => 'hidden_requester_gmr']);

    gmrAskToJoin($this, $requester, $group, ['anonymous' => true]);

    Event::assertDispatched(GroupTherapyMembershipRequestReceivedEvent::class, function ($event) use ($requester) {
        $payload = json_encode($event->broadcastWith());

        return array_keys($event->broadcastWith()) === ['groupTherapyId', 'requestId']
            && ! str_contains($payload, 'Hidden')
            && ! str_contains($payload, $requester->username);
    });

    // What the creator's page refetches on the broadcast (the pendingMembershipRequest prop).
    $response = $this->actingAs($creator)->get(route('group.therapies.get', ['groupTherapyId' => $group->id]))->assertOk();
    $from = json_decode(json_encode($response->viewData('page')['props']['pendingMembershipRequest']), true)['from'];

    expect($from)->toBe(['id' => null, 'fullName' => ConstantsEnum::anonymousUserLabel->value, 'isUser' => true]);
    $response->assertDontSee('Hidden')->assertDontSee($requester->username);
});

test('the broadcast is a best-effort step: the request is still made when it fails', function () {
    Event::listen(GroupTherapyMembershipRequestReceivedEvent::class, fn () => throw new RuntimeException('Broadcaster down.'));
    [$group] = gmrGroup();
    $requester = User::factory()->adult()->create();

    gmrAskToJoin($this, $requester, $group);

    expect(Request::query()->whereFrom($requester)->exists())->toBeTrue();
});
