<?php

use App\Enums\CounsellorRecognitionConsentEnum as Consent;
use App\Enums\CounsellorRecognitionDecisionEnum as Decision;
use App\Enums\StarTypeEnum;
use App\Models\Counsellor;
use App\Models\CounsellorRecognition;
use App\Models\Report;
use App\Models\Star;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

// SCRUM-478: the counsellor directory (api.counsellors) and the request picker (counsellors.request.get) list the
// counsellors this viewer sees in Home's "Counsellors of the month" first (GetVisibleRecognitionsAction decides who, in
// its order), then everyone else A-Z by displayed name, then by id. Stars play no part.
// Helpers are file-local and cdo-prefixed (--parallel).

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-20 12:00:00', 'UTC'));
    config(['recognition.per_month' => 5]);
    Notification::fake();
});

afterEach(function () {
    Carbon::setTestNow();
});

function cdoCounsellor(string $firstName, string $lastName = 'Smith', ?string $name = null, array $state = []): Counsellor
{
    return Counsellor::factory()->create(array_merge([
        'user_id' => User::factory()->adult()->state(['firstName' => $firstName, 'lastName' => $lastName, 'otherNames' => null]),
        'name' => $name,
    ], $state));
}

function cdoRecognition(Counsellor $counsellor, Consent $consent = Consent::accepted, string $month = '2026-10-01'): CounsellorRecognition
{
    $recognition = (new CounsellorRecognition)->forceFill([
        'counsellor_id' => $counsellor->id,
        'month' => $month,
        'decision' => Decision::approved,
        'consent' => $consent,
        'consent_changed_at' => now(),
        'decided_at' => now(),
        'stars_count' => 3,
        'rank' => 1,
    ]);
    $recognition->save();

    return $recognition;
}

function cdoOpenReportAbout(Counsellor $counsellor): void
{
    $report = new Report(['description' => 'Something happened', 'data' => ['counsellorId' => $counsellor->id]]);
    $report->addedby()->associate(cdoCounsellor('Reporter'));
    $report->reportable()->associate($counsellor->user);
    $report->save();
    $report->forceFill(['status' => 'open'])->save();
}

/** @return array<int, int> */
function cdoDirectoryIds($test, ?User $viewer = null, int $page = 1, string $route = 'api.counsellors'): array
{
    if ($viewer) {
        $test->actingAs($viewer);
    }

    return $test->getJson(route($route, ['page' => $page]))->assertSuccessful()->json('data.*.id');
}

test('the showcase\'s counsellors come first, then everyone else A-Z by displayed name', function () {
    $zoe = cdoCounsellor('Zoe');
    $adam = cdoCounsellor('Adam');
    $mary = cdoCounsellor('Mary');
    $yaw = cdoCounsellor('Yaw');
    $ben = cdoCounsellor('Ben');

    cdoRecognition($yaw);
    cdoRecognition($mary);

    // Showcase order (approval order), then A-Z.
    expect(cdoDirectoryIds($this))->toBe([$yaw->id, $mary->id, $adam->id, $ben->id, $zoe->id]);
});

test('a guest sees only accepted recognitions first; a signed-in user also pending ones', function () {
    $accepted = cdoCounsellor('Yaw');
    $pending = cdoCounsellor('Zoe');
    $declined = cdoCounsellor('Xena');
    $adam = cdoCounsellor('Adam');

    cdoRecognition($accepted, Consent::accepted);
    cdoRecognition($pending, Consent::pending);
    cdoRecognition($declined, Consent::declined);

    expect(cdoDirectoryIds($this))->toBe([$accepted->id, $adam->id, $declined->id, $pending->id]);

    $viewer = User::factory()->adult()->create();
    expect(cdoDirectoryIds($this, $viewer))->toBe([$accepted->id, $pending->id, $adam->id, $declined->id]);
});

test('another counsellor\'s declined recognition is not boosted for a signed-in counsellor', function () {
    $viewer = cdoCounsellor('Zoe');
    $adam = cdoCounsellor('Adam');
    $declined = cdoCounsellor('Yemi');
    cdoRecognition($declined, Consent::declined);

    // whereNotUser(): a counsellor never lists themselves, so only others' rows can show here.
    expect(cdoDirectoryIds($this, $viewer->user))->toBe([$adam->id, $declined->id]);
});

test('a recognised counsellor hidden by an open report is not boosted', function () {
    $reported = cdoCounsellor('Bea');
    $zoe = cdoCounsellor('Zoe');
    $ama = cdoCounsellor('Ama');
    cdoRecognition($reported);
    cdoRecognition($zoe);
    cdoOpenReportAbout($reported);
    $reporter = Report::query()->sole()->addedby;

    expect(cdoDirectoryIds($this))->toBe([$zoe->id, $ama->id, $reported->id, $reporter->id]);
});

test('the counsellor name is used when set, the account\'s name otherwise, case-insensitively, with the id breaking ties', function () {
    $named = cdoCounsellor('Zed', 'Zulu', 'Abena Mensah');
    $lower = cdoCounsellor('bob');
    // Created out of order, so the last name (then the id) has to decide.
    $kofiAppiah = cdoCounsellor('Kofi', 'Appiah');
    $kofiOne = cdoCounsellor('Kofi', 'Annan');
    $kofiTwo = cdoCounsellor('Kofi', 'annan');

    expect(cdoDirectoryIds($this))->toBe([$named->id, $lower->id, $kofiOne->id, $kofiTwo->id, $kofiAppiah->id]);
});

test('an anonymised account goes last, its erased name never read', function () {
    $erased = cdoCounsellor('Aaron', 'Aardvark');
    $erased->user->forceFill(['anonymised_at' => now()])->saveQuietly();
    $named = cdoCounsellor('Aaron', 'Aardvark', 'Zainab Owusu', ['user_id' => User::factory()->adult()->state(['anonymised_at' => now()])]);
    $zoe = cdoCounsellor('Zoe');

    // The named one shows its counsellor name, so it sorts by it ("Zainab" before "Zoe").
    expect(cdoDirectoryIds($this))->toBe([$named->id, $zoe->id, $erased->id]);
});

test('stars no longer affect the order', function () {
    // The old order was ascending star count: Zoe (none) before Adam (3).
    $zoe = cdoCounsellor('Zoe');
    $adam = cdoCounsellor('Adam');

    for ($i = 0; $i < 3; $i++) {
        $star = Star::create(['type' => StarTypeEnum::contribution->value]);
        $star->starred()->associate($adam)->save();
    }

    expect(cdoDirectoryIds($this))->toBe([$adam->id, $zoe->id]);
});

test('the request picker uses the same order, verified counsellors only', function () {
    $zoe = cdoCounsellor('Zoe');
    $adam = cdoCounsellor('Adam');
    $yaw = cdoCounsellor('Yaw');
    $unverified = cdoCounsellor('Abel', state: ['verified_at' => null, 'verification_expires_at' => null]);
    cdoRecognition($yaw);

    $viewer = User::factory()->adult()->create();

    expect(cdoDirectoryIds($this, $viewer, route: 'counsellors.request.get'))->toBe([$yaw->id, $adam->id, $zoe->id])
        ->and(cdoDirectoryIds($this, $viewer))->toBe([$yaw->id, $unverified->id, $adam->id, $zoe->id]);
});

test('page 2 carries on where page 1 stopped, with nobody repeated or missed', function () {
    $counsellors = collect(['Kwame', 'Abena', 'Yaa', 'Kojo', 'Esi', 'Ama', 'Fiifi', 'Akosua'])
        ->map(fn (string $first) => cdoCounsellor($first));
    cdoRecognition($counsellors[2]);

    $first = cdoDirectoryIds($this);
    $second = cdoDirectoryIds($this, page: 2);

    $expected = collect([$counsellors[2]])
        ->merge($counsellors->except(2)->sortBy(fn (Counsellor $c) => $c->user->firstName))
        ->pluck('id')
        ->all();

    expect($first)->toHaveCount(5)
        ->and(array_merge($first, $second))->toBe($expected);
});

test('the directory works with no recognitions at all', function () {
    $zoe = cdoCounsellor('Zoe');
    $adam = cdoCounsellor('Adam');

    expect(CounsellorRecognition::query()->count())->toBe(0)
        ->and(cdoDirectoryIds($this))->toBe([$adam->id, $zoe->id]);
});
