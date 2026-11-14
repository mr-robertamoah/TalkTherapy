<?php

use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\User;
use Illuminate\Support\Facades\Route;

// SCRUM-426: two routes sharing a name break `php artisan route:cache` (and route() can only ever give one of them).

test('no two routes share a name', function () {
    $names = collect(Route::getRoutes()->getRoutes())->map(fn ($route) => $route->getName())->filter();

    expect($names->duplicates()->values()->all())->toBe([]);
});

test('a group therapy\'s sessions have their own route names', function () {
    expect(route('group.sessions.create', ['groupTherapyId' => 5], false))->toBe('/group-therapies/5/sessions')
        ->and(route('group.sessions.update', ['groupTherapyId' => 5, 'sessionId' => 7], false))->toBe('/group-therapies/5/sessions/7')
        ->and(route('sessions.create', ['therapyId' => 5], false))->toBe('/therapies/5/sessions');
});

// The create-session form posted every session to the individual therapy's route, with a group's id as a therapy id.
test('a group\'s counsellor creates a session through the group\'s own route, on that group', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()]);
    $group = GroupTherapy::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id]);
    $group->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);

    $this->actingAs($counsellor->user)->postJson(route('group.sessions.create', ['groupTherapyId' => $group->id]), [
        'name' => 'Group check-in', 'about' => 'A check-in.', 'paymentType' => 'FREE', 'type' => 'ONLINE',
        'startTime' => now()->addDays(2)->toDateTimeString(), 'endTime' => now()->addDays(2)->addHour()->toDateTimeString(),
    ])->assertOk();

    expect(Session::query()->where('name', 'Group check-in')->sole())
        ->for_type->toBe($group->getMorphClass())->for_id->toBe($group->id);
});
