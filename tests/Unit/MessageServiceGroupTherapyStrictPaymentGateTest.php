<?php

use App\Actions\Message\EnsureCanSendMessageToForAction;
use App\DTOs\CreateMessageDTO;
use App\DTOs\GetSessionMessagesDTO;
use App\DTOs\GetTherapyTopicMessagesDTO;
use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Exceptions\MessageException;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Message;
use App\Models\PaymentAccessGrant;
use App\Models\Session;
use App\Models\TherapyTopic;
use App\Models\Transaction;
use App\Models\User;
use App\Services\MessageService;

// TT-7.5b-b3/SCRUM-267: GroupTherapy coverage for the same 3 message-reading entry points
// MessageServiceStrictPaymentGateTest.php already covers for individual Therapy -- mirrors that
// file's structure, plus the late-joiner historical-content exemption this ticket introduces.

function strictGatedGroupTherapyMember(array $groupOverrides = []): array
{
    $groupTherapy = GroupTherapy::factory()->create(array_merge([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'public' => false,
        'payment_type' => 'PAID',
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60, 'strictPaymentGate' => true, 'allowFreeHistoricalAccess' => true],
    ], $groupOverrides));
    $member = User::factory()->create();
    $groupTherapy->users()->attach($member->id, ['anonymous' => false]);

    return [$groupTherapy, $member];
}

test('getSessionMessages denies an unpaid member of a strict-gated group therapy', function () {
    [$groupTherapy, $member] = strictGatedGroupTherapyMember();
    $session = Session::factory()->create(['for_id' => $groupTherapy->id, 'for_type' => GroupTherapy::class, 'start_time' => now()]);

    $result = MessageService::new()->getSessionMessages(GetSessionMessagesDTO::new()->fromArray([
        'user' => $member,
        'session' => $session,
    ]));

    expect($result)->toBe([]);
});

test('getSessionMessages allows a member once they have a successful transaction', function () {
    [$groupTherapy, $member] = strictGatedGroupTherapyMember();
    $session = Session::factory()->create(['for_id' => $groupTherapy->id, 'for_type' => GroupTherapy::class, 'start_time' => now()]);
    Transaction::factory()->create([
        'for_type' => GroupTherapy::class,
        'for_id' => $groupTherapy->id,
        'user_id' => $member->id,
        'status' => 'SUCCESS',
    ]);

    $result = MessageService::new()->getSessionMessages(GetSessionMessagesDTO::new()->fromArray([
        'user' => $member,
        'session' => $session,
    ]));

    expect($result)->not->toBe([]);
});

test('getSessionMessages exempts an unpaid member from a session that predates their join, via allowFreeHistoricalAccess', function () {
    [$groupTherapy, $member] = strictGatedGroupTherapyMember();
    $groupTherapy->users()->updateExistingPivot($member->id, ['created_at' => now()->subDays(5)]);
    $oldSession = Session::factory()->create([
        'for_id' => $groupTherapy->id,
        'for_type' => GroupTherapy::class,
        'start_time' => now()->subDays(10),
    ]);

    $result = MessageService::new()->getSessionMessages(GetSessionMessagesDTO::new()->fromArray([
        'user' => $member,
        'session' => $oldSession,
    ]));

    expect($result)->not->toBe([]);
});

test('getSessionMessages still gates a session dated after the member\'s own join, even with allowFreeHistoricalAccess on', function () {
    [$groupTherapy, $member] = strictGatedGroupTherapyMember();
    $groupTherapy->users()->updateExistingPivot($member->id, ['created_at' => now()->subDays(5)]);
    $newSession = Session::factory()->create([
        'for_id' => $groupTherapy->id,
        'for_type' => GroupTherapy::class,
        'start_time' => now()->subDay(),
    ]);

    $result = MessageService::new()->getSessionMessages(GetSessionMessagesDTO::new()->fromArray([
        'user' => $member,
        'session' => $newSession,
    ]));

    expect($result)->toBe([]);
});

test('getSessionMessages does not exempt a pre-join session when allowFreeHistoricalAccess is off', function () {
    [$groupTherapy, $member] = strictGatedGroupTherapyMember([
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60, 'strictPaymentGate' => true, 'allowFreeHistoricalAccess' => false],
    ]);
    $groupTherapy->users()->updateExistingPivot($member->id, ['created_at' => now()->subDays(5)]);
    $oldSession = Session::factory()->create([
        'for_id' => $groupTherapy->id,
        'for_type' => GroupTherapy::class,
        'start_time' => now()->subDays(10),
    ]);

    $result = MessageService::new()->getSessionMessages(GetSessionMessagesDTO::new()->fromArray([
        'user' => $member,
        'session' => $oldSession,
    ]));

    expect($result)->toBe([]);
});

test('getSessionMessages is unaffected for an active counsellor of a strict-gated group therapy', function () {
    [$groupTherapy] = strictGatedGroupTherapyMember();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);
    $session = Session::factory()->create(['for_id' => $groupTherapy->id, 'for_type' => GroupTherapy::class, 'start_time' => now()]);

    $result = MessageService::new()->getSessionMessages(GetSessionMessagesDTO::new()->fromArray([
        'user' => $counsellorUser,
        'session' => $session,
    ]));

    expect($result)->not->toBe([]);
});

test('getTherapyTopicMessages denies an unpaid group therapy member', function () {
    [$groupTherapy, $member] = strictGatedGroupTherapyMember();
    $session = Session::factory()->create(['for_id' => $groupTherapy->id, 'for_type' => GroupTherapy::class, 'start_time' => now()]);
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);

    TherapyTopic::unguard();
    $topic = TherapyTopic::create(['name' => 'Topic', 'counsellor_id' => $counsellor->id]);
    TherapyTopic::reguard();
    $topic->sessions()->attach($session->id);

    $result = MessageService::new()->getTherapyTopicMessages(GetTherapyTopicMessagesDTO::new()->fromArray([
        'user' => $member,
        'topic' => $topic,
        'sessionId' => $session->id,
    ]));

    expect($result)->toBe([]);
});

// Reviewer suggestion: getSessionMessages has both a "denies" and an "exempts via historical
// access" test -- this closes the same loop for getTherapyTopicMessages, whose wiring
// ($session?->start_time) is identical but wasn't independently exercised at this level before.
test('getTherapyTopicMessages exempts an unpaid member from a topic\'s session that predates their join', function () {
    [$groupTherapy, $member] = strictGatedGroupTherapyMember();
    $groupTherapy->users()->updateExistingPivot($member->id, ['created_at' => now()->subDays(5)]);
    $oldSession = Session::factory()->create([
        'for_id' => $groupTherapy->id,
        'for_type' => GroupTherapy::class,
        'start_time' => now()->subDays(10),
    ]);
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);

    TherapyTopic::unguard();
    $topic = TherapyTopic::create(['name' => 'Topic', 'counsellor_id' => $counsellor->id]);
    TherapyTopic::reguard();
    $topic->sessions()->attach($oldSession->id);

    $result = MessageService::new()->getTherapyTopicMessages(GetTherapyTopicMessagesDTO::new()->fromArray([
        'user' => $member,
        'topic' => $topic,
        'sessionId' => $oldSession->id,
    ]));

    expect($result)->not->toBe([]);
});

test('getMessageReplies denies an unpaid group therapy member', function () {
    [$groupTherapy, $member] = strictGatedGroupTherapyMember();
    $session = Session::factory()->create(['for_id' => $groupTherapy->id, 'for_type' => GroupTherapy::class, 'start_time' => now()]);

    Message::unguard();
    $message = Message::factory()->create(['for_id' => $session->id, 'for_type' => Session::class]);
    Message::reguard();

    $denied = MessageService::new()->getMessageReplies($message, $member);
    expect($denied)->toBe([]);

    PaymentAccessGrant::factory()->create([
        'user_id' => $member->id,
        'for_type' => GroupTherapy::class,
        'for_id' => $groupTherapy->id,
    ]);

    $allowed = MessageService::new()->getMessageReplies($message, $member);
    expect($allowed)->not->toBe([]);
});

// The defining behavior of getMessageReplies' own comparison point: it uses the PARENT message's
// own created_at, not the session's start_time -- an old session that's still "in progress" can
// have a NEW parent message whose replies are still gated, even though the session itself predates
// the member's join.
test('getMessageReplies gates replies to a NEW message even inside a session that predates the member\'s join', function () {
    [$groupTherapy, $member] = strictGatedGroupTherapyMember();
    $groupTherapy->users()->updateExistingPivot($member->id, ['created_at' => now()->subDays(5)]);
    $session = Session::factory()->create([
        'for_id' => $groupTherapy->id,
        'for_type' => GroupTherapy::class,
        'start_time' => now()->subDays(10),
    ]);

    Message::unguard();
    $oldMessage = Message::factory()->create(['for_id' => $session->id, 'for_type' => Session::class, 'created_at' => now()->subDays(9)]);
    $newMessage = Message::factory()->create(['for_id' => $session->id, 'for_type' => Session::class, 'created_at' => now()->subDay()]);
    Message::reguard();

    expect(MessageService::new()->getMessageReplies($oldMessage, $member))->not->toBe([])
        ->and(MessageService::new()->getMessageReplies($newMessage, $member))->toBe([]);
});

// TT-7.5b-b3/SCRUM-267 regression: message CREATION must never benefit from the historical
// exemption, even inside a session that predates the member's join -- otherwise a non-paying
// member could send unlimited new messages for free forever in any session old enough. Tested
// directly against EnsureCanSendMessageToForAction (not the full createMessage() service
// pipeline) so an unrelated validation failure elsewhere in that pipeline can't make this test
// pass for the wrong reason.
test('creating a NEW message is still gated even inside a session that predates the member\'s join', function () {
    [$groupTherapy, $member] = strictGatedGroupTherapyMember();
    $groupTherapy->users()->updateExistingPivot($member->id, ['created_at' => now()->subDays(5)]);
    $session = Session::factory()->create([
        'for_id' => $groupTherapy->id,
        'for_type' => GroupTherapy::class,
        'start_time' => now()->subDays(10),
    ]);

    expect(fn () => EnsureCanSendMessageToForAction::new()->execute(CreateMessageDTO::new()->fromArray([
        'user' => $member,
        'for' => $session,
    ])))->toThrow(MessageException::class, 'You are not allowed to create a message for this session.');
});
