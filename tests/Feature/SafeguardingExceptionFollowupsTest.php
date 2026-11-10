<?php

use App\Actions\Guardianship\HandleGuardianLossAction;
use App\Actions\User\AnonymiseClosedAccountAction;
use App\Enums\AdministratorTypeEnum;
use App\Enums\ConstantsEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Events\GuardianAccessChangedEvent;
use App\Events\TherapyAccessChangedEvent;
use App\Http\Resources\TherapyResource;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Guardianship;
use App\Models\Request;
use App\Models\SafeguardingException;
use App\Models\Therapy;
use App\Models\User;
use App\Notifications\ClientAccessPausedNotification;
use App\Notifications\ClientAccessResumedNotification;
use App\Notifications\TherapyContinuationNotification;
use App\Support\WardRef;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

// SCRUM-384: follow-ups to the safeguarding exception (SCRUM-381/382) -- the requests list's query
// count, the admin page's paging and "no longer needed" list, the shared limit, and the closed
// account's name in counsellors' notices. Helpers are file-local and sgf-prefixed (--parallel).

beforeEach(function () {
    Event::fake([GuardianAccessChangedEvent::class, TherapyAccessChangedEvent::class]);
});

function sgfCounsellor(): array
{
    $user = User::factory()->adult()->create(['email_verified_at' => now()]);

    return [$user, Counsellor::factory()->create(['user_id' => $user->id])];
}

function sgfMinor(array $attributes = []): User
{
    return User::factory()->create(['dob' => now()->subYears(15)->toDateString(), 'email_verified_at' => now(), ...$attributes]);
}

// A minor with no guardian in an open 1:1 with $counsellor.
function sgfTherapy(Counsellor $counsellor, ?User $minor = null, bool $anonymous = false): Therapy
{
    return Therapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => ($minor ?? sgfMinor())->id, 'counsellor_id' => $counsellor->id,
        'status' => TherapyStatusEnum::in_session->value, 'anonymous' => $anonymous, 'public' => false, 'client_was_minor_at_creation' => true,
    ]);
}

function sgfGroup(Counsellor $counsellor): GroupTherapy
{
    $group = GroupTherapy::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id, 'status' => TherapyStatusEnum::in_session->value, 'anonymous' => false]);
    $group->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);

    return $group;
}

// A pending request, written straight to the table (the send action refuses a duplicate).
function sgfRequest(Counsellor $from, Therapy|GroupTherapy $for, User $ward, string $reason = 'They need to keep going.'): Request
{
    $request = new Request([
        'type' => RequestTypeEnum::safeguardingException->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => ['reason' => $reason, 'proposedEndsAt' => now()->addDays(14)->toJSON(), 'wardId' => $ward->id],
    ]);
    $request->from()->associate($from);
    $request->for()->associate($for);
    $request->save();

    return $request;
}

function sgfSuperAdmin(?User $user = null): User
{
    $user ??= User::factory()->adult()->create();
    Administrator::factory()->create(['user_id' => $user->id, 'type' => AdministratorTypeEnum::super->value]);

    return User::query()->find($user->id);
}

// How many queries listing the requests takes for $viewer (after a first visit, which records the
// visitor once).
function sgfListQueries(User $viewer): int
{
    test()->actingAs(User::query()->find($viewer->id))->getJson(route('requests.get'))->assertOk();
    $viewer = User::query()->find($viewer->id);
    DB::flushQueryLog();
    DB::enableQueryLog();
    test()->actingAs($viewer)->getJson(route('requests.get'))->assertOk();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

function sgfGuardian(User $ward): void
{
    Guardianship::query()->create(['guardian_id' => User::factory()->adult()->create()->id, 'ward_id' => $ward->id, 'ward_was_minor_at_creation' => true]);
}

// -- 1. The requests list's query count ------------------------------------------------------------

test('listing safeguarding requests takes the same number of queries for 2 rows as for 6', function () {
    [$counsellorUser, $counsellor] = sgfCounsellor();
    $group = sgfGroup($counsellor);
    $therapy = sgfTherapy($counsellor);
    $super = sgfSuperAdmin();
    // A super administrator who is also a counsellor (of someone else): reads whether they counsel
    // each minor, once per minor.
    [$counsellingUser, $counsellingCounsellor] = sgfCounsellor();
    sgfTherapy($counsellingCounsellor, User::factory()->adult()->create());
    $counsellingSuper = sgfSuperAdmin($counsellingUser);

    $addMembers = function (int $count) use ($group, $counsellor) {
        foreach (range(1, $count) as $i) {
            $member = sgfMinor();
            $group->users()->attach($member->id, ['anonymous' => true, 'was_minor_at_join' => true]);
            sgfRequest($counsellor, $group, $member);
        }
    };
    $addSameWard = function (int $count) use ($therapy, $counsellor) {
        foreach (range(1, $count) as $i) {
            sgfRequest($counsellor, $therapy, $therapy->addedby);
        }
    };

    $addMembers(1);
    $addSameWard(1);
    $before = [sgfListQueries($counsellorUser), sgfListQueries($super)];

    // More members (distinct minors) and more rows about the same minor.
    $addMembers(2);
    $addSameWard(2);
    expect([sgfListQueries($counsellorUser), sgfListQueries($super)])->toBe($before);

    // The counselling super administrator reads once per distinct minor (the group's two new
    // members), never once per row: the two extra rows about the 1:1 client cost nothing.
    $addSameWard(2);
    $afterCounselling = sgfListQueries($counsellingSuper);
    $addSameWard(2);
    expect(sgfListQueries($counsellingSuper))->toBe($afterCounselling);
});

test('the preloaded list still names an anonymous member "Anonymous" to the counsellor and in full to the super administrator', function () {
    [$counsellorUser, $counsellor] = sgfCounsellor();
    $group = sgfGroup($counsellor);
    $member = sgfMinor(['firstName' => 'Rowan', 'lastName' => 'Hale']);
    $group->users()->attach($member->id, ['anonymous' => true, 'was_minor_at_join' => true]);
    $request = sgfRequest($counsellor, $group, $member);
    $gone = sgfMinor();
    $goneRequest = sgfRequest($counsellor, $group, $gone);
    $gone->forceDelete();

    $listed = $this->actingAs($counsellorUser)->getJson(route('requests.get'))->assertOk()->json('data');
    $byId = collect($listed)->keyBy('id');
    expect($byId[$request->id]['safeguardingException']['client']['fullName'])->toBe(ConstantsEnum::anonymousUserLabel->value)
        ->and($byId[$request->id]['safeguardingException'])->not->toHaveKey('safeguardingExceptionMaxDays')
        ->and($byId[$goneRequest->id]['safeguardingException']['client']['fullName'])->toBe(AnonymiseClosedAccountAction::CLOSED_ACCOUNT_NAME)
        ->and(json_encode($listed))->not->toContain('Rowan')->not->toContain('"wardId"');

    $super = $this->actingAs(sgfSuperAdmin())->getJson(route('requests.get'))->assertOk()->json('data');
    $row = collect($super)->firstWhere('id', $request->id);
    expect($row['safeguardingException']['client']['fullName'])->toBe($member->name)
        ->and($row['safeguardingException']['isRespondent'])->toBeTrue()
        ->and($row['safeguardingException']['safeguardingExceptionMaxDays'])->toBe(SafeguardingException::MAX_DAYS);
});

// -- 2. The admin page -----------------------------------------------------------------------------

test('"Paused now" is paged on pausedPage, and the exceptions keep page', function () {
    [, $counsellor] = sgfCounsellor();
    foreach (range(1, 21) as $i) {
        sgfTherapy($counsellor);
    }
    $super = sgfSuperAdmin();

    $this->actingAs($super)->get(route('administrator.safeguarding_exceptions'))->assertOk()
        ->assertInertia(fn ($page) => $page->has('pausedMinors.data', 20)
            ->where('pausedMinors.meta.total', 21)
            ->where('pausedMinors.meta.current_page', 1)
            ->where('safeguardingExceptionMaxDays', SafeguardingException::MAX_DAYS));

    $this->actingAs($super)->get(route('administrator.safeguarding_exceptions', ['pausedPage' => 2]))->assertOk()
        ->assertInertia(fn ($page) => $page->has('pausedMinors.data', 1)
            ->where('pausedMinors.meta.current_page', 2)
            ->where('exceptions.meta.current_page', 1)
            ->where('pausedMinors.meta.links', fn ($links) => collect($links)->filter(fn ($link) => $link['url'])->every(fn ($link) => str_contains($link['url'], 'pausedPage='))));
});

test('"Requests no longer needed" lists pending requests whose minor isn\'t paused any more, and the super administrator can reject them', function () {
    [$counsellorUser, $counsellor] = sgfCounsellor();
    $guarded = sgfTherapy($counsellor);
    $covered = sgfTherapy($counsellor);
    $grown = sgfTherapy($counsellor);
    $closed = sgfTherapy($counsellor);
    $stillPaused = sgfTherapy($counsellor);
    $requests = collect(compact('guarded', 'covered', 'grown', 'closed', 'stillPaused'))
        ->map(fn (Therapy $therapy) => sgfRequest($counsellor, $therapy, $therapy->addedby));

    sgfGuardian($guarded->addedby);
    SafeguardingException::query()->create([
        'ward_id' => $covered->addedby->id, 'granted_by' => sgfSuperAdmin()->id, 'reason' => 'Private.',
        'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(5),
    ]);
    $grown->addedby->forceFill(['dob' => now()->subYears(18)->subDay()->toDateString()])->save();
    $closed->addedby->delete();

    $super = sgfSuperAdmin();
    $response = $this->actingAs($super)->get(route('administrator.safeguarding_exceptions'))->assertOk();
    $rows = collect($response->viewData('page')['props']['noLongerNeeded'])->keyBy('id');

    expect($rows->keys()->sort()->values()->all())->toBe($requests->except('stillPaused')->pluck('id')->sort()->values()->all())
        ->and($rows[$requests['guarded']->id])->toMatchArray(['why' => 'They have a guardian now.', 'canManage' => true, 'involved' => false, 'client' => $guarded->addedby->name, 'reason' => 'They need to keep going.'])
        ->and($rows[$requests['covered']->id]['why'])->toBe('A safeguarding exception is in force.')
        ->and($rows[$requests['grown']->id]['why'])->toBe('They have turned 18.')
        ->and($rows[$requests['closed']->id])->toMatchArray(['why' => 'The account was closed.', 'client' => 'a closed account', 'canManage' => true]);

    foreach ($requests->except('stillPaused') as $request) {
        $this->actingAs($super)->postJson(route('requests.respond', ['requestId' => $request->id]), ['response' => 'rejected'])->assertCreated();
        expect($request->fresh()->status)->toBe(RequestStatusEnum::rejected->value);
    }

    $this->actingAs($super)->get(route('administrator.safeguarding_exceptions'))
        ->assertInertia(fn ($page) => $page->where('noLongerNeeded', []));
});

test('an administrator involved with the minor sees a "no longer needed" request masked; a normal administrator can\'t reject it', function () {
    [$counsellorUser, $counsellor] = sgfCounsellor();
    $therapy = sgfTherapy($counsellor);
    $request = sgfRequest($counsellor, $therapy, $therapy->addedby, 'Confidential family detail.');
    sgfGuardian($therapy->addedby);

    // The counsellor who sent it, made a super administrator: involved (they counsel the minor).
    $response = $this->actingAs(sgfSuperAdmin($counsellorUser))->get(route('administrator.safeguarding_exceptions'))
        ->assertInertia(fn ($page) => $page->where('noLongerNeeded.0.involved', true)
            ->where('noLongerNeeded.0.canManage', false)
            ->missing('noLongerNeeded.0.client')
            ->missing('noLongerNeeded.0.reason')
            ->missing('noLongerNeeded.0.id'));
    expect($response->getContent())->not->toContain('Confidential family detail.')->not->toContain($therapy->name);

    $normal = User::factory()->adult()->has(Administrator::factory()->state(['type' => AdministratorTypeEnum::normal->value]))->create();
    $this->actingAs($normal)->get(route('administrator.safeguarding_exceptions'))
        ->assertInertia(fn ($page) => $page->where('noLongerNeeded.0.id', $request->id)->where('noLongerNeeded.0.canManage', false));
});

// -- 4. The shared limit ---------------------------------------------------------------------------

test('the counsellor of a paused client gets the limit with the therapy; nobody else does', function () {
    [$counsellorUser, $counsellor] = sgfCounsellor();
    $therapy = sgfTherapy($counsellor);
    $view = fn (User $viewer) => (new TherapyResource($therapy->fresh()))->resolve(tap(HttpRequest::create('/'), fn ($r) => $r->setUserResolver(fn () => $viewer)));

    expect($view($counsellorUser)['safeguardingExceptionMaxDays'])->toBe(SafeguardingException::MAX_DAYS)
        ->and($view(User::query()->find($therapy->addedby_id)))->not->toHaveKey('safeguardingExceptionMaxDays');
});

// -- 6. The closed account's name in counsellors' notices ------------------------------------------

test('the pause, resume and continuation notices carry an opaque wardRef, never the id', function () {
    [$counsellorUser, $counsellor] = sgfCounsellor();
    $therapy = sgfTherapy($counsellor);
    $ward = $therapy->addedby;
    $ref = WardRef::of($ward);

    $notices = [
        new ClientAccessPausedNotification($therapy, 'Kit Lane', $ward),
        new ClientAccessResumedNotification($therapy, 'Kit Lane', $ward, now()->addDays(3)),
        new TherapyContinuationNotification(TherapyContinuationNotification::RENEWED, now()->addDays(3), $therapy, 'Kit Lane', $ward),
    ];

    foreach ($notices as $notice) {
        $data = $notice->toArray($counsellorUser);
        expect($data['wardRef'])->toBe($ref)->not->toBe((string) $ward->id)
            ->and(json_encode($data))->not->toContain('"wardId"');
    }
    expect((new TherapyContinuationNotification(TherapyContinuationNotification::RENEWED, now()->addDays(3)))->toArray($ward))->not->toHaveKey('wardRef')
        ->and($ref)->not->toBe(WardRef::of($ward->id + 1));

    // Sent by the loss handling with the ward's ref.
    Notification::fake();
    HandleGuardianLossAction::new()->execute(User::query()->find($ward->id), 'test');
    Notification::assertSentTo($counsellorUser, ClientAccessPausedNotification::class, fn ($n) => $n->toArray($counsellorUser)['wardRef'] === $ref);
});

test('anonymising a closed account replaces its name in its counsellors\' stored notices, and nothing else', function () {
    [$counsellorUser, $counsellor] = sgfCounsellor();
    $therapy = sgfTherapy($counsellor);
    $ward = $therapy->addedby;
    $other = sgfTherapy($counsellor, sgfMinor(['firstName' => 'Other']));

    $counsellorUser->notifyNow(new ClientAccessPausedNotification($therapy, $ward->name, $ward), ['database']);
    $counsellorUser->notifyNow(new ClientAccessResumedNotification($therapy, $ward->name, $ward), ['database']);
    $counsellorUser->notifyNow(new TherapyContinuationNotification(TherapyContinuationNotification::ENDING_SOON, now()->addDays(2), $therapy, $ward->name, $ward), ['database']);
    $counsellorUser->notifyNow(new ClientAccessPausedNotification($other, $other->addedby->name, $other->addedby), ['database']);
    $ward->notifyNow(new TherapyContinuationNotification(TherapyContinuationNotification::RENEWED, now()->addDays(2)), ['database']);

    expect(AnonymiseClosedAccountAction::new()->execute(User::query()->find($ward->id)))->toBeTrue();

    $data = DB::table('notifications')->where('notifiable_id', $counsellorUser->id)->orderBy('created_at')->get()
        ->map(fn ($row) => json_decode($row->data, true));
    expect($data)->toHaveCount(4)
        ->and($data->where('wardRef', WardRef::of($ward))->pluck('clientName')->unique()->all())->toBe([AnonymiseClosedAccountAction::CLOSED_ACCOUNT_NAME])
        ->and($data->where('wardRef', WardRef::of($ward))->count())->toBe(3)
        ->and($data->firstWhere('wardRef', WardRef::of($other->addedby))['clientName'])->toBe($other->addedby->name)
        ->and($data->firstWhere('wardRef', WardRef::of($ward))['type'])->toStartWith('guardian_access.')
        ->and(DB::table('notifications')->where('notifiable_id', $ward->id)->where('notifiable_type', $ward->getMorphClass())->count())->toBe(0);
});
