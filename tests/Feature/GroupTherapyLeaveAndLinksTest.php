<?php

use App\Actions\CounsellorVerification\EnsureCounsellorMayTakeOnClientsAction;
use App\Actions\GroupTherapy\AddCounsellorToGroupTherapyAction;
use App\Actions\GroupTherapy\AddGroupTherapyMemberAction;
use App\Actions\GroupTherapy\LeaveGroupTherapyAction;
use App\Actions\Link\EnsureUserCanCreateLinkForAction;
use App\Actions\Link\PerformGroupTherapyLinkAction;
use App\Actions\User\EnsureDobChangeIsAllowedAction;
use App\Actions\Video\RemovePersonFromLiveCallsAction;
use App\Contracts\VideoProviderInterface;
use App\DTOs\CreateLinkDTO;
use App\Enums\ConstantsEnum;
use App\Enums\LinkStateEnum;
use App\Enums\LinkTypeEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Enums\SessionStatusEnum;
use App\Enums\SuspensionScopeEnum;
use App\Enums\TherapyStatusEnum;
use App\Events\GroupTherapyLeftEvent;
use App\Events\GroupTherapyMembershipChangedEvent;
use App\Exceptions\GuardianRequiredException;
use App\Exceptions\LinkException;
use App\Http\Resources\MessageResource;
use App\Jobs\RemovePersonFromLiveCallJob;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Guardianship;
use App\Models\Link;
use App\Models\Message;
use App\Models\Report;
use App\Models\Request;
use App\Models\Session;
use App\Models\Suspension;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VideoSession;
use App\Models\VideoSessionParticipant;
use App\Notifications\GroupTherapyCounsellorLinkUsedNotification;
use App\Notifications\GroupTherapyMemberJoinedGuardianNotification;
use App\Notifications\GroupTherapyMemberLeftGuardianNotification;
use App\Notifications\GroupTherapyMemberLeftNotification;
use App\Support\TherapyLink;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// SCRUM-73 (leaving a group) and SCRUM-383 (group invitation links). Helpers are file-local and
// gtl-prefixed (--parallel).

beforeEach(function () {
    Notification::fake();
});

function gtlCounsellor(array $attributes = []): Counsellor
{
    return Counsellor::factory()->create(array_merge(['user_id' => User::factory()->adult()->create()->id, 'verified_at' => now()], $attributes));
}

// A running group created by an adult user, with an active counsellor.
function gtlGroup(array $attributes = []): array
{
    $creator = User::factory()->adult()->create(['email_verified_at' => now()]);
    $counsellor = gtlCounsellor();
    $group = GroupTherapy::factory()->create(array_merge([
        'addedby_type' => User::class, 'addedby_id' => $creator->id, 'anonymous' => false, 'public' => false,
        'allow_anyone' => true, 'max_users' => 5, 'max_counsellors' => 3, 'status' => TherapyStatusEnum::in_session->value,
    ], $attributes));
    $group->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);

    return [$group, $creator, $counsellor];
}

function gtlMember(GroupTherapy $group, ?User $user = null, bool $anonymous = false): User
{
    $user ??= User::factory()->adult()->create();
    $group->allMemberships()->attach($user->id, ['anonymous' => $anonymous, 'was_minor_at_join' => ! $user->isAdult()]);

    return $user;
}

function gtlMinor(bool $guarded = true): array
{
    $minor = User::factory()->create(['dob' => now()->subYears(15)->toDateString()]);
    $guardian = null;
    if ($guarded) {
        $guardian = User::factory()->adult()->create();
        Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $minor->id, 'ward_was_minor_at_creation' => true]);
    }

    return [$minor, $guardian];
}

function gtlLeave($test, User $user, GroupTherapy $group)
{
    return $test->actingAs($user)->postJson(route('api.group.therapies.leave', ['groupTherapyId' => $group->id]));
}

function gtlLink(GroupTherapy $group, User|Counsellor $addedby, string $type, string $state = 'ACTIVE'): Link
{
    return Link::query()->forceCreate(['type' => $type, 'state' => $state, 'uuid' => (string) Str::uuid(),
        'addedby_type' => $addedby::class, 'addedby_id' => $addedby->id, 'for_type' => GroupTherapy::class, 'for_id' => $group->id]);
}

// A suspension row straight in the table -- this file is about what a suspended person may still do.
function gtlSuspend(User $person, SuspensionScopeEnum $scope): Suspension
{
    $report = new Report(['description' => 'Conduct']);
    $report->addedby()->associate(User::factory()->adult()->create());
    $report->reportable()->associate(GroupTherapy::factory()->create());
    $report->save();

    return Suspension::query()->forceCreate(['user_id' => $person->id, 'report_id' => $report->id, 'scope' => $scope->value, 'public_reason' => 'Conduct',
        'starts_at' => now()->subMinute(), 'ends_at' => now()->addDay()]);
}

function gtlSession(GroupTherapy $group, Counsellor $counsellor): Session
{
    return Session::factory()->create([
        'for_type' => GroupTherapy::class, 'for_id' => $group->id, 'addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id,
        'status' => SessionStatusEnum::in_session->value, 'type' => 'ONLINE', 'start_time' => now()->subMinutes(5), 'end_time' => now()->addHour(),
    ]);
}

function gtlVideoProvider(): VideoProviderInterface
{
    return new class implements VideoProviderInterface
    {
        public array $removed = [];

        public function createRoom(VideoSession $videoSession): array
        {
            return ['room_id' => 'room', 'meta' => []];
        }

        public function createParticipantCredentials(VideoSession $videoSession, User $user, string $displayName, bool $receiveOnly = false, bool $canSendVideo = true): array
        {
            return [];
        }

        public function endRoom(VideoSession $videoSession): void {}

        public function removeParticipant(VideoSession $videoSession, User $user): void
        {
            $this->removed[] = [$videoSession->id, $user->id];
        }

        public function updateParticipantCapabilities(VideoSession $videoSession, User $user, bool $canSendAudio, bool $canSendVideo): void {}
    };
}

function gtlInCall(Session $session, User ...$people): VideoSession
{
    $room = VideoSession::query()->create(['session_id' => $session->id, 'provider' => 'daily', 'started_at' => now()]);
    foreach ($people as $person) {
        VideoSessionParticipant::query()->create(['video_session_id' => $room->id, 'participant_type' => User::class, 'participant_id' => $person->id, 'joined_at' => now()]);
    }

    return $room;
}

// ---- Leaving ---------------------------------------------------------------------------------

test('a member leaves: the row is kept and stamped, capacity is freed, the leaver loses a private group', function () {
    [$group, $creator] = gtlGroup(['max_users' => 1]);
    $member = gtlMember($group);
    Event::fake([GroupTherapyLeftEvent::class, GroupTherapyMembershipChangedEvent::class]);

    gtlLeave($this, $member, $group)->assertOk()->assertJson(['status' => true]);

    expect($group->users()->count())->toBe(0)
        ->and($group->allMemberships()->whereKey($member->id)->first()->pivot->left_at)->not->toBeNull()
        ->and($group->fresh()->isParticipant($member))->toBeFalse();
    Event::assertDispatched(GroupTherapyLeftEvent::class);
    Event::assertDispatched(GroupTherapyMembershipChangedEvent::class);

    // Their place is free for someone else.
    $this->actingAs(User::factory()->adult()->create())->postJson(route('api.group.therapies.join', ['groupTherapyId' => $group->id]))->assertOk();

    // A private group's page shows them only the "you left" preview now (the chat stays closed).
    $this->actingAs($member)->get(route('group.therapies.get', ['groupTherapyId' => $group->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('preview', 'former_member')->where('isFormerMember', true)->where('recentSessions', []));
});

test('a former member can still read a public group', function () {
    [$group] = gtlGroup(['public' => true]);
    $member = gtlMember($group);

    gtlLeave($this, $member, $group)->assertOk();

    $this->actingAs($member)->get(route('group.therapies.get', ['groupTherapyId' => $group->id]))->assertOk();
});

test('the creator, a counsellor, a stranger and a second leave are refused', function () {
    [$group, $creator, $counsellor] = gtlGroup();
    $member = gtlMember($group);

    gtlLeave($this, $creator, $group)->assertStatus(422)->assertJsonPath('message', LeaveGroupTherapyAction::CREATOR);
    gtlLeave($this, $counsellor->user, $group)->assertStatus(422)->assertJsonPath('message', LeaveGroupTherapyAction::NOT_A_MEMBER);
    gtlLeave($this, User::factory()->adult()->create(), $group)->assertStatus(422)->assertJsonPath('message', LeaveGroupTherapyAction::NOT_A_MEMBER);

    gtlLeave($this, $member, $group)->assertOk();
    gtlLeave($this, $member, $group)->assertStatus(422)->assertJsonPath('message', LeaveGroupTherapyAction::ALREADY_LEFT);
});

test('a group created through a counsellor profile can\'t be left by that counsellor either', function () {
    $counsellor = gtlCounsellor();
    $group = GroupTherapy::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id]);

    gtlLeave($this, $counsellor->user, $group)->assertStatus(422)->assertJsonPath('message', LeaveGroupTherapyAction::CREATOR);
});

test('nobody can leave an ended group', function () {
    [$group] = gtlGroup(['status' => TherapyStatusEnum::ended->value]);
    $member = gtlMember($group);

    gtlLeave($this, $member, $group)->assertStatus(422)->assertJsonPath('message', LeaveGroupTherapyAction::ENDED);
    expect($group->users()->whereKey($member->id)->exists())->toBeTrue();
});

test('a paused minor and an account-suspended member can still leave', function () {
    [$group] = gtlGroup();
    [$paused] = gtlMinor(guarded: false);
    gtlMember($group, $paused);
    $suspended = gtlMember($group);
    gtlSuspend($suspended, SuspensionScopeEnum::account);

    expect($paused->isPausedForMissingGuardian())->toBeTrue()
        ->and($suspended->activeSuspension())->not->toBeNull();

    gtlLeave($this, $paused, $group)->assertOk();
    gtlLeave($this, $suspended, $group)->assertOk();
    expect($group->users()->count())->toBe(0);
});

test('the creator and active counsellors are told, by the name the group shows; nobody gets a user id', function () {
    [$group, $creator, $counsellor] = gtlGroup();
    $invited = gtlCounsellor();
    $group->counsellors()->attach($invited->id, ['state' => 'INACTIVE', 'role' => 'NORMAL']);
    $named = gtlMember($group, User::factory()->adult()->create(['firstName' => 'Nadia', 'lastName' => 'Quill']));
    $hidden = gtlMember($group, User::factory()->adult()->create(['firstName' => 'Hector', 'lastName' => 'Moss']), anonymous: true);

    gtlLeave($this, $named, $group)->assertOk();
    gtlLeave($this, $hidden, $group)->assertOk();

    foreach ([$creator, $counsellor->user] as $person) {
        Notification::assertSentTo($person, GroupTherapyMemberLeftNotification::class, fn ($n, $c, $notifiable) => $n->toArray($notifiable)['memberName'] === $named->name);
        Notification::assertSentTo($person, GroupTherapyMemberLeftNotification::class, function ($n, $c, $notifiable) use ($hidden, $group) {
            $mail = implode(' ', $n->toMail($notifiable)->introLines);
            $array = $n->toArray($notifiable);

            return $array['memberName'] === ConstantsEnum::anonymousUserLabel->value
                && ! str_contains($mail, 'Hector') && ! str_contains($mail, 'Moss')
                // the group's own id key can equal the member's id by chance -- leave it out of the check
                && ! in_array($hidden->id, array_diff_key($array, TherapyLink::ids($group)), true) && ! array_key_exists('userId', $array);
        });
    }
    Notification::assertNotSentTo($invited->user, GroupTherapyMemberLeftNotification::class);
    Notification::assertNotSentTo($named, GroupTherapyMemberLeftNotification::class);
});

test('a minor\'s guardians are told neutrally; an adult\'s nobody', function () {
    [$group] = gtlGroup();
    [$minor, $guardian] = gtlMinor();
    gtlMember($group, $minor);
    $adult = gtlMember($group);

    gtlLeave($this, $minor, $group)->assertOk();
    gtlLeave($this, $adult, $group)->assertOk();

    Notification::assertSentTo($guardian, GroupTherapyMemberLeftGuardianNotification::class, function ($n, $c, $notifiable) use ($group) {
        return $n->toArray($notifiable) === ['type' => 'group_therapy.ward_left', 'groupTherapyId' => $group->id];
    });
    Notification::assertSentTimes(GroupTherapyMemberLeftGuardianNotification::class, 1);
});

test('the leaver is ejected from the group\'s live call under their join lock, and retried from the queue when busy', function () {
    $provider = gtlVideoProvider();
    app()->instance(VideoProviderInterface::class, $provider);
    [$group, , $counsellor] = gtlGroup();
    $member = gtlMember($group);
    $busy = gtlMember($group);
    $session = gtlSession($group, $counsellor);
    $room = gtlInCall($session, $member, $busy, $counsellor->user);

    gtlLeave($this, $member, $group)->assertOk();
    expect($room->participantRowsFor($member->id)->whereNull('left_at')->exists())->toBeFalse()
        ->and($room->participantRowsFor($counsellor->user->id)->whereNull('left_at')->exists())->toBeTrue();

    Queue::fake([RemovePersonFromLiveCallJob::class]);
    $lock = Cache::store(config('cache.scheduler_store'))->lock("video-join:{$session->id}:{$busy->id}", 90);
    $lock->get();
    gtlLeave($this, $busy, $group)->assertOk();
    $lock->release();

    Queue::assertPushed(RemovePersonFromLiveCallJob::class);
    (new RemovePersonFromLiveCallJob($session->id, $busy->id, RemovePersonFromLiveCallsAction::NO_LONGER_MEMBER))->handle();
    expect($room->participantRowsFor($busy->id)->whereNull('left_at')->exists())->toBeFalse();
});

test('the no-longer-member policy never removes a current participant', function () {
    app()->instance(VideoProviderInterface::class, gtlVideoProvider());
    [$group, , $counsellor] = gtlGroup();
    $member = gtlMember($group);
    $room = gtlInCall(gtlSession($group, $counsellor), $member);

    expect(RemovePersonFromLiveCallsAction::new()->execute($member, RemovePersonFromLiveCallsAction::NO_LONGER_MEMBER))->toBe(0)
        ->and($room->participantRowsFor($member->id)->whereNull('left_at')->exists())->toBeTrue();
});

test('pending safeguarding requests for the leaver in this group are closed', function () {
    [$group, , $counsellor] = gtlGroup();
    [$minor] = gtlMinor(guarded: false);
    gtlMember($group, $minor);
    $other = GroupTherapy::factory()->create();
    $mk = fn (GroupTherapy $for) => Request::factory()->create([
        'from_type' => Counsellor::class, 'from_id' => $counsellor->id, 'to_type' => null, 'to_id' => null,
        'for_type' => GroupTherapy::class, 'for_id' => $for->id, 'type' => RequestTypeEnum::safeguardingException->value,
        'status' => RequestStatusEnum::pending->value, 'data' => ['wardId' => $minor->id, 'reason' => 'x'],
    ]);
    $here = $mk($group);
    $elsewhere = $mk($other);

    gtlLeave($this, $minor, $group)->assertOk();

    expect($here->fresh()->status)->toBe(RequestStatusEnum::inconsequencial->value)
        ->and($here->fresh()->responded_at)->not->toBeNull()
        ->and($elsewhere->fresh()->status)->toBe(RequestStatusEnum::pending->value);
});

test('a safeguarding request for someone who has left is refused', function () {
    [$group, , $counsellor] = gtlGroup();
    [$minor] = gtlMinor(guarded: false);
    gtlMember($group, $minor);
    $key = (string) $group->users()->whereKey($minor->id)->first()->pivot->id;

    gtlLeave($this, $minor, $group)->assertOk();

    $this->actingAs($counsellor->user)->postJson(route('api.safeguarding_exception_requests.group', ['groupTherapyId' => $group->id]), [
        'memberKey' => $key, 'reason' => 'Needs support while a guardian is found.', 'proposedEndsAt' => now()->addDays(10)->toJSON(),
    ])->assertStatus(422);
    expect(Request::query()->where('type', RequestTypeEnum::safeguardingException->value)->exists())->toBeFalse();
});

test('a leaver\'s messages show as anonymous in the JSON; an anonymous message stays masked after they rejoin named', function () {
    [$group, , $counsellor] = gtlGroup();
    $member = gtlMember($group, null, anonymous: true);
    $session = gtlSession($group, $counsellor);

    $this->actingAs($member)->postJson(route('api.messages.create'), [
        'content' => 'Sent anonymously', 'type' => 'NORMAL', 'fromId' => $member->id, 'fromType' => 'User', 'forId' => $session->id, 'forType' => 'Session',
    ])->assertSuccessful();
    $message = Message::query()->latest('id')->firstOrFail();
    expect($message->sender_anonymous)->toBeTrue();

    gtlLeave($this, $member, $group)->assertOk();
    // Rejoin as named, and send a named message.
    $this->actingAs($member)->postJson(route('api.group.therapies.join', ['groupTherapyId' => $group->id]), ['anonymous' => false])->assertOk();
    expect((bool) $group->fresh()->membershipOf($member)->anonymous)->toBeFalse();

    $this->actingAs($member)->postJson(route('api.messages.create'), [
        'content' => 'Sent named', 'type' => 'NORMAL', 'fromId' => $member->id, 'fromType' => 'User', 'forId' => $session->id, 'forType' => 'Session',
    ])->assertSuccessful();
    $named = Message::query()->latest('id')->firstOrFail();

    $viewer = $counsellor->user;
    expect(MessageResource::present($message->fresh(), $viewer)['fromUserId'])->toBeNull()
        ->and(MessageResource::present($named->fresh(), $viewer)['fromUserId'])->toBe($member->id)
        // The sender always sees their own.
        ->and(MessageResource::present($message->fresh(), $member)['fromUserId'])->toBe($member->id);

    // A named message from someone who then leaves is masked live (SCRUM-380), and its JSON carries no id.
    gtlLeave($this, $member, $group)->assertOk();
    $json = json_encode(MessageResource::present($named->fresh(), $viewer));
    expect(MessageResource::present($named->fresh(), $viewer)['fromUserId'])->toBeNull()
        ->and($json)->not->toContain('"fromUserId":'.$member->id);
});

test('rejoining keeps the original join date, re-chooses anonymity, and the minor flag sticks', function () {
    [$group] = gtlGroup(['anonymous' => false]);
    [$minor] = gtlMinor();
    gtlMember($group, $minor, anonymous: true);
    $joinedAt = $group->users()->whereKey($minor->id)->first()->pivot->created_at;

    gtlLeave($this, $minor, $group)->assertOk();
    // They become an adult (an approved dob change) before rejoining.
    $minor->forceFill(['dob' => now()->subYears(20)->toDateString()])->save();
    $this->travel(2)->days();

    $this->actingAs($minor)->postJson(route('api.group.therapies.join', ['groupTherapyId' => $group->id]), ['anonymous' => false])->assertOk();

    $row = $group->fresh()->users()->whereKey($minor->id)->first()->pivot;
    expect($row->left_at)->toBeNull()
        ->and((bool) $row->anonymous)->toBeFalse()
        ->and((bool) $row->was_minor_at_join)->toBeTrue()
        ->and($row->created_at->equalTo($joinedAt))->toBeTrue()
        ->and($group->allMemberships()->whereKey($minor->id)->count())->toBe(1);
});

test('rejoining a group that needs approval sends a request again, and accepting it rejoins them', function () {
    [$group, $creator] = gtlGroup(['allow_anyone' => false]);
    $member = gtlMember($group);
    gtlLeave($this, $member, $group)->assertOk();

    $this->actingAs($member)->postJson(route('api.group.therapies.join', ['groupTherapyId' => $group->id]), ['anonymous' => true])
        ->assertOk()->assertJsonPath('result.type', RequestTypeEnum::groupTherapyMembership->value);
    expect($group->users()->whereKey($member->id)->exists())->toBeFalse();

    $request = Request::query()->where('type', RequestTypeEnum::groupTherapyMembership->value)->latest('id')->firstOrFail();
    $this->actingAs($creator)->postJson(route('requests.respond', ['requestId' => $request->id]), ['response' => 'accepted'])->assertSuccessful();

    $row = $group->fresh()->users()->whereKey($member->id)->first()?->pivot;
    expect($row)->not->toBeNull()->and((bool) $row->anonymous)->toBeTrue();
});

test('leaving refunds nothing; a refund can still be asked for, and an unrefunded payment still counts on rejoin', function () {
    [$group] = gtlGroup();
    $member = gtlMember($group);
    $transaction = Transaction::factory()->create(['for_type' => GroupTherapy::class, 'for_id' => $group->id, 'user_id' => $member->id, 'status' => 'SUCCESS']);

    gtlLeave($this, $member, $group)->assertOk();

    expect($transaction->fresh()->status)->toBe('SUCCESS')
        ->and(Request::query()->where('type', RequestTypeEnum::refund->value)->exists())->toBeFalse();

    $this->actingAs($member)->postJson(route('transactions.refund_request.store', ['transactionId' => $transaction->id]), ['reason' => 'I left the group.'])
        ->assertOk()->assertJson(['refundRequestStatus' => 'PENDING']);

    $this->actingAs($member)->postJson(route('api.group.therapies.join', ['groupTherapyId' => $group->id]))->assertOk();
    expect($group->fresh()->latestTransactionFor($member)?->is($transaction))->toBeTrue();
});

test('leaving, editing the dob across 18 and rejoining still needs approval; former members still count for reports', function () {
    [$group] = gtlGroup();
    [$minor] = gtlMinor();
    gtlMember($group, $minor);
    gtlLeave($this, $minor, $group)->assertOk();

    $reflection = new ReflectionMethod(EnsureDobChangeIsAllowedAction::class, 'hasQualifyingRelationship');
    // Even with the guardianship gone, the group they left is a relationship.
    Guardianship::query()->where('ward_id', $minor->id)->delete();
    expect($reflection->invoke(new EnsureDobChangeIsAllowedAction, $minor->fresh(), $minor))->toBeTrue();

    $report = new Report(['description' => 'About the group']);
    $report->addedby()->associate(User::factory()->adult()->create());
    $report->reportable()->associate($group);
    $report->save();
    expect($report->fresh()->involves($minor))->toBeTrue();
});

// ---- Joining -------------------------------------------------------------------------------

test('the join button refuses an ended group, and an immediate join alerts a minor\'s guardians', function () {
    [$ended] = gtlGroup(['status' => TherapyStatusEnum::ended->value]);
    [$open] = gtlGroup();
    [$minor, $guardian] = gtlMinor();

    $this->actingAs($minor)->postJson(route('api.group.therapies.join', ['groupTherapyId' => $ended->id]))
        ->assertStatus(422)->assertJsonPath('message', AddGroupTherapyMemberAction::ENDED);

    $this->actingAs($minor)->postJson(route('api.group.therapies.join', ['groupTherapyId' => $open->id]))->assertOk();
    Notification::assertSentTo($guardian, GroupTherapyMemberJoinedGuardianNotification::class, function ($n, $c, $notifiable) {
        return str_contains($n->toMail($notifiable)->subject, 'Joined');
    });
});

// ---- Counsellor links ----------------------------------------------------------------------

test('a counsellor link adds the counsellor, closes their invite, deactivates the link and tells the creator and a minor creator\'s guardians', function () {
    [$minorCreator, $guardian] = gtlMinor();
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $minorCreator->id, 'client_was_minor_at_creation' => true, 'max_counsellors' => 3]);
    $counsellor = gtlCounsellor();
    $invite = Request::factory()->create([
        'from_type' => User::class, 'from_id' => $minorCreator->id, 'to_type' => Counsellor::class, 'to_id' => $counsellor->id,
        'for_type' => GroupTherapy::class, 'for_id' => $group->id, 'type' => RequestTypeEnum::groupTherapy->value, 'status' => RequestStatusEnum::pending->value, 'data' => [],
    ]);
    $link = gtlLink($group, $minorCreator, LinkTypeEnum::groupTherapyCounsellor->value);

    $this->actingAs($counsellor->user)->get(route('links.get', ['uuid' => $link->uuid]))
        ->assertRedirect(route('group.therapies.get', ['groupTherapyId' => $group->id]))
        ->assertSessionHas('message', PerformGroupTherapyLinkAction::COUNSELLOR_JOINED);

    expect($group->fresh()->isCounsellor($counsellor))->toBeTrue()
        ->and($invite->fresh()->status)->toBe(RequestStatusEnum::inconsequencial->value)
        ->and($link->fresh()->state)->toBe(LinkStateEnum::inactive->value);
    Notification::assertSentTo($minorCreator, GroupTherapyCounsellorLinkUsedNotification::class);
    Notification::assertSentTo($guardian, GroupTherapyCounsellorLinkUsedNotification::class);
});

test('a counsellor link refuses each case that may not join', function (Closure $setup, string $message, int $status) {
    [$group, $creator] = gtlGroup();
    [$user, $link] = $setup($group, $creator);

    $this->actingAs($user)->getJson(route('links.get', ['uuid' => $link->uuid]))
        ->assertStatus($status)->assertJsonPath('message', $message);
    expect($link->fresh()->state)->toBe(LinkStateEnum::active->value);
})->with([
    'full' => [function (GroupTherapy $group, User $creator) {
        $group->update(['max_counsellors' => 1]);

        return [gtlCounsellor()->user, gtlLink($group, $creator, LinkTypeEnum::groupTherapyCounsellor->value)];
    }, AddCounsellorToGroupTherapyAction::GROUP_FULL, 422],
    'ended' => [function (GroupTherapy $group, User $creator) {
        $group->update(['status' => TherapyStatusEnum::ended->value]);

        return [gtlCounsellor()->user, gtlLink($group, $creator, LinkTypeEnum::groupTherapyCounsellor->value)];
    }, AddCounsellorToGroupTherapyAction::NOT_ALLOWED, 422],
    // SCRUM-31: told why -- their own verification isn't a secret from them.
    'unverified' => [fn (GroupTherapy $group, User $creator) => [gtlCounsellor(['verified_at' => null])->user, gtlLink($group, $creator, LinkTypeEnum::groupTherapyCounsellor->value)], EnsureCounsellorMayTakeOnClientsAction::NOT_VERIFIED, 422],
    'suspended' => [function (GroupTherapy $group, User $creator) {
        $counsellor = gtlCounsellor();
        gtlSuspend($counsellor->user, SuspensionScopeEnum::counsellor);

        return [$counsellor->user, gtlLink($group, $creator, LinkTypeEnum::groupTherapyCounsellor->value)];
    }, 'Your counsellor role is suspended, so you cannot do this right now.', 403],
    'no counsellor profile' => [fn (GroupTherapy $group, User $creator) => [User::factory()->adult()->create(), gtlLink($group, $creator, LinkTypeEnum::groupTherapyCounsellor->value)], LinkException::NO_COUNSELLOR, 422],
    'the creator' => [fn (GroupTherapy $group, User $creator) => [$creator, gtlLink($group, $creator, LinkTypeEnum::groupTherapyCounsellor->value)], 'You cannot use a link you created.', 422],
    'a member' => [function (GroupTherapy $group, User $creator) {
        $counsellor = gtlCounsellor();
        gtlMember($group, $counsellor->user);

        return [$counsellor->user, gtlLink($group, $creator, LinkTypeEnum::groupTherapyCounsellor->value)];
    }, AddCounsellorToGroupTherapyAction::NOT_ALLOWED, 422],
]);

test('a counsellor link into a paused minor\'s group is refused neutrally, with no code', function () {
    [$minor] = gtlMinor(guarded: false);
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $minor->id]);
    $link = gtlLink($group, $minor, LinkTypeEnum::groupTherapyCounsellor->value);

    $this->actingAs(gtlCounsellor()->user)->getJson(route('links.get', ['uuid' => $link->uuid]))
        ->assertForbidden()->assertJsonPath('message', GuardianRequiredException::LINK_UNAVAILABLE)->assertJsonMissingPath('code');
});

// ---- Member links --------------------------------------------------------------------------

test('a member link opens the group page with a confirmation; confirming joins without approval, once', function () {
    [$group, $creator] = gtlGroup(['allow_anyone' => false, 'anonymous' => true]);
    $invitee = User::factory()->adult()->create();
    $link = gtlLink($group, $creator, LinkTypeEnum::groupTherapyParticipant->value);
    $pending = Request::factory()->create([
        'from_type' => User::class, 'from_id' => $invitee->id, 'to_type' => User::class, 'to_id' => $creator->id,
        'for_type' => GroupTherapy::class, 'for_id' => $group->id, 'type' => RequestTypeEnum::groupTherapyMembership->value,
        'status' => RequestStatusEnum::pending->value, 'data' => ['anonymous' => true],
    ]);

    $this->actingAs($invitee)->get(route('links.get', ['uuid' => $link->uuid]))
        ->assertRedirect(route('group.therapies.get', ['groupTherapyId' => $group->id, 'invitation' => $link->uuid]));
    expect($group->users()->whereKey($invitee->id)->exists())->toBeFalse();

    // The private group's page opens for them, with the invitation, but not its history.
    $this->actingAs($invitee)->get(route('group.therapies.get', ['groupTherapyId' => $group->id, 'invitation' => $link->uuid]))
        ->assertOk()->assertInertia(fn ($page) => $page->where('linkInvitation.uuid', $link->uuid)->where('recentSessions', []));
    // Without it, it doesn't.
    $this->actingAs($invitee)->get(route('group.therapies.get', ['groupTherapyId' => $group->id]))->assertRedirect(route('home'));

    // An anonymous group forces anonymity on.
    $this->actingAs($invitee)->postJson(route('api.links.join', ['uuid' => $link->uuid]), ['anonymous' => false])
        ->assertOk()->assertJsonPath('message', PerformGroupTherapyLinkAction::JOINED);

    expect((bool) $group->fresh()->membershipOf($invitee)->anonymous)->toBeTrue()
        ->and($pending->fresh()->status)->toBe(RequestStatusEnum::inconsequencial->value)
        ->and($link->fresh()->state)->toBe(LinkStateEnum::inactive->value);

    // Single use.
    $second = User::factory()->adult()->create();
    $this->actingAs($second)->postJson(route('api.links.join', ['uuid' => $link->uuid]))->assertStatus(422)->assertJsonPath('message', 'This link is no longer active.');
});

test('a member link alerts a minor\'s guardians when they join through it', function () {
    [$group, $creator] = gtlGroup();
    [$minor, $guardian] = gtlMinor();
    $link = gtlLink($group, $creator, LinkTypeEnum::groupTherapyParticipant->value);

    $this->actingAs($minor)->postJson(route('api.links.join', ['uuid' => $link->uuid]))->assertOk();

    Notification::assertSentTo($guardian, GroupTherapyMemberJoinedGuardianNotification::class);
    expect((bool) $group->fresh()->membershipOf($minor)->was_minor_at_join)->toBeTrue();
});

test('a member link refuses a full group, an ended one, a member, a paused minor and a paused creator\'s group', function () {
    [$full, $creator] = gtlGroup(['max_users' => 1]);
    gtlMember($full);
    $this->actingAs(User::factory()->adult()->create())->postJson(route('api.links.join', ['uuid' => gtlLink($full, $creator, LinkTypeEnum::groupTherapyParticipant->value)->uuid]))
        ->assertStatus(422)->assertJsonPath('message', AddGroupTherapyMemberAction::FULL);

    [$ended, $endedCreator] = gtlGroup(['status' => TherapyStatusEnum::ended->value]);
    $this->actingAs(User::factory()->adult()->create())->getJson(route('links.get', ['uuid' => gtlLink($ended, $endedCreator, LinkTypeEnum::groupTherapyParticipant->value)->uuid]))
        ->assertStatus(422)->assertJsonPath('message', AddGroupTherapyMemberAction::ENDED);

    [$group, $groupCreator] = gtlGroup();
    $member = gtlMember($group);
    $this->actingAs($member)->postJson(route('api.links.join', ['uuid' => gtlLink($group, $groupCreator, LinkTypeEnum::groupTherapyParticipant->value)->uuid]))
        ->assertStatus(422)->assertJsonPath('message', AddGroupTherapyMemberAction::ALREADY_MEMBER);

    [$paused] = gtlMinor(guarded: false);
    $this->actingAs($paused)->postJson(route('api.links.join', ['uuid' => gtlLink($group, $groupCreator, LinkTypeEnum::groupTherapyParticipant->value)->uuid]))
        ->assertStatus(422)->assertJsonPath('code', GuardianRequiredException::CODE);

    [$pausedCreator] = gtlMinor(guarded: false);
    $pausedGroup = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $pausedCreator->id]);
    $this->actingAs(User::factory()->adult()->create())->postJson(route('api.links.join', ['uuid' => gtlLink($pausedGroup, $pausedCreator, LinkTypeEnum::groupTherapyParticipant->value)->uuid]))
        ->assertForbidden()->assertJsonPath('message', GuardianRequiredException::LINK_UNAVAILABLE)->assertJsonMissingPath('code');
});

test('a counsellor link can\'t be confirmed as a member link', function () {
    [$group, $creator] = gtlGroup();
    $link = gtlLink($group, $creator, LinkTypeEnum::groupTherapyCounsellor->value);

    $this->actingAs(User::factory()->adult()->create())->postJson(route('api.links.join', ['uuid' => $link->uuid]))
        ->assertStatus(422)->assertJsonPath('message', 'This link is no longer active.');
    expect($group->users()->count())->toBe(0);
});

test('two people racing one member link: exactly one gets in', function () {
    [$group, $creator] = gtlGroup();
    $link = gtlLink($group, $creator, LinkTypeEnum::groupTherapyParticipant->value);
    [$first, $second] = [User::factory()->adult()->create(), User::factory()->adult()->create()];

    // Both opened the link (the stale copy is what the second request holds) before either confirmed.
    $statuses = [
        $this->actingAs($first)->postJson(route('api.links.join', ['uuid' => $link->uuid]))->status(),
        $this->actingAs($second)->postJson(route('api.links.join', ['uuid' => $link->uuid]))->status(),
    ];
    // And through the action directly, with a stale (still active) link, as a racer would hold it.
    $third = User::factory()->adult()->create();
    $stale = $link->replicate()->forceFill(['id' => $link->id, 'state' => LinkStateEnum::active->value]);
    expect(fn () => PerformGroupTherapyLinkAction::new()->joinAsMember(CreateLinkDTO::new()->fromArray(['user' => $third, 'link' => $stale])))
        ->toThrow(LinkException::class, 'This link is no longer active.');

    expect($statuses)->toBe([200, 422])
        ->and($group->users()->count())->toBe(1);
});

// ---- Admin links ---------------------------------------------------------------------------

test('an admin link is refused at creation and when used', function () {
    [$group, $creator] = gtlGroup();

    $this->actingAs($creator)->postJson(route('api.links.create'), [
        'type' => LinkTypeEnum::groupTherapyAdmin->value, 'addedbyType' => 'User', 'addedbyId' => $creator->id, 'forType' => 'GroupTherapy', 'forId' => $group->id,
    ])->assertForbidden()->assertJsonPath('message', EnsureUserCanCreateLinkForAction::NOT_ALLOWED);

    $old = gtlLink($group, $creator, LinkTypeEnum::groupTherapyAdmin->value);
    $this->actingAs(gtlCounsellor()->user)->getJson(route('links.get', ['uuid' => $old->uuid]))
        ->assertStatus(422)->assertJsonPath('message', 'This link is no longer active.');
});
