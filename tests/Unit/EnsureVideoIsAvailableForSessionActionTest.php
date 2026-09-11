<?php

use App\Actions\Video\EnsureVideoIsAvailableForSessionAction;
use App\Exceptions\VideoException;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use App\Models\VideoConsent;

// TT-3.1a/SCRUM-274: the one gate every video entry point passes through.

function onlineInSessionTherapySession(array $overrides = []): Session
{
    $client = User::factory()->adult()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id,
    ]);

    return Session::factory()->create(array_merge([
        'for_id' => $therapy->id,
        'for_type' => Therapy::class,
        'type' => 'ONLINE',
        'status' => 'IN_SESSION',
        'start_time' => now(),
    ], $overrides));
}

test('a participant can join video on an online, in-session Therapy session', function () {
    $session = onlineInSessionTherapySession();
    $client = $session->for->addedby;

    expect(fn () => EnsureVideoIsAvailableForSessionAction::new()->execute($session, $client))
        ->not->toThrow(VideoException::class);
});

test('IN_SESSION_CONFIRMATION also allows video', function () {
    $session = onlineInSessionTherapySession(['status' => 'IN_SESSION_CONFIRMATION']);
    $client = $session->for->addedby;

    expect(fn () => EnsureVideoIsAvailableForSessionAction::new()->execute($session, $client))
        ->not->toThrow(VideoException::class);
});

test('a PENDING session is not yet available for video, unlike messaging\'s own broader window', function () {
    $session = onlineInSessionTherapySession(['status' => 'PENDING']);
    $client = $session->for->addedby;

    expect(fn () => EnsureVideoIsAvailableForSessionAction::new()->execute($session, $client))
        ->toThrow(VideoException::class);
});

test('a HELD session is not available for video', function () {
    $session = onlineInSessionTherapySession(['status' => 'HELD']);
    $client = $session->for->addedby;

    expect(fn () => EnsureVideoIsAvailableForSessionAction::new()->execute($session, $client))
        ->toThrow(VideoException::class);
});

test('an IN_PERSON session is never available for video regardless of status', function () {
    $session = onlineInSessionTherapySession(['type' => 'IN_PERSON']);
    $client = $session->for->addedby;

    expect(fn () => EnsureVideoIsAvailableForSessionAction::new()->execute($session, $client))
        ->toThrow(VideoException::class);
});

test('a non-participant cannot join video even on an otherwise-available session', function () {
    $session = onlineInSessionTherapySession();
    $unrelatedUser = User::factory()->create();

    expect(fn () => EnsureVideoIsAvailableForSessionAction::new()->execute($session, $unrelatedUser))
        ->toThrow(VideoException::class);
});

// TT-3.1b/SCRUM-275 security-review finding: this action became HTTP-reachable via
// VideoSessionController, which resolves any Session by id from the URL with no ownership
// scoping. The participant check now runs FIRST (not last), so an unrelated authenticated user
// always gets the exact same generic denial regardless of the session's actual type/mode/status
// -- they can no longer use the distinct messages below (group-vs-1:1, in-person-vs-online,
// pending-vs-in-progress) as an oracle to learn facts about a session that isn't theirs.
test('a non-participant gets the same generic denial regardless of the session\'s actual type, mode, or status', function () {
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'public' => true,
    ]);
    $session = Session::factory()->create([
        'for_id' => $groupTherapy->id,
        'for_type' => GroupTherapy::class,
        'type' => 'ONLINE',
        'status' => 'IN_SESSION',
        'start_time' => now(),
    ]);
    $unrelatedUser = User::factory()->create();

    expect(fn () => EnsureVideoIsAvailableForSessionAction::new()->execute($session, $unrelatedUser))
        ->toThrow(VideoException::class, 'You are not allowed to join this session.');
});

test('the assigned counsellor is a valid participant for video', function () {
    $session = onlineInSessionTherapySession();
    $counsellorUser = $session->for->counsellor->user;

    expect(fn () => EnsureVideoIsAvailableForSessionAction::new()->execute($session, $counsellorUser))
        ->not->toThrow(VideoException::class);
});

// TT-3.1e-d/SCRUM-283: replaces the interim block's old blanket "no minor, ever" rule with the
// real per-scope consent check -- these two regression tests prove the interim block's own
// original scenarios (minor blocked without consent, counsellor exempt) still hold under the new
// mechanism.
function minorClientOnlineInSessionTherapySession(array $sessionOverrides = []): array
{
    $minorClient = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $minorClient->id,
        'counsellor_id' => $counsellor->id,
    ]);
    $session = Session::factory()->create(array_merge([
        'for_id' => $therapy->id,
        'for_type' => Therapy::class,
        'type' => 'ONLINE',
        'status' => 'IN_SESSION',
        'start_time' => now(),
    ], $sessionOverrides));

    return compact('minorClient', 'counsellorUser', 'therapy', 'session');
}

test('a minor client without any consent grant is blocked from joining video', function () {
    $data = minorClientOnlineInSessionTherapySession();

    expect(fn () => EnsureVideoIsAvailableForSessionAction::new()->execute($data['session'], $data['minorClient']))
        ->toThrow(VideoException::class, 'Guardian video consent is required before this account can join video for this session.');
});

// The counsellor side is never gated by this check -- mirrors this codebase's own established
// "counsellor is never gated" convention from the entire payment-gate epic.
test('the counsellor can still join video even when the therapy\'s own client is a minor with no consent', function () {
    $data = minorClientOnlineInSessionTherapySession();

    expect(fn () => EnsureVideoIsAvailableForSessionAction::new()->execute($data['session'], $data['counsellorUser']))
        ->not->toThrow(VideoException::class);
});

test('a minor client CAN join once a valid PER_THERAPY consent grant exists', function () {
    $data = minorClientOnlineInSessionTherapySession();
    VideoConsent::factory()->create([
        'ward_id' => $data['minorClient']->id,
        'consentable_type' => Therapy::class,
        'consentable_id' => $data['therapy']->id,
    ]);

    expect(fn () => EnsureVideoIsAvailableForSessionAction::new()->execute($data['session'], $data['minorClient']))
        ->not->toThrow(VideoException::class);
});

test('a minor client CAN join once a valid PER_SESSION consent grant exists for that exact session', function () {
    $data = minorClientOnlineInSessionTherapySession();
    VideoConsent::factory()->create([
        'ward_id' => $data['minorClient']->id,
        'consentable_type' => Session::class,
        'consentable_id' => $data['session']->id,
    ]);

    expect(fn () => EnsureVideoIsAvailableForSessionAction::new()->execute($data['session'], $data['minorClient']))
        ->not->toThrow(VideoException::class);
});

test('a PER_SESSION grant for a sibling session does not unlock this session', function () {
    $data = minorClientOnlineInSessionTherapySession();
    $siblingSession = Session::factory()->create(['for_id' => $data['therapy']->id, 'for_type' => Therapy::class]);
    VideoConsent::factory()->create([
        'ward_id' => $data['minorClient']->id,
        'consentable_type' => Session::class,
        'consentable_id' => $siblingSession->id,
    ]);

    expect(fn () => EnsureVideoIsAvailableForSessionAction::new()->execute($data['session'], $data['minorClient']))
        ->toThrow(VideoException::class);
});

test('a minor client is blocked again once their consent grant is revoked', function () {
    $data = minorClientOnlineInSessionTherapySession();
    VideoConsent::factory()->revoked()->create([
        'ward_id' => $data['minorClient']->id,
        'consentable_type' => Therapy::class,
        'consentable_id' => $data['therapy']->id,
    ]);

    expect(fn () => EnsureVideoIsAvailableForSessionAction::new()->execute($data['session'], $data['minorClient']))
        ->toThrow(VideoException::class);
});

// TT-3.1e-a's "mode switches are prospective-only" guarantee, proven at the actual enforcement
// point: a grant made under the OLD mode still unlocks the join even after the therapy switches.
test('a grant made under a prior video consent mode still unlocks joining after the mode later switches', function () {
    $data = minorClientOnlineInSessionTherapySession();
    $data['therapy']->update(['video_consent_mode' => 'PER_SESSION']);
    VideoConsent::factory()->create([
        'ward_id' => $data['minorClient']->id,
        'consentable_type' => Session::class,
        'consentable_id' => $data['session']->id,
    ]);
    $data['therapy']->update(['video_consent_mode' => 'PER_THERAPY']);

    expect(fn () => EnsureVideoIsAvailableForSessionAction::new()->execute($data['session'], $data['minorClient']))
        ->not->toThrow(VideoException::class);
});

// TT-3.1 is 1:1 (individual Therapy) only -- GroupTherapy video is TT-3.2, not yet scoped.
test('a GroupTherapy-backed session is not yet available for video', function () {
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'public' => true,
    ]);
    $member = User::factory()->create();
    $groupTherapy->users()->attach($member->id, ['anonymous' => false]);
    $session = Session::factory()->create([
        'for_id' => $groupTherapy->id,
        'for_type' => GroupTherapy::class,
        'type' => 'ONLINE',
        'status' => 'IN_SESSION',
        'start_time' => now(),
    ]);

    expect(fn () => EnsureVideoIsAvailableForSessionAction::new()->execute($session, $member))
        ->toThrow(VideoException::class);
});
