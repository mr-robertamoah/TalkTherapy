<?php

use App\Actions\Session\EnsureCanCreateSessionAction;
use App\DTOs\CreateSessionDTO;
use App\Exceptions\SessionException;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;

// SCRUM-210: refusing a session create must never reveal anything about the target therapy to
// someone who isn't allowed to create sessions for it.

function aPrivateTherapyForSessionCreateLeak(bool $withActiveSession = false): array
{
    $client = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id,
        'public' => false,
        'anonymous' => true,
        'name' => 'Very Private Therapy Name',
    ]);

    if ($withActiveSession) {
        Session::factory()->create([
            'for_id' => $therapy->id,
            'for_type' => Therapy::class,
            'status' => 'IN_SESSION',
        ]);
    }

    return [$therapy->fresh(), $counsellor];
}

function attemptSessionCreateForLeakTest(User $user, Therapy $therapy): string
{
    try {
        EnsureCanCreateSessionAction::new()->execute(CreateSessionDTO::new()->fromArray(['user' => $user, 'for' => $therapy]));
    } catch (SessionException $e) {
        return $e->getMessage();
    }

    return '';
}

test('an unrelated user is refused without the therapy being named', function () {
    [$therapy] = aPrivateTherapyForSessionCreateLeak();

    $message = attemptSessionCreateForLeakTest(User::factory()->create(), $therapy);

    expect($message)->toBe('You are not allowed to create a session here.')
        ->and($message)->not->toContain('Very Private Therapy Name');
});

test('an unrelated user is refused the same way even when the therapy has a live session', function () {
    [$therapy] = aPrivateTherapyForSessionCreateLeak(withActiveSession: true);

    expect(attemptSessionCreateForLeakTest(User::factory()->create(), $therapy))
        ->toBe('You are not allowed to create a session here.');
});

test('the therapy\'s own counsellor still gets the specific active-session message', function () {
    [$therapy, $counsellor] = aPrivateTherapyForSessionCreateLeak(withActiveSession: true);

    expect(attemptSessionCreateForLeakTest($counsellor->user, $therapy))
        ->toContain('has an active session');
});

test('the therapy\'s own counsellor passes when there is no active session', function () {
    [$therapy, $counsellor] = aPrivateTherapyForSessionCreateLeak();

    expect(attemptSessionCreateForLeakTest($counsellor->user, $therapy))->toBe('');
});

test('a counsellor who is not on this therapy is refused the same generic way', function () {
    [$therapy] = aPrivateTherapyForSessionCreateLeak(withActiveSession: true);
    $otherCounsellor = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);

    expect(attemptSessionCreateForLeakTest($otherCounsellor->user, $therapy))
        ->toBe('You are not allowed to create a session here.');
});

test('an admin is allowed', function () {
    [$therapy] = aPrivateTherapyForSessionCreateLeak();
    $admin = User::factory()->has(Administrator::factory())->create();

    expect(attemptSessionCreateForLeakTest($admin, $therapy))->toBe('');
});

test('a counsellor targeting a therapy with no counsellor yet is refused, not met with an error', function () {
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory()->create()->id,
        'counsellor_id' => null,
    ]);
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);

    expect(attemptSessionCreateForLeakTest($counsellor->user, $therapy->fresh()))
        ->toBe('You are not allowed to create a session here.');
});
