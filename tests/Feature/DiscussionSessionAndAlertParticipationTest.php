<?php

use App\Actions\Discussion\EnsureDiscussionDataIsValidAction;
use App\Actions\Discussion\UpdateDiscussionAction;
use App\DTOs\CreateDiscussionDTO;
use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;

// SCRUM-352: a discussion's session must be its own therapy's; only participants wait for alerts.

function aTherapyWithSessionFor352(): array
{
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()->create()->id, 'counsellor_id' => $counsellor->id]);
    $session = Session::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id]);

    return [$therapy, $session, $counsellor];
}

function discussionMessageFor352(array $data): string
{
    try {
        EnsureDiscussionDataIsValidAction::new()->execute(CreateDiscussionDTO::new()->fromArray($data));
    } catch (Throwable $e) {
        return $e->getMessage();
    }

    return '';
}

test('a discussion can\'t be tied to another therapy\'s session', function () {
    [$therapy, $ownSession, $counsellor] = aTherapyWithSessionFor352();
    [, $foreignSession] = aTherapyWithSessionFor352();
    $groupSession = Session::factory()->create(['for_type' => GroupTherapy::class, 'for_id' => $therapy->id]);

    $ownSession->update(['start_time' => now()->subDays(3), 'end_time' => now()->subDays(3)->addHour()]);
    $base = ['user' => $counsellor->user, 'addedby' => $counsellor, 'for' => $therapy, 'name' => 'Case review',
        'startTime' => now()->addDays(2)->toDateTimeString(), 'endTime' => now()->addDays(2)->addHour()->toDateTimeString()];

    expect(discussionMessageFor352([...$base, 'session' => $foreignSession]))->toBe('That session is not part of this therapy.')
        // Same id, different kind of therapy: still not this therapy's.
        ->and(discussionMessageFor352([...$base, 'session' => $groupSession]))->toBe('That session is not part of this therapy.')
        // Its own session passes validation entirely.
        ->and(discussionMessageFor352([...$base, 'session' => $ownSession]))->toBe('');
});

test('adding a session to a discussion that had none no longer crashes', function () {
    [$therapy, $session, $counsellor] = aTherapyWithSessionFor352();
    $discussion = Discussion::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id, 'for_type' => Therapy::class, 'for_id' => $therapy->id, 'session_id' => null]);

    UpdateDiscussionAction::new()->execute(CreateDiscussionDTO::new()->fromArray([
        'user' => $counsellor->user, 'discussion' => $discussion, 'for' => $therapy, 'session' => $session,
    ]));

    expect($discussion->fresh()->session_id)->toBe($session->id);
});

test('only a participant can wait for a therapy\'s alerts, and a stranger can\'t tell it exists', function () {
    [$therapy, , $counsellor] = aTherapyWithSessionFor352();
    $stranger = User::factory()->create();

    $this->actingAs($counsellor->user)->postJson(route('alert.wait'), ['alertableType' => 'Therapy', 'alertableId' => $therapy->id])->assertSuccessful();

    $refused = $this->actingAs($stranger)->postJson(route('alert.wait'), ['alertableType' => 'Therapy', 'alertableId' => $therapy->id])->assertStatus(422);
    $missing = $this->actingAs($stranger)->postJson(route('alert.wait'), ['alertableType' => 'Therapy', 'alertableId' => 999999])->assertStatus(422);
    expect($refused->json('message'))->toBe($missing->json('message'));
});

test('over HTTP, creating a discussion with another therapy\'s session is refused', function () {
    [$therapy, , $counsellor] = aTherapyWithSessionFor352();
    [, $foreignSession] = aTherapyWithSessionFor352();

    $this->actingAs($counsellor->user)->postJson(route('api.discussions.create'), [
        'name' => 'Case review', 'description' => 'x',
        'addedbyType' => 'Counsellor', 'addedbyId' => $counsellor->id,
        'forType' => 'Therapy', 'forId' => $therapy->id,
        'sessionId' => $foreignSession->id,
        'startTime' => now()->addDays(2)->toDateTimeString(), 'endTime' => now()->addDays(2)->addHour()->toDateTimeString(),
    ])->assertStatus(422)->assertJsonPath('message', 'That session is not part of this therapy.');

    expect(Discussion::query()->count())->toBe(0);
});

test('a stranger can\'t wait for a group\'s alerts either, and other model types get the same answer', function () {
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()->create()->id]);
    $stranger = User::factory()->create();

    $group_ = $this->actingAs($stranger)->postJson(route('alert.wait'), ['alertableType' => 'GroupTherapy', 'alertableId' => $group->id])->assertStatus(422);
    $session = $this->actingAs($stranger)->postJson(route('alert.wait'), ['alertableType' => 'Session', 'alertableId' => Session::factory()->create()->id])->assertStatus(422);
    expect($group_->json('message'))->toBe($session->json('message'));
});

test('the migration unlinks discussions tied to another therapy\'s session', function () {
    [$therapy, $ownSession, $counsellor] = aTherapyWithSessionFor352();
    [, $foreignSession] = aTherapyWithSessionFor352();
    $bad = Discussion::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id, 'for_type' => Therapy::class, 'for_id' => $therapy->id, 'session_id' => $foreignSession->id]);
    $good = Discussion::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id, 'for_type' => Therapy::class, 'for_id' => $therapy->id, 'session_id' => $ownSession->id]);

    (require database_path('migrations/2026_11_01_100000_unlink_discussions_from_other_therapies_sessions.php'))->up();

    expect($bad->fresh()->session_id)->toBeNull()->and($good->fresh()->session_id)->toBe($ownSession->id);
});
