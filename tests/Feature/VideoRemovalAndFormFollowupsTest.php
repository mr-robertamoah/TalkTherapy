<?php

use App\Actions\Visitor\SendVisitorsStatusToSuperAdminAction;
use App\Contracts\VideoProviderInterface;
use App\Http\Requests\CreateSessionRequest;
use App\Http\Requests\UpdateSessionRequest;
use App\Jobs\RetryVideoParticipantRemovalJob;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use App\Models\VideoSession;
use App\Models\VideoSessionParticipant;
use App\Support\VideoParticipantKey;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;

// SCRUM-315 (provider-side removal retried and surfaced), SCRUM-377 (session time messages),
// SCRUM-141 (paid therapies missing their payment settings), SCRUM-376 (super-admin guard).

function vrfProvider(array &$removed, int $failures = 0): VideoProviderInterface
{
    return new class($removed, $failures) implements VideoProviderInterface
    {
        public function __construct(private array &$removed, private int $failures) {}

        public function createRoom(VideoSession $videoSession): array
        {
            return [];
        }

        public function createParticipantCredentials(VideoSession $videoSession, User $user, string $displayName, bool $isOwner = false, bool $receiveOnly = false, bool $canSendVideo = true): array
        {
            return [];
        }

        public function endRoom(VideoSession $videoSession): void {}

        public function removeParticipant(VideoSession $videoSession, User $user): void
        {
            if ($this->failures-- > 0) {
                throw new RuntimeException('Provider unreachable.');
            }

            $this->removed[] = $user->id;
        }

        public function updateParticipantCapabilities(VideoSession $videoSession, User $user, bool $canSendAudio, bool $canSendVideo): void {}
    };
}

function vrfGroupCall(): array
{
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => User::factory()->adult()->create()->id, 'public' => true,
    ]);
    $counsellorUser = User::factory()->create();
    $groupTherapy->counsellors()->attach(Counsellor::factory()->create(['user_id' => $counsellorUser->id])->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $member = User::factory()->create();
    $groupTherapy->users()->attach($member->id, ['anonymous' => false]);
    $session = Session::factory()->create(['for_id' => $groupTherapy->id, 'for_type' => GroupTherapy::class]);
    $videoSession = VideoSession::factory()->create(['session_id' => $session->id, 'provider_room_id' => 'room-vrf']);
    $participant = VideoSessionParticipant::factory()->create([
        'video_session_id' => $videoSession->id, 'participant_type' => User::class,
        'participant_id' => $member->id, 'left_at' => null,
    ]);

    return compact('counsellorUser', 'member', 'session', 'videoSession', 'participant');
}

// --- SCRUM-315 --------------------------------------------------------------------------------

test('the counsellor is told when the video service did not confirm a removal, and a retry is queued', function () {
    Queue::fake();
    $removed = [];
    app()->instance(VideoProviderInterface::class, vrfProvider($removed, failures: 1));
    $call = vrfGroupCall();

    $this->actingAs($call['counsellorUser'])
        ->postJson(route('sessions.video.participants.remove', [
            'sessionId' => $call['session']->id,
            'participantKey' => VideoParticipantKey::for($call['session']->id, $call['member']->id),
        ]))
        ->assertOk()
        ->assertJsonPath('providerConfirmed', false)
        ->assertJsonPath('message', fn ($message) => str_contains($message, 'not confirmed'));

    expect($call['participant']->fresh()->left_at)->not->toBeNull();
    Queue::assertPushed(RetryVideoParticipantRemovalJob::class);
});

test('a confirmed removal says so and queues nothing', function () {
    Queue::fake();
    $removed = [];
    app()->instance(VideoProviderInterface::class, vrfProvider($removed));
    $call = vrfGroupCall();

    $this->actingAs($call['counsellorUser'])
        ->postJson(route('sessions.video.participants.remove', [
            'sessionId' => $call['session']->id,
            'participantKey' => VideoParticipantKey::for($call['session']->id, $call['member']->id),
        ]))
        ->assertOk()
        ->assertJsonPath('providerConfirmed', true);

    expect($removed)->toBe([$call['member']->id]);
    Queue::assertNotPushed(RetryVideoParticipantRemovalJob::class);
});

test('the retry asks the provider again', function () {
    $removed = [];
    app()->instance(VideoProviderInterface::class, vrfProvider($removed));
    $call = vrfGroupCall();
    $call['participant']->update(['left_at' => now()]);

    (new RetryVideoParticipantRemovalJob($call['videoSession']->id, $call['member']->id))->handle(app(VideoProviderInterface::class));

    expect($removed)->toBe([$call['member']->id]);
});

test('the retry leaves alone someone who joined again, and a call that has ended', function () {
    $removed = [];
    app()->instance(VideoProviderInterface::class, vrfProvider($removed));
    $rejoined = vrfGroupCall(); // their open join row stands for a later, allowed join
    $ended = vrfGroupCall();
    $ended['participant']->update(['left_at' => now()]);
    $ended['videoSession']->update(['ended_at' => now()]);

    foreach ([$rejoined, $ended] as $call) {
        (new RetryVideoParticipantRemovalJob($call['videoSession']->id, $call['member']->id))->handle(app(VideoProviderInterface::class));
    }

    expect($removed)->toBe([]);
});

test('a failing retry throws so the queue tries again', function () {
    $removed = [];
    app()->instance(VideoProviderInterface::class, vrfProvider($removed, failures: 1));
    $call = vrfGroupCall();
    $call['participant']->update(['left_at' => now()]);

    expect(fn () => (new RetryVideoParticipantRemovalJob($call['videoSession']->id, $call['member']->id))->handle(app(VideoProviderInterface::class)))
        ->toThrow(RuntimeException::class);
});

// --- SCRUM-377 --------------------------------------------------------------------------------

test('a session start under 30 minutes away gets the custom message, not Laravel\'s "prohibited" one', function (string $requestClass) {
    $request = $requestClass::create('/x', 'POST', [
        'startTime' => now()->addMinutes(10)->toIso8601String(),
        'endTime' => now()->addMinutes(20)->toIso8601String(),
    ]);

    $errors = Validator::make($request->all(), $request->rules(), $request->messages())->errors();

    expect($errors->first('startTime'))->toContain('at least 30 minutes from now')
        ->and($errors->first('endTime'))->toContain('at least 30 minutes from the start time');
})->with([[CreateSessionRequest::class], [UpdateSessionRequest::class]]);

// --- SCRUM-141 --------------------------------------------------------------------------------

test('the audit lists paid therapies missing their payment settings, and only those', function () {
    $owner = User::factory()->adult()->create();
    $broken = Therapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $owner->id, 'payment_type' => 'PAID', 'payment_data' => null,
    ]);
    Therapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $owner->id, 'payment_type' => 'PAID',
        'payment_data' => ['amount' => 50, 'currency' => 'GHS', 'per' => 'PER_THERAPY'],
    ]);
    Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $owner->id, 'payment_type' => 'FREE', 'payment_data' => null]);
    $brokenGroup = GroupTherapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $owner->id, 'payment_type' => 'PAID',
        'payment_data' => ['currency' => 'GHS', 'per' => 'PER_SESSION'],
    ]);

    $this->artisan('payments:audit-paid-therapies')
        ->expectsTable(['Type', 'ID', 'Name', 'Owner', 'Missing'], [
            ['Therapy', $broken->id, $broken->name, "User #{$owner->id}", 'amount, currency, per'],
            ['GroupTherapy', $brokenGroup->id, $brokenGroup->name, "User #{$owner->id}", 'amount'],
        ])
        ->assertExitCode(1);
});

test('the audit passes when nothing is missing', function () {
    $this->artisan('payments:audit-paid-therapies')->assertExitCode(0);
});

// --- SCRUM-376 --------------------------------------------------------------------------------

test('the daily visitors status logs instead of crashing when there is no super admin', function () {
    Log::spy();

    SendVisitorsStatusToSuperAdminAction::new()->execute();

    Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'No super admin'));
});
