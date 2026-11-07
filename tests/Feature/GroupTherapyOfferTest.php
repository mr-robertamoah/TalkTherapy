<?php

use App\Actions\GroupTherapy\EnsureCanSendGroupTherapyAssistanceRequestAction;
use App\Actions\Request\CreateRequestAction;
use App\DTOs\CreateRequestDTO;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Enums\SuspensionScopeEnum;
use App\Enums\TherapyStatusEnum;
use App\Exceptions\CounsellorException;
use App\Http\Resources\RequestResource;
use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\GroupTherapy;
use App\Models\Report;
use App\Models\Request;
use App\Models\Suspension;
use App\Models\User;
use App\Notifications\GroupTherapyAssistanceRequestSentNotification;
use App\Notifications\TherapyAssistanceRequestAcceptedNotification;
use App\Support\CounsellorVerification;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Notification;

// SCRUM-389: asking for a counsellor on a group after it was set up -- a counsellor offering to help
// a public group (the creator accepts), and the creator inviting more counsellors. Helpers are
// file-local and gto-prefixed (--parallel).

beforeEach(fn () => Notification::fake());

function gtoCounsellor(array $state = []): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()->adult()->create(['gender' => 'FEMALE', 'country' => 'Ghana'])->id, ...$state]);
}

function gtoGroup(array $attributes = []): GroupTherapy
{
    $creator = User::factory()->adult()->create(['gender' => 'MALE', 'country' => 'Ghana']);

    return GroupTherapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $creator->id, 'public' => true, 'anonymous' => false,
        'max_counsellors' => 2, 'status' => TherapyStatusEnum::pending->value, ...$attributes,
    ]);
}

function gtoOffer($test, Counsellor $counsellor, GroupTherapy $group)
{
    return $test->actingAs($counsellor->user)->postJson(route('group.therapies.assist', ['groupTherapyId' => $group->id]));
}

function gtoPending(GroupTherapy $group)
{
    return Request::query()->whereFor($group)->where('type', RequestTypeEnum::groupTherapy->value)->wherePending();
}

test('a counsellor offers to help a public group; its creator accepts and the counsellor joins its team', function () {
    $group = gtoGroup();
    $counsellor = gtoCounsellor();

    gtoOffer($this, $counsellor, $group)->assertOk()->assertJsonPath('status', true);

    $offer = gtoPending($group)->sole();
    expect($offer->from->is($counsellor))->toBeTrue()
        ->and($offer->to->is($group->addedby))->toBeTrue()
        ->and($group->pendingRequestFor($counsellor)?->id)->toBe($offer->id);
    Notification::assertSentTo($group->addedby, GroupTherapyAssistanceRequestSentNotification::class);

    $this->actingAs($group->addedby)->postJson(route('requests.respond', ['requestId' => $offer->id]), ['response' => 'accepted'])->assertSuccessful();

    expect($offer->fresh()->status)->toBe(RequestStatusEnum::accepted->value)
        ->and($group->fresh()->isCounsellor($counsellor))->toBeTrue();
    Notification::assertSentTo($counsellor, TherapyAssistanceRequestAcceptedNotification::class, function ($notification) use ($counsellor) {
        return str_contains((string) $notification->toMail($counsellor)->render(), 'Your offer to help the group therapy');
    });
});

test('only the group\'s creator accepts an offer, and only while the counsellor can still take on work', function () {
    $group = gtoGroup();
    $counsellor = gtoCounsellor();
    gtoOffer($this, $counsellor, $group)->assertOk();
    $offer = gtoPending($group)->sole();

    // Someone else can't answer it.
    $this->actingAs(User::factory()->adult()->create())->postJson(route('requests.respond', ['requestId' => $offer->id]), ['response' => 'accepted'])->assertStatus(422);

    // Their verification ended in between.
    $counsellor->forceFill(['verification_expires_at' => now()->subDay()])->save();
    $this->actingAs($group->addedby)->postJson(route('requests.respond', ['requestId' => $offer->id]), ['response' => 'accepted'])->assertStatus(422);

    expect($offer->fresh()->status)->toBe(RequestStatusEnum::pending->value)
        ->and($group->fresh()->isCounsellor($counsellor))->toBeFalse();
});

test('an offer is refused for a private, ended or full group, a group they\'re on or were in, and while one is waiting', function () {
    $counsellor = gtoCounsellor();

    gtoOffer($this, $counsellor, gtoGroup(['public' => false]))->assertStatus(422);
    gtoOffer($this, $counsellor, gtoGroup(['status' => TherapyStatusEnum::ended->value]))->assertStatus(422);

    $full = gtoGroup(['max_counsellors' => 1]);
    $full->counsellors()->attach(gtoCounsellor()->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    gtoOffer($this, $counsellor, $full)->assertStatus(422)->assertJsonPath('message', EnsureCanSendGroupTherapyAssistanceRequestAction::NO_PLACES);

    $theirs = gtoGroup();
    $theirs->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    gtoOffer($this, $counsellor, $theirs)->assertStatus(422);

    $former = gtoGroup();
    $former->allMemberships()->attach($counsellor->user_id, ['anonymous' => false, 'was_minor_at_join' => false, 'left_at' => now()]);
    gtoOffer($this, $counsellor, $former)->assertStatus(422);

    $once = gtoGroup();
    gtoOffer($this, $counsellor, $once)->assertOk();
    gtoOffer($this, $counsellor, $once)->assertStatus(422)->assertJsonPath('message', EnsureCanSendGroupTherapyAssistanceRequestAction::ALREADY_PENDING);

    expect(Request::query()->where('type', RequestTypeEnum::groupTherapy->value)->count())->toBe(1);
});

test('a counsellor already invited is pointed to the invitation, and one not currently verified can\'t offer', function () {
    $group = gtoGroup();
    $invited = gtoCounsellor();
    $this->actingAs($group->addedby)->postJson(route('group.therapies.assist', ['groupTherapyId' => $group->id]), ['counsellorIds' => [$invited->id]])->assertOk();

    gtoOffer($this, $invited, $group)->assertStatus(422)->assertJsonPath('message', EnsureCanSendGroupTherapyAssistanceRequestAction::INVITED);
    gtoOffer($this, gtoCounsellor(['verification_expires_at' => now()->subDay()]), gtoGroup())->assertStatus(422);
});

test('the creator invites more counsellors after setting the group up; nobody else can', function () {
    $group = gtoGroup(['max_counsellors' => 3]);
    $first = gtoCounsellor();
    $second = gtoCounsellor();
    $url = route('group.therapies.assist', ['groupTherapyId' => $group->id]);

    $this->actingAs($group->addedby)->postJson($url, ['counsellorIds' => [$first->id, $second->id]])->assertOk();
    expect(gtoPending($group)->get()->map(fn (Request $request) => $request->to_id)->sort()->values()->all())->toBe(collect([$first->id, $second->id])->sort()->values()->all());

    // Not again while those wait, not an unverified counsellor, and not without anyone.
    $this->actingAs($group->addedby)->postJson($url, ['counsellorIds' => [$first->id]])->assertStatus(422);
    $this->actingAs($group->addedby)->postJson($url, ['counsellorIds' => [gtoCounsellor(['verified_at' => null])->id]])->assertStatus(422);
    $this->actingAs($group->addedby)->postJson($url, ['counsellorIds' => []])->assertStatus(422)->assertJsonPath('message', EnsureCanSendGroupTherapyAssistanceRequestAction::NO_COUNSELLORS);

    // A member who isn't the creator, and a user with no counsellor profile.
    $this->actingAs(User::factory()->adult()->create())->postJson($url, ['counsellorIds' => [gtoCounsellor()->id]])->assertStatus(422);
    expect(gtoPending($group)->count())->toBe(2);
});

test('joining through an invitation or a link closes the counsellor\'s other invites and offers for the group', function () {
    $group = gtoGroup(['max_counsellors' => 3]);
    $counsellor = gtoCounsellor();
    $this->actingAs($group->addedby)->postJson(route('group.therapies.assist', ['groupTherapyId' => $group->id]), ['counsellorIds' => [$counsellor->id]])->assertOk();
    $invite = gtoPending($group)->sole();
    // An older offer of theirs, written directly (the gate would refuse it beside the invite).
    $offer = new Request(['type' => RequestTypeEnum::groupTherapy->value, 'data' => [], 'status' => RequestStatusEnum::pending->value]);
    $offer->from()->associate($counsellor);
    $offer->to()->associate($group->addedby);
    $offer->for()->associate($group);
    $offer->save();

    $this->actingAs($counsellor->user)->postJson(route('requests.respond', ['requestId' => $invite->id]), ['response' => 'accepted'])->assertSuccessful();

    expect($invite->fresh()->status)->toBe(RequestStatusEnum::accepted->value)
        ->and($offer->fresh()->status)->toBe(RequestStatusEnum::inconsequencial->value);
});

test('the counsellor who offered sees the creator by name only', function () {
    $group = gtoGroup();
    $counsellor = gtoCounsellor();
    gtoOffer($this, $counsellor, $group)->assertOk();
    $offer = gtoPending($group)->sole();

    $request = HttpRequest::create('/');
    $request->setUserResolver(fn () => $counsellor->user);
    $to = (new RequestResource($offer))->toArray($request)['to'];
    $to = is_array($to) ? $to : $to->toArray($request);

    expect($to)->toHaveKeys(['fullName', 'username'])->not->toHaveKeys(['gender', 'country', 'dob']);
});

function gtoSuspendCounsellor(User $person): void
{
    $report = new Report(['description' => 'Conduct']);
    $report->addedby()->associate(User::factory()->adult()->create());
    $report->reportable()->associate(GroupTherapy::factory()->create());
    $report->save();

    Suspension::query()->forceCreate(['user_id' => $person->id, 'report_id' => $report->id, 'scope' => SuspensionScopeEnum::counsellor->value,
        'public_reason' => 'Conduct', 'starts_at' => now()->subMinute(), 'ends_at' => now()->addDay()]);
}

test('an offer to a counsellor-created group goes to that counsellor\'s account, which accepts it -- not while they are suspended', function () {
    $creator = gtoCounsellor();
    $group = gtoGroup(['addedby_type' => Counsellor::class, 'addedby_id' => $creator->id]);
    $counsellor = gtoCounsellor();

    gtoOffer($this, $counsellor, $group)->assertOk();
    $offer = gtoPending($group)->sole();
    expect($offer->to->is($creator->user))->toBeTrue()
        ->and($offer->isAssistanceOffer())->toBeTrue()
        ->and($offer->assistanceCounsellor()?->is($counsellor))->toBeTrue();

    gtoSuspendCounsellor($creator->user);
    $this->actingAs($creator->user)->postJson(route('requests.respond', ['requestId' => $offer->id]), ['response' => 'accepted'])->assertStatus(403);
    expect($group->fresh()->isCounsellor($counsellor))->toBeFalse();
});

test('the counsellor who offered can\'t accept their own offer, and the creator can decline it', function () {
    $group = gtoGroup();
    $counsellor = gtoCounsellor();
    gtoOffer($this, $counsellor, $group)->assertOk();
    $offer = gtoPending($group)->sole();

    $this->actingAs($counsellor->user)->postJson(route('requests.respond', ['requestId' => $offer->id]), ['response' => 'accepted'])->assertStatus(422);
    $this->actingAs($group->addedby)->postJson(route('requests.respond', ['requestId' => $offer->id]), ['response' => 'rejected'])->assertSuccessful();

    expect($offer->fresh()->status)->toBe(RequestStatusEnum::rejected->value)
        ->and($group->fresh()->isCounsellor($counsellor))->toBeFalse();
});

test('a missing group, a private one and one whose creator has gone are refused alike', function () {
    $counsellor = gtoCounsellor();
    $private = gtoGroup(['public' => false]);
    $orphaned = gtoGroup();
    $orphaned->addedby->delete();

    $missing = $this->actingAs($counsellor->user)->postJson(route('group.therapies.assist', ['groupTherapyId' => 999999]))->assertStatus(422)->json('message');
    expect(gtoOffer($this, $counsellor, $private)->assertStatus(422)->json('message'))->toBe($missing)
        ->and(gtoOffer($this, $counsellor, $orphaned->fresh())->assertStatus(422)->json('message'))->toBe($missing);
});

test('more than 20 counsellors, or one that doesn\'t exist, is refused rather than quietly dropped', function () {
    $group = gtoGroup(['max_counsellors' => 3]);
    $url = route('group.therapies.assist', ['groupTherapyId' => $group->id]);

    $this->actingAs($group->addedby)->postJson($url, ['counsellorIds' => range(1, 21)])->assertStatus(422);
    $this->actingAs($group->addedby)->postJson($url, ['counsellorIds' => [gtoCounsellor()->id, 999999]])->assertStatus(422)
        ->assertJsonPath('message', EnsureCanSendGroupTherapyAssistanceRequestAction::COUNSELLOR_UNAVAILABLE);

    expect(gtoPending($group)->count())->toBe(0);
});

test('requests can\'t be made out to a counsellor who isn\'t verified, for a group or a discussion either', function () {
    $lapsed = gtoCounsellor(['verification_expires_at' => now()->subDay()]);
    $group = gtoGroup();

    expect(fn () => CreateRequestAction::new()->execute(CreateRequestDTO::new()->fromArray([
        'from' => $group->addedby, 'to' => $lapsed, 'for' => $group, 'type' => RequestTypeEnum::groupTherapy->value, 'data' => [],
    ])))->toThrow(CounsellorException::class);

    $discussion = Discussion::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => gtoCounsellor()->id]);
    expect(fn () => CreateRequestAction::new()->execute(CreateRequestDTO::new()->fromArray([
        'from' => $discussion->addedby, 'to' => $lapsed, 'for' => $discussion, 'type' => RequestTypeEnum::discussion->value, 'data' => [],
    ])))->toThrow(CounsellorException::class);
});

test('reminder days are read from one setting: a list or "30,7", repeats and junk dropped', function () {
    config(['counsellor.verification_reminder_days' => '7, 30,30,x,0']);
    expect(CounsellorVerification::reminderDays())->toBe([30, 7]);

    config(['counsellor.verification_reminder_days' => [14]]);
    expect(CounsellorVerification::reminderDays())->toBe([14]);

    config(['counsellor.verification_reminder_days' => '']);
    expect(CounsellorVerification::reminderDays())->toBe([30, 7]);
});

test('the group page tells the creator it can take a counsellor, and an invited counsellor that an invitation waits', function () {
    $group = gtoGroup();
    $invited = gtoCounsellor();
    $this->actingAs($group->addedby)->postJson(route('group.therapies.assist', ['groupTherapyId' => $group->id]), ['counsellorIds' => [$invited->id]])->assertOk();

    $creatorView = $this->actingAs($group->addedby)->get(route('group.therapies.get', ['groupTherapyId' => $group->id]))->viewData('page')['props']['therapy'];
    $invitedView = $this->actingAs($invited->user)->get(route('group.therapies.get', ['groupTherapyId' => $group->id]))->viewData('page')['props']['therapy'];
    $otherView = $this->actingAs(gtoCounsellor()->user)->get(route('group.therapies.get', ['groupTherapyId' => $group->id]))->viewData('page')['props']['therapy'];

    expect($creatorView['canTakeCounsellor'])->toBeTrue()
        ->and($invitedView['viewer']['action'])->toBe('invited')
        ->and($otherView['viewer']['action'])->toBe('offer');
});
