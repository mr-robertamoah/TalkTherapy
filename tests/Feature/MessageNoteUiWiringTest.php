<?php

use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\GroupTherapy;
use App\Models\Message;
use App\Models\MessageNote;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\TherapyTopic;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// SCRUM-203/TT-2.3b: the session/discussion message-list endpoints eager-load each message's own
// note for the requesting counsellor (used by MessageBadge.vue's inline note affordance). The
// isolation guarantee -- a co-counsellor on a shared context never sees another counsellor's note
// via this eager-load -- mirrors GetOwnMessageNoteAction's own counsellor_id scope, and is the
// single most important thing to verify here since it's a different code path (a batched
// eager-load, not the single-note endpoint SCRUM-202 already covers).

function aCounsellorForMessageNoteUiWiringRoute(): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()]);
}

function aTherapySessionForMessageNoteUiWiringRoute(Counsellor $counsellor): Session
{
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'counsellor_id' => $counsellor->id,
        'public' => true,
    ]);

    return Session::factory()->create([
        'for_id' => $therapy->id,
        'for_type' => Therapy::class,
    ]);
}

function aGroupTherapySessionForMessageNoteUiWiringRoute(array $counsellors): Session
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

test('a counsellor sees their own note attached to a session message in the message list', function () {
    $counsellor = aCounsellorForMessageNoteUiWiringRoute();
    $session = aTherapySessionForMessageNoteUiWiringRoute($counsellor);
    $message = Message::factory()->create(['for_id' => $session->id, 'for_type' => Session::class]);
    MessageNote::factory()->create(['message_id' => $message->id, 'counsellor_id' => $counsellor->id, 'content' => 'flag this']);

    $response = $this->actingAs($counsellor->user)
        ->getJson(route('api.session.messages.get', ['sessionId' => $session->id]));

    $response->assertOk();
    $entry = collect($response->json('data'))->firstWhere('id', $message->id);
    expect($entry['note']['content'])->toBe('flag this');
});

test('a session message with no note reports note as null for a counsellor viewer', function () {
    $counsellor = aCounsellorForMessageNoteUiWiringRoute();
    $session = aTherapySessionForMessageNoteUiWiringRoute($counsellor);
    $message = Message::factory()->create(['for_id' => $session->id, 'for_type' => Session::class]);

    $response = $this->actingAs($counsellor->user)
        ->getJson(route('api.session.messages.get', ['sessionId' => $session->id]));

    $response->assertOk();
    $entry = collect($response->json('data'))->firstWhere('id', $message->id);
    expect($entry['note'])->toBeNull();
});

test('a client viewer never receives a note key in the session message list', function () {
    $counsellor = aCounsellorForMessageNoteUiWiringRoute();
    $client = User::factory()->create();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id,
        'public' => true,
    ]);
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);
    $message = Message::factory()->create(['for_id' => $session->id, 'for_type' => Session::class]);
    MessageNote::factory()->create(['message_id' => $message->id, 'counsellor_id' => $counsellor->id]);

    $response = $this->actingAs($client)
        ->getJson(route('api.session.messages.get', ['sessionId' => $session->id]));

    $response->assertOk();
    $entry = collect($response->json('data'))->firstWhere('id', $message->id);
    expect($entry)->not->toHaveKey('note');
});

test('a co-counsellor never sees another counsellor note in a shared group therapy message list', function () {
    $counsellorA = aCounsellorForMessageNoteUiWiringRoute();
    $counsellorB = aCounsellorForMessageNoteUiWiringRoute();
    $session = aGroupTherapySessionForMessageNoteUiWiringRoute([$counsellorA, $counsellorB]);
    $message = Message::factory()->create(['for_id' => $session->id, 'for_type' => Session::class]);
    MessageNote::factory()->create(['message_id' => $message->id, 'counsellor_id' => $counsellorA->id, 'content' => 'private to A']);

    $response = $this->actingAs($counsellorB->user)
        ->getJson(route('api.session.messages.get', ['sessionId' => $session->id]));

    $response->assertOk();
    $entry = collect($response->json('data'))->firstWhere('id', $message->id);
    expect($entry['note'])->toBeNull();
});

test('a counsellor sees their own note attached to a discussion message in the message list', function () {
    $counsellor = aCounsellorForMessageNoteUiWiringRoute();
    $discussion = Discussion::factory()->create([
        'addedby_type' => Counsellor::class,
        'addedby_id' => $counsellor->id,
        'status' => 'PENDING',
    ]);
    $discussion->counsellors()->attach($counsellor->id);
    $message = Message::factory()->create(['for_id' => $discussion->id, 'for_type' => Discussion::class]);
    MessageNote::factory()->create(['message_id' => $message->id, 'counsellor_id' => $counsellor->id, 'content' => 'discussion note']);

    $response = $this->actingAs($counsellor->user)
        ->getJson(route('api.discussion.messages.get', ['discussionId' => $discussion->id]));

    $response->assertOk();
    $entry = collect($response->json('data'))->firstWhere('id', $message->id);
    expect($entry['note']['content'])->toBe('discussion note');
});

test('a counsellor sees their own note attached to a message in the topic-filtered message list', function () {
    // reviewer finding (SCRUM-203): this is the third of three sibling message-list endpoints
    // feeding the same MessageBadge UI -- getSessionMessages/getDiscussionMessages originally
    // got the notes eager-load, this one was missed, which would have made a message's own note
    // invisible (and un-editable) from the topic-filtered view even though it exists.
    //
    // Acting as an admin (in addition to being a counsellor) deliberately avoids passing
    // `sessionId` at all -- MessageService::getTherapyTopicMessages()'s pre-existing
    // `whereSessionId()` dynamic-where filters on a `messages.session_id` column that doesn't
    // exist (Message is a polymorphic for_id/for_type model, not session_id-keyed); this is a
    // latent, unrelated bug in that endpoint's own sessionId filter that isn't reachable via any
    // current frontend call site (TherapyComponent.vue's getTopicMessages() never sends
    // sessionId), so it's out of this ticket's scope to fix. Acting as admin skips the
    // `$therapy` public/participant branch that would otherwise require a resolvable `$therapy`
    // (which itself depends on the same broken sessionId filter), letting this test verify the
    // one thing it's actually for: the notes eager-load, gated on `$user->counsellor`, still
    // fires for this endpoint.
    $counsellor = aCounsellorForMessageNoteUiWiringRoute();
    Administrator::factory()->create(['user_id' => $counsellor->user->id]);
    $session = aTherapySessionForMessageNoteUiWiringRoute($counsellor);
    // TherapyTopic's $fillable still lists the pre-migration 'therapy_id' column, not the
    // renamed 'topicable_id'/'topicable_type' -- forceCreate bypasses mass-assignment guarding
    // (see SessionMessagesAuthorizationTest.php's identical comment).
    $topic = TherapyTopic::query()->forceCreate([
        'name' => 'topic',
        'counsellor_id' => $counsellor->id,
        'topicable_id' => $session->for_id,
        'topicable_type' => Therapy::class,
    ]);
    $message = Message::factory()->create([
        'for_id' => $session->id,
        'for_type' => Session::class,
        'therapy_topic_id' => $topic->id,
    ]);
    MessageNote::factory()->create(['message_id' => $message->id, 'counsellor_id' => $counsellor->id, 'content' => 'topic-filtered note']);

    $response = $this->actingAs($counsellor->user)
        ->getJson(route('api.topic.messages.get', ['topicId' => $topic->id]));

    $response->assertOk();
    $entry = collect($response->json('data'))->firstWhere('id', $message->id);
    expect($entry['note']['content'])->toBe('topic-filtered note');
});

test('fetching session messages does not N+1 against message_notes per message', function () {
    // Scoped specifically to the notes eager-load this ticket adds -- MessageResource has other,
    // pre-existing N+1s (files, replying) unrelated to notes and out of this ticket's scope, so
    // this counts only queries touching message_notes rather than the total query count.
    $counsellor = aCounsellorForMessageNoteUiWiringRoute();
    $session = aTherapySessionForMessageNoteUiWiringRoute($counsellor);

    $messageNotesQueryCountFor = function (int $messageCount) use ($counsellor, $session) {
        Message::query()->where('for_id', $session->id)->where('for_type', Session::class)->delete();

        foreach (range(1, $messageCount) as $i) {
            $message = Message::factory()->create(['for_id' => $session->id, 'for_type' => Session::class]);
            MessageNote::factory()->create(['message_id' => $message->id, 'counsellor_id' => $counsellor->id]);
        }

        DB::enableQueryLog();
        $this->actingAs($counsellor->user)
            ->getJson(route('api.session.messages.get', ['sessionId' => $session->id]))
            ->assertOk();
        $messageNotesQueries = collect(DB::getQueryLog())
            ->filter(fn ($query) => str_contains($query['query'], 'message_notes'))
            ->count();
        DB::flushQueryLog();
        DB::disableQueryLog();

        return $messageNotesQueries;
    };

    expect($messageNotesQueryCountFor(6))->toBe($messageNotesQueryCountFor(2));
});
