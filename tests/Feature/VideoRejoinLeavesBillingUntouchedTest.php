<?php

use App\Actions\Session\FailUnheldSessionsAction;
use App\Enums\SessionStatusEnum;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use App\Models\VideoSession;
use App\Models\VideoSessionParticipant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

// TT-3.3d/SCRUM-461: the closeout regression for SCRUM-28 (video on weak connections). A weak line
// can make a browser rejoin automatically several times in a row without ever leaving (SCRUM-459).
// None of that may reach the session's own record or its money: Session.status, attendance, whether
// the scheduler fails an unheld session, counsellor earnings, transactions and organisation invoice
// lines all stay as they were, and the call still counts each person once (the Chime head count,
// JoinVideoSessionAction::ensureChimeGroupTherapyCapacity). Each rejoin closes the row it replaces.
// The single-rejoin and refused-rejoin cases are in VideoRejoinGuardTest; Join/Leave/End vs.
// Session.status in VideoActionsNeverMutateSessionStatusTest.

// Starts inside its window, awaiting confirmation -- the state FailUnheldSessionsAction acts on once
// it is past its end.
function vrbSessionFor(string $forType, int $forId): Session
{
    return Session::factory()->create([
        'for_id' => $forId,
        'for_type' => $forType,
        'type' => 'ONLINE',
        'status' => SessionStatusEnum::in_session_confirmation->value,
        'start_time' => now()->subMinutes(10),
        'end_time' => now()->addMinutes(50),
    ]);
}

function vrbOneToOne(): array
{
    $client = User::factory()->adult()->create();
    $counsellorUser = User::factory()->adult()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id,
    ]);

    return [
        'people' => [$client, $counsellorUser],
        'session' => vrbSessionFor(Therapy::class, $therapy->id),
        'control' => vrbSessionFor(Therapy::class, $therapy->id),
    ];
}

function vrbGroup(): array
{
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => User::factory()->adult()->create()->id,
        'public' => true, 'anonymous' => false,
    ]);
    $counsellorUser = User::factory()->adult()->create();
    $groupTherapy->counsellors()->attach(Counsellor::factory()->create(['user_id' => $counsellorUser->id])->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $member = User::factory()->adult()->create();
    $groupTherapy->users()->attach($member->id, ['anonymous' => false, 'was_minor_at_join' => false]);

    return [
        'people' => [$member, $counsellorUser],
        'session' => vrbSessionFor(GroupTherapy::class, $groupTherapy->id),
        'control' => vrbSessionFor(GroupTherapy::class, $groupTherapy->id),
    ];
}

// Everything a video call must never change.
function vrbSnapshot(Session $session): array
{
    $session = $session->fresh();

    return [
        'status' => $session->status,
        'ended_at' => $session->ended_at,
        'attendances' => DB::table('session_attendances')->orderBy('id')->get()->toArray(),
        'earnings' => DB::table('counsellor_earnings')->orderBy('id')->get()->toArray(),
        'transactions' => DB::table('transactions')->orderBy('id')->get()->toArray(),
        'invoice_lines' => DB::table('organization_invoice_lines')->orderBy('id')->get()->toArray(),
    ];
}

// The same count the Chime capacity check makes: distinct people with an open row in this call.
function vrbPeopleInCall(int $videoSessionId): int
{
    return VideoSessionParticipant::query()
        ->where('video_session_id', $videoSessionId)
        ->where('participant_type', User::class)
        ->whereNull('left_at')
        ->distinct()
        ->count('participant_id');
}

dataset('vrb calls', [
    'one-to-one therapy' => [fn () => vrbOneToOne()],
    'group therapy' => [fn () => vrbGroup()],
]);

test('three automatic rejoins in a row leave the session, attendance and money untouched', function (Closure $call) {
    config(['video.provider' => 'fake']);
    Notification::fake();
    ['people' => $people, 'session' => $session, 'control' => $control] = $call();
    $before = vrbSnapshot($session);

    $videoSessionId = null;
    foreach ($people as $person) {
        $videoSessionId = $this->actingAs($person)
            ->postJson(route('sessions.video.join', ['sessionId' => $session->id]))
            ->assertOk()
            ->json('videoSessionId');
    }
    expect(vrbPeopleInCall($videoSessionId))->toBe(2);

    // Each person drops and rejoins three times, never leaving.
    foreach ($people as $person) {
        foreach (range(1, 3) as $attempt) {
            $this->travel(1)->seconds();
            $this->actingAs($person)
                ->postJson(route('sessions.video.join', ['sessionId' => $session->id]), ['rejoin' => true, 'videoSessionId' => $videoSessionId])
                ->assertOk()
                ->assertJsonPath('videoSessionId', $videoSessionId);

            expect(vrbPeopleInCall($videoSessionId))->toBe(2);
        }
    }

    expect(VideoSession::query()->where('session_id', $session->id)->count())->toBe(1);
    $videoSession = VideoSession::find($videoSessionId);
    foreach ($people as $person) {
        $rows = $videoSession->participantRowsFor($person->id)->orderBy('id')->get();
        // The first join plus three rejoins; each rejoin closed the one before it (left, not removed).
        expect($rows)->toHaveCount(4)
            ->and($rows->whereNull('left_at'))->toHaveCount(1)
            ->and($rows->last()->left_at)->toBeNull()
            ->and($rows->whereNotNull('removed_at'))->toHaveCount(0);
    }

    expect(vrbSnapshot($session))->toEqual($before);

    // Past its end, the scheduler treats the session exactly as it treats the same session with no video at all.
    $this->travel(2)->hours();
    $controlBefore = $control->fresh()->status;
    FailUnheldSessionsAction::new()->execute();

    expect($controlBefore)->toBe($before['status'])
        ->and($control->fresh()->status)->toBe(SessionStatusEnum::failed->value)
        ->and($session->fresh()->status)->toBe($control->fresh()->status)
        ->and($session->fresh()->ended_at === null)->toBe($control->fresh()->ended_at === null);

    $after = vrbSnapshot($session);
    expect(collect($after)->except(['status', 'ended_at'])->all())
        ->toEqual(collect($before)->except(['status', 'ended_at'])->all());
})->with('vrb calls');
