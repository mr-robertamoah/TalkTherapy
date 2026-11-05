<?php

use App\Actions\GroupTherapy\AddCounsellorToGroupTherapyAction;
use App\Actions\GroupTherapy\AddGroupTherapyMemberAction;
use App\Actions\GroupTherapy\LeaveGroupTherapyAction;
use App\Actions\Message\CreateMessageAction;
use App\Actions\Session\ChangeSessionStatusAction;
use App\Actions\User\AnonymiseClosedAccountAction;
use App\DTOs\CreateMessageDTO;
use App\DTOs\CreateSessionDTO;
use App\Enums\AdministratorTypeEnum;
use App\Enums\LinkTypeEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Enums\SessionStatusEnum;
use App\Enums\TherapyStatusEnum;
use App\Events\GroupTherapyMembershipChangedEvent;
use App\Exceptions\CannotJoinGroupTherapyException;
use App\Http\Resources\GroupTherapyResource;
use App\Http\Resources\MessageResource;
use App\Http\Resources\SessionResource;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Guardianship;
use App\Models\Link;
use App\Models\Report;
use App\Models\Request;
use App\Models\Session;
use App\Models\User;
use App\Notifications\ClientAccessPausedNotification;
use App\Notifications\GroupTherapyMemberJoinedGuardianNotification;
use App\Notifications\GroupTherapyMemberLeftNotification;
use App\Notifications\SafeguardingExceptionRequestDecidedNotification;
use App\Support\WardRef;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

// SCRUM-73/383 review fixes: former members in reports and the "you left" preview, the invitation
// preview, anonymity snapshots, closed-account names in notices, and the shared join step.
// Helpers are file-local and grf-prefixed (--parallel).

function grfCounsellor(): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id, 'verified_at' => now(), 'name' => 'Dr. Grf Counsellor']);
}

// A running, private, named group created by an adult user (with personal details a link holder must
// not see), with one active and one invited counsellor.
function grfGroup(array $attributes = [], ?User $creator = null): array
{
    $creator ??= User::factory()->adult()->create(['firstName' => 'Secret', 'lastName' => 'Creatorname', 'username' => 'secret_creator_'.Str::random(6)]);
    $counsellor = grfCounsellor();
    $invited = grfCounsellor();
    $invited->update(['name' => 'Dr. Grf Invited']);
    $group = GroupTherapy::factory()->create(array_merge([
        'addedby_type' => User::class, 'addedby_id' => $creator->id, 'anonymous' => false, 'public' => false,
        'allow_anyone' => false, 'max_users' => 5, 'max_counsellors' => 3, 'status' => TherapyStatusEnum::in_session->value,
    ], $attributes));
    $group->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $group->counsellors()->attach($invited->id, ['state' => 'INACTIVE', 'role' => 'NORMAL']);

    return [$group, $creator, $counsellor, $invited];
}

function grfMember(GroupTherapy $group, ?User $user = null, bool $anonymous = false, bool $left = false): User
{
    $user ??= User::factory()->adult()->create();
    $group->allMemberships()->attach($user->id, ['anonymous' => $anonymous, 'was_minor_at_join' => false, 'left_at' => $left ? now()->subDay() : null]);

    return $user;
}

function grfLink(GroupTherapy $group, User $addedby, string $type = 'GROUP_THERAPY_PARTICIPANT', array $attributes = []): Link
{
    return Link::query()->forceCreate(array_merge(['type' => $type, 'state' => 'ACTIVE', 'uuid' => (string) Str::uuid(),
        'addedby_type' => $addedby::class, 'addedby_id' => $addedby->id, 'for_type' => GroupTherapy::class, 'for_id' => $group->id], $attributes));
}

function grfSession(GroupTherapy $group, Counsellor $counsellor): Session
{
    return Session::factory()->create([
        'for_type' => GroupTherapy::class, 'for_id' => $group->id, 'addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id,
        'status' => SessionStatusEnum::in_session->value, 'type' => 'ONLINE', 'start_time' => now()->subMinutes(5), 'end_time' => now()->addHour(),
    ]);
}

function grfReport(User $reporter, string $type, int $id): array
{
    return ['description' => 'Something happened', 'reportableType' => $type, 'reportableId' => $id, 'addedbyType' => 'user', 'addedbyId' => $reporter->id, 'data' => []];
}

function grfRequestAs(?User $user): HttpRequest
{
    $request = HttpRequest::create('/');
    $request->setUserResolver(fn () => $user);

    return $request;
}

beforeEach(function () {
    Notification::fake();
});

// ---- Former members: reports and the "you left" preview -----------------------------------------

test('a former member may report the group and its sessions; a stranger still may not', function () {
    [$group, , $counsellor] = grfGroup();
    $session = grfSession($group, $counsellor);
    $session->forceFill(['created_at' => now()->subDays(2)])->save(); // held while they were a member
    $former = grfMember($group, left: true);
    $stranger = User::factory()->adult()->create();

    $this->actingAs($former)->postJson(route('api.reports.create'), grfReport($former, 'GroupTherapy', $group->id))->assertOk();
    $this->actingAs($former)->postJson(route('api.reports.create'), grfReport($former, 'Session', $session->id))->assertOk();
    $this->actingAs($stranger)->postJson(route('api.reports.create'), grfReport($stranger, 'GroupTherapy', $group->id))->assertStatus(422);
});

test('a former member may not report a session created after they left', function () {
    [$group, , $counsellor] = grfGroup();
    $former = grfMember($group, left: true); // left a day ago
    $later = grfSession($group, $counsellor);

    $this->actingAs($former)->postJson(route('api.reports.create'), grfReport($former, 'Session', $later->id))->assertStatus(422);
});

test('an administrator sees a former member as one, and may name them as the reported person', function () {
    [$group, $creator] = grfGroup();
    $member = grfMember($group);
    $former = grfMember($group, left: true);
    $report = new Report(['description' => 'A former member was abusive']);
    $report->addedby()->associate($creator);
    $report->reportable()->associate($group);
    $report->save();
    $admin = User::factory()->adult()->has(Administrator::factory())->create();

    $this->actingAs($admin)->get(route('administrator.reports.show', ['report' => $report->id]))->assertOk()
        ->assertInertia(fn ($page) => $page->where('report.participants', fn ($people) => collect($people)->firstWhere('id', $former->id)['role'] === 'former member'
            && collect($people)->firstWhere('id', $member->id)['role'] === 'member'));

    $this->actingAs($admin)->post(route('admin.reports.party', ['report' => $report->id]), ['type' => 'user', 'id' => $former->id])->assertSessionHasNoErrors();
    expect($report->fresh()->data)->toBe(['userId' => $former->id]);
});

test('a former member of a private group sees only its masked preview, and the chat stays closed', function () {
    [$group, $creator, $counsellor, $invited] = grfGroup();
    grfSession($group, $counsellor);
    $former = grfMember($group, left: true);

    $this->actingAs($former)->get(route('group.therapies.get', ['groupTherapyId' => $group->id]))->assertOk()
        ->assertInertia(fn ($page) => $page->where('preview', 'former_member')
            ->where('isFormerMember', true)
            ->where('recentSessions', [])
            ->where('recentTopics', [])
            ->where('therapy.addedby', ['id' => null, 'fullName' => 'Client (Anonymous User)', 'isUser' => true])
            ->where('therapy.counsellors', [['id' => $counsellor->id, 'name' => 'Dr. Grf Counsellor', 'isCounsellor' => true]])
            ->missing('therapy.paymentRoster')
            ->missing('therapy.activeSession'));

    $this->actingAs($former)->get(route('group.therapies.chat', ['groupTherapyId' => $group->id]))->assertRedirect(route('home'));
});

test('a former member rejoins by the usual rules: a group that needs approval gets a request', function () {
    [$group] = grfGroup();
    $former = grfMember($group, left: true);

    $this->actingAs($former)->postJson(route('api.group.therapies.join', ['groupTherapyId' => $group->id]))->assertOk();

    expect(Request::query()->where('type', RequestTypeEnum::groupTherapyMembership->value)->whereFrom($former)->wherePending()->exists())->toBeTrue()
        ->and($group->fresh()->isParticipant($former))->toBeFalse();
});

test('a former member cannot become a counsellor of that group', function () {
    [$group] = grfGroup();
    $former = grfMember($group, left: true);
    $counsellor = Counsellor::factory()->create(['user_id' => $former->id, 'verified_at' => now()]);

    expect(fn () => AddCounsellorToGroupTherapyAction::new()->execute($group, $counsellor, $former))
        ->toThrow(CannotJoinGroupTherapyException::class, AddCounsellorToGroupTherapyAction::NOT_ALLOWED);
});

// ---- The invitation preview ---------------------------------------------------------------------

test('a usable member invitation shows a private group as a preview, without the creator\'s details or the invited counsellors', function () {
    [$group, $creator, $counsellor, $invited] = grfGroup();
    $link = grfLink($group, $creator);
    $invitee = User::factory()->adult()->create();

    $response = $this->actingAs($invitee)->get(route('group.therapies.get', ['groupTherapyId' => $group->id, 'invitation' => $link->uuid]))->assertOk()
        ->assertInertia(fn ($page) => $page->where('preview', 'invitation')
            ->where('linkInvitation.uuid', $link->uuid)
            ->where('recentSessions', [])
            ->where('therapy.addedby.id', null)
            ->where('therapy.counsellors', [['id' => $counsellor->id, 'name' => 'Dr. Grf Counsellor', 'isCounsellor' => true]]));

    expect($response->getContent())->not->toContain('Creatorname')->not->toContain($creator->username)->not->toContain((string) $invited->name);
});

test('a group created through a counsellor profile names that counsellor in the preview', function () {
    $owner = grfCounsellor();
    [$group] = grfGroup(['addedby_type' => Counsellor::class, 'addedby_id' => $owner->id]);
    $link = grfLink($group, $owner->user);

    $this->actingAs(User::factory()->adult()->create())->get(route('group.therapies.get', ['groupTherapyId' => $group->id, 'invitation' => $link->uuid]))->assertOk()
        ->assertInertia(fn ($page) => $page->where('therapy.addedby', ['id' => $owner->id, 'name' => 'Dr. Grf Counsellor', 'isCounsellor' => true]));
});

test('nothing but a usable member invitation for this group opens a private group\'s page', function (Closure $setup) {
    [$group, $creator] = grfGroup();
    [$viewer, $uuid, $group] = $setup($group, $creator);

    $request = $viewer ? $this->actingAs($viewer) : $this;
    $request->get(route('group.therapies.get', ['groupTherapyId' => $group->id, 'invitation' => $uuid]))->assertRedirect();
})->with([
    'another group\'s link' => [fn (GroupTherapy $group, User $creator) => [User::factory()->adult()->create(), grfLink(grfGroup([], $creator)[0], $creator)->uuid, $group]],
    'a counsellor link' => [fn (GroupTherapy $group, User $creator) => [User::factory()->adult()->create(), grfLink($group, $creator, LinkTypeEnum::groupTherapyCounsellor->value)->uuid, $group]],
    'a deactivated link' => [fn (GroupTherapy $group, User $creator) => [User::factory()->adult()->create(), grfLink($group, $creator, attributes: ['state' => 'IN_ACTIVE'])->uuid, $group]],
    'the viewer\'s own link' => [function (GroupTherapy $group, User $creator) {
        $viewer = User::factory()->adult()->create();

        return [$viewer, grfLink($group, $viewer)->uuid, $group];
    }],
    'a guest' => [fn (GroupTherapy $group, User $creator) => [null, grfLink($group, $creator)->uuid, $group]],
    'a link meant for someone else' => [fn (GroupTherapy $group, User $creator) => [User::factory()->adult()->create(), grfLink($group, $creator, attributes: ['to_type' => User::class, 'to_id' => User::factory()->adult()->create()->id])->uuid, $group]],
    'an ended group' => [function (GroupTherapy $group, User $creator) {
        $group->update(['status' => TherapyStatusEnum::ended->value]);

        return [User::factory()->adult()->create(), grfLink($group, $creator)->uuid, $group];
    }],
    'a paused creator' => [function (GroupTherapy $group, User $creator) {
        $minor = User::factory()->create(['dob' => now()->subYears(15)->toDateString()]);
        $pausedGroup = grfGroup([], $minor)[0];

        return [User::factory()->adult()->create(), grfLink($pausedGroup, $minor)->uuid, $pausedGroup];
    }],
]);

// ---- Anonymity snapshots ------------------------------------------------------------------------

test('a message sent to an anonymous member keeps their id hidden after they rejoin as named', function () {
    [$group, , $counsellor] = grfGroup(['allow_anyone' => true]);
    $session = grfSession($group, $counsellor);
    $member = grfMember($group, anonymous: true);
    $other = grfMember($group);

    $message = CreateMessageAction::new()->execute(CreateMessageDTO::new()->fromArray([
        'user' => $counsellor->user, 'from' => $counsellor, 'to' => $member, 'for' => $session,
        'content' => 'Just for you', 'type' => 'NORMAL', 'status' => 'SENT',
    ]));
    expect($message->recipient_anonymous)->toBeTrue();

    LeaveGroupTherapyAction::new()->execute($member, $group);
    DB::transaction(fn () => AddGroupTherapyMemberAction::new()->execute($group->fresh(), $member, false, GroupTherapyMemberJoinedGuardianNotification::VIA_BUTTON));
    expect($group->fresh()->hidesIdentityOf($member))->toBeFalse();

    expect(MessageResource::present($message->fresh(), $other)['toUserId'])->toBeNull()
        ->and(MessageResource::present($message->fresh(), $member)['toUserId'])->toBe($member->id);
});

test('a session status changed by an anonymous member keeps their id hidden after they rejoin as named', function () {
    [$group, , $counsellor] = grfGroup(['allow_anyone' => true]);
    $session = grfSession($group, $counsellor);
    $member = grfMember($group, anonymous: true);
    $other = grfMember($group);

    ChangeSessionStatusAction::new()->execute(CreateSessionDTO::new()->fromArray(['user' => $member, 'session' => $session]), SessionStatusEnum::held->value);
    expect($session->fresh()->updatedby_anonymous)->toBeTrue();

    LeaveGroupTherapyAction::new()->execute($member, $group);
    DB::transaction(fn () => AddGroupTherapyMemberAction::new()->execute($group->fresh(), $member, false, GroupTherapyMemberJoinedGuardianNotification::VIA_BUTTON));

    $fresh = Session::query()->find($session->id);
    expect(SessionResource::make($fresh)->resolve(grfRequestAs($other))['updatedById'])->toBeNull()
        ->and(SessionResource::make($fresh)->resolve(grfRequestAs($member))['updatedById'])->toBe($member->id);
});

// ---- Closed accounts in stored notices ----------------------------------------------------------

test('anonymising a closed account renames it in "a member left" notices, under previous app keys too, past an unreadable row', function () {
    [$group, $creator] = grfGroup();
    $member = User::factory()->adult()->create(['firstName' => 'Leaving', 'lastName' => 'Member']);
    $old = 'base64:'.base64_encode(random_bytes(32));
    $insert = fn (string $type, string $data) => DB::table('notifications')->insert([
        'id' => (string) Str::uuid(), 'type' => $type, 'notifiable_type' => User::class, 'notifiable_id' => $creator->id, 'data' => $data, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $insert(GroupTherapyMemberLeftNotification::class, json_encode((new GroupTherapyMemberLeftNotification($group, 'Leaving Member', $member))->toArray($creator)));
    $insert(GroupTherapyMemberLeftNotification::class, json_encode(['type' => 'group_therapy.member_left', 'memberName' => 'Leaving Member', WardRef::KEY => WardRef::of($member, $old)]));
    $insert(ClientAccessPausedNotification::class, '{"wardRef":"'.WardRef::of($member).'", not json');
    $insert(GroupTherapyMemberLeftNotification::class, json_encode((new GroupTherapyMemberLeftNotification($group, 'Someone Else', User::factory()->create()))->toArray($creator)));
    config(['app.previous_keys' => [$old]]);

    expect(AnonymiseClosedAccountAction::new()->execute($member->fresh()))->toBeTrue();

    $names = DB::table('notifications')->where('notifiable_id', $creator->id)->get()
        ->map(fn ($row) => json_decode($row->data, true)['memberName'] ?? 'unreadable')->sort()->values()->all();
    expect($names)->toBe(['Someone Else', AnonymiseClosedAccountAction::CLOSED_ACCOUNT_NAME, AnonymiseClosedAccountAction::CLOSED_ACCOUNT_NAME, 'unreadable']);
});

test('a pause notice queued before the ref existed is stored without it', function () {
    [$group, $creator] = grfGroup();
    $notice = (new ReflectionClass(ClientAccessPausedNotification::class))->newInstanceWithoutConstructor();
    foreach (['therapy' => $group, 'clientName' => 'Old'] as $property => $value) {
        (new ReflectionProperty($notice, $property))->setValue($notice, $value);
    }

    expect($notice->toArray($creator))->not->toHaveKey(WardRef::KEY)->toHaveKey('clientName', 'Old');
});

// ---- Leaving closes safeguarding requests --------------------------------------------------------

test('leaving closes the member\'s pending safeguarding requests with a reason, and tells the counsellor neutrally', function () {
    [$group, , $counsellor] = grfGroup();
    $member = grfMember($group);
    $request = new Request(['type' => RequestTypeEnum::safeguardingException->value, 'status' => RequestStatusEnum::pending->value, 'data' => ['wardId' => $member->id, 'reason' => 'Needs support']]);
    $request->from()->associate($counsellor);
    $request->for()->associate($group);
    $request->save();

    $this->actingAs($member)->postJson(route('api.group.therapies.leave', ['groupTherapyId' => $group->id]))->assertOk();

    $request->refresh();
    expect($request->status)->toBe(RequestStatusEnum::inconsequencial->value)
        ->and($request->responded_at)->not->toBeNull()
        ->and($request->data)->toMatchArray(['wardId' => $member->id, 'reason' => LeaveGroupTherapyAction::REQUEST_CLOSED_REASON]);
    Notification::assertSentTo($counsellor->user, SafeguardingExceptionRequestDecidedNotification::class,
        fn ($notice) => $notice->toArray($counsellor->user)['type'] === 'safeguarding_exception.request_closed');
});

// QA: the Requests modal's "Closed" tab lists them (status=INCONSEQUENCIAL), and only there.
test('a request closed by a leave is listed under the closed status, not as pending', function () {
    [$group, , $counsellor] = grfGroup();
    $member = grfMember($group);
    $request = new Request(['type' => RequestTypeEnum::safeguardingException->value, 'status' => RequestStatusEnum::pending->value, 'data' => ['wardId' => $member->id, 'reason' => 'Needs support']]);
    $request->from()->associate($counsellor);
    $request->for()->associate($group);
    $request->save();

    $this->actingAs($member)->postJson(route('api.group.therapies.leave', ['groupTherapyId' => $group->id]))->assertOk();

    $closed = $this->actingAs($counsellor->user)->getJson(route('requests.get', ['status' => RequestStatusEnum::inconsequencial->value]))
        ->assertOk()->json('data');
    $pending = $this->actingAs($counsellor->user)->getJson(route('requests.get', ['status' => RequestStatusEnum::pending->value]))
        ->assertOk()->json('data');

    expect(collect($closed)->pluck('id')->all())->toBe([$request->id])
        ->and($closed[0]['status'])->toBe(RequestStatusEnum::inconsequencial->value)
        ->and(collect($pending)->pluck('id'))->not->toContain($request->id);
});

test('the super administrator can close a request whose minor\'s account is gone, and the counsellor is told it was closed', function () {
    [$group, , $counsellor] = grfGroup();
    $super = User::factory()->adult()->has(Administrator::factory()->state(['type' => AdministratorTypeEnum::super->value]))->create();
    $request = new Request(['type' => RequestTypeEnum::safeguardingException->value, 'status' => RequestStatusEnum::pending->value, 'data' => ['wardId' => 987654]]);
    $request->from()->associate($counsellor);
    $request->for()->associate($group);
    $request->save();

    $this->actingAs($super)->postJson(route('requests.respond', ['requestId' => $request->id]), ['response' => 'rejected'])->assertSuccessful();

    expect($request->fresh()->status)->toBe(RequestStatusEnum::rejected->value);
    Notification::assertSentTo($counsellor->user, SafeguardingExceptionRequestDecidedNotification::class, function ($notice) use ($counsellor) {
        $mail = implode(' ', $notice->toMail($counsellor->user)->introLines);

        return str_contains($mail, 'no longer needed') && ! str_contains($mail, 'stays paused');
    });
});

// ---- Joining ------------------------------------------------------------------------------------

test('every way of joining refreshes the group\'s pages live and alerts a minor\'s guardians', function () {
    Event::fake([GroupTherapyMembershipChangedEvent::class]);
    [$group] = grfGroup(['allow_anyone' => true]);
    $minor = User::factory()->create(['dob' => now()->subYears(15)->toDateString()]);
    $guardian = User::factory()->adult()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $minor->id, 'ward_was_minor_at_creation' => true]);

    $this->actingAs($minor)->postJson(route('api.group.therapies.join', ['groupTherapyId' => $group->id]))->assertOk();

    Event::assertDispatched(GroupTherapyMembershipChangedEvent::class);
    Notification::assertSentTo($guardian, GroupTherapyMemberJoinedGuardianNotification::class);
});

test('an accepted request for a group that has since filled is rejected with the reason', function () {
    [$group, $creator] = grfGroup(['max_users' => 1]);
    $requester = User::factory()->adult()->create();
    $this->actingAs($requester)->postJson(route('api.group.therapies.join', ['groupTherapyId' => $group->id]))->assertOk();
    grfMember($group);
    $request = Request::query()->whereFrom($requester)->firstOrFail();

    $this->actingAs($creator)->postJson(route('requests.respond', ['requestId' => $request->id]), ['response' => 'accepted']);

    expect($request->fresh()->status)->toBe(RequestStatusEnum::rejected->value)
        ->and($request->fresh()->data['reason'])->toBe('This group therapy has since reached its maximum number of members.');
});

test('joining is throttled', function () {
    [$group] = grfGroup(['allow_anyone' => true]);
    $user = User::factory()->adult()->create();

    foreach (range(1, 10) as $ignored) {
        $this->actingAs($user)->postJson(route('api.group.therapies.join', ['groupTherapyId' => $group->id]));
    }

    $this->actingAs($user)->postJson(route('api.group.therapies.join', ['groupTherapyId' => $group->id]))->assertStatus(429);
});

test('a counsellor link is refused at creation when the group has no counsellor place left', function () {
    [$group, $creator] = grfGroup(['max_counsellors' => 1]);

    $this->actingAs($creator)->postJson(route('api.links.create'), [
        'type' => LinkTypeEnum::groupTherapyCounsellor->value, 'addedbyId' => $creator->id, 'addedbyType' => 'User', 'forId' => $group->id, 'forType' => 'GroupTherapy',
    ])->assertStatus(422)->assertJsonPath('message', AddCounsellorToGroupTherapyAction::GROUP_FULL);
});

// ---- Query counts -------------------------------------------------------------------------------

test('a group\'s member count comes from its loaded members, not one more query', function () {
    [$group, $creator] = grfGroup(['public' => true]);
    grfMember($group);
    grfMember($group);
    $group = GroupTherapy::query()->with('users')->find($group->id);

    DB::enableQueryLog();
    $data = GroupTherapyResource::make($group)->resolve(grfRequestAs(null));
    $counts = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'count(*)') && str_contains($query['query'], 'group_therapy_user'));
    DB::disableQueryLog();

    expect($data['membersCount'])->toBe(2)->and($counts)->toBeEmpty();
});

// ---- Architecture -------------------------------------------------------------------------------

test('nothing in app/ writes to membership rows through the current-members relations', function () {
    $offenders = collect(File::allFiles(app_path()))
        ->filter(fn ($file) => preg_match('/\b(users|groupTherapies)\(\)\s*->\s*(updateExistingPivot|detach|sync|syncWithoutDetaching|syncWithPivotValues|toggle)\s*\(/', $file->getContents()))
        ->map(fn ($file) => $file->getRelativePathname())
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});
