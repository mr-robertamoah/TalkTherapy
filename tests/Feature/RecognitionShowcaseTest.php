<?php

use App\Actions\Recognition\GetVisibleRecognitionsAction;
use App\Actions\Suspension\ApplySuspensionAction;
use App\Actions\User\GetYourDataAction;
use App\DTOs\CounsellorRecognitionDTO;
use App\Enums\AdministratorTypeEnum;
use App\Enums\CounsellorRecognitionConsentEnum as Consent;
use App\Enums\CounsellorRecognitionDecisionEnum as Decision;
use App\Enums\HelpRequestStatusEnum;
use App\Enums\StarSourceEnum;
use App\Enums\StarTypeEnum;
use App\Enums\SuspensionScopeEnum;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\CounsellorRecognition;
use App\Models\HelpRequest;
use App\Models\Profession;
use App\Models\Report;
use App\Models\Star;
use App\Models\Suspension;
use App\Models\Therapy;
use App\Models\TherapyCase;
use App\Models\User;
use App\Services\CounsellorRecognitionService;
use Database\Seeders\RecognitionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;

// TT-7.11f/SCRUM-476: the recognition showcase -- Home's "Counsellors of the month", the profile badge and the owner's
// consent controls, all through GetVisibleRecognitionsAction; Home's "Leading" on new-rule stars, signed-in only; and no
// star counts sent to anyone on Home, the profile or the counsellor APIs.

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-20 12:00:00', 'UTC'));
    config(['recognition.per_month' => 5, 'recognition.stars_count_from' => null]);
    Notification::fake();
});

afterEach(function () {
    Carbon::setTestNow();
});

function rscCounsellor(array $state = []): Counsellor
{
    return Counsellor::factory()->create(array_merge(['user_id' => User::factory()->adult()], $state));
}

function rscSuperAdmin(): User
{
    return User::factory()->adult()
        ->has(Administrator::factory()->state(['type' => AdministratorTypeEnum::super->value]))
        ->create();
}

// A decided recognition, written directly (a fixture: the service's own rules are SCRUM-473's tests).
function rscRecognition(Counsellor $counsellor, ?Consent $consent = Consent::accepted, string $month = '2026-10-01', Decision $decision = Decision::approved): CounsellorRecognition
{
    $recognition = (new CounsellorRecognition)->forceFill([
        'counsellor_id' => $counsellor->id,
        'month' => $month,
        'decision' => $decision,
        'consent' => $decision === Decision::approved ? $consent : null,
        'consent_changed_at' => now(),
        'decided_at' => now(),
        'stars_count' => 3,
        'rank' => 1,
    ]);
    $recognition->save();

    return $recognition;
}

// $count new-rule stars at $at, written directly (fixtures for stars already earned; see SCRUM-473's tests).
function rscStars(Counsellor $counsellor, int $count, string $at = '2026-11-10 10:00:00'): void
{
    static $key = 0;

    for ($i = 0; $i < $count; $i++) {
        $star = (new Star)->forceFill([
            'type' => StarSourceEnum::sessionHeld->starType()->value,
            'source' => StarSourceEnum::sessionHeld,
            'award_key' => 'session_held:rsc'.(++$key),
        ]);
        $star->starred()->associate($counsellor);
        $star->starreable()->associate($counsellor->user);
        $star->save();
        $star->forceFill(['created_at' => Carbon::parse($at, 'UTC')])->save();
    }
}

// A star from before the new rules (no source), on either of a counsellor's morphs.
function rscLegacyStar(Model $starred, string $at = '2026-11-10 10:00:00'): void
{
    $star = Star::create(['type' => StarTypeEnum::contribution->value]);
    $star->starred()->associate($starred);
    $star->save();
    $star->forceFill(['created_at' => Carbon::parse($at, 'UTC')])->save();
}

function rscReport(Model $reportable, Model $reporter, array $data, string $status = 'open'): Report
{
    $report = new Report(['description' => 'Something happened', 'data' => $data]);
    $report->addedby()->associate($reporter);
    $report->reportable()->associate($reportable);
    $report->save();
    $report->forceFill(['status' => $status])->save();

    return $report;
}

function rscSuspend(Counsellor $counsellor): Suspension
{
    $client = User::factory()->adult()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => $counsellor->id]);
    $report = rscReport($therapy, $client, ['counsellorId' => $counsellor->id], 'resolved');
    $report->forceFill(['outcome' => 'suspension_recommended', 'resolution_note' => 'Serious'])->save();

    return ApplySuspensionAction::new()->execute(rscSuperAdmin(), $report, SuspensionScopeEnum::counsellor, 30, 'Repeated abusive messages to a client.');
}

// Puts $counsellor in one of the states that hide their recognitions.
function rscApplyCondition(Counsellor $counsellor, string $condition): void
{
    match ($condition) {
        'none' => null,
        'suspended' => rscSuspend($counsellor),
        'reported via data' => rscReport($counsellor->user, rscCounsellor(), ['counsellorId' => $counsellor->id]),
        'reported via help request' => rscReport(
            HelpRequest::create(['user_id' => $counsellor->user_id, 'status' => HelpRequestStatusEnum::ended->value]),
            rscCounsellor(),
            [],
            'under_review',
        ),
        'lapsed' => $counsellor->forceFill(['verified_at' => now()->subYear(), 'verification_expires_at' => now()->subDay()])->save(),
        'revoked' => $counsellor->forceFill(['verification_revoked_at' => now()->subDay()])->save(),
        'profile deleted' => $counsellor->delete(),
        'account deleted' => $counsellor->user->delete(),
    };
}

function rscViewer(string $viewer, Counsellor $counsellor): ?User
{
    return match ($viewer) {
        'guest' => null,
        'signed-in' => User::factory()->adult()->create(),
        'owner' => User::withTrashed()->find($counsellor->user_id),
    };
}

// Every key, at any depth, of a decoded JSON payload.
function rscKeys(mixed $value): array
{
    if (! is_array($value)) {
        return [];
    }

    $keys = [];
    foreach ($value as $key => $child) {
        if (is_string($key)) {
            $keys[] = $key;
        }
        $keys = array_merge($keys, rscKeys($child));
    }

    return array_values(array_unique($keys));
}

// Keys that carry a star count or a rank: stars, overallStars, starsCount, currentMonthStarsCount, rank... (not "start").
function rscStarKeys(mixed $payload): array
{
    return array_values(array_filter(rscKeys($payload), fn (string $key) => (bool) preg_match('/[Ss]tars?(?![a-z])|[Rr]ank(?![a-z])/', $key)));
}

test('the star-key check catches every star count the old payloads carried', function () {
    expect(rscStarKeys(['stars' => 1, 'overallStars' => 2, 'a' => ['overallStarsCount' => 3, 'currentMonthStarsCount' => 4, 'starsCount' => 5, 'rank' => 6]]))
        ->toHaveCount(6)
        ->and(rscStarKeys(['startsAt' => 1, 'start' => 2, 'restarted' => 3]))->toBe([]);
});

function rscHomeProps($test, ?User $viewer = null): array
{
    $test = $viewer ? $test->actingAs($viewer) : $test;
    $props = null;

    $test->get(route('home'))->assertOk()->assertInertia(function (AssertableInertia $page) use (&$props) {
        $props = $page->toArray()['props'];
    });

    $props = json_decode(json_encode($props), true);

    // A resource collection prop is {data: [...]}, or the bare list once HandleInertiaRequestsV2 has switched
    // JsonResource wrapping off (process-wide) for a signed-in request -- Home.vue reads both.
    foreach (['recognisedCounsellors', 'leadingCounsellors'] as $key) {
        $props[$key] = $props[$key]['data'] ?? $props[$key];
    }

    return $props;
}

function rscProfileProps($test, Counsellor $counsellor, ?User $viewer = null): array
{
    $test = $viewer ? $test->actingAs($viewer) : $test;
    $props = null;

    $test->get(route('counsellor.show', ['counsellorId' => $counsellor->id]))->assertOk()->assertInertia(function (AssertableInertia $page) use (&$props) {
        $props = $page->toArray()['props'];
    });

    return json_decode(json_encode($props), true);
}

// ---- The visibility rule ----

test('who sees a recognition, by viewer, consent and the counsellor\'s standing', function (string $viewer, Consent $consent, string $condition) {
    $counsellor = rscCounsellor();
    rscRecognition($counsellor, $consent);
    rscApplyCondition($counsellor, $condition);
    $who = rscViewer($viewer, $counsellor);

    $expected = $condition === 'none' && match ($consent) {
        Consent::accepted => true,
        Consent::pending => $viewer !== 'guest',
        Consent::declined, Consent::withdrawn => $viewer === 'owner',
    };

    $showcase = GetVisibleRecognitionsAction::new()->execute($who)->pluck('counsellor_id')->all();
    $badge = GetVisibleRecognitionsAction::new()->execute($who, Counsellor::withTrashed()->find($counsellor->id))->pluck('counsellor_id')->all();

    expect($showcase)->toBe($expected ? [$counsellor->id] : [])
        ->and($badge)->toBe($expected ? [$counsellor->id] : []);
})->with(['guest', 'signed-in', 'owner'])
    ->with([Consent::accepted, Consent::pending, Consent::declined, Consent::withdrawn])
    ->with(['none', 'suspended', 'reported via data', 'reported via help request', 'lapsed', 'revoked', 'profile deleted', 'account deleted']);

test('a skipped recognition is never shown, not even to its counsellor', function () {
    $counsellor = rscCounsellor();
    rscRecognition($counsellor, null, decision: Decision::skipped);

    expect(GetVisibleRecognitionsAction::new()->execute($counsellor->user, $counsellor))->toBeEmpty()
        ->and(GetVisibleRecognitionsAction::new()->execute($counsellor->user))->toBeEmpty();
});

test('a hidden recognition comes back once the suspension is lifted or the report closed', function () {
    $suspended = rscCounsellor();
    $reported = rscCounsellor();
    rscRecognition($suspended);
    rscRecognition($reported);
    $suspension = rscSuspend($suspended);
    $report = rscReport($reported->user, rscCounsellor(), ['counsellorId' => $reported->id]);

    expect(GetVisibleRecognitionsAction::new()->execute(null))->toBeEmpty();

    $suspension->forceFill(['lifted_at' => now()])->save();
    $report->forceFill(['status' => 'resolved'])->save();

    expect(GetVisibleRecognitionsAction::new()->execute(null)->pluck('counsellor_id')->all())->toBe([$suspended->id, $reported->id]);
});

test('another counsellor\'s declined or withdrawn recognition stays theirs', function () {
    $mine = rscCounsellor();
    $theirs = rscCounsellor();
    rscRecognition($theirs, Consent::declined);
    rscRecognition($mine, Consent::withdrawn);

    expect(GetVisibleRecognitionsAction::new()->execute($mine->user)->pluck('counsellor_id')->all())->toBe([$mine->id])
        ->and(GetVisibleRecognitionsAction::new()->execute($mine->user, $theirs))->toBeEmpty();
});

// ---- Only the latest month ----

test('the showcase is the latest month with an approved recognition, and nothing older', function () {
    $october = rscCounsellor();
    $november = rscCounsellor();
    $skippedLater = rscCounsellor();
    rscRecognition($october, Consent::accepted, '2026-10-01');
    rscRecognition($november, Consent::pending, '2026-11-01');

    // November is the latest approved month: a guest, who can't see November's pending one, sees no October either.
    expect(GetVisibleRecognitionsAction::new()->execute(null))->toBeEmpty()
        ->and(GetVisibleRecognitionsAction::new()->execute(User::factory()->adult()->create())->pluck('counsellor_id')->all())->toBe([$november->id]);

    // A skipped row doesn't make a month the latest.
    CounsellorRecognition::query()->where('counsellor_id', $november->id)->delete();
    rscRecognition($skippedLater, null, '2026-11-01', Decision::skipped);

    expect(GetVisibleRecognitionsAction::new()->execute(null)->pluck('counsellor_id')->all())->toBe([$october->id]);
});

test('the profile badge lists every month this viewer may see, newest first', function () {
    $counsellor = rscCounsellor();
    rscRecognition($counsellor, Consent::accepted, '2026-10-01');
    rscRecognition($counsellor, Consent::pending, '2026-11-01');
    rscRecognition($counsellor, Consent::withdrawn, '2026-09-01');

    expect(rscProfileProps($this, $counsellor)['recognitionBadges'])->toBe(['October 2026'])
        ->and(rscProfileProps($this, $counsellor, User::factory()->adult()->create())['recognitionBadges'])->toBe(['November 2026', 'October 2026'])
        ->and(rscProfileProps($this, $counsellor, $counsellor->user)['recognitionBadges'])->toBe(['November 2026', 'October 2026', 'September 2026']);

    rscSuspend($counsellor);

    expect(rscProfileProps($this, $counsellor, User::factory()->adult()->create())['recognitionBadges'])->toBe([]);
});

// ---- The payload ----

test('a showcase card is exactly the name, photo, profession, up to 3 cases and the month', function () {
    $profession = Profession::forceCreate(['name' => 'clinical psychologist']);
    $counsellor = rscCounsellor(['name' => 'Dr. Ama Mensah', 'profession_id' => $profession->id]);
    $cases = collect(['anxiety', 'grief', 'stress', 'sleep'])->map(fn (string $name) => TherapyCase::forceCreate(['name' => $name]));
    $counsellor->cases()->attach($cases->pluck('id')->all());
    rscRecognition($counsellor);

    $cards = rscHomeProps($this)['recognisedCounsellors'];

    expect($cards)->toHaveCount(1)
        ->and(array_keys($cards[0]))->toBe(['id', 'name', 'avatar', 'profession', 'cases', 'month'])
        ->and($cards[0])->toMatchArray([
            'id' => $counsellor->id,
            'name' => 'Dr. Ama Mensah',
            'avatar' => null,
            'profession' => 'clinical psychologist',
            'cases' => ['anxiety', 'grief', 'stress'],
            'month' => 'October 2026',
        ]);
});

// ---- No star counts ----

test('no star count or rank reaches a guest on Home, the profile or the counsellor APIs', function () {
    $counsellor = rscCounsellor();
    rscStars($counsellor, 4);
    rscLegacyStar($counsellor);
    rscLegacyStar($counsellor->user);
    rscRecognition($counsellor);

    $home = rscHomeProps($this);

    expect($home['leadingCounsellors'])->toBe([])
        ->and($home['recognisedCounsellors'])->toHaveCount(1)
        ->and(rscStarKeys($home))->toBe([])
        ->and(rscStarKeys(rscProfileProps($this, $counsellor)))->toBe([])
        ->and(rscStarKeys($this->getJson(route('api.counsellors.random'))->assertOk()->json()))->toBe([])
        ->and(rscStarKeys($this->getJson(route('api.counsellors'))->assertOk()->json()))->toBe([]);
});

test('signed-in users get Leading, but no counts either -- the owner included', function () {
    $counsellor = rscCounsellor();
    rscStars($counsellor, 4);
    rscRecognition($counsellor);

    $home = rscHomeProps($this, User::factory()->adult()->create());

    expect($home['leadingCounsellors'])->toHaveCount(1)
        ->and(rscStarKeys($home))->toBe([])
        ->and(rscStarKeys(rscProfileProps($this, $counsellor, $counsellor->user)))->toBe([])
        ->and(rscStarKeys($this->actingAs($counsellor->user)->getJson(route('api.counsellors.random'))->json()))->toBe([]);
});

// ---- Leading ----

test('Leading ranks this month\'s new-rule stars among counsellors eligible now', function () {
    $first = rscCounsellor();
    $second = rscCounsellor();
    $legacyHeavy = rscCounsellor();
    $suspended = rscCounsellor();
    $reported = rscCounsellor();
    $lastMonth = rscCounsellor();

    rscStars($first, 3);
    rscStars($second, 2, '2026-11-02 08:00:00');
    rscStars($legacyHeavy, 1);
    foreach (range(1, 6) as $i) {
        rscLegacyStar($legacyHeavy);
        rscLegacyStar($legacyHeavy->user);
    }
    rscStars($suspended, 9);
    rscSuspend($suspended);
    rscStars($reported, 9);
    rscReport(HelpRequest::create(['user_id' => $reported->user_id, 'status' => HelpRequestStatusEnum::ended->value]), rscCounsellor(), []);
    rscStars($lastMonth, 9, '2026-10-30 10:00:00');

    $leading = rscHomeProps($this, User::factory()->adult()->create())['leadingCounsellors'];

    expect(array_column($leading, 'id'))->toBe([$first->id, $second->id, $legacyHeavy->id])
        ->and(array_keys($leading[0]))->toBe(['id', 'name', 'avatar', 'cover', 'verification', 'matchesPreferences']);
});

test('Leading shows at most 5', function () {
    foreach (range(1, 7) as $i) {
        rscStars(rscCounsellor(), $i);
    }

    expect(rscHomeProps($this, User::factory()->adult()->create())['leadingCounsellors'])->toHaveCount(5);
});

// ---- The owner's consent controls ----

test('only the counsellor gets their recognitions with the consent controls', function () {
    $counsellor = rscCounsellor();
    rscRecognition($counsellor, Consent::declined, '2026-10-01');
    rscRecognition($counsellor, null, '2026-11-01', Decision::skipped);

    expect(rscProfileProps($this, $counsellor, $counsellor->user)['recognitions'])->toBe([
        ['id' => $counsellor->recognitions()->where('decision', 'approved')->value('id'), 'month' => 'October 2026', 'consent' => 'declined', 'consentRequestId' => null],
    ])
        ->and(rscProfileProps($this, $counsellor, User::factory()->adult()->create()))->not->toHaveKey('recognitions')
        ->and(rscProfileProps($this, $counsellor))->not->toHaveKey('recognitions');
});

test('the counsellor accepts, withdraws and opts back in from their profile', function () {
    $counsellor = rscCounsellor();
    rscStars($counsellor, 3, '2026-10-10 10:00:00');
    $recognition = CounsellorRecognitionService::new()->approve(CounsellorRecognitionDTO::new()->fromArray([
        'user' => rscSuperAdmin(), 'month' => '2026-10', 'counsellorId' => $counsellor->id,
    ]));

    $own = rscProfileProps($this, $counsellor, $counsellor->user)['recognitions'][0];
    expect($own)->toMatchArray(['id' => $recognition->id, 'consent' => 'pending'])
        ->and($own['consentRequestId'])->not->toBeNull()
        ->and(GetVisibleRecognitionsAction::new()->execute(null))->toBeEmpty();

    // Accept: through the consent request, as the card does.
    $this->actingAs($counsellor->user)->postJson(route('requests.respond', ['requestId' => $own['consentRequestId']]), ['response' => 'accepted'])
        ->assertCreated();
    expect($recognition->fresh()->consent)->toBe(Consent::accepted)
        ->and(rscProfileProps($this, $counsellor, $counsellor->user)['recognitions'][0]['consentRequestId'])->toBeNull()
        ->and(GetVisibleRecognitionsAction::new()->execute(null)->pluck('id')->all())->toBe([$recognition->id]);

    // Withdraw, then opt back in.
    $this->actingAs($counsellor->user)->postJson(route('counsellor.recognitions.consent', ['recognition' => $recognition->id]), ['action' => 'withdraw'])
        ->assertOk();
    expect(GetVisibleRecognitionsAction::new()->execute(null))->toBeEmpty()
        ->and(rscProfileProps($this, $counsellor, $counsellor->user)['recognitionBadges'])->toBe(['October 2026']);

    $this->actingAs($counsellor->user)->postJson(route('counsellor.recognitions.consent', ['recognition' => $recognition->id]), ['action' => 'restore'])
        ->assertOk();
    expect(GetVisibleRecognitionsAction::new()->execute(null)->pluck('id')->all())->toBe([$recognition->id]);
});

test('declining from the profile keeps it the counsellor\'s alone', function () {
    $counsellor = rscCounsellor();
    rscStars($counsellor, 3, '2026-10-10 10:00:00');
    $recognition = CounsellorRecognitionService::new()->approve(CounsellorRecognitionDTO::new()->fromArray([
        'user' => rscSuperAdmin(), 'month' => '2026-10', 'counsellorId' => $counsellor->id,
    ]));
    $requestId = rscProfileProps($this, $counsellor, $counsellor->user)['recognitions'][0]['consentRequestId'];

    $this->actingAs($counsellor->user)->postJson(route('requests.respond', ['requestId' => $requestId]), ['response' => 'rejected'])
        ->assertCreated();

    expect($recognition->fresh()->consent)->toBe(Consent::declined)
        ->and(GetVisibleRecognitionsAction::new()->execute(User::factory()->adult()->create()))->toBeEmpty()
        ->and(GetVisibleRecognitionsAction::new()->execute($counsellor->user, $counsellor)->pluck('id')->all())->toBe([$recognition->id]);
});

// ---- Queries ----

test('Home\'s query count doesn\'t grow with the recognised or leading counsellors', function (bool $signedIn) {
    $profession = Profession::forceCreate(['name' => 'counsellor']);
    $cases = collect(['anxiety', 'grief', 'stress', 'sleep'])->map(fn (string $name) => TherapyCase::forceCreate(['name' => $name]));
    $viewer = $signedIn ? User::factory()->adult()->create() : null;

    $count = function () use ($viewer) {
        $this->app['auth']->forgetGuards();
        DB::flushQueryLog();
        DB::enableQueryLog();
        rscHomeProps($this, $viewer);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $add = function () use ($profession, $cases) {
        $counsellor = rscCounsellor(['profession_id' => $profession->id, 'name' => null]);
        $counsellor->cases()->attach($cases->pluck('id')->all());
        rscRecognition($counsellor);
        rscStars($counsellor, 2);
    };

    $add();
    $count(); // Warm-up: the first request reads things later ones have in memory.
    $one = $count();

    foreach (range(1, 4) as $i) {
        $add();
    }

    expect(rscHomeProps($this, $viewer)['recognisedCounsellors'])->toHaveCount(5)
        ->and($count())->toBe($one);
})->with(['guest' => false, 'signed in' => true]);

// ---- Your Data ----

test('Your Data lists the counsellor\'s recognitions: the month and their consent, approved only', function () {
    $counsellor = rscCounsellor();
    rscRecognition($counsellor, Consent::withdrawn, '2026-09-01');
    rscRecognition($counsellor, Consent::accepted, '2026-10-01');
    rscRecognition($counsellor, null, '2026-11-01', Decision::skipped);

    expect(GetYourDataAction::new()->execute($counsellor->user)['counsellor']['recognitions'])->toBe([
        ['month' => '2026-10', 'consent' => 'accepted'],
        ['month' => '2026-09', 'consent' => 'withdrawn'],
    ])
        ->and(GetYourDataAction::new()->execute(User::factory()->adult()->create())['counsellor'])->toBeNull();
});

// ---- Seed data ----

test('the seeder makes each showcase state once, and running it again adds nothing', function () {
    $named = fn (string $username) => User::factory()->adult()->create(['username' => $username]);
    $named('mr_robertamoah')->administrator()->create(['type' => AdministratorTypeEnum::super->value, 'verified_at' => now()]);
    foreach (array_keys(RecognitionSeeder::STARS) as $username) {
        rscCounsellor(['user_id' => $named($username)->id]);
    }
    $named('john_davis');
    rscSuspend(rscCounsellor(['user_id' => $named('suspended_counsellor_demo')->id]));

    $this->seed(RecognitionSeeder::class);
    $this->seed(RecognitionSeeder::class);

    $counsellorId = fn (string $username) => User::query()->where('username', $username)->sole()->counsellor->id;
    $consent = fn (string $username) => CounsellorRecognition::query()
        ->where('month', '2026-10-01')
        ->where('counsellor_id', $counsellorId($username))
        ->sole()
        ->consent;

    expect(CounsellorRecognition::query()->count())->toBe(5)
        ->and($consent('sarah_johnson'))->toBe(Consent::pending)
        ->and($consent('michael_chen'))->toBe(Consent::accepted)
        ->and($consent('emma_williams'))->toBe(Consent::declined)
        ->and($consent('james_wilson'))->toBe(Consent::accepted)
        ->and($consent('suspended_counsellor_demo'))->toBe(Consent::accepted)
        ->and(Report::query()->where('description', 'like', RecognitionSeeder::REPORT_MARKER.'%')->count())->toBe(1);

    $names = fn (?User $viewer) => GetVisibleRecognitionsAction::new()->execute($viewer)
        ->map(fn (CounsellorRecognition $recognition) => $recognition->counsellor->user->username)->all();

    // James (open report) and the suspended counsellor are hidden; Emma declined.
    expect($names(null))->toBe(['michael_chen'])
        ->and($names(User::factory()->adult()->create()))->toBe(['sarah_johnson', 'michael_chen'])
        ->and($names(User::query()->where('username', 'emma_williams')->sole()))->toBe(['sarah_johnson', 'michael_chen', 'emma_williams']);

    // Leading: this month's stars, without the reported James.
    $leading = rscHomeProps($this, User::factory()->adult()->create())['leadingCounsellors'];
    expect(array_column($leading, 'id'))->toBe(array_map($counsellorId, ['lisa_anderson', 'david_brown', 'michael_chen']));
});
