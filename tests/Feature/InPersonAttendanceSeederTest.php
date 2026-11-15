<?php

use App\Enums\ConstantsEnum;
use App\Enums\SessionStatusEnum;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\SessionAttendance;
use App\Models\Therapy;
use App\Models\User;
use Database\Seeders\InPersonAttendanceSeeder;
use Database\Seeders\InPersonSessionSeeder;
use Illuminate\Support\Facades\Cache;

// SCRUM-446: the attendance demo -- an in-person 1:1 session and a group session that ended an hour ago, so their
// attendance window is open, one group member anonymous. Running it again reopens the window and clears the marks.

function ipasAccounts(): User
{
    $michael = User::factory()->adult()->create(['username' => 'michael_chen']);
    Counsellor::factory()->create(['user_id' => $michael->id]);
    foreach (['john_davis', 'robert_miller', 'amy_taylor'] as $username) {
        User::factory()->adult()->create(['username' => $username]);
    }

    return $michael;
}

test('the attendance demo seeds two in-person sessions with an open window, one member anonymous; a re-run refreshes them', function () {
    $michael = ipasAccounts();
    $this->seed(InPersonSessionSeeder::class);
    $this->seed(InPersonAttendanceSeeder::class);

    $session = Session::query()->where('name', InPersonAttendanceSeeder::SESSION)->sole();
    $groupSession = Session::query()->where('name', InPersonAttendanceSeeder::GROUP_SESSION)->sole();
    expect($session->for)->toBeInstanceOf(Therapy::class)->and($session->status)->toBe(SessionStatusEnum::pending->value)
        ->and($groupSession->for)->toBeInstanceOf(GroupTherapy::class)->and($groupSession->status)->toBe(SessionStatusEnum::held->value)
        ->and($session->type)->toBe('IN_PERSON')->and($groupSession->type)->toBe('IN_PERSON')
        ->and($session->end_time->diffInMinutes(now()->subHour(), true))->toBeLessThan(2);

    $this->actingAs($michael)->getJson(route('api.session.attendance.index', ['sessionId' => $session->id]))
        ->assertOk()->assertJsonPath('canRecord', true)->assertJsonCount(1, 'register');
    $register = $this->getJson(route('api.session.attendance.index', ['sessionId' => $groupSession->id]))
        ->assertOk()->assertJsonPath('canRecord', true)->json('register');
    expect(collect($register)->pluck('name')->all())->toHaveCount(3)->toContain(ConstantsEnum::anonymousUserLabel->value)->not->toContain(User::query()->where('username', 'amy_taylor')->value('name'));

    // Marked and closed as a no-show, then seeded again: back to an open, unmarked, pending session.
    $this->postJson(route('api.session.attendance.store', ['sessionId' => $session->id]), ['attendance' => [['key' => 'client', 'status' => 'ABSENT']]])->assertSuccessful();
    $this->postJson(route('api.session.attendance.close_no_show', ['sessionId' => $session->id]))->assertSuccessful();
    expect(Cache::has('absent-notice:'.User::query()->where('username', 'john_davis')->value('id').":{$session->id}"))->toBeTrue();
    $this->travel(3)->days();

    // Since then amy_taylor left the group, and someone else joined it.
    $group = GroupTherapy::query()->where('name', 'In-Person Demo Group')->sole();
    $amy = User::query()->where('username', 'amy_taylor')->sole();
    $group->allMemberships()->updateExistingPivot($amy->id, ['left_at' => now()]);
    $outsider = User::factory()->adult()->create();
    $group->allMemberships()->attach($outsider->id, ['anonymous' => false, 'background_story' => 'x']);

    $this->seed(InPersonAttendanceSeeder::class);

    expect(Cache::has('absent-notice:'.User::query()->where('username', 'john_davis')->value('id').":{$session->id}"))->toBeFalse()
        ->and($group->users()->whereKey($amy->id)->exists())->toBeTrue()
        ->and($group->allMemberships()->whereKey($outsider->id)->sole()->pivot->created_at->isToday())->toBeTrue();
    $this->getJson(route('api.session.attendance.index', ['sessionId' => $groupSession->id]))->assertOk()->assertJsonCount(3, 'register');

    $session->refresh();
    expect(Session::query()->where('name', InPersonAttendanceSeeder::SESSION)->count())->toBe(1)
        ->and($session->status)->toBe(SessionStatusEnum::pending->value)->and($session->ended_at)->toBeNull()
        ->and($session->end_time->diffInMinutes(now()->subHour(), true))->toBeLessThan(2)
        ->and(SessionAttendance::query()->where('session_id', $session->id)->exists())->toBeFalse()
        ->and($group->allMemberships()->count())->toBe(4);
});

test('the attendance demo is skipped without its accounts or the in-person demo', function () {
    ipasAccounts();
    $this->seed(InPersonAttendanceSeeder::class);

    expect(Session::query()->where('name', InPersonAttendanceSeeder::SESSION)->exists())->toBeFalse();
});
