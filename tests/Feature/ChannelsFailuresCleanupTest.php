<?php

use App\Actions\Discussion\NotifyParticipantsOfStartingDiscussionsAction;
use App\Actions\Session\NotifyParticipantsOfStartingSessionsAction;
use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Enums\TransactionStatusEnum;
use App\Events\DiscussionRequestResponseEvent;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\GroupTherapy;
use App\Models\Request;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\DiscussionDueNotification;
use App\Notifications\DiscussionUpdatedNotification;
use App\Notifications\GuardianshipRemovedNotification;
use App\Notifications\SessionCreatedNotification;
use App\Notifications\SessionDueNotification;
use Carbon\CarbonInterface;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Notification;

// SCRUM-372: realtime leftovers (SCRUM-370 and 371 are covered by their own tests).

function cfcGroupWithPaidSession(): array
{
    $creator = User::factory()->adult()->create();
    $member = User::factory()->adult()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $creator->id, 'payment_type' => 'PAID']);
    $group->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);
    $group->users()->attach($member->id, ['anonymous' => false, 'background_story' => 'x']);
    $session = Session::factory()->create(['for_id' => $group->id, 'for_type' => GroupTherapy::class, 'payment_type' => 'PAID']);
    Transaction::factory()->create(['for_type' => Session::class, 'for_id' => $session->id, 'user_id' => $creator->id, 'status' => TransactionStatusEnum::success->value]);

    return compact('creator', 'member', 'counsellor', 'group', 'session');
}

test('a discussion invitation answer goes to the creator\'s counsellor channel, also when the creator is their user account', function () {
    $creatorCounsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $invited = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()->adult()->create()->id, 'counsellor_id' => $creatorCounsellor->id]);

    foreach ([$creatorCounsellor, $creatorCounsellor->user] as $creator) {
        $discussion = Discussion::factory()->create([
            'for_id' => $therapy->id, 'for_type' => Therapy::class,
            'addedby_type' => $creator::class, 'addedby_id' => $creator->id,
        ]);
        $request = Request::factory()->for($creatorCounsellor, 'from')->for($invited, 'to')->for($discussion, 'for')->create([
            'type' => RequestTypeEnum::discussion->value, 'status' => RequestStatusEnum::accepted->value, 'data' => [],
        ]);

        $channels = (new DiscussionRequestResponseEvent($request))->broadcastOn();

        expect($channels)->toHaveCount(1)
            ->and($channels[0])->toBeInstanceOf(PrivateChannel::class)
            ->and($channels[0]->name)->toBe("private-counsellors.{$creatorCounsellor->id}");
    }
});

test('discussion times are dates, like session times', function () {
    $discussion = Discussion::factory()->create();

    expect($discussion->fresh()->start_time)->toBeInstanceOf(CarbonInterface::class)
        ->and($discussion->fresh()->end_time)->toBeInstanceOf(CarbonInterface::class);
});

test('the "due" reminders go out once per session and discussion, the discussion one to its creator too', function () {
    Notification::fake();
    $client = User::factory()->adult()->create();
    $creator = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $invited = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => $creator->id]);
    Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class, 'status' => 'PENDING', 'start_time' => now()->addMinutes(20), 'end_time' => now()->addMinutes(80)]);
    $discussion = Discussion::factory()->create([
        'for_id' => $therapy->id, 'for_type' => Therapy::class, 'addedby_type' => Counsellor::class, 'addedby_id' => $creator->id,
        'status' => 'PENDING', 'start_time' => now()->addMinutes(20), 'end_time' => now()->addMinutes(80),
    ]);
    $discussion->counsellors()->attach($invited->id);

    foreach (range(1, 3) as $run) {
        NotifyParticipantsOfStartingSessionsAction::new()->execute();
        NotifyParticipantsOfStartingDiscussionsAction::new()->execute();
    }

    Notification::assertSentToTimes($client, SessionDueNotification::class, 1);
    Notification::assertSentToTimes($creator, DiscussionDueNotification::class, 1);
    Notification::assertSentToTimes($invited, DiscussionDueNotification::class, 1);
});

test('a guardianship notification never carries the acting user\'s date of birth to the other party', function () {
    $actor = User::factory()->adult()->create();
    $other = User::factory()->adult()->create();
    $this->actingAs($actor);
    request()->setUserResolver(fn () => $actor);

    $payload = (new GuardianshipRemovedNotification($actor))->toBroadcast($other)->data['user'];

    expect($payload)->not->toHaveKey('dob');
});

// SCRUM-374: notification payloads are small fixed summaries -- identical for every recipient, with
// no per-viewer field (payment status, dob) that could carry one person's view to another.
test('session and discussion notifications carry the same small summary to every recipient', function () {
    $g = cfcGroupWithPaidSession();
    $this->actingAs($g['counsellor']->user);
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()->adult()->create()->id]);
    $creatorUser = User::factory()->adult()->create();
    $creatorUser->counsellor()->save(Counsellor::factory()->make());
    $discussion = Discussion::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class, 'addedby_type' => User::class, 'addedby_id' => $creatorUser->id]);
    $admin = User::factory()->has(Administrator::factory())->create();

    $session = (new SessionCreatedNotification($g['session']->fresh()));
    $forMember = $session->toBroadcast($g['member'])->data['session'];
    $forCounsellor = $session->toBroadcast($g['counsellor']->user)->data['session'];

    $discussionPayload = (new DiscussionUpdatedNotification($discussion->fresh()))->toBroadcast($admin)->data['discussion'];

    expect(array_keys($forMember))->toBe(['id', 'name', 'startTime', 'endTime', 'status'])
        ->and($forMember)->toEqual($forCounsellor)
        ->and(array_keys($discussionPayload))->toBe(['id', 'name', 'startTime', 'endTime', 'status']);
});
