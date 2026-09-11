<?php

use App\Actions\Video\JoinVideoSessionAction;
use App\Contracts\VideoProviderInterface;
use App\Enums\OrganizationCounsellorStatusEnum;
use App\Enums\OrganizationMemberBillingModeEnum;
use App\Exceptions\VideoException;
use App\Models\Counsellor;
use App\Models\Organization;
use App\Models\OrganizationCounsellor;
use App\Models\OrganizationMember;
use App\Models\OrganizationMemberBillingConfig;
use App\Models\PaymentAccessGrant;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use App\Models\VideoSession;

// TT-3.1b/SCRUM-275: joining video must be gated exactly like message reading/creation already
// are (EnsureCanSendMessageToForActionStrictPaymentGateTest.php's own scenarios) -- otherwise a
// strict-gated client could bypass the whole TT-7.5 payment-gating effort entirely by joining
// video without ever touching text chat.

function fakeVideoProviderForPaymentGateTest(): VideoProviderInterface
{
    return new class implements VideoProviderInterface
    {
        public function createRoom(VideoSession $videoSession): array
        {
            return ['room_id' => "fake-room-{$videoSession->id}", 'meta' => ['fake' => true]];
        }

        public function createParticipantCredentials(VideoSession $videoSession, User $user, string $displayName, bool $isOwner = false): array
        {
            return ['token' => "fake-token-{$user->id}"];
        }

        public function endRoom(VideoSession $videoSession): void {}
    };
}

function perSessionStrictGatedTherapyForVideoJoin(array $overrides = []): Therapy
{
    return Therapy::factory()->create(array_merge([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'public' => false,
        'payment_type' => 'PAID',
        'payment_data' => ['per' => 'PER_SESSION', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60, 'strictPaymentGate' => true],
    ], $overrides));
}

function perTherapyStrictGatedTherapyForVideoJoin(array $overrides = []): Therapy
{
    return Therapy::factory()->create(array_merge([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'public' => false,
        'payment_type' => 'PAID',
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60, 'strictPaymentGate' => true],
    ], $overrides));
}

function onlineInSessionSessionForVideoPaymentGate(Therapy $therapy): Session
{
    return Session::factory()->create([
        'for_id' => $therapy->id,
        'for_type' => Therapy::class,
        'type' => 'ONLINE',
        'status' => 'IN_SESSION',
        'start_time' => now(),
    ]);
}

test('joining denies a client with no grant for a PER_SESSION-gated session', function () {
    app()->instance(VideoProviderInterface::class, fakeVideoProviderForPaymentGateTest());
    $client = User::factory()->create();
    $therapy = perSessionStrictGatedTherapyForVideoJoin(['addedby_id' => $client->id]);
    $session = onlineInSessionSessionForVideoPaymentGate($therapy);

    expect(fn () => JoinVideoSessionAction::new()->execute($session, $client))
        ->toThrow(VideoException::class, 'Payment is required to access video for this session.');

    $this->assertDatabaseCount('video_sessions', 0);
});

test('joining allows a client once a session-level grant exists', function () {
    app()->instance(VideoProviderInterface::class, fakeVideoProviderForPaymentGateTest());
    $client = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = perSessionStrictGatedTherapyForVideoJoin(['addedby_id' => $client->id, 'counsellor_id' => $counsellor->id]);
    $session = onlineInSessionSessionForVideoPaymentGate($therapy);
    PaymentAccessGrant::factory()->create([
        'user_id' => $client->id,
        'for_type' => Session::class,
        'for_id' => $session->id,
    ]);

    $credentials = JoinVideoSessionAction::new()->execute($session, $client);

    expect($credentials)->toBe(['token' => "fake-token-{$client->id}"]);
});

test('joining blocks a PER_THERAPY-gated therapy\'s video directly, closing the same still-reachable hole reading/creating messages was fixed for', function () {
    app()->instance(VideoProviderInterface::class, fakeVideoProviderForPaymentGateTest());
    $client = User::factory()->create();
    $therapy = perTherapyStrictGatedTherapyForVideoJoin(['addedby_id' => $client->id]);
    $session = onlineInSessionSessionForVideoPaymentGate($therapy);

    expect(fn () => JoinVideoSessionAction::new()->execute($session, $client))
        ->toThrow(VideoException::class);

    $this->assertDatabaseCount('video_sessions', 0);
});

test('joining allows a PER_THERAPY-gated therapy\'s video once a therapy-level grant already exists (e.g. from page load)', function () {
    app()->instance(VideoProviderInterface::class, fakeVideoProviderForPaymentGateTest());
    $client = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = perTherapyStrictGatedTherapyForVideoJoin(['addedby_id' => $client->id, 'counsellor_id' => $counsellor->id]);
    $session = onlineInSessionSessionForVideoPaymentGate($therapy);
    PaymentAccessGrant::factory()->create([
        'user_id' => $client->id,
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
    ]);

    $credentials = JoinVideoSessionAction::new()->execute($session, $client);

    expect($credentials)->toBe(['token' => "fake-token-{$client->id}"]);
});

// TT-7.3b-f2/SCRUM-238: proves real-caller wiring for joining video too, not just messaging --
// mirrors MessageServiceStrictPaymentGateTest's/EnsureCanSendMessageToForActionStrictPaymentGateTest's
// identical scenario.
test('joining denies a client when the retainer-covering organization is billing-suspended', function () {
    app()->instance(VideoProviderInterface::class, fakeVideoProviderForPaymentGateTest());
    $client = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = perTherapyStrictGatedTherapyForVideoJoin(['addedby_id' => $client->id, 'counsellor_id' => $counsellor->id]);
    $session = onlineInSessionSessionForVideoPaymentGate($therapy);

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

    expect(fn () => JoinVideoSessionAction::new()->execute($session, $client))
        ->toThrow(VideoException::class);

    $this->assertDatabaseCount('video_sessions', 0);
});

test('joining is unaffected for the counsellor of a strict-gated therapy', function () {
    app()->instance(VideoProviderInterface::class, fakeVideoProviderForPaymentGateTest());
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = perSessionStrictGatedTherapyForVideoJoin(['counsellor_id' => $counsellor->id]);
    $session = onlineInSessionSessionForVideoPaymentGate($therapy);

    $credentials = JoinVideoSessionAction::new()->execute($session, $counsellorUser);

    expect($credentials)->toBe(['token' => "fake-token-{$counsellorUser->id}"]);
});
