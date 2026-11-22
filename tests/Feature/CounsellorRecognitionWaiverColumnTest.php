<?php

use App\DTOs\PlatformFeeWaiverDTO;
use App\Enums\AdministratorTypeEnum;
use App\Enums\CounsellorRecognitionDecisionEnum;
use App\Enums\HelpRequestStatusEnum;
use App\Enums\StarSourceEnum;
use App\Exceptions\PlatformFeeWaiverException;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\CounsellorRecognition;
use App\Models\HelpRequest;
use App\Models\PlatformFeeWaiver;
use App\Models\Report;
use App\Models\Star;
use App\Models\Therapy;
use App\Models\User;
use App\Services\PlatformFeeWaiverService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

// TT-7.11e2/SCRUM-477: the admin recognition page's "Fee waiver" column -- the periods linked to the month's
// recognitions come in the page props (feeWaivers), in a fixed number of queries, cut down for an ordinary
// administrator and for the viewer's own row. Helpers are file-local and rwc-prefixed (--parallel).

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-20 12:00:00', 'UTC'));
    config(['recognition.per_month' => 5, 'recognition.stars_count_from' => null]);
    Notification::fake();
});

afterEach(function () {
    Carbon::setTestNow();
});

function rwcAdmin(bool $super = true): User
{
    return User::factory()->adult()
        ->has(Administrator::factory()->state([
            'type' => $super ? AdministratorTypeEnum::super->value : AdministratorTypeEnum::normal->value,
        ]))
        ->create();
}

function rwcCounsellor(array $state = []): Counsellor
{
    return Counsellor::factory()->create(array_merge(['user_id' => User::factory()->adult()], $state));
}

// An approved recognition for $month ('Y-m-01'), with a period of the counsellor's linked to it.
function rwcLinked(Counsellor $counsellor, string $startsAt, ?string $endsAt, string $month = '2026-10-01', array $waiver = []): PlatformFeeWaiver
{
    $period = PlatformFeeWaiver::factory()
        ->between(Carbon::parse($startsAt, 'UTC'), $endsAt ? Carbon::parse($endsAt, 'UTC') : null)
        ->forCounsellor($counsellor)
        ->create(['reason' => 'Counsellor of the month, October 2026', ...$waiver]);

    (new CounsellorRecognition)->forceFill([
        'counsellor_id' => $counsellor->id,
        'month' => $month,
        'decision' => CounsellorRecognitionDecisionEnum::approved,
        'added_manually' => true,
        'stars_count' => 0,
        'rank' => null,
        'decided_at' => now(),
        'platform_fee_waiver_id' => $period->id,
    ])->save();

    return $period;
}

function rwcOpenReport(Counsellor $counsellor): void
{
    $client = User::factory()->adult()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => $counsellor->id]);
    $report = new Report(['description' => 'Something happened', 'data' => ['counsellorId' => $counsellor->id]]);
    $report->addedby()->associate($client);
    $report->reportable()->associate($therapy);
    $report->save();
    $report->forceFill(['status' => 'open'])->save();
}

// The counsellor's own account asked for urgent help, and that help chat is reported (open): it keeps them from a new
// period, but who asked stays private (security review M1).
function rwcHelpRequestReport(Counsellor $counsellor): void
{
    $help = HelpRequest::create(['user_id' => $counsellor->user_id, 'status' => HelpRequestStatusEnum::ended->value]);
    $report = new Report(['description' => 'Something happened', 'data' => []]);
    $report->addedby()->associate(User::factory()->adult()->create());
    $report->reportable()->associate($help);
    $report->save();
    $report->forceFill(['status' => 'open'])->save();
}

// $count new-rule stars in October 2026, written directly (as the SCRUM-474 test does).
function rwcStars(Counsellor $counsellor, int $count): void
{
    static $key = 0;

    for ($i = 0; $i < $count; $i++) {
        $star = (new Star)->forceFill([
            'type' => StarSourceEnum::sessionHeld->starType()->value,
            'source' => StarSourceEnum::sessionHeld,
            'award_key' => 'session_held:rwc'.(++$key),
        ]);
        $star->starred()->associate($counsellor);
        $star->starreable()->associate($counsellor->user);
        $star->save();
        $star->forceFill(['created_at' => Carbon::parse('2026-10-10 10:00:00', 'UTC')])->save();
    }
}

test('a super admin gets the month\'s linked periods with everything, and nothing else', function () {
    $counsellor = rwcCounsellor();
    $upcoming = rwcLinked($counsellor, '2026-12-01 00:00', '2027-01-01 00:00');
    $active = rwcLinked($other = rwcCounsellor(), '2026-11-01 00:00', '2026-12-01 00:00');
    // Not this month's: another month's recognition, an unlinked period of a listed counsellor, a platform-wide one.
    rwcLinked(rwcCounsellor(), '2026-11-01 00:00', '2026-12-01 00:00', '2026-11-01');
    PlatformFeeWaiver::factory()->forCounsellor($counsellor)->between(Carbon::parse('2027-02-01', 'UTC'), Carbon::parse('2027-03-01', 'UTC'))->create();
    PlatformFeeWaiver::factory()->create();

    $this->actingAs(rwcAdmin())->get(route('administrator.recognition', ['month' => '2026-10']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/Recognition')
            ->has('feeWaivers', 2)
            ->where('feeWaivers.0.id', $upcoming->id)
            ->where('feeWaivers.0.counsellorId', $counsellor->id)
            ->where('feeWaivers.0.recognitionId', CounsellorRecognition::query()->where('platform_fee_waiver_id', $upcoming->id)->value('id'))
            ->where('feeWaivers.0.recognitionDecision', 'approved')
            ->where('feeWaivers.0.startsAt', '2026-12-01T00:00:00Z')
            ->where('feeWaivers.0.endsAt', '2027-01-01T00:00:00Z')
            ->where('feeWaivers.0.status', PlatformFeeWaiver::UPCOMING)
            ->where('feeWaivers.0.reason', 'Counsellor of the month, October 2026')
            ->where('feeWaivers.0.needsReview', false)
            ->where('feeWaivers.0.canDelete', true)
            ->where('feeWaivers.0.canEnd', false)
            ->where('feeWaivers.0.redacted', false)
            ->where('feeWaivers.1.id', $active->id)
            ->where('feeWaivers.1.counsellorId', $other->id)
            ->where('feeWaivers.1.status', PlatformFeeWaiver::ACTIVE)
            ->where('feeWaivers.1.canEnd', true)
            ->where('feeWaivers.1.canDelete', false)
            // The linked recognitions are on the page as rows, so the column can find them.
            ->has('recognition.others', 2));
});

test('an ordinary admin gets the dates, the status and needsReview -- no reason, no names, no actions', function () {
    $counsellor = rwcCounsellor();
    $period = rwcLinked($counsellor, '2026-11-01 00:00', '2026-12-01 00:00');
    rwcOpenReport($counsellor);

    $this->actingAs(rwcAdmin(super: false))->get(route('administrator.recognition', ['month' => '2026-10']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('feeWaivers', 1)
            ->where('feeWaivers.0', fn ($waiver) => collect($waiver)->keys()->sort()->values()->all() === collect([
                'id', 'startsAt', 'endsAt', 'status', 'deleted', 'counsellorId', 'recognitionId', 'recognitionDecision', 'needsReview', 'redacted',
            ])->sort()->values()->all())
            ->where('feeWaivers.0.id', $period->id)
            ->where('feeWaivers.0.status', PlatformFeeWaiver::ACTIVE)
            ->where('feeWaivers.0.needsReview', true));
});

test('the viewer\'s own row says only that a period exists', function (bool $super) {
    $admin = rwcAdmin($super);
    $own = rwcCounsellor(['user_id' => $admin->id]);
    rwcLinked($own, '2026-11-01 00:00', '2026-12-01 00:00');
    rwcOpenReport($own);
    $other = rwcLinked(rwcCounsellor(), '2026-12-01 00:00', '2027-01-01 00:00');

    $this->actingAs($admin)->get(route('administrator.recognition', ['month' => '2026-10']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('ownCounsellorId', $own->id)
            ->has('feeWaivers', 2)
            ->where('feeWaivers.0', [
                'counsellorId' => $own->id,
                'recognitionId' => CounsellorRecognition::query()->where('counsellor_id', $own->id)->value('id'),
                'redacted' => true,
            ])
            ->where('feeWaivers.1.id', $other->id)
            ->where('feeWaivers.1.redacted', false));
})->with(['super admin' => true, 'ordinary admin' => false]);

test('a period needs review once its recognition is skipped, and only while it still applies', function () {
    $counsellor = rwcCounsellor();
    rwcLinked($counsellor, '2026-11-01 00:00', '2026-12-01 00:00');
    rwcLinked($ended = rwcCounsellor(), '2026-11-01 00:00', '2026-11-10 00:00');
    CounsellorRecognition::query()->update(['decision' => CounsellorRecognitionDecisionEnum::skipped->value]);

    $this->actingAs(rwcAdmin())->get(route('administrator.recognition', ['month' => '2026-10']))
        ->assertInertia(fn ($page) => $page
            ->where('feeWaivers.0.needsReview', true)
            ->where('feeWaivers.0.recognitionDecision', 'skipped')
            ->where('feeWaivers.1.counsellorId', $ended->id)
            ->where('feeWaivers.1.status', PlatformFeeWaiver::ENDED)
            ->where('feeWaivers.1.needsReview', false));
});

test('the page reads the column in the same number of queries however many periods are linked', function () {
    $admin = rwcAdmin();
    $count = function () use ($admin) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get(route('administrator.recognition', ['month' => '2026-10']))->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    // The same kinds of row each time (ended by someone, under an open report), so only their number changes.
    $linked = function () use ($admin) {
        $counsellor = rwcCounsellor();
        rwcLinked($counsellor, '2026-11-01 00:00', '2026-12-01 00:00')->forceFill(['ended_by_id' => $admin->id])->save();
        rwcOpenReport($counsellor);
    };

    $linked();
    $count(); // warm up: the first request also records the visitor and caches the user
    $one = $count();

    foreach (range(1, 4) as $i) {
        $linked();
    }

    expect($count())->toBe($one);
});

test('the service lists them for any administrator, and nobody else', function () {
    $dto = fn (User $user) => PlatformFeeWaiverDTO::new()->fromArray(['user' => $user]);
    $month = Carbon::parse('2026-10-01', 'UTC');
    rwcLinked(rwcCounsellor(), '2026-12-01 00:00', '2027-01-01 00:00');

    expect(PlatformFeeWaiverService::new()->getLinkedToRecognitionMonthForAdmin($dto(rwcAdmin(super: false)), $month)['waivers'])->toHaveCount(1)
        ->and(fn () => PlatformFeeWaiverService::new()->getLinkedToRecognitionMonthForAdmin($dto(User::factory()->adult()->create()), $month))
        ->toThrow(PlatformFeeWaiverException::class, PlatformFeeWaiverService::NOT_ALLOWED_TO_VIEW);
});

test('the per-counsellor JSON list stays super-admin only', function () {
    $counsellor = rwcCounsellor();

    $this->actingAs(rwcAdmin(super: false))->getJson(route('admin.counsellors.fee-waivers.index', ['counsellor' => $counsellor->id]))
        ->assertForbidden();
});

test('a help-request report never shows as needsReview, on the page or the JSON route, though it still blocks a new period', function (bool $super) {
    $counsellor = rwcCounsellor();
    $period = rwcLinked($counsellor, '2026-11-01 00:00', '2026-12-01 00:00');
    rwcHelpRequestReport($counsellor);
    $admin = rwcAdmin($super);

    $this->actingAs($admin)->get(route('administrator.recognition', ['month' => '2026-10']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('feeWaivers.0.id', $period->id)
            ->where('feeWaivers.0.needsReview', false));

    if (! $super) {
        return;
    }

    $this->actingAs($admin)->getJson(route('admin.counsellors.fee-waivers.index', ['counsellor' => $counsellor->id]))
        ->assertOk()
        ->assertJsonPath('counsellor.eligible', true)
        ->assertJsonPath('waivers.0.needsReview', false);

    // Creating is still gated on every open report.
    $this->actingAs($admin)->postJson(route('admin.counsellors.fee-waivers.store', ['counsellor' => $counsellor->id]), [
        'starts_at' => '2027-01-01T00:00', 'ends_at' => '2027-02-01T00:00', 'reason' => 'Counsellor of the month',
    ])->assertJsonValidationErrors('counsellor');

    // ...and so is the response's flag after an end (the same display rule).
    $this->actingAs($admin)->postJson(route('admin.counsellors.fee-waivers.end', ['counsellor' => $counsellor->id, 'waiver' => $period->id]))
        ->assertOk()
        ->assertJsonPath('waiver.status', PlatformFeeWaiver::CANCELLED);
})->with(['super admin' => true, 'ordinary admin' => false]);

test('an open report that names the counsellor still shows as needsReview on the JSON route', function () {
    $counsellor = rwcCounsellor();
    rwcLinked($counsellor, '2026-11-01 00:00', '2026-12-01 00:00');
    rwcOpenReport($counsellor);

    $this->actingAs(rwcAdmin())->getJson(route('admin.counsellors.fee-waivers.index', ['counsellor' => $counsellor->id]))
        ->assertJsonPath('counsellor.eligible', false)
        ->assertJsonPath('waivers.0.needsReview', true);
});

test('the page after approving or adding has the new recognition\'s id on its row, for the waiver to link to', function (string $action) {
    $counsellor = rwcCounsellor();
    if ($action === 'approve') {
        rwcStars($counsellor, 3);
    }
    $admin = rwcAdmin();
    $page = route('administrator.recognition', ['month' => '2026-10']);

    $this->actingAs($admin)->from($page)
        ->followingRedirects()
        ->post(route("admin.recognition.{$action}"), ['month' => '2026-10', 'counsellor_id' => $counsellor->id])
        ->assertOk()
        ->assertInertia(function ($inertia) use ($action, $counsellor) {
            $list = $action === 'approve' ? 'suggestions' : 'others';
            $inertia->component('Admin/Recognition')
                ->where("recognition.{$list}.0.counsellorId", $counsellor->id)
                ->where("recognition.{$list}.0.decision", 'approved')
                ->where("recognition.{$list}.0.recognitionId", CounsellorRecognition::query()->where('counsellor_id', $counsellor->id)->value('id'));
        });
})->with(['approve', 'add']);

test('end now and delete from the page go back to it, and the column follows', function () {
    $admin = rwcAdmin();
    $page = route('administrator.recognition', ['month' => '2026-10']);
    $running = rwcLinked($first = rwcCounsellor(), '2026-11-01 00:00', '2026-12-01 00:00');
    $upcoming = rwcLinked($second = rwcCounsellor(), '2026-12-01 00:00', '2027-01-01 00:00');

    $this->actingAs($admin)->from($page)
        ->post(route('admin.counsellors.fee-waivers.end', ['counsellor' => $first->id, 'waiver' => $running->id]))
        ->assertRedirect($page)
        ->assertSessionHasNoErrors();

    $this->actingAs($admin)->from($page)
        ->post(route('admin.counsellors.fee-waivers.delete', ['counsellor' => $second->id, 'waiver' => $upcoming->id]))
        ->assertRedirect($page)
        ->assertSessionHasNoErrors();

    // A refusal (already ended) comes back as a field error on the page.
    $this->actingAs($admin)->from($page)
        ->post(route('admin.counsellors.fee-waivers.end', ['counsellor' => $first->id, 'waiver' => $running->id]))
        ->assertRedirect($page)
        ->assertSessionHasErrors('waiver');

    $this->actingAs($admin)->get($page)
        ->assertInertia(fn ($inertia) => $inertia
            ->has('feeWaivers', 1)
            ->where('feeWaivers.0.id', $running->id)
            ->where('feeWaivers.0.status', PlatformFeeWaiver::CANCELLED)
            ->where('feeWaivers.0.canEnd', false));
});
