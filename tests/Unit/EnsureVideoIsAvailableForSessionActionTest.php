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
    $client = User::factory()->create();
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

test('the assigned counsellor is a valid participant for video', function () {
    $session = onlineInSessionTherapySession();
    $counsellorUser = $session->for->counsellor->user;

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
