<?php

use App\Enums\SessionStatusEnum;
use App\Events\MessageDeletedEvent;
use App\Events\MessageSentEvent;
use App\Events\MessageUpdatedEvent;
use App\Events\SessionStartedEvent;
use App\Events\SessionTopicSetEvent;
use App\Events\SessionTopicUnsetEvent;
use App\Events\SessionUpdatedEvent;
use App\Http\Resources\MessageResource;
use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\GroupTherapy;
use App\Models\Message;
use App\Models\MessageNote;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Http\Resources\MissingValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

// SCRUM-202/TT-2.3a: authorization + CRUD for a counsellor's private notes on a single chat
// Message, across all three Message::for contexts (Therapy session, GroupTherapy session,
// Discussion). Unlike SessionNote, editability is deliberately NOT time-gated -- see
// decision-log.md ("SCRUM-22 (TT-2.3): message-note editability diverges from the documented
// reuse plan").

function aCounsellorForMessageNotesRoute(): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()]);
}

function aTherapySessionForMessageNotesRoute(Counsellor $counsellor, array $sessionAttributes = []): Session
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

function aGroupTherapySessionForMessageNotesRoute(array $counsellors): Session
{
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
    ]);

    foreach ($counsellors as $counsellor) {
        $groupTherapy->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    }

    return Session::factory()->create([
        'for_id' => $groupTherapy->id,
        'for_type' => GroupTherapy::class,
    ]);
}

function aDiscussionForMessageNotesRoute(array $counsellors): Discussion
{
    $discussion = Discussion::factory()->create([
        'addedby_type' => Counsellor::class,
        'addedby_id' => Counsellor::factory(['user_id' => User::factory()]),
    ]);

    foreach ($counsellors as $counsellor) {
        $discussion->counsellors()->attach($counsellor->id);
    }

    return $discussion;
}

function aMessageForMessageNotesRoute($for): Message
{
    return Message::factory()->create([
        'for_id' => $for->id,
        'for_type' => $for::class,
    ]);
}

test('a counsellor can create a note on a message from their own therapy session', function () {
    $counsellor = aCounsellorForMessageNotesRoute();
    $session = aTherapySessionForMessageNotesRoute($counsellor);
    $message = aMessageForMessageNotesRoute($session);

    $response = $this
        ->actingAs($counsellor->user)
        ->post(route('api.message.notes.store', ['messageId' => $message->id]), [
            'content' => 'Client hesitated before answering this.',
        ]);

    $response->assertOk();
    $response->assertJsonPath('note.content', 'Client hesitated before answering this.');
    $this->assertDatabaseHas('message_notes', [
        'message_id' => $message->id,
        'counsellor_id' => $counsellor->id,
        'content' => 'Client hesitated before answering this.',
    ]);
});

test('a counsellor can create a note on a message from a shared group therapy session', function () {
    $counsellor = aCounsellorForMessageNotesRoute();
    $session = aGroupTherapySessionForMessageNotesRoute([$counsellor]);
    $message = aMessageForMessageNotesRoute($session);

    $this->actingAs($counsellor->user)
        ->post(route('api.message.notes.store', ['messageId' => $message->id]), ['content' => 'group note'])
        ->assertOk()
        ->assertJsonPath('note.content', 'group note');
});

test('a counsellor can create a note on a message from a discussion they participate in', function () {
    $counsellor = aCounsellorForMessageNotesRoute();
    $discussion = aDiscussionForMessageNotesRoute([$counsellor]);
    $message = aMessageForMessageNotesRoute($discussion);

    $this->actingAs($counsellor->user)
        ->post(route('api.message.notes.store', ['messageId' => $message->id]), ['content' => 'discussion note'])
        ->assertOk()
        ->assertJsonPath('note.content', 'discussion note');
});

test('a counsellor can fetch their own note for a message, or null if none exists', function () {
    $counsellor = aCounsellorForMessageNotesRoute();
    $session = aTherapySessionForMessageNotesRoute($counsellor);
    $message = aMessageForMessageNotesRoute($session);

    $emptyResponse = $this->actingAs($counsellor->user)
        ->get(route('api.message.notes.index', ['messageId' => $message->id]));
    $emptyResponse->assertOk();
    expect($emptyResponse->json('note'))->toBeNull();

    MessageNote::factory()->create(['message_id' => $message->id, 'counsellor_id' => $counsellor->id]);

    $response = $this->actingAs($counsellor->user)
        ->get(route('api.message.notes.index', ['messageId' => $message->id]));
    $response->assertOk();
    expect($response->json('note.id'))->not->toBeNull();
});

test('a counsellor cannot see another counsellor note on a shared group therapy message', function () {
    $counsellorA = aCounsellorForMessageNotesRoute();
    $counsellorB = aCounsellorForMessageNotesRoute();
    $session = aGroupTherapySessionForMessageNotesRoute([$counsellorA, $counsellorB]);
    $message = aMessageForMessageNotesRoute($session);
    $noteA = MessageNote::factory()->create(['message_id' => $message->id, 'counsellor_id' => $counsellorA->id]);

    $indexResponse = $this->actingAs($counsellorB->user)
        ->get(route('api.message.notes.index', ['messageId' => $message->id]));
    $indexResponse->assertOk();
    expect($indexResponse->json('note'))->toBeNull();

    $this->actingAs($counsellorB->user)
        ->patch(route('api.message.notes.update', ['noteId' => $noteA->id]), ['content' => 'attempted takeover'])
        ->assertStatus(422);
    $this->assertDatabaseHas('message_notes', ['id' => $noteA->id, 'content' => $noteA->content]);

    $this->actingAs($counsellorB->user)
        ->delete(route('api.message.notes.destroy', ['noteId' => $noteA->id]))
        ->assertStatus(422);
    $this->assertDatabaseHas('message_notes', ['id' => $noteA->id, 'deleted_at' => null]);
});

test('a counsellor cannot see another counsellor note on a shared discussion message', function () {
    $counsellorA = aCounsellorForMessageNotesRoute();
    $counsellorB = aCounsellorForMessageNotesRoute();
    $discussion = aDiscussionForMessageNotesRoute([$counsellorA, $counsellorB]);
    $message = aMessageForMessageNotesRoute($discussion);
    MessageNote::factory()->create(['message_id' => $message->id, 'counsellor_id' => $counsellorA->id]);

    $indexResponse = $this->actingAs($counsellorB->user)
        ->get(route('api.message.notes.index', ['messageId' => $message->id]));
    $indexResponse->assertOk();
    expect($indexResponse->json('note'))->toBeNull();
});

test('a counsellor can add a new note after deleting their previous one on the same message', function () {
    // security-engineer finding (SCRUM-202): the unique index on (message_id, counsellor_id)
    // still counts a soft-deleted row, so a naive create-after-delete would hit a raw DB
    // constraint violation instead of the intended clean flow. CreateMessageNoteAction restores
    // the trashed row instead of inserting a second one.
    $counsellor = aCounsellorForMessageNotesRoute();
    $session = aTherapySessionForMessageNotesRoute($counsellor);
    $message = aMessageForMessageNotesRoute($session);
    $original = MessageNote::factory()->create(['message_id' => $message->id, 'counsellor_id' => $counsellor->id, 'content' => 'original']);

    $this->actingAs($counsellor->user)
        ->delete(route('api.message.notes.destroy', ['noteId' => $original->id]))
        ->assertOk();
    $this->assertSoftDeleted('message_notes', ['id' => $original->id]);

    $response = $this->actingAs($counsellor->user)
        ->post(route('api.message.notes.store', ['messageId' => $message->id]), ['content' => 'a fresh note']);

    $response->assertOk();
    $response->assertJsonPath('note.content', 'a fresh note');
    expect(MessageNote::where('message_id', $message->id)->where('counsellor_id', $counsellor->id)->count())->toBe(1);
    $this->assertDatabaseHas('message_notes', ['id' => $original->id, 'content' => 'a fresh note', 'deleted_at' => null]);
});

test('a co-counsellor cannot update or delete another counsellor note by guessing its id', function () {
    $counsellorA = aCounsellorForMessageNotesRoute();
    $counsellorB = aCounsellorForMessageNotesRoute();
    $session = aGroupTherapySessionForMessageNotesRoute([$counsellorA, $counsellorB]);
    $message = aMessageForMessageNotesRoute($session);
    $noteA = MessageNote::factory()->create(['message_id' => $message->id, 'counsellor_id' => $counsellorA->id]);

    $this->actingAs($counsellorB->user)
        ->patch(route('api.message.notes.update', ['noteId' => $noteA->id]), ['content' => 'idor attempt'])
        ->assertStatus(422);
    $this->assertDatabaseHas('message_notes', ['id' => $noteA->id, 'content' => $noteA->content]);

    $this->actingAs($counsellorB->user)
        ->delete(route('api.message.notes.destroy', ['noteId' => $noteA->id]))
        ->assertStatus(422);
    $this->assertDatabaseHas('message_notes', ['id' => $noteA->id, 'deleted_at' => null]);
});

test('a counsellor cannot add a second note to the same message', function () {
    $counsellor = aCounsellorForMessageNotesRoute();
    $session = aTherapySessionForMessageNotesRoute($counsellor);
    $message = aMessageForMessageNotesRoute($session);
    MessageNote::factory()->create(['message_id' => $message->id, 'counsellor_id' => $counsellor->id]);

    $response = $this->actingAs($counsellor->user)
        ->post(route('api.message.notes.store', ['messageId' => $message->id]), ['content' => 'second note']);

    $response->assertStatus(422);
    expect(MessageNote::where('message_id', $message->id)->where('counsellor_id', $counsellor->id)->count())->toBe(1);
});

test('a counsellor not participating in the message context is rejected on every operation', function () {
    $assignedCounsellor = aCounsellorForMessageNotesRoute();
    $outsiderCounsellor = aCounsellorForMessageNotesRoute();
    $session = aTherapySessionForMessageNotesRoute($assignedCounsellor);
    $message = aMessageForMessageNotesRoute($session);

    $this->actingAs($outsiderCounsellor->user)
        ->post(route('api.message.notes.store', ['messageId' => $message->id]), ['content' => 'x'])
        ->assertStatus(422);

    $this->actingAs($outsiderCounsellor->user)
        ->get(route('api.message.notes.index', ['messageId' => $message->id]))
        ->assertStatus(422);
});

test('the client on the therapy can never create, view, update, or delete a message note', function () {
    $counsellor = aCounsellorForMessageNotesRoute();
    $client = User::factory()->create();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id,
    ]);
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);
    $message = aMessageForMessageNotesRoute($session);
    $note = MessageNote::factory()->create(['message_id' => $message->id, 'counsellor_id' => $counsellor->id]);

    $this->actingAs($client)
        ->post(route('api.message.notes.store', ['messageId' => $message->id]), ['content' => 'x'])
        ->assertStatus(422);

    $this->actingAs($client)
        ->get(route('api.message.notes.index', ['messageId' => $message->id]))
        ->assertStatus(422);

    $this->actingAs($client)
        ->patch(route('api.message.notes.update', ['noteId' => $note->id]), ['content' => 'x'])
        ->assertStatus(422);

    $this->actingAs($client)
        ->delete(route('api.message.notes.destroy', ['noteId' => $note->id]))
        ->assertStatus(422);
});

test('the author can update and delete their own note', function () {
    $counsellor = aCounsellorForMessageNotesRoute();
    $session = aTherapySessionForMessageNotesRoute($counsellor);
    $message = aMessageForMessageNotesRoute($session);
    $note = MessageNote::factory()->create(['message_id' => $message->id, 'counsellor_id' => $counsellor->id]);

    $this->actingAs($counsellor->user)
        ->patch(route('api.message.notes.update', ['noteId' => $note->id]), ['content' => 'updated'])
        ->assertOk()
        ->assertJsonPath('note.content', 'updated');

    $this->actingAs($counsellor->user)
        ->delete(route('api.message.notes.destroy', ['noteId' => $note->id]))
        ->assertOk();

    $this->assertSoftDeleted('message_notes', ['id' => $note->id]);
});

test('the author can still edit their note long after the session has ended, unlike a session note', function () {
    // This is the deliberate divergence from SessionNote's grace-window rule (decision-log.md,
    // SCRUM-22/TT-2.3) -- a message note is often reviewed well after the session it's attached
    // to has ended, and Discussion (the other Message::for target) has no ended_at concept at
    // all to gate on. Backdating ended_at far beyond session-notes.edit_grace_minutes proves this
    // update is NOT accidentally reusing GuardsPrivateNoteEditWindow.
    $counsellor = aCounsellorForMessageNotesRoute();
    $session = aTherapySessionForMessageNotesRoute($counsellor, ['status' => SessionStatusEnum::held->value]);
    DB::table('sessions')->where('id', $session->id)->update([
        'ended_at' => now()->subMinutes(config('session-notes.edit_grace_minutes') + 500),
    ]);
    $message = aMessageForMessageNotesRoute($session);
    $note = MessageNote::factory()->create(['message_id' => $message->id, 'counsellor_id' => $counsellor->id]);

    $this->actingAs($counsellor->user)
        ->patch(route('api.message.notes.update', ['noteId' => $note->id]), ['content' => 'still editable'])
        ->assertOk()
        ->assertJsonPath('note.content', 'still editable');
});

test('an unauthenticated request cannot reach any message note endpoint', function () {
    $counsellor = aCounsellorForMessageNotesRoute();
    $session = aTherapySessionForMessageNotesRoute($counsellor);
    $message = aMessageForMessageNotesRoute($session);
    $note = MessageNote::factory()->create(['message_id' => $message->id, 'counsellor_id' => $counsellor->id]);

    $this->postJson(route('api.message.notes.store', ['messageId' => $message->id]), ['content' => 'x'])
        ->assertUnauthorized();

    $this->getJson(route('api.message.notes.index', ['messageId' => $message->id]))
        ->assertUnauthorized();

    $this->patchJson(route('api.message.notes.update', ['noteId' => $note->id]), ['content' => 'x'])
        ->assertUnauthorized();

    $this->deleteJson(route('api.message.notes.destroy', ['noteId' => $note->id]))
        ->assertUnauthorized();
});

test('creating, updating, and deleting a message note broadcasts no events at all', function () {
    Event::fake();

    $counsellor = aCounsellorForMessageNotesRoute();
    $session = aTherapySessionForMessageNotesRoute($counsellor);
    $message = aMessageForMessageNotesRoute($session);

    $this->actingAs($counsellor->user)
        ->post(route('api.message.notes.store', ['messageId' => $message->id]), ['content' => 'private observation']);

    $note = MessageNote::first();

    $this->actingAs($counsellor->user)
        ->patch(route('api.message.notes.update', ['noteId' => $note->id]), ['content' => 'updated observation']);

    $this->actingAs($counsellor->user)
        ->delete(route('api.message.notes.destroy', ['noteId' => $note->id]));

    Event::assertNotDispatched(SessionUpdatedEvent::class);
    Event::assertNotDispatched(SessionStartedEvent::class);
    Event::assertNotDispatched(SessionTopicSetEvent::class);
    Event::assertNotDispatched(SessionTopicUnsetEvent::class);
    Event::assertNotDispatched(MessageSentEvent::class);
    Event::assertNotDispatched(MessageUpdatedEvent::class);
    Event::assertNotDispatched(MessageDeletedEvent::class);
});

test('MessageResource never exposes a message note unless the caller explicitly eager-loaded it', function () {
    // SCRUM-203/TT-2.3b deliberately adds a `note` field to MessageResource, but only ever
    // populated when the caller (MessageService) explicitly eager-loads `notes` scoped to the
    // requesting counsellor -- see that ticket's own test coverage
    // (MessageNoteUiWiringTest.php) for the full isolation guarantee. Here, `toArray()` is
    // called directly on a model with `notes` NOT loaded, so `note` must resolve to a
    // MissingValue (Laravel's own "omit this key from the JSON response" marker) -- not actual
    // note content -- and `notes`/`messageNotes` (the raw relation/plural names) must never be
    // used as top-level keys regardless.
    $counsellor = aCounsellorForMessageNotesRoute();
    $session = aTherapySessionForMessageNotesRoute($counsellor);
    $message = aMessageForMessageNotesRoute($session);
    MessageNote::factory()->create(['message_id' => $message->id, 'counsellor_id' => $counsellor->id]);

    $array = (new MessageResource($message->fresh()))->toArray(request());

    expect($array)->not->toHaveKeys(['notes', 'messageNotes']);
    expect($array['note'])->toBeInstanceOf(MissingValue::class);
});
