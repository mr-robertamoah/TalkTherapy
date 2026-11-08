<?php

use App\Actions\Admin\GetAdminToDoCountsAction;
use App\Enums\AdministratorTypeEnum;
use App\Enums\ConstantsEnum;
use App\Enums\SafetyFlagNoticeStateEnum;
use App\Enums\SafetyFlagStatusEnum;
use App\Enums\TherapyStatusEnum;
use App\Events\HelpQueueChangedEvent;
use App\Events\HelpRequestRingingEvent;
use App\Events\HelpRequestUpdatedEvent;
use App\Events\SafetyFlagsChangedEvent;
use App\Http\Resources\GroupTherapyResource;
use App\Http\Resources\TherapyResource;
use App\Jobs\EscalateHelpRequestJob;
use App\Models\AdminContentAccess;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Guardianship;
use App\Models\HelpRequest;
use App\Models\Report;
use App\Models\SafetyFlag;
use App\Models\SafetyFlagEvent;
use App\Models\Suspension;
use App\Models\Therapy;
use App\Models\User;
use App\Notifications\SafetyFlagRaisedNotification;
use App\Support\AdminNavigation;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

// SCRUM-32 (TT-4.3a): a counsellor's safety flag -- raising it, the alerts, and the administrators'
// list, acknowledge, reassign and logged views. Helpers are file-local and sfl-prefixed (--parallel).

const SFL_REASON = 'They described a plan and have stopped eating; they need more than weekly therapy.';

function sflAdmin(bool $super = false): User
{
    return User::factory()->adult()->has(Administrator::factory()->state([
        'type' => $super ? AdministratorTypeEnum::super->value : AdministratorTypeEnum::normal->value,
    ]))->create(['email_verified_at' => now()]);
}

// A 1:1 between a client (adult unless $minor) and a counsellor.
function sflTherapy(bool $minor = false, array $therapy = []): array
{
    $client = User::factory()->create(['dob' => now()->subYears($minor ? 15 : 30)->toDateString(), 'firstName' => 'Ama', 'country' => 'Ghana']);
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => $counsellor->id,
        'status' => TherapyStatusEnum::in_session->value, 'anonymous' => false, 'public' => false, ...$therapy,
    ]);

    return compact('client', 'counsellor', 'therapy');
}

function sflGroup(User $member, bool $anonymous = true): array
{
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $group = GroupTherapy::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id, 'status' => TherapyStatusEnum::in_session->value, 'anonymous' => false]);
    $group->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $group->users()->attach($member->id, ['anonymous' => $anonymous, 'was_minor_at_join' => false]);

    return compact('group', 'counsellor');
}

function sflInput(array $overrides = []): array
{
    return ['urgency' => 'URGENT', 'reason' => SFL_REASON, 'discussedWithClient' => 'YES', 'emergencyServicesContacted' => false, ...$overrides];
}

function sflRaise($test, Counsellor $counsellor, Therapy $therapy, array $input = [])
{
    return $test->actingAs($counsellor->user)->postJson(route('api.safety_flags.therapy', ['therapyId' => $therapy->id]), sflInput($input));
}

beforeEach(function () {
    config(['safety.enabled' => true]);
    Event::fake([SafetyFlagsChangedEvent::class, HelpRequestRingingEvent::class, HelpRequestUpdatedEvent::class, HelpQueueChangedEvent::class]);
    Bus::fake([EscalateHelpRequestJob::class]);
});

test('switched off, every safety flag route is a 404 and there is no menu item or badge', function () {
    config(['safety.enabled' => false]);
    ['counsellor' => $counsellor, 'therapy' => $therapy] = sflTherapy();
    $admin = sflAdmin();

    sflRaise($this, $counsellor, $therapy)->assertNotFound();
    $this->actingAs($admin)->get(route('administrator.safety_flags'))->assertNotFound();
    expect(json_encode(AdminNavigation::for($admin)))->not->toContain('safety-flags')
        ->and(GetAdminToDoCountsAction::new()->badges($admin))->not->toHaveKey('safetyAlerts');
});

test('a 1:1 counsellor raises a flag: stored encrypted, recorded, and every uninvolved administrator told -- never who or why', function () {
    Notification::fake();
    ['client' => $client, 'counsellor' => $counsellor, 'therapy' => $therapy] = sflTherapy();
    $admin = sflAdmin();
    $super = sflAdmin(super: true);
    $guardianAdmin = sflAdmin();
    Guardianship::query()->create(['guardian_id' => $guardianAdmin->id, 'ward_id' => $client->id, 'ward_was_minor_at_creation' => false]);
    Administrator::factory()->create(['user_id' => $counsellor->user_id]);

    sflRaise($this, $counsellor, $therapy, ['urgency' => 'IMMINENT'])->assertCreated()->assertExactJson(['status' => true]);

    $flag = SafetyFlag::query()->sole();
    expect($flag->status)->toBe(SafetyFlagStatusEnum::open)->and($flag->reason)->toBe(SFL_REASON)
        ->and(DB::table('safety_flags')->value('reason'))->not->toContain('stopped eating')
        ->and($flag->country_code)->toBe('GH')
        ->and($flag->events()->pluck('action')->all())->toBe([SafetyFlagEvent::RAISED]);

    Notification::assertSentTo([$admin, $super], SafetyFlagRaisedNotification::class, function ($notification, $channels, $notifiable) use ($flag) {
        $mail = $notification->toMail($notifiable);
        $text = json_encode([$mail->subject, $mail->introLines, $notification->toArray($notifiable)]);

        return $notification->toArray($notifiable)['safetyFlag'] === $flag->public_id
            && ! str_contains($text, 'Ama') && ! str_contains($text, 'stopped eating') && str_contains($text, 'Imminent');
    });
    Notification::assertNotSentTo([$guardianAdmin, $counsellor->user->fresh(), $client], SafetyFlagRaisedNotification::class);
    // The ping: no id, and only to the administrators who aren't involved, each on their own channel.
    Event::assertDispatched(SafetyFlagsChangedEvent::class, function ($event) use ($admin, $super, $guardianAdmin) {
        $channels = collect($event->broadcastOn())->map(fn ($channel) => $channel->name);

        return $event->broadcastWith() === [] && $channels->contains("private-users.{$admin->id}")
            && $channels->contains("private-users.{$super->id}") && ! $channels->contains("private-users.{$guardianAdmin->id}");
    });
});

test('only the counsellor of the therapy may flag; anyone else -- and a missing therapy -- gets the same 404; fields are checked', function () {
    ['client' => $client, 'counsellor' => $counsellor, 'therapy' => $therapy] = sflTherapy();
    $other = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);

    foreach ([$client, $other->user, sflAdmin()] as $user) {
        $this->actingAs($user)->postJson(route('api.safety_flags.therapy', ['therapyId' => $therapy->id]), sflInput())->assertNotFound();
    }
    $this->actingAs($counsellor->user)->postJson(route('api.safety_flags.therapy', ['therapyId' => 999999]), sflInput())->assertNotFound();
    sflRaise($this, $counsellor, $therapy, ['urgency' => 'SOON', 'reason' => 'short', 'discussedWithClient' => 'MAYBE'])
        ->assertStatus(422)->assertJsonValidationErrors(['urgency', 'reason', 'discussedWithClient']);
    expect(SafetyFlag::query()->count())->toBe(0);
});

test('one open flag per client per therapy; once it is closed another can be raised', function () {
    ['counsellor' => $counsellor, 'therapy' => $therapy] = sflTherapy();

    sflRaise($this, $counsellor, $therapy)->assertCreated();
    sflRaise($this, $counsellor, $therapy)->assertStatus(409);
    expect(SafetyFlag::query()->count())->toBe(1);

    // TT-4.3b's withdraw/resolve clear the key in the same update as the status.
    SafetyFlag::query()->update(['status' => SafetyFlagStatusEnum::resolved->value, 'open_client_key' => null]);
    sflRaise($this, $counsellor, $therapy)->assertCreated();
});

test('a flag works on a paused minor\'s therapy, an unpaid one and a lapsed counsellor, and changes nothing the client sees', function () {
    ['client' => $minor, 'counsellor' => $counsellor, 'therapy' => $therapy] = sflTherapy(minor: true, therapy: ['payment_data' => ['type' => 'PAID', 'amount' => 100]]);
    $counsellor->forceFill(['verified_at' => null])->save();
    expect($minor->fresh()->isPausedForMissingGuardian())->toBeTrue();
    // What the client's therapy page is given, as them.
    $seenByClient = function () use ($minor, $therapy) {
        $request = Request::create('/');
        $request->setUserResolver(fn () => $minor);

        // Dates of birth left out: UserMiniResource's per-request admin cache makes them flicker in a test.
        return preg_replace('/"dob":"[^"]*",/', '', json_encode((new TherapyResource($therapy->fresh()))->resolve($request)));
    };
    $before = $seenByClient();

    sflRaise($this, $counsellor, $therapy)->assertCreated();

    $flag = SafetyFlag::query()->sole();
    expect($flag->was_minor)->toBeTrue()->and($flag->had_guardian)->toBeFalse()->and($flag->guardian_notice_state)->toBe(SafetyFlagNoticeStateEnum::pending)
        ->and($seenByClient())->toBe($before)
        ->and($minor->notifications()->count())->toBe(0)
        ->and($therapy->fresh()->status)->toBe(TherapyStatusEnum::in_session->value);
});

test('a group counsellor flags a member by key (a former member too); the members list never gives user ids', function () {
    User::factory()->count(3)->create(); // So a member's key and user id can't coincide.
    $member = User::factory()->adult()->create();
    ['group' => $group, 'counsellor' => $counsellor] = sflGroup($member);

    $members = $this->actingAs($counsellor->user)->getJson(route('api.safety_flags.group_members', ['groupTherapyId' => $group->id]))->assertOk()->json('members');
    expect($members)->toHaveCount(1)->and($members[0]['name'])->toBe(ConstantsEnum::anonymousUserLabel->value)->and($members[0])->not->toHaveKey('id')
        ->and((string) $members[0]['key'])->not->toBe((string) $member->id);

    $group->allMemberships()->updateExistingPivot($member->id, ['left_at' => now()]);
    $this->actingAs($counsellor->user)->postJson(route('api.safety_flags.group', ['groupTherapyId' => $group->id]), sflInput(['memberKey' => $members[0]['key']]))->assertCreated();
    $this->actingAs($counsellor->user)->postJson(route('api.safety_flags.group', ['groupTherapyId' => $group->id]), sflInput(['memberKey' => (string) ($member->id + 1000)]))->assertNotFound();
    $this->actingAs($member)->getJson(route('api.safety_flags.group_members', ['groupTherapyId' => $group->id]))->assertNotFound();

    $flag = SafetyFlag::query()->sole();
    expect($flag->anonymous)->toBeTrue()->and((int) $flag->client_user_id)->toBe($member->id)->and($flag->kind->value)->toBe('GROUP');
});

test('the counsellor who answered an urgent help chat can flag the person, during it and for 14 days after', function () {
    $person = User::factory()->adult()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id, 'urgent_help_available_until' => now()->addHours(2)]);
    $id = $this->actingAs($person)->postJson(route('help.requests.store'), ['anonymous' => true])->json('helpRequest.id');
    $this->actingAs($counsellor->user)->postJson(route('help.requests.accept', ['helpRequest' => $id]))->assertOk();
    $this->actingAs($person)->postJson(route('help.requests.end', ['helpRequest' => $id]))->assertOk();

    $this->actingAs(User::factory()->adult()->create())->postJson(route('help.requests.safety_flag', ['helpRequest' => $id]), sflInput())->assertNotFound();
    $this->travel(15)->days();
    $this->actingAs($counsellor->user)->postJson(route('help.requests.safety_flag', ['helpRequest' => $id]), sflInput())->assertNotFound();
    $this->travel(-2)->days();
    $this->actingAs($counsellor->user)->postJson(route('help.requests.safety_flag', ['helpRequest' => $id]), sflInput())->assertCreated();

    $flag = SafetyFlag::query()->sole();
    expect($flag->kind->value)->toBe('HELP_CHAT')->and($flag->anonymous)->toBeTrue()
        ->and($flag->flaggable_id)->toBe(HelpRequest::query()->find($id)->therapy_id);
});

test('administrators: the list hides who and why and leaves out involved ones; the first to acknowledge handles it; only they see who and why, each look recorded', function () {
    ['client' => $client, 'counsellor' => $counsellor, 'therapy' => $therapy] = sflTherapy();
    $first = sflAdmin();
    $second = sflAdmin();
    $guardianAdmin = sflAdmin();
    Guardianship::query()->create(['guardian_id' => $guardianAdmin->id, 'ward_id' => $client->id, 'ward_was_minor_at_creation' => false]);
    sflRaise($this, $counsellor, $therapy, ['urgency' => 'IMMINENT', 'emergencyServicesContacted' => true, 'emergencyServicesNote' => 'Ambulance called'])->assertCreated();
    $flag = SafetyFlag::query()->sole();

    $list = $this->actingAs($first)->getJson(route('admin.safety_flags.list'))->assertOk();
    expect($list->json('flags'))->toHaveCount(1)->and($list->json('flags.0.id'))->toBe($flag->public_id)->and($list->getContent())->not->toContain('Ama')->not->toContain('stopped eating')->not->toContain($therapy->name)
        ->and($this->actingAs($guardianAdmin)->getJson(route('admin.safety_flags.list'))->json('flags'))->toBe([])
        ->and(GetAdminToDoCountsAction::new()->badges($first)['safetyAlerts'])->toBe(1)
        ->and(GetAdminToDoCountsAction::new()->badges($guardianAdmin)['safetyAlerts'])->toBe(0);
    $this->actingAs($guardianAdmin)->postJson(route('admin.safety_flags.acknowledge', ['flag' => $flag->public_id]))->assertNotFound();

    // Who and why: not before acknowledging.
    $this->actingAs($first)->postJson(route('admin.safety_flags.who', ['flag' => $flag->public_id]))->assertForbidden();
    $this->actingAs($first)->postJson(route('admin.safety_flags.acknowledge', ['flag' => $flag->public_id]))->assertOk();
    $this->actingAs($second)->postJson(route('admin.safety_flags.acknowledge', ['flag' => $flag->public_id]))->assertStatus(409);
    $this->actingAs($second)->postJson(route('admin.safety_flags.reason', ['flag' => $flag->public_id]))->assertForbidden();

    $this->actingAs($first)->postJson(route('admin.safety_flags.who', ['flag' => $flag->public_id]))->assertOk()
        ->assertJsonPath('client.name', $client->name)->assertJsonPath('where.name', $therapy->name);
    $this->actingAs($first)->postJson(route('admin.safety_flags.reason', ['flag' => $flag->public_id]))->assertOk()
        ->assertJsonPath('reason', SFL_REASON)->assertJsonPath('emergencyServicesNote', 'Ambulance called');

    expect(AdminContentAccess::query()->where('admin_user_id', $first->id)->pluck('resource')->sort()->values()->all())
        ->toBe([AdminContentAccess::SAFETY_FLAG_IDENTITY, AdminContentAccess::SAFETY_FLAG_REASON])
        ->and(GetAdminToDoCountsAction::new()->badges($second)['safetyAlerts'])->toBe(0)
        ->and($flag->fresh()->events()->pluck('action')->all())->toBe([SafetyFlagEvent::RAISED, SafetyFlagEvent::ACKNOWLEDGED]);
});

test('a super administrator can hand an acknowledged flag to another uninvolved administrator; nobody else can', function () {
    ['client' => $client, 'counsellor' => $counsellor, 'therapy' => $therapy] = sflTherapy();
    sflRaise($this, $counsellor, $therapy)->assertCreated();
    $flag = SafetyFlag::query()->sole();
    $handler = sflAdmin();
    $super = sflAdmin(super: true);
    $next = sflAdmin();
    $guardianAdmin = sflAdmin();
    Guardianship::query()->create(['guardian_id' => $guardianAdmin->id, 'ward_id' => $client->id, 'ward_was_minor_at_creation' => false]);
    // Only one being handled can be handed on.
    $this->actingAs($super)->postJson(route('admin.safety_flags.reassign', ['flag' => $flag->public_id]), ['adminId' => $next->id])->assertStatus(409);
    $this->actingAs($handler)->postJson(route('admin.safety_flags.acknowledge', ['flag' => $flag->public_id]))->assertOk();

    $this->actingAs($next)->postJson(route('admin.safety_flags.reassign', ['flag' => $flag->public_id]), ['adminId' => $next->id])->assertForbidden();
    $this->actingAs($super)->postJson(route('admin.safety_flags.reassign', ['flag' => $flag->public_id]), ['adminId' => $guardianAdmin->id])->assertStatus(422);
    $this->actingAs($super)->postJson(route('admin.safety_flags.reassign', ['flag' => $flag->public_id]), ['adminId' => $next->id])->assertOk();

    expect((int) $flag->fresh()->handler_user_id)->toBe($next->id)
        ->and($flag->fresh()->events()->where('action', SafetyFlagEvent::REASSIGNED)->sole()->data)->toBe(['from' => $handler->id, 'to' => $next->id]);
    $this->actingAs($handler)->postJson(route('admin.safety_flags.who', ['flag' => $flag->public_id]))->assertForbidden();
});

test('a counsellor can raise only so many flags an hour', function () {
    config(['safety.flags_per_hour' => 1]);
    ['counsellor' => $counsellor, 'therapy' => $therapy] = sflTherapy();
    sflRaise($this, $counsellor, $therapy)->assertCreated();

    $another = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()->adult()->create()->id, 'counsellor_id' => $counsellor->id, 'status' => TherapyStatusEnum::in_session->value]);
    sflRaise($this, $counsellor, $another)->assertStatus(429);
});

test('the history can\'t be changed and flags can\'t be deleted -- by the model or the database', function () {
    ['counsellor' => $counsellor, 'therapy' => $therapy] = sflTherapy();
    sflRaise($this, $counsellor, $therapy)->assertCreated();
    $flag = SafetyFlag::query()->sole();

    expect(fn () => $flag->events()->first()->update(['action' => 'x']))->toThrow(LogicException::class)
        ->and(fn () => DB::table('safety_flag_events')->update(['action' => 'x']))->toThrow(Exception::class)
        ->and(fn () => DB::table('safety_flag_events')->delete())->toThrow(Exception::class)
        ->and(fn () => DB::table('safety_flags')->delete())->toThrow(Exception::class);
})->group('mysql-triggers');

test('a counsellor suspended from counselling can still flag; one whose whole account is suspended can\'t reach it', function () {
    ['counsellor' => $counsellor, 'therapy' => $therapy] = sflTherapy();
    $suspend = function (User $user, string $scope) {
        $report = new Report(['description' => 'Conduct', 'data' => []]);
        $report->addedby()->associate(User::factory()->adult()->create());
        $report->reportable()->associate($user);
        $report->save();
        Suspension::query()->forceCreate(['user_id' => $user->id, 'report_id' => $report->id, 'scope' => $scope, 'starts_at' => now()->subHour(), 'public_reason' => 'A reason.']);
    };

    $suspend($counsellor->user, 'counsellor');
    sflRaise($this, $counsellor->fresh(), $therapy)->assertCreated();

    ['counsellor' => $other, 'therapy' => $otherTherapy] = sflTherapy();
    $suspend($other->user, 'account');
    sflRaise($this, $other->fresh(), $otherTherapy)->assertForbidden();
});

test('the demo data seeds an open imminent flag on a minor and one being handled on an anonymous group member', function () {
    $super = sflAdmin(super: true);
    (new DatabaseSeeder)->createSafetyFlagDemoData($super);

    $flags = SafetyFlag::query()->orderBy('id')->get();
    expect($flags)->toHaveCount(4) // TT-4.3b adds a withdrawn and a resolved one.
        ->and($flags[0]->status)->toBe(SafetyFlagStatusEnum::open)->and($flags[0]->was_minor)->toBeTrue()->and($flags[0]->had_guardian)->toBeTrue()
        ->and($flags[1]->status)->toBe(SafetyFlagStatusEnum::acknowledged)->and($flags[1]->anonymous)->toBeTrue()
        ->and((int) $flags[1]->handler_user_id)->toBe($super->id);
    $this->actingAs($super)->postJson(route('admin.safety_flags.reason', ['flag' => $flags[1]->public_id]))->assertOk();
});

test('a therapy or group that ended over 14 days ago can no longer be flagged -- and the page doesn\'t offer it', function () {
    ['counsellor' => $counsellor, 'therapy' => $therapy] = sflTherapy(therapy: ['status' => TherapyStatusEnum::ended->value]);
    $member = User::factory()->adult()->create();
    ['group' => $group, 'counsellor' => $groupCounsellor] = sflGroup($member);
    $group->forceFill(['status' => TherapyStatusEnum::ended->value])->save();
    $key = (string) $group->users()->whereKey($member->id)->first()->pivot->id;

    $canRaise = function (User $viewer, $resource) {
        $request = Request::create('/');
        $request->setUserResolver(fn () => $viewer);

        return $resource->resolve($request)['canRaiseSafetyFlag'] ?? null;
    };
    expect($canRaise($counsellor->user, new TherapyResource($therapy->fresh())))->toBeTrue()
        ->and($canRaise($therapy->addedby, new TherapyResource($therapy->fresh())))->toBeNull();

    $this->travel(15)->days();
    sflRaise($this, $counsellor, $therapy)->assertNotFound();
    $this->actingAs($groupCounsellor->user)->postJson(route('api.safety_flags.group', ['groupTherapyId' => $group->id]), sflInput(['memberKey' => $key]))->assertNotFound();
    $this->actingAs($groupCounsellor->user)->getJson(route('api.safety_flags.group_members', ['groupTherapyId' => $group->id]))->assertNotFound();
    expect($canRaise($counsellor->user, new TherapyResource($therapy->fresh())))->toBeFalse()
        ->and($canRaise($groupCounsellor->user, new GroupTherapyResource($group->fresh())))->toBeFalse();
});

test('in a group: the creator can be flagged by "creator"; never a fellow counsellor or oneself; two former members are told apart without names', function () {
    $creator = User::factory()->adult()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $coCounsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $creator->id, 'status' => TherapyStatusEnum::in_session->value, 'anonymous' => false]);
    $group->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $group->counsellors()->attach($coCounsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    [$first, $second] = User::factory()->adult()->count(2)->create();
    foreach ([$first, $second] as $left) {
        $group->users()->attach($left->id, ['anonymous' => false, 'was_minor_at_join' => false, 'left_at' => now()->subMonth()]);
    }

    $members = collect($this->actingAs($counsellor->user)->getJson(route('api.safety_flags.group_members', ['groupTherapyId' => $group->id]))->json('members'));
    $former = $members->where('left', true)->pluck('name');
    expect($members->pluck('key'))->toContain('creator')->and($former)->toHaveCount(2)->and($former->unique())->toHaveCount(2)
        ->and($former->implode(' '))->not->toContain($first->firstName)->not->toContain($second->firstName);

    $this->actingAs($counsellor->user)->postJson(route('api.safety_flags.group', ['groupTherapyId' => $group->id]), sflInput(['memberKey' => 'creator']))->assertCreated();
    expect((int) SafetyFlag::query()->sole()->client_user_id)->toBe($creator->id);
});

test('for a group flag, administrators who are a former member, one of its counsellors, or a guardian of another member are involved', function () {
    $member = User::factory()->adult()->create();
    $other = User::factory()->adult()->create();
    ['group' => $group, 'counsellor' => $counsellor] = sflGroup($member);
    $group->users()->attach($other->id, ['anonymous' => false, 'was_minor_at_join' => false]);
    $key = (string) $group->users()->whereKey($member->id)->first()->pivot->id;

    $formerMember = sflAdmin();
    $group->users()->attach($formerMember->id, ['anonymous' => false, 'was_minor_at_join' => false, 'left_at' => now()->subWeek()]);
    $groupCounsellorAdmin = sflAdmin();
    $group->counsellors()->attach(Counsellor::factory()->create(['user_id' => $groupCounsellorAdmin->id])->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $otherGuardian = sflAdmin();
    Guardianship::query()->create(['guardian_id' => $otherGuardian->id, 'ward_id' => $other->id, 'ward_was_minor_at_creation' => false]);
    $uninvolved = sflAdmin();

    $this->actingAs($counsellor->user)->postJson(route('api.safety_flags.group', ['groupTherapyId' => $group->id]), sflInput(['memberKey' => $key]))->assertCreated();

    foreach ([$formerMember, $groupCounsellorAdmin, $otherGuardian] as $involved) {
        expect($this->actingAs($involved)->getJson(route('admin.safety_flags.list'))->json('flags'))->toBe([])
            ->and(GetAdminToDoCountsAction::new()->badges($involved)['safetyAlerts'])->toBe(0);
    }
    expect($this->actingAs($uninvolved)->getJson(route('admin.safety_flags.list'))->json('flags'))->toHaveCount(1);
});

test('with no uninvolved administrator to tell, it is logged as critical, never dropped silently', function () {
    Log::spy();
    ['client' => $client, 'counsellor' => $counsellor, 'therapy' => $therapy] = sflTherapy();
    $guardianAdmin = sflAdmin();
    Guardianship::query()->create(['guardian_id' => $guardianAdmin->id, 'ward_id' => $client->id, 'ward_was_minor_at_creation' => false]);

    sflRaise($this, $counsellor, $therapy)->assertCreated();

    Log::shouldHaveReceived('critical')->withArgs(fn ($message) => str_contains($message, 'Nobody could be told of safety flag'))->once();
});

test('the read log names a safety flag without its id, and offers no way to pick it out', function () {
    ['counsellor' => $counsellor, 'therapy' => $therapy] = sflTherapy();
    sflRaise($this, $counsellor, $therapy)->assertCreated();
    $flag = SafetyFlag::query()->sole();
    $super = sflAdmin(super: true);
    $this->actingAs($super)->postJson(route('admin.safety_flags.acknowledge', ['flag' => $flag->public_id]))->assertOk();
    $this->actingAs($super)->postJson(route('admin.safety_flags.who', ['flag' => $flag->public_id]))->assertOk();

    $this->actingAs($super)->get(route('administrator.content-accesses'))->assertInertia(fn ($page) => $page
        ->where('accesses.data.0.subject', 'A safety flag')->where('accesses.data.0.subjectId', null)->where('accesses.data.0.subjectKey', null));
});
