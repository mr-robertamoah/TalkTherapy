<?php

use App\Actions\Guardianship\EnsureCanHaveNewGuardianAction;
use App\Enums\LinkStateEnum;
use App\Enums\LinkTypeEnum;
use App\Models\Link;
use App\Models\Request as RequestModel;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

// SCRUM-441 (the user's decision, 2026-11-15): guardians are for under-18s only.

function gmoMinor(): User
{
    return User::factory()->create(['dob' => now()->subYears(15)->toDateString()]);
}

function gmoLink(User $ward): Link
{
    return Link::query()->forceCreate([
        'uuid' => (string) Str::uuid(), 'type' => LinkTypeEnum::guardianship->value, 'state' => LinkStateEnum::active->value,
        'addedby_type' => User::class, 'addedby_id' => $ward->id, 'for_type' => User::class, 'for_id' => $ward->id,
    ]);
}

test('only someone under 18 can ask for a guardian', function () {
    $guardian = User::factory()->adult()->create();
    $adult = User::factory()->adult()->create();
    $minor = gmoMinor();

    $this->actingAs($adult)->postJson(route('api.users.guardianshiprequest', ['userId' => $adult->id]), ['guardianId' => $guardian->id])
        ->assertStatus(422)->assertJsonPath('message', EnsureCanHaveNewGuardianAction::ADULT);
    $this->actingAs($minor)->postJson(route('api.users.guardianshiprequest', ['userId' => $minor->id]), ['guardianId' => $guardian->id])->assertSuccessful();
});

test('only someone under 18 can make a guardianship link', function () {
    $adult = User::factory()->adult()->create();
    $minor = gmoMinor();
    $payload = fn (User $u) => ['type' => 'GUARDIANSHIP', 'addedbyType' => 'User', 'addedbyId' => $u->id, 'forType' => 'User', 'forId' => $u->id];

    $this->actingAs($adult)->postJson(route('api.links.create'), $payload($adult))->assertStatus(422)->assertJsonPath('message', EnsureCanHaveNewGuardianAction::ADULT);
    $this->actingAs($minor)->postJson(route('api.links.create'), $payload($minor))->assertSuccessful();
    expect(Link::query()->where('for_id', $adult->id)->exists())->toBeFalse();
});

test('a request or link is not taken up once the person asking has turned 18', function () {
    $guardian = User::factory()->adult()->create();
    $ward = gmoMinor();
    $this->actingAs($ward)->postJson(route('api.users.guardianshiprequest', ['userId' => $ward->id]), ['guardianId' => $guardian->id])->assertSuccessful();
    $link = gmoLink($ward);
    $ward->forceFill(['dob' => now()->subYears(18)->subDay()->toDateString()])->save();

    $request = RequestModel::query()->where('to_id', $guardian->id)->latest('id')->firstOrFail();
    // Neither says the ward's age.
    $this->actingAs($guardian)->postJson(route('requests.respond', ['requestId' => $request->id]), ['response' => 'accepted'])
        ->assertStatus(422)->assertJsonPath('error', EnsureCanHaveNewGuardianAction::NO_LONGER);
    $this->actingAs($guardian)->postJson(route('api.links.join', ['uuid' => $link->uuid]))
        ->assertStatus(422)->assertJsonPath('message', 'This link is no longer active.');

    expect($guardian->wards()->count())->toBe(0);
});

test('the guardian picker lists only people who could be one; other pickers are unchanged', function () {
    $me = gmoMinor();
    User::factory()->adult()->create(['username' => 'gmo_adult']);
    User::factory()->adult()->unverified()->create(['username' => 'gmo_unverified']);
    User::factory()->create(['username' => 'gmo_minor', 'dob' => now()->subYears(16)->toDateString()]);
    User::factory()->create(['username' => 'gmo_nodob']);

    $names = fn (array $params) => collect($this->actingAs($me)->getJson(route('api.users', ['like' => 'gmo_', ...$params]))->assertOk()->json('data'))->pluck('username')->sort()->values()->all();

    expect($names(['for' => 'guardian']))->toBe(['gmo_adult'])
        ->and($names([]))->toBe(['gmo_adult', 'gmo_minor', 'gmo_nodob', 'gmo_unverified']);
});

test('an adult gets nothing from the guardian picker, whoever they search for (it would pick out minors)', function () {
    User::factory()->adult()->create(['username' => 'gmo2_adult']);
    User::factory()->create(['username' => 'gmo2_minor', 'dob' => now()->subYears(16)->toDateString()]);

    $this->actingAs(User::factory()->adult()->create())->getJson(route('api.users', ['like' => 'gmo2_', 'for' => 'guardian']))
        ->assertOk()->assertExactJson([]);
});

test('a guardian can still reject a request from someone who has turned 18', function () {
    $guardian = User::factory()->adult()->create();
    $ward = gmoMinor();
    $this->actingAs($ward)->postJson(route('api.users.guardianshiprequest', ['userId' => $ward->id]), ['guardianId' => $guardian->id])->assertSuccessful();
    $ward->forceFill(['dob' => now()->subYears(19)->toDateString()])->save();

    $request = RequestModel::query()->where('to_id', $guardian->id)->latest('id')->firstOrFail();
    $this->actingAs($guardian)->postJson(route('requests.respond', ['requestId' => $request->id]), ['response' => 'rejected'])->assertSuccessful();
});

test('opening an adult ward\'s old link says only that it is no longer active', function () {
    $ward = gmoMinor();
    $link = gmoLink($ward);
    $ward->forceFill(['dob' => now()->subYears(19)->toDateString()])->save();

    $this->actingAs(User::factory()->adult()->create())->get(route('links.get', ['uuid' => $link->uuid]))
        ->assertInertia(fn ($page) => $page->component('Links/Confirm')->where('refusal', 'This link is no longer active.'));
});

test('no date of birth counts as under 18 for asking, but the picker needs one (it would otherwise pick out minors)', function () {
    $guardian = User::factory()->adult()->create(['username' => 'gmo3_adult']);
    $noDob = User::factory()->create();

    $this->actingAs($noDob)->postJson(route('api.users.guardianshiprequest', ['userId' => $noDob->id]), ['guardianId' => $guardian->id])->assertSuccessful();
    $this->actingAs($noDob)->postJson(route('api.links.create'), ['type' => 'GUARDIANSHIP', 'addedbyType' => 'User', 'addedbyId' => $noDob->id, 'forType' => 'User', 'forId' => $noDob->id])->assertSuccessful();
    $this->actingAs($noDob)->getJson(route('api.users', ['like' => 'gmo3_', 'for' => 'guardian']))->assertOk()->assertExactJson([]);
});

test('the picker turns on the 18th birthday, as isAdult() does -- 29 February included', function () {
    $me = gmoMinor();
    $listed = fn () => collect($this->actingAs($me)->getJson(route('api.users', ['like' => 'gmo4_', 'for' => 'guardian']))->json('data'))->pluck('username')->sort()->values()->all();

    User::factory()->create(['username' => 'gmo4_today', 'dob' => now()->subYearsNoOverflow(18)->toDateString()]);
    User::factory()->create(['username' => 'gmo4_tomorrow', 'dob' => now()->subYearsNoOverflow(18)->addDay()->toDateString()]);
    expect($listed())->toBe(['gmo4_today']);

    Carbon::setTestNow('2028-02-29 12:00:00');
    $leap = User::factory()->create(['username' => 'gmo4_leap', 'dob' => '2010-03-01']);
    expect($leap->isAdult())->toBeFalse()->and($listed())->not->toContain('gmo4_leap');
    Carbon::setTestNow();
});
