<?php

use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use App\Support\VideoParticipantKey;

// SCRUM-380: on every video route, a session id that doesn't exist gets exactly the answer someone
// who isn't in a real session gets -- the same status and body -- so the routes can't be used to
// learn which session ids exist. Remove, grant/revoke and raise-hand used to answer an outsider
// differently for a 1:1 and a group; an outsider now gets the group answer for both.

function vustSessions(): array
{
    $client = User::factory()->adult()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id,
    ]);
    $groupCounsellor = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
    $group = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory()->adult()->create()->id,
        'public' => true,
    ]);
    $group->counsellors()->attach($groupCounsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $live = ['type' => 'ONLINE', 'status' => 'IN_SESSION', 'start_time' => now()];

    return [
        'oneToOne' => Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class, ...$live]),
        'group' => Session::factory()->create(['for_id' => $group->id, 'for_type' => GroupTherapy::class, ...$live]),
        'counsellorUser' => $counsellor->user,
    ];
}

function vustCall($test, User $user, string $route, int $sessionId)
{
    $parameters = ['sessionId' => $sessionId];

    if (str_contains($route, 'participants.')) {
        $parameters['participantKey'] = VideoParticipantKey::for($sessionId, $user->id + 1);
    }

    $response = str_ends_with($route, '.show')
        ? $test->actingAs($user)->getJson(route($route, $parameters))
        : $test->actingAs($user)->postJson(route($route, $parameters));

    return [$response->status(), $response->json()];
}

test('an unknown session gets the same answer as a real session the caller is not in', function (string $route, int $status, array $body) {
    $sessions = vustSessions();
    $outsider = User::factory()->adult()->create();
    $unknownId = Session::max('id') + 1000;

    $unknown = vustCall($this, $outsider, $route, $unknownId);

    expect($unknown)->toBe([$status, $body])
        ->and(vustCall($this, $outsider, $route, $sessions['oneToOne']->id))->toBe($unknown)
        ->and(vustCall($this, $outsider, $route, $sessions['group']->id))->toBe($unknown);
})->with([
    'join' => ['sessions.video.join', 422, ['message' => 'You are not allowed to join this session.', 'videoConsentRequired' => false]],
    'leave' => ['sessions.video.leave', 422, ['message' => 'You are not allowed to leave this session\'s video call.', 'videoConsentRequired' => false]],
    'end' => ['sessions.video.end', 422, ['message' => 'You are not allowed to end this session\'s video call.', 'videoConsentRequired' => false]],
    'remove' => ['sessions.video.participants.remove', 422, ['message' => 'Only a counsellor may remove a participant from this group video call.', 'videoConsentRequired' => false]],
    'grant speaking' => ['sessions.video.participants.grant_speaking', 422, ['message' => 'Only a counsellor may change who can speak in this group video call.', 'videoConsentRequired' => false]],
    'revoke speaking' => ['sessions.video.participants.revoke_speaking', 422, ['message' => 'Only a counsellor may change who can speak in this group video call.', 'videoConsentRequired' => false]],
    'raise hand' => ['sessions.video.hand.raise', 422, ['message' => 'Join the video call before raising your hand.', 'videoConsentRequired' => false]],
    // Silent no-ops for anyone not in the call, so an unknown session is one too.
    'lower hand' => ['sessions.video.hand.lower', 200, ['message' => 'Hand lowered.']],
    'speaking activity' => ['sessions.video.speaking_activity', 200, ['message' => 'Activity recorded.']],
    // SCRUM-379 already answered this one with its 404.
    'participant name' => ['sessions.video.participants.show', 404, ['message' => 'That participant is not in this video call.', 'videoConsentRequired' => false]],
]);

test('a participant of a 1:1 still gets the 1:1 answer from the group-only routes', function (string $route, string $message) {
    $sessions = vustSessions();

    expect(vustCall($this, $sessions['counsellorUser'], $route, $sessions['oneToOne']->id))
        ->toBe([422, ['message' => $message, 'videoConsentRequired' => false]]);
})->with([
    'remove' => ['sessions.video.participants.remove', 'Removing a specific participant is only available for group therapy video calls.'],
    'grant speaking' => ['sessions.video.participants.grant_speaking', 'Speaking permission is only available for group therapy video calls.'],
    'revoke speaking' => ['sessions.video.participants.revoke_speaking', 'Speaking permission is only available for group therapy video calls.'],
    'raise hand' => ['sessions.video.hand.raise', 'Raising a hand is only available in group therapy video calls.'],
]);
