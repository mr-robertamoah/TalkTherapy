<?php

use App\Enums\SessionStatusEnum;
use App\Models\Counsellor;
use App\Models\Message;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\TherapyTopic;
use App\Models\User;

// Regression tests for SCRUM-74: GET /api/sessions/{id}/messages, /api/topics/{id}/messages,
// and /api/messages/{id}/replies previously sat outside the auth:sanctum group, and the
// service-layer guard clauses they called (`$user?->isNotAdmin() && ...`) silently skipped
// their own restriction whenever $user was null -- meaning a fully unauthenticated request
// could read a private, non-public session's entire message history.

function createPrivateSessionWithMessage(): array
{
    $therapyOwner = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_id' => $therapyOwner->id,
        'addedby_type' => $therapyOwner::class,
        'counsellor_id' => $counsellor->id,
        'public' => false,
    ]);
    $session = Session::factory()->create([
        'addedby_id' => $counsellor->id,
        'addedby_type' => $counsellor::class,
        'status' => SessionStatusEnum::in_session->value,
        'for_id' => $therapy->id,
        'for_type' => $therapy::class,
    ]);
    $message = Message::factory()->create([
        'for_id' => $session->id,
        'for_type' => $session::class,
        'content' => 'a private message',
    ]);

    return compact('therapyOwner', 'counsellorUser', 'counsellor', 'therapy', 'session', 'message');
}

test('an unauthenticated request to get session messages is rejected, not silently allowed', function () {
    $data = createPrivateSessionWithMessage();

    $response = $this->getJson(route('api.session.messages.get', ['sessionId' => $data['session']->id]));

    $response->assertStatus(401);
});

test('an unauthenticated request to get topic messages is rejected', function () {
    $data = createPrivateSessionWithMessage();
    // TherapyTopic's $fillable still lists the pre-migration 'therapy_id' column, not the
    // renamed 'topicable_id'/'topicable_type' -- forceCreate bypasses mass-assignment guarding.
    $topic = TherapyTopic::query()->forceCreate([
        'counsellor_id' => $data['counsellor']->id,
        'topicable_id' => $data['therapy']->id,
        'topicable_type' => $data['therapy']::class,
        'name' => 'a private topic',
    ]);

    $response = $this->getJson(route('api.topic.messages.get', ['topicId' => $topic->id]));

    $response->assertStatus(401);
});

test('an unauthenticated request to get message replies is rejected', function () {
    $data = createPrivateSessionWithMessage();

    $response = $this->getJson(route('api.message.replies.get', ['messageId' => $data['message']->id]));

    $response->assertStatus(401);
});

test('an authenticated non-participant cannot read a private session\'s messages', function () {
    $data = createPrivateSessionWithMessage();
    $outsider = User::factory()->create();

    $response = $this
        ->actingAs($outsider)
        ->getJson(route('api.session.messages.get', ['sessionId' => $data['session']->id]));

    $response->assertOk();
    expect($response->json('data'))->toBeEmpty();
});

test('the therapy owner can still read their own private session\'s messages', function () {
    $data = createPrivateSessionWithMessage();

    $response = $this
        ->actingAs($data['therapyOwner'])
        ->getJson(route('api.session.messages.get', ['sessionId' => $data['session']->id]));

    $response->assertOk();
    expect($response->json('data'))->not->toBeEmpty();
});

test('an authenticated non-participant cannot fetch replies to a message in a private session', function () {
    $data = createPrivateSessionWithMessage();
    $reply = Message::factory()->create([
        'message_id' => $data['message']->id,
        'for_id' => $data['session']->id,
        'for_type' => $data['session']::class,
    ]);
    $outsider = User::factory()->create();

    $response = $this
        ->actingAs($outsider)
        ->getJson(route('api.message.replies.get', ['messageId' => $data['message']->id]));

    $response->assertOk();
    expect($response->json('data'))->toBeEmpty();
});

test('the therapy owner can fetch replies to a message in their own private session', function () {
    $data = createPrivateSessionWithMessage();
    Message::factory()->create([
        'message_id' => $data['message']->id,
        'for_id' => $data['session']->id,
        'for_type' => $data['session']::class,
    ]);

    $response = $this
        ->actingAs($data['therapyOwner'])
        ->getJson(route('api.message.replies.get', ['messageId' => $data['message']->id]));

    $response->assertOk();
    expect($response->json('data'))->not->toBeEmpty();
});

test('Message::isNotParty() treats a null user as not a party, not as a party', function () {
    $data = createPrivateSessionWithMessage();

    expect($data['message']->isNotParty(null))->toBeTrue();
});

// Regression tests for a deeper instance of the same bug class, found during this ticket's
// security review: Message::for()/Session::for() aren't withTrashed(), so once a session's
// parent Therapy is soft-deleted (an entirely ordinary, existing feature -- DeleteTherapyAction
// doesn't touch its Sessions/Messages), `$session->for` resolves to null and the guard clauses'
// `?->` chains silently evaluated to falsy-and-therefore-allow instead of denying, letting ANY
// authenticated user (not just a guest) read the orphaned private content.

test('an authenticated non-participant cannot read a session\'s messages once its therapy is soft-deleted', function () {
    $data = createPrivateSessionWithMessage();
    $data['therapy']->delete();
    $outsider = User::factory()->create();

    $response = $this
        ->actingAs($outsider)
        ->getJson(route('api.session.messages.get', ['sessionId' => $data['session']->id]));

    $response->assertOk();
    expect($response->json('data'))->toBeEmpty();
});

test('an authenticated non-participant cannot read a topic\'s messages once its therapy is soft-deleted', function () {
    $data = createPrivateSessionWithMessage();
    $topic = TherapyTopic::query()->forceCreate([
        'counsellor_id' => $data['counsellor']->id,
        'topicable_id' => $data['therapy']->id,
        'topicable_type' => $data['therapy']::class,
        'name' => 'a private topic',
    ]);
    $topic->sessions()->attach($data['session']->id);
    Message::factory()->create([
        'for_id' => $data['session']->id,
        'for_type' => $data['session']::class,
        'therapy_topic_id' => $topic->id,
    ]);
    $data['therapy']->delete();
    $outsider = User::factory()->create();

    $response = $this
        ->actingAs($outsider)
        ->getJson(route('api.topic.messages.get', ['topicId' => $topic->id, 'sessionId' => $data['session']->id]));

    $response->assertOk();
    expect($response->json('data'))->toBeEmpty();
});

test('an authenticated non-participant cannot fetch replies once the message\'s therapy is soft-deleted', function () {
    $data = createPrivateSessionWithMessage();
    Message::factory()->create([
        'message_id' => $data['message']->id,
        'for_id' => $data['session']->id,
        'for_type' => $data['session']::class,
    ]);
    $data['therapy']->delete();
    $outsider = User::factory()->create();

    $response = $this
        ->actingAs($outsider)
        ->getJson(route('api.message.replies.get', ['messageId' => $data['message']->id]));

    $response->assertOk();
    expect($response->json('data'))->toBeEmpty();
});

// Regression tests for a sibling instance of the exact same bug pattern, found by
// security-engineer while reviewing this ticket: GET /therapies/{id}/sessions and
// GET /therapies/{id}/topics are intentionally reachable by guests (for PUBLIC therapies --
// unlike the message-reading routes above, these were never meant to require login), but their
// service-layer guards used the same `$user?->isNotAdmin() && ...` short-circuit, so an
// unauthenticated request for a PRIVATE therapy's sessions/topics also leaked, confirmed live.

test('an unauthenticated request can still see a public therapy\'s sessions (no regression)', function () {
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_id' => $counsellorUser->id,
        'addedby_type' => $counsellorUser::class,
        'counsellor_id' => $counsellor->id,
        'public' => true,
    ]);
    Session::factory()->create([
        'addedby_id' => $counsellor->id,
        'addedby_type' => $counsellor::class,
        'for_id' => $therapy->id,
        'for_type' => $therapy::class,
    ]);

    $response = $this->getJson(route('api.sessions.get', ['therapyId' => $therapy->id]));

    $response->assertOk();
    expect($response->json('data'))->not->toBeEmpty();
});

test('an unauthenticated request cannot see a private therapy\'s sessions', function () {
    $data = createPrivateSessionWithMessage();

    $response = $this->getJson(route('api.sessions.get', ['therapyId' => $data['therapy']->id]));

    $response->assertOk();
    expect($response->json('data'))->toBeEmpty();
});

test('an authenticated non-participant cannot see a private therapy\'s sessions', function () {
    $data = createPrivateSessionWithMessage();
    $outsider = User::factory()->create();

    $response = $this
        ->actingAs($outsider)
        ->getJson(route('api.sessions.get', ['therapyId' => $data['therapy']->id]));

    $response->assertOk();
    expect($response->json('data'))->toBeEmpty();
});

test('the therapy owner can still see their own private therapy\'s sessions', function () {
    $data = createPrivateSessionWithMessage();

    $response = $this
        ->actingAs($data['therapyOwner'])
        ->getJson(route('api.sessions.get', ['therapyId' => $data['therapy']->id]));

    $response->assertOk();
    expect($response->json('data'))->not->toBeEmpty();
});

test('an unauthenticated request can still see a public therapy\'s topics (no regression)', function () {
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_id' => $counsellorUser->id,
        'addedby_type' => $counsellorUser::class,
        'counsellor_id' => $counsellor->id,
        'public' => true,
    ]);
    TherapyTopic::query()->forceCreate([
        'counsellor_id' => $counsellor->id,
        'topicable_id' => $therapy->id,
        'topicable_type' => $therapy::class,
        'name' => 'a public topic',
    ]);

    $response = $this->getJson(route('api.topics.get', ['therapyId' => $therapy->id]));

    $response->assertOk();
    expect($response->json('data'))->not->toBeEmpty();
});

test('an unauthenticated request cannot see a private therapy\'s topics', function () {
    $data = createPrivateSessionWithMessage();
    TherapyTopic::query()->forceCreate([
        'counsellor_id' => $data['counsellor']->id,
        'topicable_id' => $data['therapy']->id,
        'topicable_type' => $data['therapy']::class,
        'name' => 'a private topic',
    ]);

    $response = $this->getJson(route('api.topics.get', ['therapyId' => $data['therapy']->id]));

    $response->assertOk();
    expect($response->json('data'))->toBeEmpty();
});

test('an authenticated non-participant cannot see a private therapy\'s topics', function () {
    $data = createPrivateSessionWithMessage();
    TherapyTopic::query()->forceCreate([
        'counsellor_id' => $data['counsellor']->id,
        'topicable_id' => $data['therapy']->id,
        'topicable_type' => $data['therapy']::class,
        'name' => 'a private topic',
    ]);
    $outsider = User::factory()->create();

    $response = $this
        ->actingAs($outsider)
        ->getJson(route('api.topics.get', ['therapyId' => $data['therapy']->id]));

    $response->assertOk();
    expect($response->json('data'))->toBeEmpty();
});
