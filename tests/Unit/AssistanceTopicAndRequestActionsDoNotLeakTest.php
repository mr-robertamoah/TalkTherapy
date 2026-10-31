<?php

use App\Actions\Therapy\EnsureCanSendTherapyAssistanceRequestAction;
use App\Actions\Therapy\EnsureTherapyHasNoAssistanceAction;
use App\Actions\TherapyTopic\EnsureCanCreateTherapyTopicAction;
use App\Actions\TherapyTopic\EnsureCanUpdateTherapyTopicAction;
use App\Actions\TherapyTopic\EnsureTherapyTopicExistsAction;
use App\DTOs\AssistTherapyDTO;
use App\DTOs\CreateRequestDTO;
use App\DTOs\CreateTherapyTopicDTO;
use App\Models\Counsellor;
use App\Models\Therapy;
use App\Models\TherapyTopic;
use App\Models\User;
use App\Services\DiscussionService;
use App\Services\TherapyService;

// SCRUM-344: the SCRUM-339/343 rule for assistance requests, topics and discussion requests --
// authorize first, refuse without naming anything, answer "not found" like "not allowed".

function aTherapyForLeak344(bool $public = false, bool $withCounsellor = false): array
{
    $client = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->create()->id, 'name' => 'Dr Secret Counsellor']);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $withCounsellor ? $counsellor->id : null,
        'public' => $public,
        'name' => 'My private struggles',
    ]);

    return [$therapy->fresh(), $client, $counsellor];
}

function messageForLeak344(callable $run): string
{
    try {
        $run();
    } catch (Throwable $e) {
        return $e->getMessage();
    }

    return '';
}

test('an outsider asking to assist is refused before anything about the therapy is revealed', function () {
    [$therapy] = aTherapyForLeak344(withCounsellor: true);

    $message = messageForLeak344(fn () => TherapyService::new()->sendAssistanceRequest(
        AssistTherapyDTO::new()->fromArray(['user' => User::factory()->create(), 'therapy' => $therapy])
    ));

    expect($message)->toBe('You are not allowed to do that with this therapy.')
        ->and($message)->not->toContain('Secret');
});

test('a counsellor may only offer to assist a public therapy', function () {
    [$private] = aTherapyForLeak344();
    [$public] = aTherapyForLeak344(public: true);
    $offering = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);

    expect(messageForLeak344(fn () => EnsureCanSendTherapyAssistanceRequestAction::new()->execute(
        AssistTherapyDTO::new()->fromArray(['user' => $offering->user, 'therapy' => $private])
    )))->toBe('You are not allowed to do that with this therapy.');
    expect(fn () => EnsureCanSendTherapyAssistanceRequestAction::new()->execute(
        AssistTherapyDTO::new()->fromArray(['user' => $offering->user, 'therapy' => $public])
    ))->not->toThrow(Throwable::class);
});

test('the therapy\'s own client may ask for assistance', function () {
    [$therapy, $client] = aTherapyForLeak344();

    expect(fn () => EnsureCanSendTherapyAssistanceRequestAction::new()->execute(
        AssistTherapyDTO::new()->fromArray(['user' => $client, 'therapy' => $therapy])
    ))->not->toThrow(Throwable::class);
});

test('an already-assisted therapy never names its counsellor', function () {
    [$therapy, $client] = aTherapyForLeak344(withCounsellor: true);

    expect(messageForLeak344(fn () => EnsureTherapyHasNoAssistanceAction::new()->execute(
        AssistTherapyDTO::new()->fromArray(['user' => $client, 'therapy' => $therapy])
    )))->toBe('This therapy already has a counsellor.');
});

test('creating a topic for someone else\'s therapy is refused without naming it', function () {
    [$therapy] = aTherapyForLeak344(withCounsellor: true);
    $other = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);

    $message = messageForLeak344(fn () => EnsureCanCreateTherapyTopicAction::new()->execute(
        CreateTherapyTopicDTO::new()->fromArray(['user' => $other->user, 'therapy' => $therapy])
    ));

    expect($message)->toBe('You are not allowed to do that with this therapy.')
        ->and($message)->not->toContain('struggles');
});

test('updating someone else\'s topic, or a missing one, gets the same refusal', function () {
    [$therapy, , $counsellor] = aTherapyForLeak344(withCounsellor: true);
    // TherapyTopic's $fillable predates the topicable_* rename -- forceCreate, as elsewhere.
    $topic = TherapyTopic::query()->forceCreate([
        'counsellor_id' => $counsellor->id,
        'topicable_id' => $therapy->id,
        'topicable_type' => Therapy::class,
        'name' => 'Childhood abuse',
    ]);
    $outsider = User::factory()->create();

    $refused = messageForLeak344(fn () => EnsureCanUpdateTherapyTopicAction::new()->execute(
        CreateTherapyTopicDTO::new()->fromArray(['user' => $outsider, 'therapyTopic' => $topic])
    ));
    $missing = messageForLeak344(fn () => EnsureTherapyTopicExistsAction::new()->execute(
        CreateTherapyTopicDTO::new()->fromArray(['user' => $outsider, 'therapyTopic' => null])
    ));

    expect($refused)->toBe('You are not allowed to do that with this topic.')
        ->and($missing)->toBe($refused)
        ->and($refused)->not->toContain('abuse');
});

test('a discussion request for a missing discussion gets the same refusal as a forbidden one', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);

    expect(messageForLeak344(fn () => DiscussionService::new()->sendCounsellorRequest(
        CreateRequestDTO::new()->fromArray(['from' => $counsellor, 'to' => null, 'for' => null])
    )))->toBe('You are not allowed to do that with this discussion.');
});

// The most realistic leak before the fix: any counsellor POSTing any therapy id.
test('a counsellor probing a private, already-assisted therapy learns nothing', function () {
    [$therapy] = aTherapyForLeak344(withCounsellor: true);
    $prober = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);

    $message = messageForLeak344(fn () => TherapyService::new()->sendAssistanceRequest(
        AssistTherapyDTO::new()->fromArray(['user' => $prober->user, 'therapy' => $therapy])
    ));

    expect($message)->toBe('You are not allowed to do that with this therapy.')
        ->and($message)->not->toContain('Secret');
});

test('a counsellor offering on a public, unassisted therapy still sends the request', function () {
    [$therapy, $client] = aTherapyForLeak344(public: true);
    $offering = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);

    TherapyService::new()->sendAssistanceRequest(
        AssistTherapyDTO::new()->fromArray(['user' => $offering->user, 'therapy' => $therapy])
    );

    expect($offering->sentRequests()->where('for_type', Therapy::class)->where('for_id', $therapy->id)->exists())->toBeTrue();
});
