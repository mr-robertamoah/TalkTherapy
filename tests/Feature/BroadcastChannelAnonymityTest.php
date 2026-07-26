<?php

use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// routes/channels.php has no existing test coverage of its own, so per the SCRUM-71 spec these
// call the registered presence-channel closures directly (via reflection into the Broadcaster's
// protected $channels map) rather than round-tripping through the actual broadcasting/auth HTTP
// endpoint and a real Pusher/Reverb signature.
function broadcastChannelCallback(string $pattern): Closure
{
    $broadcaster = Broadcast::connection();
    $channels = (function () {
        return $this->channels;
    })->call($broadcaster);

    expect($channels)->toHaveKey($pattern);

    return $channels[$pattern];
}

test('individual therapy channel masks the client\'s own name when the therapy is anonymous', function () {
    $client = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id,
        'anonymous' => true,
    ]);

    $callback = broadcastChannelCallback('therapies.{therapyId}');

    $result = $callback($client, $therapy->id);

    expect($result)->toBe(['id' => $client->id, 'name' => 'Client (Anonymous User)']);
});

test('individual therapy channel never masks the counsellor\'s own name', function () {
    $client = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id,
        'anonymous' => true,
    ]);

    $callback = broadcastChannelCallback('therapies.{therapyId}');

    $result = $callback($counsellorUser, $therapy->id);

    expect($result)->toBe(['id' => $counsellorUser->id, 'name' => $counsellorUser->name]);
});

test('group therapy channel masks a member whose own pivot is anonymous, even though the group is not', function () {
    $member = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    // isParticipant() (checked before masking) only recognizes the group's own addedby (or an
    // assigned counsellor) as a participant, so the member under test must be the creator here.
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $member->id,
        'anonymous' => false,
    ]);
    $groupTherapy->users()->attach($member->id, ['anonymous' => true]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);

    $callback = broadcastChannelCallback('groupTherapies.{groupTherapyId}');

    $result = $callback($member, $groupTherapy->id);

    expect($result)->toBe(['id' => $member->id, 'name' => 'Client (Anonymous User)']);
});

test('group therapy channel masks every member when the group-level flag is anonymous (the OR branch the old bug missed)', function () {
    $member = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $member->id,
        'anonymous' => true,
    ]);
    $groupTherapy->users()->attach($member->id, ['anonymous' => false]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);

    $callback = broadcastChannelCallback('groupTherapies.{groupTherapyId}');

    $result = $callback($member, $groupTherapy->id);

    expect($result)->toBe(['id' => $member->id, 'name' => 'Client (Anonymous User)']);
});

test('group therapy channel never masks a counsellor\'s own name, even when the group is anonymous', function () {
    $member = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $member->id,
        'anonymous' => true,
    ]);
    $groupTherapy->users()->attach($member->id, ['anonymous' => false]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);

    $callback = broadcastChannelCallback('groupTherapies.{groupTherapyId}');

    $result = $callback($counsellorUser, $groupTherapy->id);

    expect($result)->toBe(['id' => $counsellorUser->id, 'name' => $counsellorUser->name]);
});

test('group therapy channel does not mask a member when neither the group nor their own pivot is anonymous', function () {
    $member = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    // isParticipant() (checked before masking) only recognizes the group's own addedby (or an
    // assigned counsellor) as a participant, so the member under test must be the creator here.
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $member->id,
        'anonymous' => false,
    ]);
    $groupTherapy->users()->attach($member->id, ['anonymous' => false]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);

    $callback = broadcastChannelCallback('groupTherapies.{groupTherapyId}');

    $result = $callback($member, $groupTherapy->id);

    expect($result)->toBe(['id' => $member->id, 'name' => $member->name]);
});
