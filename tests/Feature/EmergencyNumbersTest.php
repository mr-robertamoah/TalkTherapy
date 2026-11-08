<?php

use App\Enums\AdministratorTypeEnum;
use App\Models\Administrator;
use App\Models\EmergencyNumber;
use App\Models\EmergencyNumberEvent;
use App\Models\GroupTherapy;
use App\Models\Report;
use App\Models\Suspension;
use App\Models\User;
use App\Support\Countries;

// SCRUM-33: the emergency numbers behind "I need help now" -- verified-only, per country, managed by a
// super administrator with every change kept. Helpers are file-local and enx-prefixed (--parallel).

function enxAdmin(bool $super = false): User
{
    return User::factory()->adult()->has(Administrator::factory()->state([
        'type' => $super ? AdministratorTypeEnum::super->value : AdministratorTypeEnum::normal->value,
    ]))->create();
}

function enxValues(array $overrides = []): array
{
    return [
        'countryCode' => 'GH', 'label' => 'Test line', 'number' => '000', 'notes' => 'Test only',
        'sourceUrl' => 'https://example.test/source', 'verifiedBy' => 'A Tester', 'verifiedAt' => now()->subDay()->toDateString(),
        'position' => 0, ...$overrides,
    ];
}

function enxSuspend(User $person): void
{
    $report = new Report(['description' => 'Something happened']);
    $report->addedby()->associate(User::factory()->adult()->create());
    $report->reportable()->associate(GroupTherapy::factory()->create());
    $report->save();
    Suspension::query()->forceCreate(['user_id' => $person->id, 'report_id' => $report->id, 'scope' => 'account', 'public_reason' => 'Conduct', 'starts_at' => now()->subMinute()]);
}

function enxNumber(string $code = 'GH', array $state = []): EmergencyNumber
{
    return EmergencyNumber::query()->forceCreate([
        'country_code' => $code, 'label' => 'Test line', 'number' => '000', 'source_url' => 'https://example.test',
        'verified_by' => 'A Tester', 'verified_at' => now()->subDay()->toDateString(), ...$state,
    ]);
}

test('the country list comes from the ISO data, and a saved name or alias resolves to its code', function () {
    expect(count(Countries::all()))->toBeGreaterThan(240)
        ->and(Countries::codeFor('Ghana'))->toBe('GH')
        ->and(Countries::codeFor(' ghana '))->toBe('GH')
        ->and(Countries::codeFor('United States of America'))->toBe('US')
        ->and(Countries::codeFor('Ivory Coast'))->toBe('CI')
        // Shared by both Congos, so it points nowhere.
        ->and(Countries::codeFor('Congo'))->toBeNull()
        ->and(Countries::codeFor('Atlantis'))->toBeNull()
        ->and(Countries::nameOf('GH'))->toBe('Ghana');
});

test('anyone -- a guest, a signed-in person, a suspended account -- gets their country\'s numbers, or none rather than a guess', function () {
    $ghana = enxNumber('GH', ['label' => 'Ghana line', 'position' => 2]);
    $ghanaFirst = enxNumber('GH', ['label' => 'Ghana first', 'position' => 1]);
    $nigeria = enxNumber('NG', ['label' => 'Nigeria line']);

    // A guest: the default country.
    $guest = $this->getJson(route('help.emergency_numbers'))->assertOk();
    expect($guest->json('country.code'))->toBe('GH')
        ->and(collect($guest->json('numbers'))->pluck('id')->all())->toBe([$ghanaFirst->id, $ghana->id]);

    // Their own country, by its saved name.
    $nigerian = User::factory()->adult()->create(['country' => 'Nigeria']);
    expect(collect($this->actingAs($nigerian)->getJson(route('help.emergency_numbers'))->json('numbers'))->pluck('id')->all())->toBe([$nigeria->id]);

    // A country with none: an empty list, never another country's number.
    expect($this->getJson(route('help.emergency_numbers', ['country' => 'ke']))->json())
        ->toBe(['country' => ['code' => 'KE', 'name' => 'Kenya'], 'numbers' => []]);

    // A suspended account still gets them.
    $suspended = User::factory()->adult()->create(['country' => 'Ghana']);
    enxSuspend($suspended);
    expect($this->actingAs($suspended)->getJson(route('help.emergency_numbers'))->assertOk()->json('country.code'))->toBe('GH');
});

test('only a super administrator adds, edits or removes a number, and every change is kept', function () {
    $this->actingAs(enxAdmin())->post(route('admin.emergency_numbers.store'), enxValues())
        ->assertSessionHasErrors('alert');
    expect(EmergencyNumber::query()->count())->toBe(0);

    $super = enxAdmin(super: true);
    $this->actingAs($super)->post(route('admin.emergency_numbers.store'), enxValues())->assertSessionHasNoErrors();
    $number = EmergencyNumber::query()->sole();

    $this->actingAs($super)->post(route('admin.emergency_numbers.update', ['number' => $number->id]), enxValues(['label' => 'Renamed']))->assertSessionHasNoErrors();
    $this->actingAs($super)->post(route('admin.emergency_numbers.delete', ['number' => $number->id]))->assertSessionHasNoErrors();

    expect(EmergencyNumber::query()->count())->toBe(0)
        ->and(EmergencyNumberEvent::query()->orderBy('id')->pluck('action')->all())->toBe(['created', 'updated', 'deleted'])
        ->and(EmergencyNumberEvent::query()->where('action', 'updated')->value('values'))->toContain('Renamed');

    expect(fn () => EmergencyNumberEvent::query()->first()->update(['action' => 'x']))->toThrow(LogicException::class);
});

test('a number needs a real country, a diallable number, a source, who verified it and when (not in the future)', function () {
    $super = enxAdmin(super: true);

    foreach ([
        'countryCode' => ['countryCode' => 'XX'],
        'number' => ['number' => 'call me'],
        'sourceUrl' => ['sourceUrl' => 'not a link'],
        'verifiedBy' => ['verifiedBy' => ''],
        'verifiedAt' => ['verifiedAt' => now()->addDay()->toDateString()],
    ] as $field => $bad) {
        $this->actingAs($super)->post(route('admin.emergency_numbers.store'), enxValues($bad))->assertSessionHasErrors($field);
    }

    expect(EmergencyNumber::query()->count())->toBe(0);
});

test('every administrator sees the numbers page, with the ones due a fresh check flagged; others don\'t', function () {
    enxNumber('GH', ['verified_at' => now()->subMonths(7)->toDateString()]);

    $this->actingAs(enxAdmin())->get(route('administrator.emergency_numbers'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/EmergencyNumbers')->where('canManage', false)->where('numbers.0.reviewDue', true));
    $this->actingAs(User::factory()->adult()->create())->get(route('administrator.emergency_numbers'))->assertRedirect();
});
