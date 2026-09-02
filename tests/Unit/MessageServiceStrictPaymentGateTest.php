<?php

use App\DTOs\GetSessionMessagesDTO;
use App\DTOs\GetTherapyTopicMessagesDTO;
use App\Models\Counsellor;
use App\Models\Message;
use App\Models\PaymentAccessGrant;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\TherapyTopic;
use App\Models\Transaction;
use App\Models\User;
use App\Services\MessageService;

// SCRUM-220/TT-7.5a: session- and chat-level enforcement, consolidated onto the shared
// EnsureUserCanAccessTherapyContentAction check. Covers both the PER_SESSION-payable case (new)
// and closing the "strict-gated therapy's chat is still reachable directly" hole for the
// PER_THERAPY-payable case (previously only gated at page load, SCRUM-219).

function perSessionStrictGatedTherapy(array $overrides = []): Therapy
{
    return Therapy::factory()->create(array_merge([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'public' => false,
        'payment_type' => 'PAID',
        'payment_data' => ['per' => 'PER_SESSION', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60, 'strictPaymentGate' => true],
    ], $overrides));
}

function perTherapyStrictGatedTherapy(array $overrides = []): Therapy
{
    return Therapy::factory()->create(array_merge([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'public' => false,
        'payment_type' => 'PAID',
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60, 'strictPaymentGate' => true],
    ], $overrides));
}

test('getSessionMessages denies a client with no grant or successful transaction for a PER_SESSION-gated session', function () {
    $client = User::factory()->create();
    $therapy = perSessionStrictGatedTherapy(['addedby_id' => $client->id]);
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);

    $result = MessageService::new()->getSessionMessages(GetSessionMessagesDTO::new()->fromArray([
        'user' => $client,
        'session' => $session,
    ]));

    expect($result)->toBe([]);
    $this->assertDatabaseCount('payment_access_grants', 0);
});

test('getSessionMessages grants and allows access once a successful transaction for that session exists', function () {
    $client = User::factory()->create();
    $therapy = perSessionStrictGatedTherapy(['addedby_id' => $client->id]);
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);
    Transaction::factory()->create([
        'for_type' => Session::class,
        'for_id' => $session->id,
        'user_id' => $client->id,
        'status' => 'SUCCESS',
    ]);

    $result = MessageService::new()->getSessionMessages(GetSessionMessagesDTO::new()->fromArray([
        'user' => $client,
        'session' => $session,
    ]));

    expect($result)->not->toBe([]);
    $this->assertDatabaseHas('payment_access_grants', [
        'user_id' => $client->id,
        'for_type' => Session::class,
        'for_id' => $session->id,
    ]);
});

test('getSessionMessages keeps access for an existing session grant even after the transaction later fails', function () {
    $client = User::factory()->create();
    $therapy = perSessionStrictGatedTherapy(['addedby_id' => $client->id]);
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);
    $transaction = Transaction::factory()->create([
        'for_type' => Session::class,
        'for_id' => $session->id,
        'user_id' => $client->id,
        'status' => 'SUCCESS',
    ]);
    PaymentAccessGrant::factory()->create([
        'user_id' => $client->id,
        'for_type' => Session::class,
        'for_id' => $session->id,
        'transaction_id' => $transaction->id,
    ]);
    $transaction->update(['status' => 'FAILED']);

    $result = MessageService::new()->getSessionMessages(GetSessionMessagesDTO::new()->fromArray([
        'user' => $client,
        'session' => $session,
    ]));

    expect($result)->not->toBe([]);
});

test('a grant for a different session on the same PER_SESSION-gated therapy does not satisfy this session', function () {
    $client = User::factory()->create();
    $therapy = perSessionStrictGatedTherapy(['addedby_id' => $client->id]);
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);
    $otherSession = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);
    PaymentAccessGrant::factory()->create([
        'user_id' => $client->id,
        'for_type' => Session::class,
        'for_id' => $otherSession->id,
    ]);

    $result = MessageService::new()->getSessionMessages(GetSessionMessagesDTO::new()->fromArray([
        'user' => $client,
        'session' => $session,
    ]));

    expect($result)->toBe([]);
});

test('getSessionMessages blocks a PER_THERAPY-gated therapy\'s chat directly, closing the still-reachable hole', function () {
    $client = User::factory()->create();
    $therapy = perTherapyStrictGatedTherapy(['addedby_id' => $client->id]);
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);

    $result = MessageService::new()->getSessionMessages(GetSessionMessagesDTO::new()->fromArray([
        'user' => $client,
        'session' => $session,
    ]));

    expect($result)->toBe([]);
});

test('getSessionMessages allows a PER_THERAPY-gated therapy\'s chat once a therapy-level grant already exists (e.g. from page load)', function () {
    $client = User::factory()->create();
    $therapy = perTherapyStrictGatedTherapy(['addedby_id' => $client->id]);
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);
    PaymentAccessGrant::factory()->create([
        'user_id' => $client->id,
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
    ]);

    $result = MessageService::new()->getSessionMessages(GetSessionMessagesDTO::new()->fromArray([
        'user' => $client,
        'session' => $session,
    ]));

    expect($result)->not->toBe([]);
});

test('getSessionMessages is unaffected for the counsellor of a strict-gated therapy', function () {
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = perSessionStrictGatedTherapy(['counsellor_id' => $counsellor->id]);
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);

    $result = MessageService::new()->getSessionMessages(GetSessionMessagesDTO::new()->fromArray([
        'user' => $counsellorUser,
        'session' => $session,
    ]));

    expect($result)->not->toBe([]);
});

test('getMessageReplies applies the same PER_SESSION strict gate to a session-parented message', function () {
    $client = User::factory()->create();
    $therapy = perSessionStrictGatedTherapy(['addedby_id' => $client->id]);
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);

    Message::unguard();
    $message = Message::factory()->create(['for_id' => $session->id, 'for_type' => Session::class]);
    Message::reguard();

    $denied = MessageService::new()->getMessageReplies($message, $client);
    expect($denied)->toBe([]);

    PaymentAccessGrant::factory()->create([
        'user_id' => $client->id,
        'for_type' => Session::class,
        'for_id' => $session->id,
    ]);

    $allowed = MessageService::new()->getMessageReplies($message, $client);
    expect($allowed)->not->toBe([]);
});

test('getTherapyTopicMessages applies the same PER_SESSION strict gate', function () {
    $client = User::factory()->create();
    $therapy = perSessionStrictGatedTherapy(['addedby_id' => $client->id]);
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);

    TherapyTopic::unguard();
    $topic = TherapyTopic::create(['name' => 'Topic', 'counsellor_id' => $counsellor->id]);
    TherapyTopic::reguard();
    $topic->sessions()->attach($session->id);

    $denied = MessageService::new()->getTherapyTopicMessages(GetTherapyTopicMessagesDTO::new()->fromArray([
        'user' => $client,
        'topic' => $topic,
        'sessionId' => $session->id,
    ]));
    expect($denied)->toBe([]);

    PaymentAccessGrant::factory()->create([
        'user_id' => $client->id,
        'for_type' => Session::class,
        'for_id' => $session->id,
    ]);

    $allowed = MessageService::new()->getTherapyTopicMessages(GetTherapyTopicMessagesDTO::new()->fromArray([
        'user' => $client,
        'topic' => $topic,
        'sessionId' => $session->id,
    ]));
    expect($allowed)->not->toBe([]);
});
