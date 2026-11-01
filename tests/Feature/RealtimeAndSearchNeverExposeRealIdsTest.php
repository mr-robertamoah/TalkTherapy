<?php

use App\Http\Resources\UserMiniResource;
use App\Http\Resources\UserResource;
use App\Models\Administrator;
use App\Models\Session;
use App\Models\User;
use App\Models\VideoSession;
use App\Support\VideoParticipantKey;
use Illuminate\Http\Request;

// SCRUM-357: the identities other people see in realtime are opaque, and the user search no longer
// hands out dates of birth. (Event payloads, provider identities and presence channel returns are
// covered in their own test files, updated in the same PR.)

function resolveFor357($resource, ?User $viewer): array
{
    return $resource->resolve(Request::create('/')->setUserResolver(fn () => $viewer));
}

test('a video participant key is per session: it never equals the user id, and differs across sessions', function () {
    $user = User::factory()->create();
    [$sessionA, $sessionB] = Session::factory()->count(2)->create();

    $keyA = VideoParticipantKey::for($sessionA->id, $user->id);
    $keyB = VideoParticipantKey::for($sessionB->id, $user->id);

    expect($keyA)->not->toBe((string) $user->id)
        ->and($keyA)->not->toBe($keyB)
        ->and(strlen($keyA))->toBe(24)
        ->and(VideoParticipantKey::for($sessionA->id, $user->id))->toBe($keyA);
});

test('a key resolves only for someone who joined that session\'s video; forged or foreign keys resolve to nobody', function () {
    $user = User::factory()->create();
    $session = Session::factory()->create();
    $videoSession = VideoSession::factory()->create(['session_id' => $session->id]);
    $videoSession->participants()->forceCreate(['participant_type' => User::class, 'participant_id' => $user->id, 'joined_at' => now()]);
    $otherSession = Session::factory()->create();

    expect(VideoParticipantKey::resolve($session, VideoParticipantKey::for($session->id, $user->id)))->toBe($user->id)
        ->and(VideoParticipantKey::resolve($session, VideoParticipantKey::for($otherSession->id, $user->id)))->toBeNull()
        ->and(VideoParticipantKey::resolve($session, (string) $user->id))->toBeNull()
        ->and(VideoParticipantKey::resolve($session, 'forged-key'))->toBeNull();
});

test('the presence identity is not the user id, and differs per channel so nobody can be linked across them', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $inGroupA = $user->broadcastKeyFor('presence-groupTherapies.1');
    $inGroupB = $user->broadcastKeyFor('presence-groupTherapies.2');

    expect($inGroupA)->not->toBe((string) $user->id)
        ->and($inGroupA)->not->toBe($inGroupB)
        ->and($inGroupA)->not->toBe($other->broadcastKeyFor('presence-groupTherapies.1'))
        ->and($user->broadcastKeyFor('presence-groupTherapies.1'))->toBe($inGroupA);

    // Inside /broadcasting/auth the broadcaster asks for the key of the channel being joined.
    request()->merge(['channel_name' => 'presence-groupTherapies.2']);
    expect($user->getAuthIdentifierForBroadcasting())->toBe($inGroupB);

    // Nothing ships a key to the browser any more -- it reads its own from the channel.
    expect(resolveFor357(new UserResource($user), $user))->not->toHaveKey('broadcastKey');
});

test('a user\'s date of birth is only for themselves and admins -- never in someone else\'s search results', function () {
    $user = User::factory()->create(['dob' => '1990-04-12']);
    $stranger = User::factory()->create();
    $admin = User::factory()->has(Administrator::factory())->create();

    expect(resolveFor357(new UserMiniResource($user), $stranger))->not->toHaveKey('dob')
        ->and(resolveFor357(new UserMiniResource($user), null))->not->toHaveKey('dob')
        ->and(resolveFor357(new UserMiniResource($user), $user))->toHaveKey('dob')
        ->and(resolveFor357(new UserMiniResource($user), $admin))->toHaveKey('dob');

    $this->actingAs($stranger)->getJson(route('api.users', ['like' => $user->username]))
        ->assertOk()
        ->assertJsonPath('data.0.id', $user->id)
        ->assertJsonMissingPath('data.0.dob');
});
