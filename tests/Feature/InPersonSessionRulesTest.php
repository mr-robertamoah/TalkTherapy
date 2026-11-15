<?php

use App\Actions\Session\EnsureConfirmedByAnotherPersonAction;
use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Enums\LinkTypeEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Enums\SessionStatusEnum;
use App\Enums\TherapyStatusEnum;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Guardianship;
use App\Models\Link;
use App\Models\Request;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use App\Support\InPerson;
use Database\Seeders\InPersonSessionSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

// SCRUM-443 (SCRUM-115): the second start/end step from someone else; a place for in-person sessions and none for
// online ones; in-person excludes minors (the user's decision).

function ipxTherapy(array $client = [], array $therapy = []): array
{
    $client = User::factory()->adult()->create($client);
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $t = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => $counsellor->id, 'allow_in_person' => true, 'payment_type' => 'FREE', ...$therapy]);

    return [$client, $counsellor, $t];
}

function ipxSession(Therapy $therapy, array $attributes = []): Session
{
    return Session::factory()->create([
        'for_type' => Therapy::class, 'for_id' => $therapy->id, 'addedby_type' => Counsellor::class, 'addedby_id' => $therapy->counsellor_id,
        'status' => SessionStatusEnum::pending->value, 'payment_type' => 'FREE', 'type' => 'ONLINE',
        'start_time' => now()->subMinutes(5), 'end_time' => now()->addMinutes(55), ...$attributes,
    ]);
}

function ipxMinorWithGuardian(): User
{
    $minor = User::factory()->create(['dob' => now()->subYears(15)->toDateString()]);
    Guardianship::query()->create(['guardian_id' => User::factory()->adult()->create()->id, 'ward_id' => $minor->id]);

    return $minor;
}

function ipxCreate($test, User $as, Therapy $therapy, array $body)
{
    return $test->actingAs($as)->postJson(route('api.sessions.create', ['therapyId' => $therapy->id]), [
        'name' => 'ipx session', 'about' => 'ipx about this session', 'paymentType' => 'FREE',
        'startTime' => now()->addDays(3)->setTime(10, 0)->toDateTimeString(), 'endTime' => now()->addDays(3)->setTime(11, 0)->toDateTimeString(),
        ...$body,
    ]);
}

test('starting and ending take two people: whoever asked can\'t confirm their own request', function () {
    [$client, $counsellor, $therapy] = ipxTherapy();
    $session = ipxSession($therapy);
    $start = fn (User $who) => $this->actingAs($who)->postJson(route('api.sessions.in_session', ['sessionId' => $session->id]));
    $end = fn (User $who) => $this->actingAs($who)->postJson(route('api.sessions.end', ['sessionId' => $session->id]));

    $start($counsellor->user)->assertSuccessful();
    expect($session->fresh()->status)->toBe(SessionStatusEnum::in_session_confirmation->value);
    $start($counsellor->user)->assertStatus(422)->assertJsonPath('message', EnsureConfirmedByAnotherPersonAction::SAME_PERSON);
    $start($client)->assertSuccessful();
    expect($session->fresh()->status)->toBe(SessionStatusEnum::in_session->value);

    $this->travel(2)->hours(); // a session ends once its end time has passed
    $end($client)->assertSuccessful();
    expect($session->fresh()->status)->toBe(SessionStatusEnum::held_confirmation->value);
    $end($client)->assertStatus(422);
    expect($session->fresh()->status)->toBe(SessionStatusEnum::held_confirmation->value);
    $end($counsellor->user)->assertSuccessful();
    expect($session->fresh()->status)->toBe(SessionStatusEnum::held->value);
});

test('an in-person session needs a pin and a landmark; an online one stores no place', function () {
    [, $counsellor, $therapy] = ipxTherapy();

    ipxCreate($this, $counsellor->user, $therapy, ['type' => 'IN_PERSON', 'lat' => 5.6, 'lng' => -0.18])->assertJsonValidationErrors('landmark');
    ipxCreate($this, $counsellor->user, $therapy, ['type' => 'IN_PERSON', 'lat' => 5.6, 'lng' => -0.18, 'landmark' => 'ipx Room 3'])->assertSuccessful();
    ipxCreate($this, $counsellor->user, $therapy, [
        'type' => 'ONLINE', 'startTime' => now()->addDays(5)->setTime(10, 0)->toDateTimeString(), 'endTime' => now()->addDays(5)->setTime(11, 0)->toDateTimeString(),
    ])->assertSuccessful();

    $online = Session::query()->where('for_id', $therapy->id)->where('type', 'ONLINE')->firstOrFail();
    expect($online->latitude)->toBeNull()->and($online->longitude)->toBeNull()->and($online->landmark)->toBeNull();
});

test('switching to online clears the place; an edit that leaves the place out keeps it', function () {
    [, $counsellor, $therapy] = ipxTherapy();
    $session = ipxSession($therapy, ['type' => 'IN_PERSON', 'latitude' => 5.6, 'longitude' => -0.18, 'landmark' => 'ipx Room 3', 'start_time' => now()->addDays(2), 'end_time' => now()->addDays(2)->addHour()]);
    $update = fn (array $body) => $this->actingAs($counsellor->user)->patchJson(route('api.sessions.update', ['sessionId' => $session->id]), $body);

    $update(['name' => 'ipx renamed'])->assertSuccessful();
    expect($session->fresh()->landmark)->toBe('ipx Room 3')->and((float) $session->fresh()->latitude)->toBe(5.6);

    $update(['type' => 'ONLINE'])->assertSuccessful();
    expect($session->fresh()->only(['latitude', 'longitude', 'landmark']))->toBe(['latitude' => null, 'longitude' => null, 'landmark' => null]);
});

test('in-person is for adults only: a minor can\'t switch it on, and a therapy with a minor gets no in-person sessions', function () {
    $minor = ipxMinorWithGuardian();
    [, $counsellor, $therapy] = ipxTherapy([], ['allow_in_person' => false]);
    $therapy->addedby()->associate($minor)->save();

    $this->actingAs($minor)->patchJson(route('api.therapies.update', ['therapyId' => $therapy->id]), ['allowInPerson' => true])
        ->assertStatus(422)->assertJsonPath('message', InPerson::ADULTS_ONLY);

    // One switched on before the rule: no in-person session is booked or started there.
    $therapy->forceFill(['allow_in_person' => true])->save();
    ipxCreate($this, $counsellor->user, $therapy, ['type' => 'IN_PERSON', 'lat' => 5.6, 'lng' => -0.18, 'landmark' => 'ipx Room 3'])
        ->assertStatus(422)->assertJsonPath('message', InPerson::ADULTS_ONLY);
});

test('a minor can\'t join a group that meets in person', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $group = GroupTherapy::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id, 'allow_in_person' => true, 'allow_anyone' => true, 'public' => true]);
    $group->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);
    $minor = ipxMinorWithGuardian();

    $this->actingAs($minor)->postJson(route('api.group.therapies.join', ['groupTherapyId' => $group->id]), ['anonymous' => false])
        ->assertStatus(422)->assertJsonPath('message', InPerson::MINOR_CANNOT_JOIN);
    expect($group->users()->whereKey($minor->id)->exists())->toBeFalse();
});

test('in a group the confirmer is on the other side: a second member can\'t confirm a member\'s request; the counsellor can', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $group = GroupTherapy::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id, 'payment_type' => 'FREE']);
    $group->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);
    [$one, $two] = [User::factory()->adult()->create(), User::factory()->adult()->create()];
    foreach ([$one, $two] as $member) {
        $group->users()->attach($member->id, ['anonymous' => false, 'background_story' => 'x']);
    }
    $session = Session::factory()->create([
        'for_type' => GroupTherapy::class, 'for_id' => $group->id, 'addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id,
        'status' => SessionStatusEnum::pending->value, 'payment_type' => 'FREE', 'type' => 'ONLINE',
        'start_time' => now()->subMinutes(5), 'end_time' => now()->addMinutes(55),
    ]);
    $start = fn (User $who) => $this->actingAs($who)->postJson(route('api.sessions.in_session', ['sessionId' => $session->id]));

    $start($one)->assertSuccessful();
    $start($two)->assertStatus(422)->assertJsonPath('message', EnsureConfirmedByAnotherPersonAction::SAME_PERSON);
    $start($counsellor->user)->assertSuccessful();
    expect($session->fresh()->status)->toBe(SessionStatusEnum::in_session->value);
});

test('a group member who switches in-person on is not told another member is a minor', function () {
    $creator = User::factory()->adult()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $creator->id, 'allow_in_person' => false]);
    $group->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);
    $group->users()->attach(ipxMinorWithGuardian()->id, ['anonymous' => true, 'background_story' => 'x']);

    $this->actingAs($creator)->patchJson(route('api.group.therapies.update', ['groupTherapyId' => $group->id]), ['allowInPerson' => true])
        ->assertStatus(422)->assertJsonPath('message', InPerson::NOT_AVAILABLE);
    expect(InPerson::refusalFor($counsellor->user, $group))->toBe(InPerson::ADULTS_ONLY)
        ->and((bool) $group->fresh()->allow_in_person)->toBeFalse();
});

// A group run by a counsellor (its creator), with that counsellor active in it.
function ipxCounsellorGroup(array $attributes = []): array
{
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $group = GroupTherapy::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id, 'payment_type' => 'FREE', 'status' => TherapyStatusEnum::in_session->value, ...$attributes]);
    $group->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);

    return [$counsellor, $group];
}

function ipxProposal(array $body = []): array
{
    return [
        'startTime' => now()->addDays(2)->setTime(10, 0)->toDateTimeString(), 'endTime' => now()->addDays(2)->setTime(11, 0)->toDateTimeString(),
        'name' => 'ipx proposed', 'about' => 'ipx about the proposal', 'paymentType' => 'FREE', ...$body,
    ];
}

test('an unrelated edit of a therapy that already allows in-person, with a minor client, isn\'t refused for resending it', function () {
    $minor = ipxMinorWithGuardian();
    [, , $therapy] = ipxTherapy();
    $therapy->addedby()->associate($minor)->save();

    $this->actingAs($minor)->patchJson(route('api.therapies.update', ['therapyId' => $therapy->id]), ['name' => 'ipx renamed', 'allowInPerson' => true])
        ->assertSuccessful();
    expect($therapy->fresh()->name)->toBe('ipx renamed');
});

test('a counsellor-created group can\'t turn in-person on while a minor is a member, and can once they have left', function () {
    [$counsellor, $group] = ipxCounsellorGroup(['allow_in_person' => false]);
    $minor = ipxMinorWithGuardian();
    $group->users()->attach($minor->id, ['anonymous' => false, 'background_story' => 'x']);
    $update = fn () => $this->actingAs($counsellor->user)->patchJson(route('api.group.therapies.update', ['groupTherapyId' => $group->id]), ['allowInPerson' => true]);

    $update()->assertStatus(422)->assertJsonPath('message', InPerson::ADULTS_ONLY);
    expect((bool) $group->fresh()->allow_in_person)->toBeFalse();

    $group->allMemberships()->updateExistingPivot($minor->id, ['left_at' => now()]);

    $update()->assertSuccessful();
    expect((bool) $group->fresh()->allow_in_person)->toBeTrue();
});

test('a minor can\'t create a therapy or a group that allows in-person; a counsellor creating a group can', function () {
    $minor = ipxMinorWithGuardian();
    $therapy = ['name' => 'ipx therapy', 'backgroundStory' => 'ipx story', 'anonymous' => false, 'allowInPerson' => true, 'public' => true, 'sessionType' => 'ONCE', 'paymentType' => 'FREE'];
    $group = ['name' => 'ipx group', 'about' => 'ipx group about', 'anonymous' => false, 'allowInPerson' => true, 'allowAnyone' => false, 'public' => true, 'sessionType' => 'ONCE', 'paymentType' => 'FREE'];

    $this->actingAs($minor)->postJson(route('api.therapies.create'), $therapy)->assertStatus(422)->assertJsonPath('message', InPerson::ADULTS_ONLY);
    $this->actingAs($minor)->postJson(route('group.therapies.create'), $group)->assertStatus(422)->assertJsonPath('message', InPerson::ADULTS_ONLY);
    expect(Therapy::query()->where('name', 'ipx therapy')->exists())->toBeFalse()
        ->and(GroupTherapy::query()->where('name', 'ipx group')->exists())->toBeFalse();

    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $this->actingAs($counsellor->user)->postJson(route('group.therapies.create'), [...$group, 'counsellorId' => $counsellor->id])->assertSuccessful();
    expect((bool) GroupTherapy::query()->where('name', 'ipx group')->sole()->allow_in_person)->toBeTrue();
});

test('an in-person session booked before the rule doesn\'t start while a minor takes part', function () {
    $minor = ipxMinorWithGuardian();
    [, $counsellor, $therapy] = ipxTherapy();
    $therapy->addedby()->associate($minor)->save();
    $session = ipxSession($therapy, ['type' => 'IN_PERSON', 'latitude' => 5.6, 'longitude' => -0.18, 'landmark' => 'ipx Room 3']);

    $this->actingAs($counsellor->user)->postJson(route('api.sessions.in_session', ['sessionId' => $session->id]))
        ->assertStatus(422)->assertJsonPath('message', InPerson::ADULTS_ONLY);
    expect($session->fresh()->status)->toBe(SessionStatusEnum::pending->value);
});

test('a minor can\'t join an in-person group through an accepted membership request, and isn\'t told why by age', function () {
    Notification::fake();
    [$counsellor, $group] = ipxCounsellorGroup(['allow_in_person' => true, 'allow_anyone' => false]);
    $minor = ipxMinorWithGuardian();
    $request = Request::factory()->create([
        'from_type' => User::class, 'from_id' => $minor->id, 'to_type' => Counsellor::class, 'to_id' => $counsellor->id,
        'for_type' => GroupTherapy::class, 'for_id' => $group->id, 'type' => RequestTypeEnum::groupTherapyMembership->value,
        'status' => RequestStatusEnum::pending->value, 'data' => ['anonymous' => false],
    ]);

    $this->actingAs($counsellor->user)->postJson(route('requests.respond', ['requestId' => $request->id]), ['response' => 'accepted'])->assertSuccessful();

    expect($request->fresh()->status)->toBe(RequestStatusEnum::rejected->value)
        ->and($request->fresh()->data['reason'])->toBe('This group can\'t take this request.')
        ->and($group->users()->whereKey($minor->id)->exists())->toBeFalse();
});

test('a minor can\'t join an in-person group through an invitation link', function () {
    Notification::fake();
    [$counsellor, $group] = ipxCounsellorGroup(['allow_in_person' => true, 'allow_anyone' => false]);
    $minor = ipxMinorWithGuardian();
    $link = Link::query()->forceCreate(['type' => LinkTypeEnum::groupTherapyParticipant->value, 'state' => 'ACTIVE', 'uuid' => (string) Str::uuid(),
        'addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id, 'for_type' => GroupTherapy::class, 'for_id' => $group->id]);

    $this->actingAs($minor)->postJson(route('api.links.join', ['uuid' => $link->uuid]))
        ->assertStatus(422)->assertJsonPath('message', InPerson::MINOR_CANNOT_JOIN);
    expect($group->users()->whereKey($minor->id)->exists())->toBeFalse();
});

test('the migration clears the place of online sessions and leaves in-person ones alone', function () {
    [, , $therapy] = ipxTherapy();
    $online = ipxSession($therapy, ['type' => 'ONLINE', 'latitude' => 0, 'longitude' => 0, 'landmark' => 'ipx stale']);
    $inPerson = ipxSession($therapy, ['type' => 'IN_PERSON', 'latitude' => 5.6, 'longitude' => -0.18, 'landmark' => 'ipx Room 3', 'start_time' => now()->addDays(2), 'end_time' => now()->addDays(2)->addHour()]);

    (require database_path('migrations/2026_12_06_100000_clear_place_of_online_sessions.php'))->up();

    expect($online->fresh()->only(['latitude', 'longitude', 'landmark']))->toBe(['latitude' => null, 'longitude' => null, 'landmark' => null])
        ->and($inPerson->fresh()->landmark)->toBe('ipx Room 3')
        ->and((float) $inPerson->fresh()->latitude)->toBe(5.6)
        ->and((float) $inPerson->fresh()->longitude)->toBe(-0.18);
});

test('an in-person proposal carries its place, and accepting it books the session there', function () {
    Notification::fake();
    [$client, $counsellor, $therapy] = ipxTherapy([], ['status' => TherapyStatusEnum::in_session->value]);

    $this->actingAs($client)->postJson(route('api.session_schedule_proposals.store', ['therapyId' => $therapy->id]), ipxProposal([
        'type' => 'IN_PERSON', 'lat' => 5.6037, 'lng' => -0.187, 'landmark' => ' ipx Room 3, 2nd floor ',
    ]))->assertOk()->assertJsonPath('proposal.proposal.landmark', 'ipx Room 3, 2nd floor');

    $proposal = Request::query()->where('type', RequestTypeEnum::sessionScheduleProposal->value)->whereMorphedTo('for', $therapy)->sole();
    $this->actingAs($counsellor->user)->postJson(route('requests.respond', ['requestId' => $proposal->id]), ['response' => 'accepted'])->assertStatus(201);

    $session = Session::query()->whereMorphedTo('for', $therapy)->sole();
    expect($proposal->fresh()->status)->toBe(RequestStatusEnum::accepted->value)
        ->and($session->type)->toBe('IN_PERSON')
        ->and($session->landmark)->toBe('ipx Room 3, 2nd floor')
        ->and((float) $session->latitude)->toBe(5.6037)
        ->and((float) $session->longitude)->toBe(-0.187);
});

test('a counter-offer keeps the in-person proposal\'s place; an online proposal stores none', function () {
    Notification::fake();
    [$client, $counsellor, $therapy] = ipxTherapy([], ['status' => TherapyStatusEnum::in_session->value]);

    $this->actingAs($client)->postJson(route('api.session_schedule_proposals.store', ['therapyId' => $therapy->id]), ipxProposal([
        'type' => 'IN_PERSON', 'lat' => 5.6037, 'lng' => -0.187, 'landmark' => 'ipx Room 3',
    ]))->assertOk();
    $first = Request::query()->whereMorphedTo('for', $therapy)->sole();

    $this->actingAs($counsellor->user)->postJson(route('api.session_schedule_proposals.counter_offer', ['requestId' => $first->id]), [
        'startTime' => now()->addDays(3)->setTime(10, 0)->toDateTimeString(), 'endTime' => now()->addDays(3)->setTime(11, 0)->toDateTimeString(),
    ])->assertOk();
    $counter = Request::query()->whereMorphedTo('for', $therapy)->wherePending()->sole();
    expect($counter->data['type'])->toBe('IN_PERSON')->and($counter->data['landmark'])->toBe('ipx Room 3')->and((float) $counter->data['latitude'])->toBe(5.6037);

    $this->actingAs($client)->postJson(route('api.session_schedule_proposals.counter_offer', ['requestId' => $counter->id]), [
        'startTime' => now()->addDays(4)->setTime(10, 0)->toDateTimeString(), 'endTime' => now()->addDays(4)->setTime(11, 0)->toDateTimeString(), 'type' => 'ONLINE',
    ])->assertOk();
    $online = Request::query()->whereMorphedTo('for', $therapy)->wherePending()->sole();
    expect([$online->data['landmark'], $online->data['latitude'], $online->data['longitude']])->toBe([null, null, null]);
});

test('an in-person proposal needs a place, a therapy that allows in-person, and no client under 18', function () {
    [$client, $counsellor, $therapy] = ipxTherapy([], ['status' => TherapyStatusEnum::in_session->value]);
    $propose = fn (User $as, Therapy $for, array $body) => $this->actingAs($as)->postJson(route('api.session_schedule_proposals.store', ['therapyId' => $for->id]), ipxProposal($body));

    $propose($client, $therapy, ['type' => 'IN_PERSON'])->assertStatus(422)->assertJsonValidationErrors(['lat', 'lng', 'landmark']);
    $propose($client, $therapy, ['type' => 'IN_PERSON', 'lat' => 5.6, 'lng' => -0.18, 'landmark' => 'ab'])->assertJsonValidationErrors('landmark');

    $therapy->forceFill(['allow_in_person' => false])->save();
    $propose($client, $therapy, ['type' => 'IN_PERSON', 'lat' => 5.6, 'lng' => -0.18, 'landmark' => 'ipx Room 3'])
        ->assertStatus(422)->assertJsonPath('message', 'In-person sessions aren\'t allowed for this therapy.');

    $therapy->forceFill(['allow_in_person' => true])->save();
    $therapy->addedby()->associate(ipxMinorWithGuardian())->save();
    $propose($counsellor->user, $therapy, ['type' => 'IN_PERSON', 'lat' => 5.6, 'lng' => -0.18, 'landmark' => 'ipx Room 3'])
        ->assertStatus(422)->assertJsonPath('message', InPerson::ADULTS_ONLY);

    expect(Request::query()->whereMorphedTo('for', $therapy)->exists())->toBeFalse();
});

test('the in-person demo seeds an adult 1:1 and a group, each with an in-person session with a place, once', function () {
    $michael = User::factory()->adult()->create(['username' => 'michael_chen']);
    Counsellor::factory()->create(['user_id' => $michael->id]);
    User::factory()->adult()->create(['username' => 'john_davis']);
    User::factory()->adult()->create(['username' => 'robert_miller']);

    $this->seed(InPersonSessionSeeder::class);
    $this->seed(InPersonSessionSeeder::class);

    $therapy = Therapy::query()->where('name', 'In-Person Demo Therapy')->sole();
    $group = GroupTherapy::query()->where('name', 'In-Person Demo Group')->sole();
    expect((bool) $therapy->allow_in_person)->toBeTrue()->and(InPerson::hasMinorClient($therapy))->toBeFalse()
        ->and((bool) $group->allow_in_person)->toBeTrue()->and(InPerson::hasMinorClient($group))->toBeFalse();

    foreach ([$therapy, $group] as $for) {
        $session = Session::query()->whereMorphedTo('for', $for)->sole();
        expect($session->type)->toBe('IN_PERSON')->and($session->landmark)->not->toBeEmpty()
            ->and($session->latitude)->not->toBeNull()->and($session->longitude)->not->toBeNull();
    }
});
