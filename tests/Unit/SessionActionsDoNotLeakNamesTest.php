<?php

use App\Actions\Session\EnsureCanChangeSessionTopicAction;
use App\Actions\Session\EnsureCanDeleteSessionAction;
use App\Actions\Session\EnsureCanEndSessionAction;
use App\Actions\Session\EnsureCanUpdateSessionAction;
use App\Actions\Session\EnsureCanUpdateSessionStatusAction;
use App\Actions\Session\EnsureSessionExistsAction;
use App\Actions\Session\EnsureTherapyExistsAction;
use App\DTOs\CreateSessionDTO;
use App\Exceptions\SessionException;
use App\Models\Counsellor;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;

// SCRUM-339: refusing a session action must never name the session (free text about therapy
// content) to someone with no access to it -- while the session's own counsellor still gets the
// specific, named state message.

function aLiveNamedSessionForLeakTest(string $status = 'IN_SESSION'): array
{
    $client = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id,
        'public' => false,
    ]);
    $session = Session::factory()->create([
        'name' => 'Talking about my divorce',
        'for_id' => $therapy->id,
        'for_type' => Therapy::class,
        'addedby_type' => Counsellor::class,
        'addedby_id' => $counsellor->id,
        'status' => $status,
        'start_time' => now()->subMinutes(10),
        'end_time' => now()->addHour(),
    ]);

    return [$session, $therapy, $counsellor];
}

function sessionActionMessageForLeakTest(callable $run): string
{
    try {
        $run();
    } catch (SessionException $e) {
        return $e->getMessage();
    }

    return '';
}

dataset('session actions that refuse outsiders', [
    'update' => [fn ($dto) => EnsureCanUpdateSessionAction::new()->execute($dto), 'You are not allowed to do that with this session.'],
    'delete' => [fn ($dto) => EnsureCanDeleteSessionAction::new()->execute($dto), 'You are not allowed to do that with this session.'],
    'change topic' => [fn ($dto) => EnsureCanChangeSessionTopicAction::new()->execute($dto), 'You are not allowed to do that with this session.'],
    'end' => [fn ($dto) => EnsureCanEndSessionAction::new()->execute($dto), 'You are not allowed to do that with this session.'],
    'start' => [fn ($dto) => EnsureCanUpdateSessionStatusAction::new()->execute($dto), 'You are not allowed to do that with this session.'],
]);

test('an outsider is refused without the session being named', function (Closure $action, string $expected) {
    [$session, $therapy] = aLiveNamedSessionForLeakTest();
    $dto = CreateSessionDTO::new()->fromArray(['user' => User::factory()->create(), 'session' => $session, 'for' => $therapy]);

    $message = sessionActionMessageForLeakTest(fn () => $action($dto));

    expect($message)->toBe($expected)
        ->and($message)->not->toContain('divorce');
})->with('session actions that refuse outsiders');

test('the session\'s own counsellor still gets the specific state message', function () {
    [$session, $therapy, $counsellor] = aLiveNamedSessionForLeakTest();
    $dto = CreateSessionDTO::new()->fromArray(['user' => $counsellor->user, 'session' => $session, 'for' => $therapy]);

    expect(sessionActionMessageForLeakTest(fn () => EnsureCanUpdateSessionAction::new()->execute($dto)))->toContain('cannot be updated')
        ->and(sessionActionMessageForLeakTest(fn () => EnsureCanDeleteSessionAction::new()->execute($dto)))->toContain('cannot be deleted');
});

test('changing the topic of a session that is not live tells its own counsellor why', function () {
    [$session, $therapy, $counsellor] = aLiveNamedSessionForLeakTest('PENDING');
    $dto = CreateSessionDTO::new()->fromArray(['user' => $counsellor->user, 'session' => $session, 'for' => $therapy]);

    expect(sessionActionMessageForLeakTest(fn () => EnsureCanChangeSessionTopicAction::new()->execute($dto)))
        ->toContain('must be in session');
});

test('a missing session gets the same answer as one the caller may not use', function () {
    $dto = CreateSessionDTO::new()->fromArray(['user' => User::factory()->create(), 'session' => null]);

    expect(sessionActionMessageForLeakTest(fn () => EnsureSessionExistsAction::new()->execute($dto)))
        ->toBe('You are not allowed to do that with this session.');
});

test('a participant ending a session too early is told why', function () {
    [$session, $therapy, $counsellor] = aLiveNamedSessionForLeakTest();
    $dto = CreateSessionDTO::new()->fromArray(['user' => $counsellor->user, 'session' => $session, 'for' => $therapy]);

    expect(sessionActionMessageForLeakTest(fn () => EnsureCanEndSessionAction::new()->execute($dto)))
        ->toBe('You can only end this session once its end time has passed.');
});

test('a missing therapy gets the same answer as one the caller may not use', function () {
    $dto = CreateSessionDTO::new()->fromArray(['user' => User::factory()->create(), 'for' => null]);

    expect(sessionActionMessageForLeakTest(fn () => EnsureTherapyExistsAction::new()->execute($dto)))
        ->toBe('You are not allowed to create a session here.');
});
