<?php

use App\Actions\Suspension\ApplySuspensionAction;
use App\Enums\AdministratorTypeEnum;
use App\Enums\CounsellorRecognitionEventEnum;
use App\Enums\SettingsEnum;
use App\Enums\StarSourceEnum;
use App\Enums\SuspensionScopeEnum;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\CounsellorRecognition;
use App\Models\PlatformSetting;
use App\Models\Report;
use App\Models\Star;
use App\Models\Therapy;
use App\Models\User;
use App\Services\SettingsService;
use App\Support\AdminNavigation;
use App\Support\StarCapPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

// TT-7.11d2/SCRUM-474: the admin recognition page -- any administrator views it, a super administrator decides, adds
// (through the counsellor search) and sets the daily star caps. Helpers are file-local and rap-prefixed (--parallel).

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-20 12:00:00', 'UTC'));
    config(['recognition.per_month' => 5, 'recognition.stars_count_from' => null]);
    Notification::fake();
});

afterEach(function () {
    Carbon::setTestNow();
});

function rapAdmin(bool $super = true): User
{
    return User::factory()->adult()
        ->has(Administrator::factory()->state([
            'type' => $super ? AdministratorTypeEnum::super->value : AdministratorTypeEnum::normal->value,
        ]))
        ->create();
}

function rapCounsellor(array $state = [], array $user = []): Counsellor
{
    return Counsellor::factory()->create(array_merge(['user_id' => User::factory()->adult()->state($user)], $state));
}

// $count new-rule stars at $at, written directly (fixtures for stars already earned; see the SCRUM-473 test).
function rapStars(Counsellor $counsellor, int $count, string $at = '2026-10-10 10:00:00'): void
{
    static $key = 0;

    for ($i = 0; $i < $count; $i++) {
        $star = (new Star)->forceFill([
            'type' => StarSourceEnum::sessionHeld->starType()->value,
            'source' => StarSourceEnum::sessionHeld,
            'award_key' => 'session_held:rap'.(++$key),
        ]);
        $star->starred()->associate($counsellor);
        $star->starreable()->associate($counsellor->user);
        $star->save();
        $star->forceFill(['created_at' => Carbon::parse($at, 'UTC')])->save();
    }
}

function rapReport(Model $reportable, Model $reporter, array $data, string $status = 'open'): Report
{
    $report = new Report(['description' => 'Something happened', 'data' => $data]);
    $report->addedby()->associate($reporter);
    $report->reportable()->associate($reportable);
    $report->save();
    $report->forceFill(['status' => $status])->save();

    return $report;
}

function rapSuspend(Counsellor $counsellor): void
{
    $client = User::factory()->adult()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => $counsellor->id]);
    $report = rapReport($therapy, $client, ['counsellorId' => $counsellor->id], 'resolved');
    $report->forceFill(['outcome' => 'suspension_recommended', 'resolution_note' => 'Serious'])->save();

    ApplySuspensionAction::new()->execute(rapAdmin(), $report, SuspensionScopeEnum::counsellor, 30, 'Repeated abusive messages to a client.');
}

function rapCaps(array $overrides = []): array
{
    return array_merge([
        'session' => 8, 'help_chat' => 5, 'discussion' => 2, 'post' => 3, 'session_per_client' => 1, 'session_per_group' => 1,
    ], $overrides);
}

// ---- The page ----

test('any admin opens the page for last month by default, with the suggestions, events and caps; controls for a super admin only', function () {
    rapStars($first = rapCounsellor(), 3);
    rapStars(rapCounsellor(), 2);

    $this->actingAs(rapAdmin(super: false))->get(route('administrator.recognition'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/Recognition')
            ->where('month', '2026-10')
            ->where('months', [['value' => '2026-10', 'label' => 'October 2026'], ['value' => '2026-11', 'label' => 'November 2026']])
            ->where('canManage', false)
            ->where('recognition.month', '2026-10')
            ->where('recognition.perMonth', 5)
            ->where('recognition.approvedCount', 0)
            ->has('recognition.suggestions', 2)
            ->where('recognition.suggestions.0.counsellorId', $first->id)
            ->where('recognition.suggestions.0.stars', 3)
            ->has('recognition.others', 0)
            ->has('recognition.unattributedOpenReportsNote')
            ->where('events', [])
            ->where('caps', rapCaps())
            ->where('capsMax', 100)
            ->has('adminNavigation'));

    $super = rapAdmin();
    $this->actingAs($super)->get(route('administrator.recognition', ['month' => '2026-11']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/Recognition')
            ->where('month', '2026-11')
            ->where('canManage', true)
            ->where('ownCounsellorId', null)
            ->has('recognition.suggestions', 0));
});

test('a super admin who is also a counsellor is told which row is theirs', function () {
    $super = rapAdmin();
    $own = rapCounsellor(['user_id' => $super->id]);

    $this->actingAs($super)->get(route('administrator.recognition'))
        ->assertInertia(fn ($page) => $page->where('ownCounsellorId', $own->id));
});

test('a month that can\'t be decided goes back to the default month with the error', function (string $month) {
    $this->actingAs(rapAdmin(super: false))->get(route('administrator.recognition', ['month' => $month]))
        ->assertRedirect(route('administrator.recognition'))
        ->assertSessionHasErrors('month');
})->with(['2026-09', '2026-12', '2025-10', 'nope', '2026-13']);

test('a non-admin is sent home, and the page is in the sidebar next to Payouts for every admin', function () {
    $this->actingAs(User::factory()->adult()->create())->get(route('administrator.recognition'))->assertRedirect(route('home'));

    $money = collect(AdminNavigation::for(rapAdmin(super: false)))->firstWhere('key', 'money');
    expect(array_column($money['items'], 'key'))->toBe(['payouts', 'recognition', 'refund-requests', 'organization-billing']);
});

test('an ordinary admin may read the suggestions JSON now, but still not decide', function () {
    rapStars($counsellor = rapCounsellor(), 2);
    $admin = rapAdmin(super: false);

    $this->actingAs($admin)->getJson(route('admin.recognition.suggestions'))
        ->assertOk()
        ->assertJsonPath('month', '2026-10')
        ->assertJsonPath('suggestions.0.counsellorId', $counsellor->id);

    foreach (['approve', 'skip', 'add'] as $action) {
        $this->actingAs($admin)->postJson(route("admin.recognition.{$action}"), ['month' => '2026-10', 'counsellor_id' => $counsellor->id])
            ->assertForbidden()
            ->assertJsonMissingPath('errors');
    }

    expect(CounsellorRecognition::count())->toBe(0);
});

test('an ordinary admin gets a 403 on the search and the caps update, before validation, and nothing changes', function () {
    $admin = rapAdmin(super: false);
    rapCounsellor([], ['firstName' => 'Zelda']);

    $this->actingAs($admin)->getJson(route('admin.recognition.counsellors', ['search' => 'Zelda']))->assertForbidden();
    $this->actingAs($admin)->getJson(route('admin.recognition.counsellors'))->assertForbidden()->assertJsonMissingPath('errors');
    $this->actingAs($admin)->postJson(route('admin.settings.recognition-caps.update'), ['caps' => rapCaps(['post' => 0])])->assertForbidden();
    $this->actingAs($admin)->postJson(route('admin.settings.recognition-caps.update'), [])->assertForbidden()->assertJsonMissingPath('errors');

    expect(PlatformSetting::count())->toBe(0);
});

test('the new routes are throttled and admin-only', function () {
    $routes = app('router')->getRoutes();

    foreach (['admin.recognition.counsellors', 'admin.settings.recognition-caps.update'] as $name) {
        $middleware = collect($routes->getByName($name)->gatherMiddleware());
        expect($middleware->contains(fn ($m) => str_starts_with($m, 'throttle:')))->toBeTrue($name)
            ->and($middleware->contains('admin'))->toBeTrue($name);
    }

    $this->getJson(route('admin.recognition.counsellors', ['search' => 'ab']))->assertUnauthorized();
    $this->postJson(route('admin.settings.recognition-caps.update'), ['caps' => rapCaps()])->assertUnauthorized();
});

// ---- Search ----

test('the search finds only counsellors who could be recognised now, with only an id, name and username, never the viewer', function () {
    $super = rapAdmin();
    $match = rapCounsellor(['name' => null], ['firstName' => 'Quillon', 'lastName' => 'Able', 'username' => 'quillon_able']);
    $byUsername = rapCounsellor(['name' => 'Dr Other'], ['username' => 'quillon_two']);
    rapCounsellor(['name' => 'Quillon Unverified', 'verified_at' => null]);
    rapCounsellor(['name' => 'Quillon Lapsed', 'verification_expires_at' => now()->subDay()]);
    rapCounsellor(['name' => 'Quillon Revoked', 'verification_revoked_at' => now()]);
    rapSuspend(rapCounsellor(['name' => 'Quillon Suspended']));
    $reported = rapCounsellor(['name' => 'Quillon Reported']);
    rapReport($reported->user, rapCounsellor(), ['counsellorId' => $reported->id]);
    rapCounsellor(['name' => 'Quillon Deleted'])->delete();
    $closed = rapCounsellor(['name' => 'Quillon Closed']);
    $closed->user->delete();
    rapCounsellor(['name' => 'Quillon Self', 'user_id' => $super->id]);
    rapCounsellor(['name' => 'Somebody Else']);

    $response = $this->actingAs($super)->getJson(route('admin.recognition.counsellors', ['search' => 'quillon']))->assertOk();

    expect($response->json('counsellors'))->toBe([
        ['id' => $match->id, 'name' => $match->user->name, 'username' => 'quillon_able'],
        ['id' => $byUsername->id, 'name' => 'Dr Other', 'username' => 'quillon_two'],
    ]);
});

test('the search needs 2 to 100 characters, and returns at most 10', function () {
    $super = rapAdmin();

    $this->actingAs($super)->getJson(route('admin.recognition.counsellors', ['search' => 'a']))->assertUnprocessable()->assertJsonValidationErrors('search');
    $this->actingAs($super)->getJson(route('admin.recognition.counsellors', ['search' => str_repeat('a', 101)]))->assertUnprocessable();
    $this->actingAs($super)->getJson(route('admin.recognition.counsellors'))->assertUnprocessable();

    foreach (range(1, 12) as $i) {
        rapCounsellor(['name' => "Many Match {$i}"]);
    }

    expect($this->actingAs($super)->getJson(route('admin.recognition.counsellors', ['search' => 'many match']))->json('counsellors'))->toHaveCount(10);
});

// ---- Daily star caps ----

test('a super admin saves the daily star caps, and StarCapPolicy uses them', function () {
    $super = rapAdmin();
    $counsellor = rapCounsellor();

    expect(StarCapPolicy::allows($counsellor, StarSourceEnum::postPublished, null))->toBeTrue();

    $this->actingAs($super)->postJson(route('admin.settings.recognition-caps.update'), ['caps' => rapCaps(['post' => 0, 'session' => 12, 'session_per_group' => 100])])
        ->assertOk()
        ->assertJsonPath('caps.post', 0)
        ->assertJsonPath('caps.session', 12);

    expect(SettingsService::new()->getRecognitionCaps())->toBe(rapCaps(['post' => 0, 'session' => 12, 'session_per_group' => 100]))
        ->and(PlatformSetting::query()->where('key', SettingsEnum::recognitionCapPost->value)->value('value'))->toBe('0')
        ->and(PlatformSetting::query()->where('key', SettingsEnum::recognitionCapPost->value)->value('updated_by_id'))->toBe($super->id)
        ->and(StarCapPolicy::allows($counsellor, StarSourceEnum::postPublished, null))->toBeFalse();

    // An Inertia form post goes back to the page.
    $this->actingAs($super)->from(route('administrator.recognition'))
        ->post(route('admin.settings.recognition-caps.update'), ['caps' => rapCaps(['post' => 4])])
        ->assertRedirect(route('administrator.recognition'));

    expect(StarCapPolicy::allows($counsellor, StarSourceEnum::postPublished, null))->toBeTrue();
    $this->actingAs(rapAdmin(super: false))->get(route('administrator.recognition'))
        ->assertInertia(fn ($page) => $page->where('caps.post', 4));
});

test('every cap must be a whole number from 0 to 100, all six at once, and a refusal saves none', function (array $caps, string $error) {
    $this->actingAs(rapAdmin())->postJson(route('admin.settings.recognition-caps.update'), ['caps' => $caps])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($error);

    expect(PlatformSetting::count())->toBe(0);
})->with([
    'over 100' => [rapCaps(['session' => 101]), 'caps.session'],
    'negative' => [rapCaps(['post' => -1]), 'caps.post'],
    'a fraction' => [rapCaps(['discussion' => 1.5]), 'caps.discussion'],
    'text' => [rapCaps(['help_chat' => 'five']), 'caps.help_chat'],
    'missing one' => [array_diff_key(rapCaps(), ['session_per_group' => true]), 'caps.session_per_group'],
    'an unknown key' => [rapCaps(['interaction' => 3]), 'caps'],
]);

// ---- Events ----

test('the page lists the month\'s recognition events, most recent first, with who made them', function () {
    $super = rapAdmin();
    rapStars($first = rapCounsellor(['name' => 'First Counsellor']), 3);
    rapStars($second = rapCounsellor(['name' => 'Second Counsellor']), 2);
    rapStars($current = rapCounsellor(), 2, '2026-11-05 10:00:00');

    $decide = fn (string $action, Counsellor $counsellor, string $month = '2026-10') => $this->actingAs($super)
        ->postJson(route("admin.recognition.{$action}"), ['month' => $month, 'counsellor_id' => $counsellor->id])->assertOk();

    $decide('approve', $first);
    Carbon::setTestNow(now()->addMinute());
    $decide('skip', $second);
    Carbon::setTestNow(now()->addMinute());
    $decide('skip', $first);
    // Another month's events aren't listed.
    $decide('approve', $current, '2026-11');

    $this->actingAs(rapAdmin(super: false))->get(route('administrator.recognition'))
        ->assertInertia(fn ($page) => $page
            ->has('events', 3)
            ->where('events.0.event', CounsellorRecognitionEventEnum::skipped->value)
            ->where('events.0.counsellorId', $first->id)
            ->where('events.0.counsellorName', 'First Counsellor')
            ->where('events.0.from', 'approved')
            ->where('events.0.actorName', $super->name)
            ->where('events.0.at', '2026-11-20T12:02:00Z')
            ->where('events.1.event', 'skipped')
            ->where('events.1.counsellorName', 'Second Counsellor')
            ->where('events.1.from', null)
            ->where('events.2.event', 'approved')
            ->where('events.2.at', '2026-11-20T12:00:00Z')
            ->where('recognition.approvedCount', 0)
            ->has('recognition.suggestions', 2));
});
