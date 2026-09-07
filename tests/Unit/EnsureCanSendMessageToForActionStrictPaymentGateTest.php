<?php

use App\DTOs\CreateMessageDTO;
use App\Enums\OrganizationCounsellorStatusEnum;
use App\Enums\OrganizationMemberBillingModeEnum;
use App\Enums\SessionStatusEnum;
use App\Exceptions\MessageException;
use App\Models\Counsellor;
use App\Models\Organization;
use App\Models\OrganizationCounsellor;
use App\Models\OrganizationMember;
use App\Models\OrganizationMemberBillingConfig;
use App\Models\PaymentAccessGrant;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use App\Services\MessageService;

// SCRUM-246: message _reading_ (MessageServiceStrictPaymentGateTest.php) has enforced the strict
// payment gate since SCRUM-220 -- message _creation_ never did. Mirrors that file's own scenarios
// (PER_SESSION denial/grant, PER_THERAPY still-reachable-chat closure, org-suspension denial,
// counsellor-unaffected regression), applied to MessageService::createMessage() instead.

function perSessionStrictGatedTherapyForCreate(array $overrides = []): Therapy
{
    return Therapy::factory()->create(array_merge([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'public' => false,
        'payment_type' => 'PAID',
        'payment_data' => ['per' => 'PER_SESSION', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60, 'strictPaymentGate' => true],
    ], $overrides));
}

function perTherapyStrictGatedTherapyForCreate(array $overrides = []): Therapy
{
    return Therapy::factory()->create(array_merge([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'public' => false,
        'payment_type' => 'PAID',
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60, 'strictPaymentGate' => true],
    ], $overrides));
}

function aStrictGatedSession(Therapy $therapy): Session
{
    return Session::factory()->create([
        'addedby_id' => $therapy->counsellor_id ? Counsellor::find($therapy->counsellor_id)->id : $therapy->addedby_id,
        'addedby_type' => $therapy->counsellor_id ? Counsellor::class : $therapy->addedby_type,
        'status' => SessionStatusEnum::in_session_confirmation->value,
        'for_id' => $therapy->id,
        'for_type' => Therapy::class,
    ]);
}

test('createMessage denies a client with no grant for a PER_SESSION-gated session', function () {
    $client = User::factory()->create();
    $therapy = perSessionStrictGatedTherapyForCreate(['addedby_id' => $client->id]);
    $session = aStrictGatedSession($therapy);

    expect(fn () => MessageService::new()->createMessage(
        CreateMessageDTO::new()->fromArray([
            'from' => $client,
            'user' => $client,
            'for' => $session,
            'content' => 'hello',
        ])
    ))->toThrow(MessageException::class, 'You are not allowed to create a message for this session.');

    $this->assertDatabaseCount('messages', 0);
});

test('createMessage allows a client once a session-level grant exists', function () {
    $client = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = perSessionStrictGatedTherapyForCreate(['addedby_id' => $client->id, 'counsellor_id' => $counsellor->id]);
    $session = aStrictGatedSession($therapy);
    PaymentAccessGrant::factory()->create([
        'user_id' => $client->id,
        'for_type' => Session::class,
        'for_id' => $session->id,
    ]);

    $message = MessageService::new()->createMessage(
        CreateMessageDTO::new()->fromArray([
            'from' => $client,
            'user' => $client,
            'for' => $session,
            'to' => $counsellor,
            'content' => 'hello',
        ])
    );

    $this->assertDatabaseHas('messages', ['id' => $message->id]);
});

test('createMessage keeps access for an existing session grant even after the transaction later fails', function () {
    $client = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = perSessionStrictGatedTherapyForCreate(['addedby_id' => $client->id, 'counsellor_id' => $counsellor->id]);
    $session = aStrictGatedSession($therapy);
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

    $message = MessageService::new()->createMessage(
        CreateMessageDTO::new()->fromArray([
            'from' => $client,
            'user' => $client,
            'for' => $session,
            'to' => $counsellor,
            'content' => 'hello',
        ])
    );

    $this->assertDatabaseHas('messages', ['id' => $message->id]);
});

test('createMessage blocks a PER_THERAPY-gated therapy\'s chat directly, closing the same still-reachable hole reading was fixed for', function () {
    $client = User::factory()->create();
    $therapy = perTherapyStrictGatedTherapyForCreate(['addedby_id' => $client->id]);
    $session = aStrictGatedSession($therapy);

    expect(fn () => MessageService::new()->createMessage(
        CreateMessageDTO::new()->fromArray([
            'from' => $client,
            'user' => $client,
            'for' => $session,
            'content' => 'hello',
        ])
    ))->toThrow(MessageException::class);

    $this->assertDatabaseCount('messages', 0);
});

test('createMessage allows a PER_THERAPY-gated therapy\'s chat once a therapy-level grant already exists (e.g. from page load)', function () {
    $client = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = perTherapyStrictGatedTherapyForCreate(['addedby_id' => $client->id, 'counsellor_id' => $counsellor->id]);
    $session = aStrictGatedSession($therapy);
    PaymentAccessGrant::factory()->create([
        'user_id' => $client->id,
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
    ]);

    $message = MessageService::new()->createMessage(
        CreateMessageDTO::new()->fromArray([
            'from' => $client,
            'user' => $client,
            'for' => $session,
            'to' => $counsellor,
            'content' => 'hello',
        ])
    );

    $this->assertDatabaseHas('messages', ['id' => $message->id]);
});

// TT-7.3b-f2/SCRUM-238: proves real-caller wiring for message creation too, not just reading --
// mirrors MessageServiceStrictPaymentGateTest's identical scenario.
test('createMessage denies a client when the retainer-covering organization is billing-suspended', function () {
    $client = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = perTherapyStrictGatedTherapyForCreate(['addedby_id' => $client->id, 'counsellor_id' => $counsellor->id]);
    $session = aStrictGatedSession($therapy);

    $organization = Organization::factory()->create(['is_consumer' => true, 'verified_at' => now()]);
    OrganizationCounsellor::factory()->create([
        'organization_id' => $organization->id,
        'counsellor_id' => $counsellor->id,
        'status' => OrganizationCounsellorStatusEnum::active->value,
    ]);
    $member = OrganizationMember::factory()->create(['organization_id' => $organization->id, 'user_id' => $client->id]);
    OrganizationMemberBillingConfig::factory()->create([
        'organization_member_id' => $member->id,
        'mode' => OrganizationMemberBillingModeEnum::retainer->value,
    ]);
    $organization->suspendBilling('Retainer invoice settlement failed.');

    expect(fn () => MessageService::new()->createMessage(
        CreateMessageDTO::new()->fromArray([
            'from' => $client,
            'user' => $client,
            'for' => $session,
            'content' => 'hello',
        ])
    ))->toThrow(MessageException::class);

    $this->assertDatabaseCount('messages', 0);
});

test('createMessage is unaffected for the counsellor of a strict-gated therapy', function () {
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = perSessionStrictGatedTherapyForCreate(['counsellor_id' => $counsellor->id]);
    $session = aStrictGatedSession($therapy);

    $message = MessageService::new()->createMessage(
        CreateMessageDTO::new()->fromArray([
            'from' => $counsellor,
            'user' => $counsellorUser,
            'for' => $session,
            'to' => $therapy->addedby,
            'content' => 'hello',
        ])
    );

    $this->assertDatabaseHas('messages', ['id' => $message->id]);
});
