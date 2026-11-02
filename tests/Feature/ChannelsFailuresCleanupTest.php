<?php

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
use App\Services\AppService;
use Carbon\CarbonInterface;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

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

test('a session notification is built as its recipient, never as whoever is signed in', function () {
    $g = cfcGroupWithPaidSession();
    // The group's counsellor is the one acting (and allowed to see the group's payment status)...
    $this->actingAs($g['counsellor']->user);
    request()->setUserResolver(fn () => $g['counsellor']->user);

    $notification = new SessionCreatedNotification($g['session']->fresh());

    // ...but a member it is sent to gets their own view, and the counsellor theirs.
    expect($notification->toBroadcast($g['member'])->data['session']['paymentStatus'])->toBeNull()
        ->and($notification->toBroadcast($g['counsellor']->user)->data['session']['paymentStatus'])->toBe(TransactionStatusEnum::success->value)
        ->and(request()->user()->id)->toBe($g['counsellor']->user->id);
});

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
        AppService::new()->notifyParticipantsOfStartingSessions();
        AppService::new()->notifyParticipantsOfStartingDiscussions();
    }

    Notification::assertSentToTimes($client, SessionDueNotification::class, 1);
    Notification::assertSentToTimes($creator, DiscussionDueNotification::class, 1);
    Notification::assertSentToTimes($invited, DiscussionDueNotification::class, 1);
});

test('one recipient\'s view never carries over to the next (an admin first, then a counsellor)', function () {
    $creatorUser = User::factory()->adult()->create();
    $creatorUser->counsellor()->save(Counsellor::factory()->make());
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()->adult()->create()->id]);
    // Created by the counsellor's user account, so DiscussionResource nests UserMiniResource (with its dob rule).
    $discussion = Discussion::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class, 'addedby_type' => User::class, 'addedby_id' => $creatorUser->id]);
    $admin = User::factory()->has(Administrator::factory())->create();
    $otherCounsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $notification = new DiscussionUpdatedNotification($discussion->fresh());

    $forAdmin = $notification->toBroadcast($admin)->data['discussion'];
    $forCounsellor = $notification->toBroadcast($otherCounsellor)->data['discussion'];

    expect(json_encode($forAdmin))->toContain('"dob"')
        ->and(json_encode($forCounsellor))->not->toContain('"dob"');
});

test('a guardianship notification never carries the acting user\'s date of birth to the other party', function () {
    $actor = User::factory()->adult()->create();
    $other = User::factory()->adult()->create();
    $this->actingAs($actor);
    request()->setUserResolver(fn () => $actor);

    $payload = (new GuardianshipRemovedNotification($actor))->toBroadcast($other)->data['user'];

    expect($payload)->not->toHaveKey('dob');
});

test('urls inside a notification payload keep the app\'s host', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()->adult()->create()->id]);
    $discussion = Discussion::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class, 'addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id]);
    $before = url('/x');

    (new DiscussionUpdatedNotification($discussion->fresh()))->toBroadcast($counsellor);

    expect(url('/x'))->toBe($before)
        ->and(Str::startsWith(app('url')->to('/'), config('app.url')))->toBeTrue();
});
