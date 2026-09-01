<?php

use App\Enums\SessionStatusEnum;
use App\Events\MessageDeletedEvent;
use App\Events\MessageSentEvent;
use App\Events\MessageUpdatedEvent;
use App\Events\SessionStartedEvent;
use App\Events\SessionTopicSetEvent;
use App\Events\SessionTopicUnsetEvent;
use App\Events\SessionUpdatedEvent;
use App\Http\Resources\SessionResource;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\SessionNote;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

// SCRUM-197/TT-2.2b: authorization + CRUD for a counsellor's private session notes. AC7 (cross-
// counsellor isolation on a shared GroupTherapy session) is the single most important test case
// here per product-owner review -- see "a counsellor cannot see..." below.

function aCounsellorForSessionNotesRoute(): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()]);
}

function aSessionForSessionNotesRoute(Counsellor $counsellor, array $sessionAttributes = []): Session
{
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'counsellor_id' => $counsellor->id,
    ]);

    return Session::factory()->create(array_merge([
        'for_id' => $therapy->id,
        'for_type' => Therapy::class,
    ], $sessionAttributes));
}

function aGroupSessionForSessionNotesRoute(array $counsellors, array $sessionAttributes = []): Session
{
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
    ]);

    foreach ($counsellors as $counsellor) {
        $groupTherapy->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    }

    return Session::factory()->create(array_merge([
        'for_id' => $groupTherapy->id,
        'for_type' => GroupTherapy::class,
    ], $sessionAttributes));
}

test('a counsellor can create a note on their own in-session therapy session', function () {
    $counsellor = aCounsellorForSessionNotesRoute();
    $session = aSessionForSessionNotesRoute($counsellor);

    $response = $this
        ->actingAs($counsellor->user)
        ->post(route('session.notes.store', ['sessionId' => $session->id]), [
            'content' => 'Client presented as withdrawn today.',
        ]);

    $response->assertOk();
    $response->assertJsonPath('note.content', 'Client presented as withdrawn today.');
    $this->assertDatabaseHas('session_notes', [
        'session_id' => $session->id,
        'counsellor_id' => $counsellor->id,
        'content' => 'Client presented as withdrawn today.',
    ]);
});

test('a counsellor can list only their own notes for a session', function () {
    $counsellor = aCounsellorForSessionNotesRoute();
    $session = aSessionForSessionNotesRoute($counsellor);

    SessionNote::factory()->count(2)->create([
        'session_id' => $session->id,
        'counsellor_id' => $counsellor->id,
    ]);

    $response = $this
        ->actingAs($counsellor->user)
        ->get(route('session.notes.index', ['sessionId' => $session->id]));

    $response->assertOk();
    expect($response->json('notes'))->toHaveCount(2);
});

test('a counsellor cannot see another counsellor notes on a shared group therapy session', function () {
    $counsellorA = aCounsellorForSessionNotesRoute();
    $counsellorB = aCounsellorForSessionNotesRoute();
    $session = aGroupSessionForSessionNotesRoute([$counsellorA, $counsellorB]);

    $noteA = SessionNote::factory()->create([
        'session_id' => $session->id,
        'counsellor_id' => $counsellorA->id,
    ]);

    $listResponse = $this
        ->actingAs($counsellorB->user)
        ->get(route('session.notes.index', ['sessionId' => $session->id]));

    $listResponse->assertOk();
    expect($listResponse->json('notes'))->toHaveCount(0);

    $updateResponse = $this
        ->actingAs($counsellorB->user)
        ->patch(route('session.notes.update', ['noteId' => $noteA->id]), [
            'content' => 'attempted takeover',
        ]);

    $updateResponse->assertStatus(422);
    $this->assertDatabaseHas('session_notes', ['id' => $noteA->id, 'content' => $noteA->content]);

    $deleteResponse = $this
        ->actingAs($counsellorB->user)
        ->delete(route('session.notes.destroy', ['noteId' => $noteA->id]));

    $deleteResponse->assertStatus(422);
    $this->assertDatabaseHas('session_notes', ['id' => $noteA->id, 'deleted_at' => null]);
});

test('a counsellor not assigned to the session is rejected on every operation', function () {
    $assignedCounsellor = aCounsellorForSessionNotesRoute();
    $outsiderCounsellor = aCounsellorForSessionNotesRoute();
    $session = aSessionForSessionNotesRoute($assignedCounsellor);

    $this->actingAs($outsiderCounsellor->user)
        ->post(route('session.notes.store', ['sessionId' => $session->id]), ['content' => 'x'])
        ->assertStatus(422);

    $this->actingAs($outsiderCounsellor->user)
        ->get(route('session.notes.index', ['sessionId' => $session->id]))
        ->assertStatus(422);
});

test('the client on the therapy can never create, list, update, or delete a session note', function () {
    $counsellor = aCounsellorForSessionNotesRoute();
    $client = User::factory()->create();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id,
    ]);
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);
    $note = SessionNote::factory()->create(['session_id' => $session->id, 'counsellor_id' => $counsellor->id]);

    $this->actingAs($client)
        ->post(route('session.notes.store', ['sessionId' => $session->id]), ['content' => 'x'])
        ->assertStatus(422);

    $this->actingAs($client)
        ->get(route('session.notes.index', ['sessionId' => $session->id]))
        ->assertStatus(422);

    $this->actingAs($client)
        ->patch(route('session.notes.update', ['noteId' => $note->id]), ['content' => 'x'])
        ->assertStatus(422);

    $this->actingAs($client)
        ->delete(route('session.notes.destroy', ['noteId' => $note->id]))
        ->assertStatus(422);
});

test('creating a note is rejected when the session is not in progress', function () {
    $counsellor = aCounsellorForSessionNotesRoute();
    $session = aSessionForSessionNotesRoute($counsellor, ['status' => SessionStatusEnum::pending->value]);

    $response = $this
        ->actingAs($counsellor->user)
        ->post(route('session.notes.store', ['sessionId' => $session->id]), ['content' => 'x']);

    $response->assertStatus(422);
    $this->assertDatabaseMissing('session_notes', ['session_id' => $session->id]);
});

test('the author can update and delete their own note while the session is live', function () {
    $counsellor = aCounsellorForSessionNotesRoute();
    $session = aSessionForSessionNotesRoute($counsellor);
    $note = SessionNote::factory()->create(['session_id' => $session->id, 'counsellor_id' => $counsellor->id]);

    $this->actingAs($counsellor->user)
        ->patch(route('session.notes.update', ['noteId' => $note->id]), ['content' => 'updated'])
        ->assertOk()
        ->assertJsonPath('note.content', 'updated');

    $this->actingAs($counsellor->user)
        ->delete(route('session.notes.destroy', ['noteId' => $note->id]))
        ->assertOk();

    $this->assertSoftDeleted('session_notes', ['id' => $note->id]);
});

test('the author can still edit their note within the configurable grace window after the session ends', function () {
    $counsellor = aCounsellorForSessionNotesRoute();
    $session = aSessionForSessionNotesRoute($counsellor, ['status' => SessionStatusEnum::held->value]);
    DB::table('sessions')->where('id', $session->id)->update(['ended_at' => now()->subMinutes(10)]);
    $note = SessionNote::factory()->create(['session_id' => $session->id, 'counsellor_id' => $counsellor->id]);

    $response = $this
        ->actingAs($counsellor->user)
        ->patch(route('session.notes.update', ['noteId' => $note->id]), ['content' => 'still within grace window']);

    $response->assertOk();
});

test('the author can no longer edit or delete their note once the grace window has elapsed', function () {
    $counsellor = aCounsellorForSessionNotesRoute();
    $session = aSessionForSessionNotesRoute($counsellor, ['status' => SessionStatusEnum::held->value]);
    DB::table('sessions')->where('id', $session->id)->update([
        'ended_at' => now()->subMinutes(config('session-notes.edit_grace_minutes') + 5),
    ]);
    $note = SessionNote::factory()->create(['session_id' => $session->id, 'counsellor_id' => $counsellor->id]);

    $this->actingAs($counsellor->user)
        ->patch(route('session.notes.update', ['noteId' => $note->id]), ['content' => 'too late'])
        ->assertStatus(422);

    $this->actingAs($counsellor->user)
        ->delete(route('session.notes.destroy', ['noteId' => $note->id]))
        ->assertStatus(422);

    $this->assertDatabaseHas('session_notes', ['id' => $note->id, 'content' => $note->content, 'deleted_at' => null]);
});

test('replaying a session status-transition endpoint does not reset or extend the note edit grace window', function () {
    // security-engineer finding (SCRUM-197): /sessions/{id}/abandon (and /end, /fail,
    // /in_session) have no idempotency guard against an already-terminal session, so if the
    // grace window were still derived from Session::updated_at, replaying one of these would
    // silently reopen editing on a note that should already be permanently locked. ended_at
    // (ChangeSessionStatusAction) is set exactly once and must never move on a replay.
    $counsellor = aCounsellorForSessionNotesRoute();
    $session = aSessionForSessionNotesRoute($counsellor);

    $this->actingAs($counsellor->user)
        ->post(route('sessions.abandon', ['sessionId' => $session->id]))
        ->assertOk();

    $session->refresh();
    expect($session->status)->toBe(SessionStatusEnum::abandoned->value);
    expect($session->ended_at)->not->toBeNull();

    $note = SessionNote::factory()->create(['session_id' => $session->id, 'counsellor_id' => $counsellor->id]);

    $backdatedEndedAt = now()->subMinutes(config('session-notes.edit_grace_minutes') + 5);
    DB::table('sessions')->where('id', $session->id)->update(['ended_at' => $backdatedEndedAt]);

    // Replay the same endpoint on the already-abandoned session -- must not move ended_at.
    $this->actingAs($counsellor->user)
        ->post(route('sessions.abandon', ['sessionId' => $session->id]))
        ->assertOk();

    expect($session->fresh()->ended_at->toDateTimeString())->toBe($backdatedEndedAt->toDateTimeString());

    $this->actingAs($counsellor->user)
        ->patch(route('session.notes.update', ['noteId' => $note->id]), ['content' => 'sneaking an edit in via replay'])
        ->assertStatus(422);
});

test('a note remains listable by its author indefinitely, even once no longer editable', function () {
    $counsellor = aCounsellorForSessionNotesRoute();
    $session = aSessionForSessionNotesRoute($counsellor, ['status' => SessionStatusEnum::held->value]);
    DB::table('sessions')->where('id', $session->id)->update([
        'ended_at' => now()->subMinutes(config('session-notes.edit_grace_minutes') + 5),
    ]);
    SessionNote::factory()->create(['session_id' => $session->id, 'counsellor_id' => $counsellor->id]);

    $response = $this
        ->actingAs($counsellor->user)
        ->get(route('session.notes.index', ['sessionId' => $session->id]));

    $response->assertOk();
    expect($response->json('notes'))->toHaveCount(1);
});

test('an unauthenticated request cannot reach any session note endpoint', function () {
    $counsellor = aCounsellorForSessionNotesRoute();
    $session = aSessionForSessionNotesRoute($counsellor);
    $note = SessionNote::factory()->create(['session_id' => $session->id, 'counsellor_id' => $counsellor->id]);

    $this->post(route('session.notes.store', ['sessionId' => $session->id]), ['content' => 'x'])
        ->assertRedirect(route('login'));

    $this->get(route('session.notes.index', ['sessionId' => $session->id]))
        ->assertRedirect(route('login'));

    $this->patch(route('session.notes.update', ['noteId' => $note->id]), ['content' => 'x'])
        ->assertRedirect(route('login'));

    $this->delete(route('session.notes.destroy', ['noteId' => $note->id]))
        ->assertRedirect(route('login'));
});

// SCRUM-198/TT-2.2c: negative-path coverage -- a session note must never be broadcast over any
// Reverb channel, nor exposed via SessionResource. Both are absence assertions, easy to violate
// silently, so they're asserted explicitly rather than trusted by omission.

test('creating, updating, and deleting a session note broadcasts no events at all', function () {
    Event::fake();

    $counsellor = aCounsellorForSessionNotesRoute();
    $session = aSessionForSessionNotesRoute($counsellor);

    $this->actingAs($counsellor->user)
        ->post(route('session.notes.store', ['sessionId' => $session->id]), ['content' => 'private observation']);

    $note = SessionNote::first();

    $this->actingAs($counsellor->user)
        ->patch(route('session.notes.update', ['noteId' => $note->id]), ['content' => 'updated observation']);

    $this->actingAs($counsellor->user)
        ->delete(route('session.notes.destroy', ['noteId' => $note->id]));

    Event::assertNotDispatched(SessionUpdatedEvent::class);
    Event::assertNotDispatched(SessionStartedEvent::class);
    Event::assertNotDispatched(SessionTopicSetEvent::class);
    Event::assertNotDispatched(SessionTopicUnsetEvent::class);
    Event::assertNotDispatched(MessageSentEvent::class);
    Event::assertNotDispatched(MessageUpdatedEvent::class);
    Event::assertNotDispatched(MessageDeletedEvent::class);
});

test('SessionResource never exposes session notes or the ended_at column', function () {
    $counsellor = aCounsellorForSessionNotesRoute();
    $session = aSessionForSessionNotesRoute($counsellor, ['status' => SessionStatusEnum::held->value]);
    DB::table('sessions')->where('id', $session->id)->update(['ended_at' => now()]);
    SessionNote::factory()->create(['session_id' => $session->id, 'counsellor_id' => $counsellor->id]);

    $array = (new SessionResource($session->fresh()))->toArray(request());

    expect($array)->not->toHaveKeys(['notes', 'sessionNotes', 'endedAt', 'ended_at']);
});

// SCRUM-198/TT-2.2c: the Vue UI calls these same endpoints via axios against their api.php
// registration (auth:sanctum, matching how this component already fetches session messages),
// not the web.php ones the tests above exercise -- both point at the identical
// SessionNoteController/Service/Action chain, so this is a wiring smoke test, not a re-run of
// the full authorization suite above.

test('a listed note reports isEditable matching the actual edit-window state', function () {
    $counsellor = aCounsellorForSessionNotesRoute();
    $liveSession = aSessionForSessionNotesRoute($counsellor);
    SessionNote::factory()->create(['session_id' => $liveSession->id, 'counsellor_id' => $counsellor->id]);

    $endedSession = aSessionForSessionNotesRoute($counsellor, ['status' => SessionStatusEnum::held->value]);
    DB::table('sessions')->where('id', $endedSession->id)->update([
        'ended_at' => now()->subMinutes(config('session-notes.edit_grace_minutes') + 5),
    ]);
    SessionNote::factory()->create(['session_id' => $endedSession->id, 'counsellor_id' => $counsellor->id]);

    $liveResponse = $this->actingAs($counsellor->user)
        ->get(route('session.notes.index', ['sessionId' => $liveSession->id]));
    expect($liveResponse->json('notes')[0]['isEditable'])->toBeTrue();

    $endedResponse = $this->actingAs($counsellor->user)
        ->get(route('session.notes.index', ['sessionId' => $endedSession->id]));
    expect($endedResponse->json('notes')[0]['isEditable'])->toBeFalse();
});

test('the api.php session notes routes are wired to the same authorized create/list flow', function () {
    $counsellor = aCounsellorForSessionNotesRoute();
    $session = aSessionForSessionNotesRoute($counsellor);

    $this->actingAs($counsellor->user)
        ->post(route('api.session.notes.store', ['sessionId' => $session->id]), ['content' => 'via api route'])
        ->assertOk()
        ->assertJsonPath('note.content', 'via api route');

    $response = $this->actingAs($counsellor->user)
        ->get(route('api.session.notes.index', ['sessionId' => $session->id]));

    $response->assertOk();
    expect($response->json('notes'))->toHaveCount(1);
});
