<?php

use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Language;
use App\Models\Religion;
use App\Models\Request;
use App\Models\Therapy;
use App\Models\TherapyCase;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// SCRUM-113/114: the Home rows and the Public Therapies page -- ranked by the viewer's preferences
// (matches first, then a seeded order that stays the same for a visit), and each card's button for
// its viewer. Helpers are file-local and prk-prefixed (--parallel).

function prkCase(): TherapyCase
{
    return TherapyCase::query()->create(['name' => 'Case '.uniqid()]);
}

function prkLanguage(): Language
{
    return Language::query()->create(['name' => 'Language '.uniqid()]);
}

function prkTherapy(array $attributes = [], ?User $client = null): Therapy
{
    $client ??= User::factory()->adult()->create();

    return Therapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $client->id, 'public' => true,
        'status' => TherapyStatusEnum::pending->value, 'counsellor_id' => null, ...$attributes,
    ]);
}

function prkGroup(array $attributes = []): GroupTherapy
{
    return GroupTherapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => User::factory()->adult()->create()->id, 'public' => true,
        'status' => TherapyStatusEnum::pending->value, 'allow_anyone' => true, 'max_users' => 10, 'max_counsellors' => 2, ...$attributes,
    ]);
}

function prkCounsellor(array $state = []): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id, ...$state]);
}

function prkViewer(array $settings = []): User
{
    return User::factory()->adult()->create(['settings' => $settings]);
}

function prkIds($response): array
{
    return collect($response->assertOk()->json('data'))->pluck('id')->all();
}

test('therapies matching the viewer\'s cases come first, labelled for them only; nothing is left out', function () {
    $case = prkCase();
    $plain = collect(range(1, 4))->map(fn () => prkTherapy());
    $match = prkTherapy();
    $match->cases()->attach($case->id);
    $viewer = prkViewer(['cases' => [$case->id]]);

    $rows = collect($this->actingAs($viewer)->getJson('/api/therapies/random?seed=42')->assertOk()->json('data'));

    expect($rows->first()['id'])->toBe($match->id)
        ->and($rows->first()['viewer']['matchesPreferences'])->toBeTrue()
        ->and($rows->slice(1)->pluck('viewer.matchesPreferences')->unique()->all())->toBe([false])
        ->and($rows->pluck('id')->sort()->values()->all())->toBe($plain->pluck('id')->push($match->id)->sort()->values()->all())
        ->and(json_encode($rows->all()))->not->toContain('preference_matches');

    // A guest sees no label.
    $this->app['auth']->forgetGuards();
    expect(collect($this->getJson('/api/therapies/random?seed=42')->json('data'))->pluck('viewer.matchesPreferences')->unique()->all())->toBe([false]);
});

test('a therapy also matches on its counsellor\'s languages and religions; more kinds matched rank higher', function () {
    $case = prkCase();
    $language = prkLanguage();
    $religion = Religion::query()->create(['name' => 'Religion '.uniqid()]);
    $counsellor = prkCounsellor();
    $counsellor->languages()->attach($language->id);
    $counsellor->religions()->attach($religion->id);

    $oneKind = prkTherapy();
    $oneKind->cases()->attach($case->id);
    $threeKinds = prkTherapy(['counsellor_id' => $counsellor->id]);
    $threeKinds->cases()->attach($case->id);
    prkTherapy();

    $viewer = prkViewer(['cases' => [$case->id], 'languages' => [$language->id], 'religions' => [$religion->id]]);

    expect(array_slice(prkIds($this->actingAs($viewer)->getJson('/api/therapies/random?seed=7')), 0, 2))->toBe([$threeKinds->id, $oneKind->id]);
})->group('mysql-json');

test('the same seed gives the same order, and paging never repeats or skips a therapy', function () {
    $therapies = collect(range(1, 15))->map(fn () => prkTherapy());
    $viewer = prkViewer();

    $first = prkIds($this->actingAs($viewer)->getJson('/api/therapies/random?seed=99'));
    $second = prkIds($this->actingAs($viewer)->getJson('/api/therapies/random?seed=99&page=2'));

    expect($first)->toHaveCount(10)
        ->and(prkIds($this->actingAs($viewer)->getJson('/api/therapies/random?seed=99')))->toBe($first)
        ->and(array_intersect($first, $second))->toBe([])
        ->and(collect([...$first, ...$second])->sort()->values()->all())->toBe($therapies->pluck('id')->sort()->values()->all());

    // The next page's link carries the seed.
    expect($this->actingAs($viewer)->getJson('/api/therapies/random?seed=99')->json('links.next'))->toContain('seed=99');
});

test('a counsellor browsing therapies is matched on their cases and on the client\'s saved languages', function () {
    $case = prkCase();
    $language = prkLanguage();
    $counsellor = prkCounsellor();
    $counsellor->cases()->attach($case->id);
    $counsellor->languages()->attach($language->id);

    $byCase = prkTherapy();
    $byCase->cases()->attach($case->id);
    $byClientLanguage = prkTherapy([], prkViewer(['languages' => [$language->id]]));
    $neither = prkTherapy();

    $rows = collect($this->actingAs($counsellor->user)->getJson('/api/therapies/random?seed=3')->json('data'))->keyBy('id');

    expect($rows[$byCase->id]['viewer']['matchesPreferences'])->toBeTrue()
        ->and($rows[$byClientLanguage->id]['viewer']['matchesPreferences'])->toBeTrue()
        ->and($rows[$neither->id]['viewer']['matchesPreferences'])->toBeFalse()
        // Only whether it matched -- never the client's preferences themselves.
        ->and(json_encode($rows->all()))->not->toContain('"languages"');
})->group('mysql-json');

test('Home groups include counsellor-created ones and leave out the viewer\'s own and the ones they\'re in', function () {
    $viewer = prkViewer();
    $byCounsellor = prkGroup(['addedby_type' => Counsellor::class, 'addedby_id' => prkCounsellor()->id]);
    $mine = prkGroup(['addedby_id' => $viewer->id]);
    $joined = prkGroup();
    $joined->users()->attach($viewer->id, ['anonymous' => false, 'was_minor_at_join' => false]);
    $private = prkGroup(['public' => false]);

    $ids = prkIds($this->actingAs($viewer)->getJson('/api/group-therapies/random?seed=5'));

    expect($ids)->toContain($byCounsellor->id)
        ->not->toContain($mine->id)
        ->not->toContain($joined->id)
        ->not->toContain($private->id);
});

test('counsellors matching the viewer\'s languages come first in the Home counsellors row', function () {
    $language = prkLanguage();
    collect(range(1, 3))->each(fn () => prkCounsellor());
    $match = prkCounsellor();
    $match->languages()->attach($language->id);

    $rows = collect($this->actingAs(prkViewer(['languages' => [$language->id]]))->getJson('/api/counsellors/random?seed=11')->assertOk()->json('data'));

    expect($rows->first()['id'])->toBe($match->id)
        ->and($rows->first()['matchesPreferences'])->toBeTrue();
});

test('the Public Therapies page lists public therapies and groups together, ranked and paged in SQL', function () {
    $case = prkCase();
    $therapies = collect(range(1, 6))->map(fn () => prkTherapy());
    $groups = collect(range(1, 6))->map(fn () => prkGroup());
    $matchingGroup = $groups->last();
    $matchingGroup->cases()->attach($case->id);
    prkTherapy(['public' => false]);
    $viewer = prkViewer(['cases' => [$case->id]]);

    $first = collect($this->actingAs($viewer)->getJson('/api/therapies/public?seed=8')->assertOk()->json('data'));
    $second = collect($this->actingAs($viewer)->getJson('/api/therapies/public?seed=8&page=2')->assertOk()->json('data'));
    $all = $first->concat($second);

    expect($first->first())->toMatchArray(['id' => $matchingGroup->id, 'type' => 'group'])
        ->and($first->first()['viewer']['matchesPreferences'])->toBeTrue()
        ->and($all)->toHaveCount(12)
        ->and($all->map(fn ($row) => "{$row['type']}:{$row['id']}")->unique())->toHaveCount(12)
        ->and($all->where('type', 'individual')->pluck('id')->sort()->values()->all())->toBe($therapies->pluck('id')->sort()->values()->all());
})->group('mysql-json');

test('saved preferences must be real ids, at most 20 of each, and can be cleared', function () {
    $case = prkCase();
    $viewer = prkViewer(['cases' => [$case->id]]);

    $this->actingAs($viewer)->post(route('preferences.set'), ['selectedCases' => [999999]])->assertSessionHasErrors('selectedCases.0');
    $this->actingAs($viewer)->post(route('preferences.set'), ['selectedCases' => range(1, 21)])->assertSessionHasErrors('selectedCases');

    $this->actingAs($viewer)->post(route('preferences.set'), ['selectedCases' => []])->assertSessionHasNoErrors();
    expect($viewer->fresh()->settings['cases'])->toBe([]);
});

test('each group card says what its viewer can do', function () {
    $viewer = prkViewer();
    $open = prkGroup();
    $ask = prkGroup(['allow_anyone' => false]);
    $asked = prkGroup(['allow_anyone' => false]);
    $request = new Request(['type' => RequestTypeEnum::groupTherapyMembership->value, 'data' => [], 'status' => RequestStatusEnum::pending->value]);
    $request->from()->associate($viewer);
    $request->to()->associate($asked->addedby);
    $request->for()->associate($asked);
    $request->save();
    $full = prkGroup(['max_users' => 1]);
    $full->users()->attach(User::factory()->adult()->create()->id, ['anonymous' => false, 'was_minor_at_join' => false]);
    $ended = prkGroup(['status' => TherapyStatusEnum::ended->value]);
    $lapsedRun = prkGroup(['addedby_type' => Counsellor::class, 'addedby_id' => prkCounsellor(['verification_expires_at' => now()->subDay()])->id]);

    $cards = collect($this->actingAs($viewer)->getJson('/api/group-therapies/random?seed=1')->json('data'))->keyBy('id');

    expect($cards[$open->id]['viewer']['action'])->toBe('join')
        ->and($cards[$ask->id]['viewer']['action'])->toBe('ask')
        ->and($cards[$asked->id]['viewer']['action'])->toBe('requestSent')
        ->and($cards[$full->id]['viewer']['action'])->toBe('full')
        ->and($cards[$ended->id]['viewer']['action'])->toBe('ended')
        ->and($cards[$lapsedRun->id]['viewer']['action'])->toBe('blocked')
        ->and($cards[$open->id])->toHaveKeys(['memberCount', 'maxUsers', 'status']);

    $this->app['auth']->forgetGuards();
    expect(collect($this->getJson('/api/group-therapies/random?seed=1')->json('data'))->pluck('viewer.action')->unique()->all())->toBe(['view']);
});

test('a counsellor\'s cards offer help where they can, and say when an offer or invitation is waiting', function () {
    $counsellor = prkCounsellor();
    $needsOne = prkTherapy();
    $offered = prkTherapy();
    $offer = new Request(['type' => RequestTypeEnum::therapy->value, 'data' => [], 'status' => RequestStatusEnum::pending->value]);
    $offer->from()->associate($counsellor);
    $offer->to()->associate($offered->addedby);
    $offer->for()->associate($offered);
    $offer->save();
    $taken = prkTherapy(['counsellor_id' => prkCounsellor()->id]);
    $group = prkGroup();

    $cards = collect($this->actingAs($counsellor->user)->getJson('/api/therapies/random?seed=2')->json('data'))->keyBy('id');
    expect($cards[$needsOne->id]['viewer']['action'])->toBe('offer')
        ->and($cards[$offered->id]['viewer']['action'])->toBe('offerSent')
        ->and($cards[$taken->id]['viewer']['action'])->toBe('view');

    $groupCards = collect($this->actingAs($counsellor->user)->getJson('/api/group-therapies/random?seed=2')->json('data'))->keyBy('id');
    expect($groupCards[$group->id]['viewer']['action'])->toBe('offer');

    // Not while their own verification isn't current.
    $counsellor->forceFill(['verification_expires_at' => now()->subDay()])->save();
    $cards = collect($this->actingAs($counsellor->user->fresh())->getJson('/api/therapies/random?seed=2')->json('data'))->keyBy('id');
    expect($cards[$needsOne->id]['viewer']['action'])->toBe('view');
});

test('the card buttons cost no query per row', function () {
    $viewer = prkViewer(['cases' => [prkCase()->id]]);
    $count = function (int $rows) use ($viewer) {
        DB::table('group_therapies')->delete();
        collect(range(1, $rows))->each(fn () => prkGroup());
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($viewer)->getJson('/api/group-therapies/random?seed=4')->assertOk();

        return count(DB::getQueryLog());
    };

    // The first request also warms per-viewer caches; compare after it.
    $count(1);

    expect($count(8))->toBe($count(3));
});

test('a group matches on the languages of its active counsellors and of a counsellor creator', function () {
    $language = prkLanguage();
    $speaker = prkCounsellor();
    $speaker->languages()->attach($language->id);

    $byCreator = prkGroup(['addedby_type' => Counsellor::class, 'addedby_id' => $speaker->id]);
    $byMember = prkGroup();
    $byMember->counsellors()->attach($speaker->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $byLeaver = prkGroup();
    $byLeaver->counsellors()->attach($speaker->id, ['state' => 'INACTIVE', 'role' => 'NORMAL']);
    $neither = prkGroup();

    $rows = collect($this->actingAs(prkViewer(['languages' => [$language->id]]))->getJson('/api/group-therapies/random?seed=6')->json('data'))->keyBy('id');

    expect($rows[$byCreator->id]['viewer']['matchesPreferences'])->toBeTrue()
        ->and($rows[$byMember->id]['viewer']['matchesPreferences'])->toBeTrue()
        ->and($rows[$byLeaver->id]['viewer']['matchesPreferences'])->toBeFalse()
        ->and($rows[$neither->id]['viewer']['matchesPreferences'])->toBeFalse();
})->group('mysql-json');

test('a counsellor\'s own religions never change how they see a client\'s therapy', function () {
    $religion = Religion::query()->create(['name' => 'Religion '.uniqid()]);
    $therapy = prkTherapy([], prkViewer(['religions' => [$religion->id]]));
    $counsellor = prkCounsellor();

    $label = fn () => collect($this->actingAs($counsellor->user->fresh())->getJson('/api/therapies/random?seed=9')->json('data'))
        ->firstWhere('id', $therapy->id)['viewer']['matchesPreferences'];

    $before = $label();
    $counsellor->religions()->attach($religion->id);

    expect($before)->toBeFalse()->and($label())->toBeFalse();
})->group('mysql-json');
