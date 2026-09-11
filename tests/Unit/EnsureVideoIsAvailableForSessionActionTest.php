<?php

use App\Actions\Video\EnsureVideoIsAvailableForSessionAction;
use App\Exceptions\VideoException;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;

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

// TT-3.1e/SCRUM-278 interim safeguard: a minor client (User::factory()'s own default -- dob is
// null, so isAdult() is false unless ->adult() is used, see UserFactory's own comment) must be
// blocked entirely until the real guardian-consent flow ships, regardless of everything else
// about the session being otherwise available.
test('a minor client is blocked from joining video even on an otherwise-available session', function () {
    $minorClient = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $minorClient->id,
        'counsellor_id' => $counsellor->id,
    ]);
    $session = Session::factory()->create([
        'for_id' => $therapy->id,
        'for_type' => Therapy::class,
        'type' => 'ONLINE',
        'status' => 'IN_SESSION',
        'start_time' => now(),
    ]);

    expect(fn () => EnsureVideoIsAvailableForSessionAction::new()->execute($session, $minorClient))
        ->toThrow(VideoException::class, 'Video is not yet available for accounts under 18. Guardian consent support is coming soon.');
});

// The counsellor side is never gated by this interim block -- mirrors this codebase's own
// established "counsellor is never gated" convention from the entire payment-gate epic.
test('the counsellor can still join video even when the therapy\'s own client is a minor', function () {
    $minorClient = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $minorClient->id,
        'counsellor_id' => $counsellor->id,
    ]);
    $session = Session::factory()->create([
        'for_id' => $therapy->id,
        'for_type' => Therapy::class,
        'type' => 'ONLINE',
        'status' => 'IN_SESSION',
        'start_time' => now(),
    ]);

    expect(fn () => EnsureVideoIsAvailableForSessionAction::new()->execute($session, $counsellorUser))
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
